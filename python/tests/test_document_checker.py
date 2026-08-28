import io
import json
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from unittest.mock import patch

from PIL import Image

from document_checker import DocumentError, analyze_text, extract_text, main, validate_file


class AnalyzeTextTest(unittest.TestCase):
    def test_valid_delivery_note_extracts_fields(self):
        result = analyze_text(
            "SURAT JALAN Nomor: SJ-2026/001 Tanggal 27/08/2026 Pengirim: Gudang Penerima: Toko"
        )

        self.assertEqual("LENGKAP", result["status"])
        self.assertEqual("SJ-2026/001", result["analysis"]["ocr"]["document_number"])
        self.assertEqual("27/08/2026", result["analysis"]["ocr"]["date"])
        self.assertLessEqual(result["scores"]["overall_score"], 100)
        self.assertEqual(50, result["scores"]["authenticity_score"])

    def test_unrelated_text_is_not_valid(self):
        result = analyze_text("Catatan rapat internal")

        self.assertEqual("TERINDIKASI_MANIPULASI", result["status"])
        self.assertEqual(0, result["scores"]["completeness_score"])

    def test_contract_contains_supported_status_and_details(self):
        result = analyze_text("INVOICE No INV-100 tanggal 27/08/2026 total 100000 TTD", "invoice")

        self.assertIn(result["status"], {"LENGKAP", "PERLU_DITINJAU", "TERINDIKASI_MANIPULASI", "TIDAK_TERBACA"})
        self.assertIsInstance(result["confidence"], float)
        self.assertIsInstance(result["notes"], str)
        self.assertEqual({"ocr", "metadata", "manipulation", "barcode"}, set(result["analysis"]))
        for score in ("readability_score", "completeness_score", "authenticity_score", "overall_score"):
            self.assertIsInstance(result["scores"][score], int)
            self.assertGreaterEqual(result["scores"][score], 0)
            self.assertLessEqual(result["scores"][score], 100)
        self.assertIsInstance(result["notes"], str)
        self.assertIsInstance(result["analysis"], dict)

    def test_approval_area_is_recognized_as_signature_evidence(self):
        result = analyze_text("Dikirim oleh Ekspedisi Nama Jelas")

        self.assertTrue(result["analysis"]["ocr"]["verification_mark_detected"])

    def test_iso_date_is_detected(self):
        result = analyze_text("Tanggal 2026-08-27")

        self.assertEqual("2026-08-27", result["analysis"]["ocr"]["date"])

    def test_english_compact_date_and_truck_label_are_detected(self):
        result = analyze_text("Tgl Masuk: 26Aug 2026 Nomor Truk: A9170R No. PO: $161935")
        ocr = result["analysis"]["ocr"]

        self.assertEqual("26Aug 2026", ocr["date"])
        self.assertEqual("A9170R", ocr["vehicle_number"])
        self.assertEqual("161935", ocr["purchase_order_number"])

    def test_stamp_and_barcode_are_accepted_as_verification_marks(self):
        result = analyze_text("Dokumen dilengkapi stempel dan QR Code")

        self.assertTrue(result["analysis"]["ocr"]["verification_mark_detected"])
        self.assertIn("cap/stempel", result["analysis"]["ocr"]["verification_mark_types"])
        self.assertIn("barcode/QR", result["analysis"]["ocr"]["verification_mark_types"])

    def test_ocr_metadata_fields_are_extracted(self):
        result = analyze_text(
            "SURAT JALAN No SJ-100\nTanggal 27/08/2026\nNo PO: PO-77\n"
            "Pengirim: Gudang Pusat\nPenerima: Toko Maju\nNo Kendaraan: B 1234 XYZ\nJumlah: 50"
        )
        ocr = result["analysis"]["ocr"]

        self.assertEqual("SJ-100", ocr["document_number"])
        self.assertEqual("PO-77", ocr["purchase_order_number"])
        self.assertEqual("Gudang Pusat", ocr["sender"])
        self.assertEqual("Toko Maju", ocr["recipient"])
        self.assertEqual("B 1234 XYZ", ocr["vehicle_number"])
        self.assertEqual("50", ocr["total_items"])
        self.assertIn("SURAT JALAN", ocr["raw_text"])

    def test_weight_vocabulary_and_unit_are_extracted(self):
        for text, label in (
            ("Berat: 36.400 KG", "berat"),
            ("Weight 1250 kg", "weight"),
            ("Gross Weight: 40,500 KGS", "gross weight"),
            ("Netto = 38.250 kg", "netto"),
            ("Tare: 13,280 KG", "tare"),
        ):
            with self.subTest(label=label):
                ocr = analyze_text(text)["analysis"]["ocr"]
                self.assertEqual(label, ocr["total_label"])
                self.assertIsNotNone(ocr["total_items"])
                self.assertIn(ocr["total_unit"], {"kg", "kgs"})

    def test_supplier_and_company_fallbacks_are_extracted(self):
        result = analyze_text(
            "TIKET PENIMBANGAN PT INDOCEMENT TUNGGAL PRAKARSA, Tbk. "
            "Kode Supplier: BS75 - KRAKATAU POSCO Dump Loc: M2-P14"
        )
        ocr = result["analysis"]["ocr"]

        self.assertEqual("BS75 - KRAKATAU POSCO", ocr["sender"])
        self.assertEqual("PT INDOCEMENT TUNGGAL PRAKARSA Tbk", ocr["recipient"])

    def test_fragmented_internal_company_name_is_normalized(self):
        result = analyze_text("PT INDO YENT Ty RINTAH MUAT Supply Dept INGGAL PRAKARSA, Tbk")

        self.assertEqual(
            "PT INDOCEMENT TUNGGAL PRAKARSA Tbk",
            result["analysis"]["ocr"]["recipient"],
        )


class FileHandlingTest(unittest.TestCase):
    def test_rejects_unsupported_extension(self):
        with tempfile.NamedTemporaryFile(suffix=".exe") as file:
            with self.assertRaisesRegex(DocumentError, "Format dokumen"):
                validate_file(Path(file.name))

    def test_extracts_image_text_through_ocr(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "fixture.png"
            Image.new("RGB", (20, 20), "white").save(path)
            with (
                patch("document_checker.pytesseract.get_languages", return_value=["eng"]),
                patch("document_checker.pytesseract.image_to_string", return_value="SURAT JALAN"),
            ):
                self.assertIn("SURAT JALAN", extract_text(path))

    def test_cli_json_is_ascii_safe_for_noisy_ocr_characters(self):
        result = analyze_text("SURAT JALAN No SJ-1 Tanggal 27/08/2026 Pengirim Penerima TTD")
        result["analysis"]["ocr"]["raw_text"] = "Teks OCR rusak: \ufffd dan é"
        stdout = io.StringIO()

        with patch("document_checker.verify_document", return_value=result), redirect_stdout(stdout):
            self.assertEqual(0, main(["fixture.jpg"]))

        output = stdout.getvalue()
        self.assertTrue(output.isascii())
        self.assertEqual("Teks OCR rusak: \ufffd dan é", json.loads(output)["analysis"]["ocr"]["raw_text"])


if __name__ == "__main__":
    unittest.main()
