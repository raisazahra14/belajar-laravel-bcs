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


def _daily_series(transactions: list[dict[str, Any]], today: date) -> tuple[list[date], list[float], int]:
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


def _base_result(item_id: Any, stock: int, minimum: int, today: date,
                 count: int, out_days: int, history_days: int) -> dict[str, Any]:
    return {
        "barang_id": item_id, "current_stock": stock, "prediction_available": False,
        "predicted_30_day_need": None, "demand_30_days": None,
        "predicted_minimum_date": None, "predicted_depletion_date": None,
        "safety_stock": minimum, "recommended_restock": max(minimum - stock, 0),
        "status": _stock_status(stock, minimum), "method": "minimum_stock_fallback",
        "confidence": None, "out_transaction_count": count,
        "out_transaction_days": out_days, "history_days": history_days,
        "reason": "Riwayat transaksi OUT belum mencukupi",
        "analysis_status": "insufficient_data", "message": "Riwayat transaksi OUT belum mencukupi",
        "metrics": None, "requires_review": False, "anomaly_reason": None,
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
    result = _base_result(item.get("id"), stock, minimum, today, valid_count, nonzero_days, history_days)
    if history_days < minimum_history or nonzero_days < minimum_out_days:
        result["reason"] = (f"Riwayat hanya {history_days} dari minimal {minimum_history} hari dan "
                            f"{nonzero_days} dari minimal {minimum_out_days} hari transaksi OUT")
        result["message"] = result["reason"]
        return result
    daily_rate = sum(usage) / history_days
    if daily_rate <= 0:
        result["reason"] = result["message"] = "Permintaan harian bernilai nol"
        return result
    demand = daily_rate * horizon
    safety = max(minimum, int(math.ceil(daily_rate * 7)))
    restock = max(int(math.ceil(demand + safety - stock)), 0)
    minimum_date = today + timedelta(days=max(0, math.ceil((stock - safety) / daily_rate))) if stock > safety else None
    depletion_date = today + timedelta(days=max(0, math.ceil(stock / daily_rate)))
    deviation = statistics.pstdev(usage) if len(usage) > 1 else 0.0
    stability = max(0.0, 1.0 - min(deviation / daily_rate, 1.0))
    confidence = round(0.4 * min(history_days / minimum_history, 1.0)
                       + 0.3 * min(nonzero_days / minimum_out_days, 1.0) + 0.3 * stability, 2)
    positive = [value for value in usage if value > 0]
    median = statistics.median(positive) if positive else 0
    outlier = bool(median and max(positive) > median * 5)
    extreme = restock > max(stock * 5, minimum * 10, 100)
    result.update({
        "prediction_available": True, "predicted_30_day_need": round(demand, 2),
        "demand_30_days": round(demand, 2),
        "predicted_minimum_date": minimum_date.isoformat() if minimum_date else None,
        "predicted_depletion_date": depletion_date.isoformat(), "safety_stock": safety,
        "recommended_restock": restock,
        "status": "Perlu Ditinjau" if outlier or extreme else _stock_status(stock, safety),
        "method": "moving_average", "confidence": confidence, "reason": None,
        "analysis_status": "completed", "message": None,
        "metrics": {"daily_demand": round(daily_rate, 4), "calendar_days": history_days},
        "requires_review": outlier or extreme,
        "anomaly_reason": "Outlier permintaan atau rekomendasi restock ekstrem" if outlier or extreme else None,
    })
    return result


def main() -> int:
    try:
        payload = json.load(sys.stdin)
        output = [predict(row) for row in payload] if isinstance(payload, list) else predict(payload)
        print(json.dumps(output, ensure_ascii=True, allow_nan=False))
        return 0
    except Exception as exc:
        print(json.dumps({"error": str(exc)}, ensure_ascii=True), file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
