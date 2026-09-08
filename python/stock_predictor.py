"""Deterministic stock forecasting CLI (JSON stdin -> JSON stdout)."""

from __future__ import annotations

import json
import math
import statistics
import sys
from datetime import date, datetime, timedelta
from typing import Any


def _parse_date(value: Any) -> date:
    return datetime.fromisoformat(str(value).replace("Z", "+00:00")).date()


def _daily_series(
    transactions: list[dict[str, Any]], today: date
) -> tuple[list[date], list[float], int]:
    totals: dict[date, float] = {}
    seen: set[str] = set()
    valid_count = 0
    for row in transactions:
        identity = str(row.get("id", ""))
        if identity and identity in seen:
            continue
        if identity:
            seen.add(identity)
        try:
            quantity = float(row.get("quantity", 0))
            day = _parse_date(row.get("date"))
        except (TypeError, ValueError):
            continue
        if quantity <= 0 or day > today:
            continue
        totals[day] = totals.get(day, 0.0) + quantity
        valid_count += 1
    if not totals:
        return [], [], 0
    start = min(totals)
    days, values = [], []
    cursor = start
    while cursor <= today:
        days.append(cursor)
        values.append(totals.get(cursor, 0.0))
        cursor += timedelta(days=1)
    return days, values, valid_count


def _stock_status(stock: int, safety: int) -> str:
    return "Mendesak" if stock < safety else ("Waspada" if stock == safety else "Aman")


def _base_result(
    item_id: Any,
    stock: int,
    minimum: int,
    today: date,
    count: int,
    out_days: int,
    history_days: int,
) -> dict[str, Any]:
    return {
        "barang_id": item_id,
        "current_stock": stock,
        "prediction_available": False,
        "predicted_30_day_need": None,
        "demand_30_days": None,
        "predicted_minimum_date": None,
        "predicted_depletion_date": None,
        "safety_stock": minimum,
        "recommended_restock": 0,
        "status": "Perlu Ditinjau",
        "method": "cold_start",
        "confidence": None,
        "out_transaction_count": count,
        "out_transaction_days": out_days,
        "history_days": history_days,
        "reason": "Input Cold Start belum lengkap",
        "analysis_status": "missing_input",
        "message": "Input Cold Start belum lengkap",
        "metrics": None,
        "requires_review": False,
        "anomaly_reason": None,
        "missing_inputs": [],
        "fallback_used": False,
        "analyzed_at": datetime.combine(today, datetime.min.time()).isoformat(),
    }


def predict(payload: dict[str, Any], today: date | None = None) -> dict[str, Any]:
    today = today or date.today()
    item = payload["item"]
    stock = max(0, int(item["current_stock"]))
    minimum = max(0, int(item.get("minimum_stock", 5)))
    horizon = max(1, int(payload.get("forecast_days", 30)))
    minimum_history = max(1, int(payload.get("minimum_history_days", 30)))
    minimum_out_days = max(1, int(payload.get("minimum_out_transaction_days", 5)))
    days, usage, valid_count = _daily_series(payload.get("out_transactions", []), today)
    nonzero_days, history_days = sum(value > 0 for value in usage), len(days)
    result = _base_result(
        item.get("id"),
        stock,
        minimum,
        today,
        valid_count,
        nonzero_days,
        history_days,
    )
    lead_time = item.get("lead_time_days")
    lead_time = int(lead_time) if lead_time not in (None, "") else None

    if valid_count == 0:
        estimate = item.get("daily_usage_estimate")
        estimate = float(estimate) if estimate not in (None, "") else None
        missing = []
        if estimate is None or estimate <= 0:
            missing.append("estimasi pemakaian harian")
        if lead_time is None or lead_time <= 0:
            missing.append("lead time")
        result["missing_inputs"] = missing
        if missing:
            result["reason"] = result["message"] = (
                "Lengkapi " + " dan ".join(missing) + " untuk menghitung prediksi Cold Start."
            )
            return result
        daily_rate = estimate
        forecast_values = [daily_rate] * horizon
        method = "cold_start"
        confidence = 0.35
        reason = "Belum ada transaksi OUT; estimasi manual digunakan."
    elif history_days < minimum_history or nonzero_days < minimum_out_days:
        daily_rate = sum(usage) / history_days
        forecast_values = [daily_rate] * horizon
        method = "simple_average"
        confidence = round(
            min(0.6, 0.25 + 0.35 * min(history_days / minimum_history, 1.0)), 2
        )
        reason = (
            f"Histori {history_days} hari/{nonzero_days} hari OUT belum cukup untuk ML; "
            "rata-rata pemakaian harian digunakan."
        )
    else:
        from sklearn.linear_model import LinearRegression  # lazy import: hanya saat histori cukup
        features = [[index] for index in range(history_days)]
        model = LinearRegression()
        model.fit(features, usage)
        future = [[history_days + index] for index in range(horizon)]
        forecast_values = [max(0.0, float(value)) for value in model.predict(future)]
        daily_rate = sum(forecast_values) / horizon
        score = max(0.0, float(model.score(features, usage)))
        confidence = round(min(0.95, 0.65 + 0.3 * score), 2)
        method = "machine_learning"
        reason = "Regresi tren menggunakan histori OUT hingga waktu analisis."

    if daily_rate <= 0:
        result["reason"] = result["message"] = "Permintaan harian bernilai nol"
        return result
    demand = sum(forecast_values)
    safety = max(minimum, int(math.ceil(daily_rate * (lead_time or 7))))
    restock = max(int(math.ceil(demand + safety - stock)), 0)
    minimum_date = (
        today + timedelta(days=max(0, math.ceil((stock - safety) / daily_rate)))
        if stock > safety
        else None
    )
    depletion_date = today + timedelta(days=max(0, math.ceil(stock / daily_rate)))
    positive = [value for value in usage if value > 0]
    median = statistics.median(positive) if positive else 0
    outlier = bool(median and max(positive) > median * 5)
    extreme = restock > max(stock * 5, minimum * 10, 100)
    result.update(
        {
            "prediction_available": True,
            "predicted_30_day_need": round(demand, 2),
            "demand_30_days": round(demand, 2),
            "predicted_minimum_date": (
                minimum_date.isoformat() if minimum_date else None
            ),
            "predicted_depletion_date": depletion_date.isoformat(),
            "safety_stock": safety,
            "recommended_restock": restock,
            "status": (
                "Perlu Ditinjau"
                if outlier or extreme
                else (
                    "Mendesak"
                    if stock < safety
                    else ("Perlu Restock" if restock > 0 else _stock_status(stock, safety))
                )
            ),
            "method": method,
            "confidence": confidence,
            "reason": reason,
            "analysis_status": "completed",
            "message": None,
            "metrics": {
                "daily_demand": round(daily_rate, 4),
                "calendar_days": history_days,
                "model": "linear_regression" if method == "machine_learning" else method,
            },
            "requires_review": outlier or extreme,
            "anomaly_reason": (
                "Outlier permintaan atau rekomendasi restock ekstrem"
                if outlier or extreme
                else None
            ),
        }
    )
    return result


def main() -> int:
    try:
        payload = json.load(sys.stdin)
        output = (
            [predict(row) for row in payload]
            if isinstance(payload, list)
            else predict(payload)
        )
        print(json.dumps(output, ensure_ascii=True, allow_nan=False))
        return 0
    except Exception as exc:
        print(json.dumps({"error": str(exc)}, ensure_ascii=True), file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
