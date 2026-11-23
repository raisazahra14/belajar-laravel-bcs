import unittest
from datetime import date, datetime, timedelta
from stock_predictor import predict

TODAY = date(2026, 8, 31)

def payload(stock=100, rows=None, estimate=None, lead_time=None, **options):
    data = {"item": {"id": 1, "current_stock": stock, "minimum_stock": 5,
                     "daily_usage_estimate": estimate, "lead_time_days": lead_time},
            "out_transactions": rows or [], "forecast_days": 30,
            "minimum_history_days": 30, "minimum_out_transaction_days": 5}
    data.update(options)
    return data

def tx(day_ago, quantity, identity=None):
    return {"id": identity if identity is not None else day_ago + 1, "quantity": quantity,
            "date": datetime.combine(TODAY - timedelta(days=day_ago), datetime.min.time()).isoformat()}

class StockPredictorTest(unittest.TestCase):
    def test_enough_history_uses_machine_learning_without_future_data(self):
        rows = [tx(day, 2 + (35 - day) // 10) for day in [35, 28, 21, 14, 7, 0]]
        result = predict(payload(200, rows), TODAY)
        self.assertTrue(result["prediction_available"])
        self.assertEqual("machine_learning", result["method"])
        self.assertEqual("linear_regression", result["metrics"]["model"])

    def test_empty_history_requires_manual_cold_start_inputs(self):
        result = predict(payload(3), TODAY)
        self.assertFalse(result["prediction_available"])
        self.assertEqual("cold_start", result["method"])
        self.assertIsNone(result["predicted_depletion_date"])
        self.assertEqual(0, result["recommended_restock"])
        self.assertEqual(["estimasi pemakaian harian", "lead time"], result["missing_inputs"])

    def test_complete_cold_start_uses_manual_estimate_and_lead_time(self):
        result = predict(payload(20, estimate=2, lead_time=5), TODAY)
        self.assertTrue(result["prediction_available"])
        self.assertEqual("cold_start", result["method"])
        self.assertEqual(60, result["predicted_30_day_need"])
        self.assertEqual(50, result["recommended_restock"])

    def test_short_history_uses_simple_average(self):
        rows = [tx(2, 4), tx(1, 3), tx(0, 2)]
        result = predict(payload(20, rows), TODAY)
        self.assertTrue(result["prediction_available"])
        self.assertEqual("simple_average", result["method"])
        self.assertEqual(90, result["predicted_30_day_need"])

    def test_zero_calendar_days_are_included(self):
        rows = [tx(day, 10) for day in [30, 20, 10, 5, 0]]
        self.assertGreater(predict(payload(100, rows), TODAY)["demand_30_days"], 0)

    def test_duplicates_and_future_are_ignored(self):
        rows = [tx(day, 2, day) for day in [30, 20, 10, 5, 0]]
        rows += [tx(0, 999, 0), {"id": 99, "quantity": 999, "date": "2026-09-01T00:00:00"}]
        result = predict(payload(100, rows), TODAY)
        self.assertEqual(5, result["out_transaction_count"])
        self.assertLess(result["demand_30_days"], 20)

    def test_invalid_quantity_does_not_divide_or_restock_negative(self):
        result = predict(payload(100, [tx(30, 0), tx(0, -2)]), TODAY)
        self.assertFalse(result["prediction_available"])
        self.assertGreaterEqual(result["recommended_restock"], 0)

    def test_stock_boundary_and_complete_contract(self):
        required = {"barang_id", "current_stock", "prediction_available", "demand_30_days",
                    "predicted_minimum_date", "predicted_depletion_date", "safety_stock",
                    "recommended_restock", "status", "method", "confidence",
                    "out_transaction_count", "out_transaction_days", "history_days", "reason", "analyzed_at"}
        for stock, status in [(4, "Mendesak"), (5, "Waspada"), (6, "Aman")]:
            result = predict(payload(stock), TODAY)
            self.assertEqual("Perlu Ditinjau", result["status"])
            self.assertTrue(required.issubset(result))

    def test_deterministic_and_outlier_flagged(self):
        rows = [tx(day, 2) for day in [35, 28, 21, 14, 7]] + [tx(0, 100)]
        first = predict(payload(30, rows), TODAY)
        self.assertEqual(first, predict(payload(30, rows), TODAY))
        self.assertTrue(first["requires_review"])
        self.assertEqual("Perlu Ditinjau", first["status"])

    def test_open_po_stock_reduces_restock_and_avoids_duplicate_urgency_when_fully_covered(self):
        rows = [tx(2, 5), tx(1, 5), tx(0, 5)]
        item_payload = payload(10, rows, lead_time=7)
        item_payload["item"]["incoming_po_stock"] = 200
        result = predict(item_payload, TODAY)

        self.assertEqual(200, result["incoming_po_stock"])
        self.assertEqual(210, result["effective_stock"])
        self.assertEqual(0, result["recommended_restock"])
        self.assertEqual("Waspada", result["status"])
        self.assertEqual((TODAY + timedelta(days=2)).isoformat(), result["predicted_depletion_date"])
        self.assertIn("PO terbuka", result["reason"])

    def test_open_po_stock_partial_coverage_keeps_urgent_status_with_reduced_restock(self):
        rows = [tx(2, 5), tx(1, 5), tx(0, 5)]
        item_payload = payload(10, rows, lead_time=7)
        item_payload["item"]["incoming_po_stock"] = 30
        result = predict(item_payload, TODAY)

        self.assertEqual(30, result["incoming_po_stock"])
        self.assertEqual(40, result["effective_stock"])
        # demand 30d = 150, safety = 35, effective = 40 -> restock = 145
        self.assertEqual(145, result["recommended_restock"])
        self.assertEqual("Mendesak", result["status"])

if __name__ == "__main__": unittest.main()
