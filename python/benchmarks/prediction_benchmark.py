"""Deterministic rolling-origin accuracy benchmark for stock forecasting."""

from __future__ import annotations

import argparse
import json
import math
import platform
import random
import statistics
import sys
import time
from datetime import date, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from stock_predictor import predict  # noqa: E402


def series(kind: str, days: int, seed: int) -> list[float]:
    rng = random.Random(seed)
    values = []
    for day in range(days):
        if kind == "stable":
            value = 10
        elif kind == "trend":
            value = 4 + day * 0.12
        elif kind == "weekly":
            value = 15 if day % 7 < 5 else 4
        else:
            value = 8 + day * 0.04 + rng.gauss(0, 2)
        values.append(round(max(0.1, value), 3))
    return values


def metrics(actual: list[float], predicted: list[float]) -> dict:
    errors = [prediction - truth for truth, prediction in zip(actual, predicted)]
    return {
        "mae": statistics.mean(abs(error) for error in errors),
        "rmse": math.sqrt(statistics.mean(error * error for error in errors)),
        "mape_pct": statistics.mean(abs(error) / max(abs(truth), 1e-9) for truth, error in zip(actual, errors)) * 100,
        "bias": statistics.mean(errors),
    }


def run() -> dict:
    origin = date(2026, 1, 1)
    samples = []
    latencies = []
    warmup_payload = {
        "item": {"id": 0, "current_stock": 1000, "minimum_stock": 10, "lead_time_days": 7},
        "forecast_days": 30,
        "out_transactions": [
            {"id": index + 1, "date": (origin + timedelta(days=index)).isoformat(), "quantity": 10}
            for index in range(60)
        ],
    }
    cold_started = time.perf_counter()
    predict(warmup_payload, today=origin + timedelta(days=59))
    cold_start_ms = (time.perf_counter() - cold_started) * 1000
    for scenario_index, kind in enumerate(("stable", "trend", "weekly", "noisy"), 1):
        values = series(kind, 180, scenario_index)
        for train_days in (60, 90, 120, 150):
            today = origin + timedelta(days=train_days - 1)
            transactions = [
                {"id": index + 1, "date": (origin + timedelta(days=index)).isoformat(), "quantity": quantity}
                for index, quantity in enumerate(values[:train_days])
            ]
            payload = {"item": {"id": scenario_index, "current_stock": 1000, "minimum_stock": 10, "lead_time_days": 7}, "forecast_days": 30, "minimum_history_days": 30, "minimum_out_transaction_days": 5, "out_transactions": transactions}
            started = time.perf_counter()
            result = predict(payload, today=today)
            latency = (time.perf_counter() - started) * 1000
            actual = sum(values[train_days: train_days + 30])
            predicted = float(result["predicted_30_day_need"])
            latencies.append(latency)
            samples.append({"scenario": kind, "train_days": train_days, "actual_30_day_need": round(actual, 3), "predicted_30_day_need": predicted, "absolute_error": round(abs(predicted - actual), 3), "absolute_percentage_error_pct": round(abs(predicted - actual) / actual * 100, 3), "latency_ms": round(latency, 3), "method": result["method"]})
    aggregate = metrics([sample["actual_30_day_need"] for sample in samples], [sample["predicted_30_day_need"] for sample in samples])
    return {
        "schema_version": 1,
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "environment": {"python": platform.python_version(), "platform": platform.platform()},
        "dataset": {"type": "synthetic rolling-origin backtest", "scenarios": 4, "origins": len(samples), "horizon_days": 30, "warning": "Nilai produksi wajib dihitung ulang memakai histori permintaan riil yang sudah dibersihkan."},
        "metrics": {**{key: round(value, 4) for key, value in aggregate.items()}, "cold_start_latency_ms": round(cold_start_ms, 3), "warm_latency_ms_mean": round(statistics.mean(latencies), 3), "warm_latency_ms_p95": round(sorted(latencies)[math.ceil(0.95 * len(latencies)) - 1], 3)},
        "samples": samples,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path)
    parser.add_argument("--max-mape", type=float, default=25.0)
    parser.add_argument("--max-rmse", type=float, default=150.0)
    args = parser.parse_args()
    result = run()
    violations = []
    if result["metrics"]["mape_pct"] > args.max_mape:
        violations.append(f"MAPE {result['metrics']['mape_pct']}% > {args.max_mape}%")
    if result["metrics"]["rmse"] > args.max_rmse:
        violations.append(f"RMSE {result['metrics']['rmse']} > {args.max_rmse}")
    result.update({"thresholds": {"max_mape_pct": args.max_mape, "max_rmse": args.max_rmse}, "passed": not violations, "violations": violations})
    rendered = json.dumps(result, indent=2, ensure_ascii=False)
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(rendered + "\n", encoding="utf-8")
    print(rendered)
    return 0 if not violations else 2


if __name__ == "__main__":
    raise SystemExit(main())
