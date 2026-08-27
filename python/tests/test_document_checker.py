import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from PIL import Image

from document_checker import DocumentError, analyze_text, extract_text, validate_file


class AnalyzeTextTest(unittest.TestCase):
    def test_valid_delivery_note_extracts_fields(self):
        result = analyze_text(
            "SURAT JALAN Nomor: SJ-2026/001 Tanggal 27/08/2026 Pengirim: Gudang Penerima: Toko"
        )

        self.assertEqual("valid", result["status"])
        self.assertEqual("SJ-2026/001", result["details"]["document_number"])
        self.assertEqual("27/08/2026", result["details"]["date"])
        self.assertLessEqual(result["score"], 100)

    def test_unrelated_text_is_not_valid(self):
        result = analyze_text("Catatan rapat internal")

        self.assertEqual("suspicious", result["status"])
        self.assertEqual(0, result["score"])

    def test_contract_contains_supported_status_and_details(self):
        result = analyze_text("INVOICE No INV-100 tanggal 27/08/2026 total 100000 TTD", "invoice")

        self.assertIn(result["status"], {"valid", "review", "suspicious", "failed"})
        self.assertIsInstance(result["message"], str)
        self.assertIsInstance(result["details"], dict)


class FileHandlingTest(unittest.TestCase):
    def test_rejects_unsupported_extension(self):
        with tempfile.NamedTemporaryFile(suffix=".exe") as file:
            with self.assertRaisesRegex(DocumentError, "Format dokumen"):
                validate_file(Path(file.name))

    def test_extracts_image_text_through_ocr(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "fixture.png"
            Image.new("RGB", (20, 20), "white").save(path)
            with patch("document_checker.pytesseract.image_to_string", return_value="SURAT JALAN"):
                self.assertEqual("SURAT JALAN", extract_text(path))


if __name__ == "__main__":
    unittest.main()
