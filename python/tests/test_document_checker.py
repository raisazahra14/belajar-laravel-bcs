import io
import json
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from unittest.mock import patch

from PIL import Image, ImageDraw, ImageFilter

from document_checker import (
    DocumentError,
    analyze_file_evidence,
    analyze_text,
    detect_verification_marks,
    extract_coordinate_fields,
    extract_coordinate_ocr_fields,
    extract_text,
    main,
    validate_file,
    verify_document,
)


class CoordinateMetadataTest(unittest.TestCase):
    @staticmethod
    def line(text, confidence=0.95, top=10):
        return {
            "page": 1,
            "text": text,
            "confidence": confidence,
            "left": 10,
            "top": top,
            "width": 500,
            "height": 20,
            "bottom": top + 20,
        }

    def test_invoice_fields_use_matching_labels_and_normalize_values(self):
        fields = extract_coordinate_fields(
            [
                self.line("No. Invoice: INV-2026/001"),
                self.line("Tanggal: 27 Agustus 2026", top=40),
                self.line("Subtotal: Rp 1.234.567", top=70),
                self.line("DPP: Rp 1.111.111", top=100),
                self.line("Mata Uang: Rupiah", top=130),
            ],
            "invoice",
        )

        self.assertEqual("INV-2026/001", fields["invoice_number"]["value"])
        self.assertEqual("2026-08-27", fields["invoice_date"]["value"])
        self.assertEqual(1234567, fields["subtotal"]["value"])
        self.assertEqual(1111111, fields["dpp"]["value"])
        self.assertEqual("IDR", fields["currency"]["value"])
        self.assertEqual("auto", fields["dpp"]["status"])
        self.assertIn("DPP", fields["dpp"]["source_text"])

    def test_dash_missing_and_low_confidence_require_manual_input(self):
        fields = extract_coordinate_fields(
            [
                self.line("DPP: -"),
                self.line("Total: Rp 50.000", confidence=0.45, top=40),
            ],
            "invoice",
        )

        self.assertIsNone(fields["dpp"]["value"])
        self.assertEqual("manual_required", fields["dpp"]["status"])
        self.assertIsNone(fields["total_amount"]["value"])
        self.assertEqual(0.45, fields["total_amount"]["confidence"])
        self.assertEqual("manual_required", fields["vendor"]["status"])

    def test_dpp_never_copies_subtotal_without_dpp_label(self):
        fields = extract_coordinate_fields(
            [self.line("Subtotal: Rp 900.000")],
            "invoice",
        )

        self.assertEqual(900000, fields["subtotal"]["value"])
        self.assertIsNone(fields["dpp"]["value"])

    def test_value_in_separate_right_or_below_ocr_block_is_detected(self):
        invoice_label = self.line("Invoice No.", top=10)
        invoice_label["width"] = 120
        invoice_value = self.line("INV-2026-777", top=11)
        invoice_value["left"] = 180
        date_label = self.line("Tanggal", top=50)
        unrelated = self.line("Customer", top=70)
        unrelated["left"] = 500
        date_value = self.line("27/08/2026", top=82)

        fields = extract_coordinate_fields(
            [invoice_label, invoice_value, date_label, unrelated, date_value],
            "invoice",
        )

        self.assertEqual("INV-2026-777", fields["invoice_number"]["value"])
        self.assertEqual("2026-08-27", fields["invoice_date"]["value"])
        self.assertEqual(180, fields["invoice_number"]["position"]["x"])

    def test_available_invoice_fixture_matches_complete_expected_result(self):
        fixture = (
            Path(__file__).parents[2]
            / "storage/app/private/document-verifications/1/BElgUmt2dn5QArW9UpAbRqJR1p6meH68TwDe8ycN.jpg"
        )
        if not fixture.exists():
            self.skipTest("Invoice pengujian lokal tidak tersedia.")

        fields = extract_coordinate_ocr_fields(fixture, "invoice")
        expected = {
            "invoice_number": "000020308/ITP-DT/VIII/2026",
            "invoice_date": "2026-08-27",
            "vendor": "PT Buana Centra Swakarsa",
            "customer": "Indocement Tunggal Prakarsa, PT",
            "npwp": "0010621191092000",
            "contract_number": None,
            "purchase_order_number": "Usting",
            "project_code": "2-01-002",
            "subtotal": 10881203,
            "discount": 0,
            "delivery_cost": 0,
            "dpp": 10881203,
            "tax": 0,
            "down_payment": 0,
            "total_amount": 10881203,
            "currency": "IDR",
        }

        self.assertEqual(expected, {field: fields[field]["value"] for field in expected})
        self.assertEqual("manual_required", fields["contract_number"]["status"])
        self.assertNotEqual("auto", fields["contract_number"]["status"])
        self.assertTrue(all(
            details["status"] != "auto" or details["confidence"] > 0
            for details in fields.values()
        ))
        self.assertNotEqual(2545704446, fields["total_amount"]["value"])
        for field in ("subtotal", "discount", "delivery_cost", "dpp", "tax", "down_payment", "total_amount"):
            self.assertEqual("amounts_middle_right", fields[field]["position"]["region"])


class AnalyzeTextTest(unittest.TestCase):
    def test_valid_delivery_note_extracts_fields(self):
        result = analyze_text(
            "SURAT JALAN Nomor: SJ-2026/001 Tanggal 27/08/2026 Pengirim: Gudang Penerima: Toko"
        )

        self.assertEqual("ASLI", result["status"])
        self.assertEqual("SJ-2026/001", result["analysis"]["ocr"]["document_number"])
        self.assertEqual("27/08/2026", result["analysis"]["ocr"]["date"])
        self.assertLessEqual(result["scores"]["overall_score"], 100)
        self.assertEqual(50, result["scores"]["authenticity_score"])

    def test_unrelated_text_is_not_valid(self):
        result = analyze_text("Catatan rapat internal")

        self.assertEqual("PALSU", result["status"])
        self.assertEqual(0, result["scores"]["completeness_score"])

    def test_contract_contains_supported_status_and_details(self):
        result = analyze_text("INVOICE No INV-100 tanggal 27/08/2026 total 100000 TTD", "invoice")

        self.assertIn(result["status"], {"ASLI", "MENCURIGAKAN", "PALSU"})
        self.assertIsInstance(result["confidence"], float)
        self.assertIsInstance(result["notes"], str)
        self.assertTrue({"ocr", "metadata", "file_metadata", "document_metadata", "manipulation", "barcode"}.issubset(result["analysis"]))
        for score in ("readability_score", "completeness_score", "authenticity_score", "overall_score"):
            self.assertIsInstance(result["scores"][score], int)
            self.assertGreaterEqual(result["scores"][score], 0)
            self.assertLessEqual(result["scores"][score], 100)
        self.assertIsInstance(result["notes"], str)
        self.assertIsInstance(result["analysis"], dict)

    def test_labeled_weighing_slip_fields_are_not_mixed(self):
        text = """SURAT JALAN / TIKET PENIMBANGAN
No. Form: 26126084738
No. PO: $161935
Dispatch No: 81619350625
Tanggal Masuk: 26 Aug 2026
Nomor Truk: A9170R
Kode Supplier: KRAKATAU POSCO
Penerima: PT INDOCEMENT TUNGGAL PRAKARSA, Tbk.
Supplier/pengirim/PBM
petugas di lokasi penerimaan"""

        metadata = analyze_text(text)["document_metadata"]

        self.assertEqual("26126084738", metadata["document_number"])
        self.assertEqual("2026-08-26", metadata["document_date"])
        self.assertEqual("S161935", metadata["po_number"])
        self.assertEqual("81619350625", metadata["do_number"])
        self.assertEqual("A9170R", metadata["vehicle_number"])
        self.assertEqual("KRAKATAU POSCO", metadata["sender"])
        self.assertEqual("PT INDOCEMENT TUNGGAL PRAKARSA, Tbk.", metadata["recipient"])
        self.assertIsNone(metadata["gross_weight"])
        self.assertIsNone(metadata["tare_weight"])
        self.assertIsNone(metadata["net_weight"])
        self.assertIsNone(metadata["weight_unit"])

    def test_unlabeled_values_are_null_in_document_metadata(self):
        metadata = analyze_text("SURAT JALAN angka 26126084738 S161935 81619350625 A9170R")["document_metadata"]

        self.assertTrue(all(value is None for value in metadata.values()))

    def test_weights_require_labels_and_units(self):
        metadata = analyze_text("SURAT JALAN\nGross: 34500 kg\nTare: 12180 kg\nNetto: 22320 kg")["document_metadata"]

        self.assertEqual(34500, metadata["gross_weight"])
        self.assertEqual(12180, metadata["tare_weight"])
        self.assertEqual(22320, metadata["net_weight"])
        self.assertEqual("kg", metadata["weight_unit"])

    def test_delivery_note_header_number_and_company_survive_common_ocr_errors(self):
        text = (
            "KRAKATAU OOSCO $S2026- 002614\n"
            "Jl. Afrika No. 02 Kawasan Krakatau Steel\nSURAT JALAN\n"
            "Tanggal\nPerusahaan Penerima\nMenyediakan dan menyerahkan produk\n"
            "No Truk : AGS! 4 TR\nPenerima Angkutan Resource Recycling Team"
        )

        result = analyze_text(text)
        metadata = result["document_metadata"]
        ocr = result["analysis"]["ocr"]

        self.assertEqual("SS2026-002614", metadata["document_number"])
        self.assertEqual("SS2026-002614", ocr["document_number"])
        self.assertEqual("KRAKATAU POSCO", metadata["sender"])
        self.assertIsNone(metadata["recipient"])
        self.assertIsNone(ocr["recipient"])
        self.assertIsNone(metadata["vehicle_number"])
        self.assertIsNone(ocr["purchase_order_number"])

    def test_numeric_date_with_spaces_is_normalized_only_when_ocr_reads_all_parts(self):
        metadata = analyze_text("SURAT JALAN\nTanggal: 11 8 2026\nPengirim: Gudang")["document_metadata"]

        self.assertEqual("2026-08-11", metadata["document_date"])

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

    def test_weighing_slip_english_labels_and_net_weight_are_extracted(self):
        result = analyze_text(
            "WEIGHING SLIP NO : DHO16014 VEHICLE NUMBER : A9514TX "
            "1st WEIGHING WEIGHT : 12800 2nd WEIGHING WEIGHT : 45120 "
            "NETT WEIGHT : 32320 kg"
        )
        ocr = result["analysis"]["ocr"]

        self.assertEqual("DHO16014", ocr["document_number"])
        self.assertEqual("A9514TX", ocr["vehicle_number"])
        self.assertEqual("32320", ocr["total_items"])
        self.assertEqual("nett weight", ocr["total_label"])
        self.assertEqual("kg", ocr["total_unit"])

    def test_metadata_labels_are_extracted_from_single_ocr_line(self):
        result = analyze_text(
            "SURAT JALAN NO SJ-9 Tanggal 2026-08-01 "
            "Pengirim: PT Contoh Sejahtera Penerima: CV Tujuan Makmur "
            "No Kendaraan: B-1234-XYZ"
        )
        ocr = result["analysis"]["ocr"]

        self.assertEqual("PT Contoh Sejahtera", ocr["sender"])
        self.assertEqual("CV Tujuan Makmur", ocr["recipient"])
        self.assertEqual("B-1234-XYZ", ocr["vehicle_number"])

    def test_invoice_uses_invoice_specific_metadata(self):
        result = analyze_text(
            "INVOICE Kepada: Indocement Tunggal Prakarsa, PT "
            "No. Invoice: 00020308/ITP-DT/VIII/2026 NPWP: 0010621191092000 "
            "No. SPK/Kontrak: SPK-10 No. PO: PO-77 Kode Project: 2-01-002 "
            "Subtotal: 10,881,203 Diskon: 0.00 Biaya Pengantaran: 0.00 "
            "DPP: 10,881,203 PPN: 0.00 Uang Muka: 0.00 TOTAL AMOUNT: 10,881,203 IDR "
            "Pembayaran dapat di transfer: PT. Buana Centra Swakarsa BNI Cabang Cilegon "
            "Cilegon, 27 Agustus 2026 TTD",
            "invoice",
        )
        ocr = result["analysis"]["ocr"]
        fields = result["analysis"]["metadata"]["fields"]

        self.assertEqual("00020308/ITP-DT/VIII/2026", ocr["document_number"])
        self.assertEqual("27 Agustus 2026", ocr["date"])
        self.assertEqual("Indocement Tunggal Prakarsa, PT", fields["customer"])
        self.assertEqual("PT. Buana Centra Swakarsa", fields["vendor"])
        self.assertEqual("0010621191092000", fields["npwp"])
        self.assertEqual("SPK-10", fields["contract_number"])
        self.assertEqual("PO-77", fields["purchase_order_number"])
        self.assertEqual("2-01-002", fields["project_code"])
        self.assertEqual(10881203, fields["subtotal"])
        self.assertEqual(10881203, fields["total_amount"])
        self.assertEqual("IDR", fields["currency"])
        self.assertEqual(100, result["scores"]["completeness_score"])
        self.assertEqual(90, result["scores"]["overall_score"])

    def test_bcs_invoice_template_extracts_scg_variant_and_keeps_dash_fields_empty(self):
        result = analyze_text(
            "INVOICE Kepada: SCG Barito Logistics, PT "
            "NPWP: 0829513688031000 Alamat: Wisma Barito Pacific 9th Floor "
            "No. Invoice: 00020309/SCG-DT/VIII/2026 "
            "No. SPK/Kontrak: - No. PO: - Kode Project: 2-01-002 "
            "Sub Total: 111,396,597 Diskon: 0.00 Biaya Pengantaran: 0.00 "
            "DPP: 111,396,597 PPN: 0.00 Total: 111,396,597 "
            "Uang Muka: 0.00 TOTAL AMOUNT: 111,396,597 IDR "
            "Pembayaran dapat di transfer: PT. Buana Centra Swakarsa BNI Cabang Cilegon "
            "Cilegon, 28 Agustus 2026 PT BUANA CENTRA SWAKARSA",
            "invoice",
        )
        fields = result["specialized_metadata"]
        document = result["document_metadata"]

        self.assertEqual("00020309/SCG-DT/VIII/2026", fields["invoice_number"])
        self.assertEqual("2026-08-28", document["document_date"])
        self.assertEqual("PT. Buana Centra Swakarsa", fields["vendor"])
        self.assertEqual("SCG Barito Logistics, PT", fields["customer"])
        self.assertEqual("0829513688031000", fields["npwp"])
        self.assertIsNone(fields["contract_number"])
        self.assertIsNone(fields["purchase_order_number"])
        self.assertEqual("2-01-002", fields["project_code"])
        self.assertEqual(111396597, fields["subtotal"])
        self.assertEqual(0, fields["discount"])
        self.assertEqual(0, fields["delivery_fee"])
        self.assertEqual(111396597, fields["dpp"])
        self.assertEqual(0, fields["tax"])
        self.assertEqual(0, fields["down_payment"])
        self.assertEqual(111396597, fields["total_amount"])
        self.assertEqual("IDR", fields["currency"])

    def test_invoice_prefers_clean_candidate_from_repeated_ocr_text(self):
        result = analyze_text(
            "INVOICE Kepada: Indocement Tung! Prokars, PT "
            "No. Involee: 0020308/1TP-DT/VIIL/2026 "
            "INVOICE Kepada: Indocement Tunggal Prakarsa, PT "
            "No. Invoice: 00020308/ITP-DT/VIII/2026 NPWP: 0010621191092000 "
            "Kode Project: 2-01-002 Subtotal: 10,881,203 DPP: 10,881,203 "
            "Total: 10,881,203 1DR Cilegon, 27 Agustus 2026 "
            "Pembayaran dapat di transfer: PT. Buana Centra Swakarsa TTD",
            "invoice",
        )

        self.assertEqual(
            "00020308/ITP-DT/VIII/2026",
            result["analysis"]["metadata"]["fields"]["invoice_number"],
        )
        self.assertEqual("IDR", result["analysis"]["metadata"]["fields"]["currency"])
        self.assertGreater(result["scores"]["overall_score"], 74)

    def test_invoice_date_is_not_confused_with_project_code(self):
        result = analyze_text(
            "INVOICE No. Invoice: 00020309/SCG-DT/VIII/2026 "
            "Kode Project: 2-01-002 TOTAL AMOUNT: 111,396,597 IDR "
            "Cilegon, 28 Agustus 2026 PT. Buana Centra Swakarsa",
            "invoice",
        )

        self.assertEqual("28 Agustus 2026", result["analysis"]["ocr"]["date"])

    def test_invoice_repeated_ocr_does_not_replace_amounts_with_table_codes(self):
        fields = analyze_text(
            "INVOICE Kepada: Indocement Tunggal Prokarsa, PT "
            "No. Invoice: 00020308/ITP-DT/V1I1/2026 NPWP: 0010621191092000 "
            "No. SPK/Kontrak: - Alamat: Bogor No. PO: Usting Kode Project: 2-01-002 "
            "Total AP: K1C001047 Sub Total: 10,881,203 Diskon: 0.00 "
            "Biaya Pengantaran: 0.00 DPP: 3 10,881,203 "
            "Pembayaran dapat di transfer: PT. Buana Centra Swakarsa PPN: 0.00 No. Rekening: 254-570-444-6 "
            "BNI Cabang Cilegon IDR Total: 10,881,203 Uang Muka: 0.00 "
            "TOTAL AMOUNT: 10,881,203 Cilegon, 27 Agustus 2026 "
            "PT. Buana Centra Swakarsa Direktur",
            "invoice",
        )["analysis"]["metadata"]["fields"]

        self.assertEqual("00020308/ITP-DT/VIII/2026", fields["invoice_number"])
        self.assertEqual("PT. Buana Centra Swakarsa", fields["vendor"])
        self.assertEqual("0010621191092000", fields["npwp"])
        self.assertIsNone(fields["contract_number"])
        self.assertIsNone(fields["purchase_order_number"])
        self.assertEqual(10881203, fields["subtotal"])
        self.assertEqual(0, fields["discount"])
        self.assertEqual(0, fields["delivery_fee"])
        self.assertEqual(10881203, fields["dpp"])
        self.assertEqual(0, fields["tax"])
        self.assertEqual(0, fields["down_payment"])
        self.assertEqual(10881203, fields["total_amount"])

    def test_physical_evidence_uses_physical_metadata(self):
        fields = analyze_text(
            "BUKTI FISIK No Bukti: BF-10 Tanggal 27/08/2026 "
            "Nama Barang: Router Jumlah: 2 Satuan: Unit Kondisi: Baik "
            "Lokasi: Gudang A Catatan: Segel utuh",
            "bukti_fisik",
        )["analysis"]["metadata"]["fields"]

        self.assertEqual("BF-10", fields["reference_number"])
        self.assertEqual("Router", fields["item_name"])
        self.assertEqual("2", fields["quantity"])
        self.assertEqual("Unit", fields["unit"])
        self.assertEqual("Baik", fields["condition"])
        self.assertEqual("Gudang A", fields["location"])
        self.assertEqual("Segel utuh", fields["notes"])


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


    def test_verification_includes_file_manipulation_and_consistency_evidence(self):
        invoice_text = (
            "INVOICE Kepada: PT Customer No. Invoice: INV-2026-001 NPWP: 0829513688031000 "
            "Sub Total: 100000 DPP: 100000 PPN: 0 TOTAL AMOUNT: 100000 "
            "PT. Vendor Indonesia Cilegon, 28 Agustus 2026"
        )
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "invoice.jpg"
            Image.new("RGB", (300, 400), "white").save(path, quality=90)
            with patch("document_checker.extract_text", return_value=invoice_text):
                result = verify_document(path, "invoice")

        self.assertTrue(result["file_metadata"]["analyzed"])
        self.assertTrue(result["analysis"]["manipulation"]["analyzed"])
        self.assertIn("ela_mean", result["analysis"]["manipulation"]["metrics"])
        self.assertTrue(result["analysis"]["consistency"]["analyzed"])
        self.assertIsInstance(result["scores"]["authenticity_score"], int)
        self.assertIn("verification_mark", result["analysis"])
        self.assertIn("verification_mark_detected", result["analysis"]["ocr"])
        self.assertIn("verification_mark_types", result["analysis"]["ocr"])
        self.assertEqual("MENCURIGAKAN", result["status"])

    def test_ela_returns_structured_risk_and_sensitive_region_contract(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "invoice.jpg"
            image = Image.new("RGB", (600, 800), "white")
            ImageDraw.Draw(image).text((80, 300), "TOTAL IDR 100.000", fill="black")
            image.save(path, quality=88)
            region = {"page": 1, "x": 60, "y": 270, "width": 300, "height": 70,
                      "target": "nominal", "text": "TOTAL IDR 100.000"}
            with patch("document_checker._sensitive_ocr_regions", return_value=[region]):
                _, ela = analyze_file_evidence(path)

        self.assertTrue(ela["analyzed"])
        self.assertEqual("ela", ela["method"])
        self.assertIn(ela["risk_level"], {"low", "medium", "high"})
        self.assertGreaterEqual(ela["risk_score"], 0)
        self.assertLessEqual(ela["risk_score"], 100)
        self.assertIn("pages", ela["metrics"])
        self.assertIn("limitations", ela)


class VisualMarkDetectionTest(unittest.TestCase):
    def fixture(self, stamp=None, signature=False, blur=False, logo=False, table=False, barcode=False):
        image = Image.new("RGB", (1000, 1400), "white")
        draw = ImageDraw.Draw(image)
        for y in range(100, 500, 35):
            draw.text((100, y), "DOKUMEN PENERIMAAN BARANG 1234567890", fill="black")
        if logo:
            draw.rectangle((60, 40, 280, 130), fill="blue")
        if table:
            for y in range(650, 1150, 50): draw.line((80, y, 920, y), fill="black", width=2)
            for x in range(80, 921, 140): draw.line((x, 650, x, 1150), fill="black", width=2)
        if barcode:
            for x in range(620, 850, 8): draw.line((x, 900, x, 1080), fill="black", width=3)
        if stamp:
            color = {"blue": (20, 70, 210), "red": (210, 30, 30), "black": (20, 20, 20)}[stamp]
            draw.ellipse((650, 900, 870, 1100), outline=color, width=9)
            draw.ellipse((680, 930, 840, 1070), outline=color, width=5)
            draw.line((700, 1000, 820, 1000), fill=color, width=5)
        if signature:
            points = [(180, 1040), (230, 970), (270, 1060), (320, 950), (350, 1050), (420, 990), (500, 1030)]
            draw.line(points, fill=(15, 30, 110), width=6, joint="curve")
            draw.line((170, 1065, 510, 1065), fill=(15, 30, 110), width=3)
        if blur:
            image = image.resize((300, 420)).filter(ImageFilter.GaussianBlur(5))
        directory = tempfile.TemporaryDirectory()
        path = Path(directory.name) / "fixture.jpg"
        image.save(path, quality=95)
        return directory, path

    def detected(self, **kwargs):
        directory, path = self.fixture(**kwargs)
        try:
            return detect_verification_marks(path)
        finally:
            directory.cleanup()

    def test_stamp_and_signature_are_detected_together(self):
        result = self.detected(stamp="blue", signature=True)
        self.assertTrue(result["detected"])
        self.assertEqual({"stamp", "signature"}, set(result["types"]))

    def test_each_stamp_color_is_detected_without_inventing_signature(self):
        for color in ("blue", "red", "black"):
            with self.subTest(color=color):
                result = self.detected(stamp=color)
                self.assertIn("stamp", result["types"])
                self.assertNotIn("signature", result["types"])

    def test_signature_only_is_detected_in_non_fixed_lower_position(self):
        result = self.detected(signature=True)
        self.assertEqual(["signature"], result["types"])

    def test_clear_document_without_mark_returns_false(self):
        result = self.detected()
        self.assertIs(False, result["detected"])
        self.assertFalse(result["requires_manual_review"])

    def test_blurry_small_document_returns_manual_review(self):
        result = self.detected(blur=True)
        self.assertIsNone(result["detected"])
        self.assertTrue(result["requires_manual_review"])

    def test_logo_table_and_barcode_are_not_marks(self):
        for option in ({"logo": True}, {"table": True}, {"barcode": True}):
            with self.subTest(option=option):
                self.assertIs(False, self.detected(**option)["detected"])

    def test_contract_has_legacy_and_new_fields_with_stable_confidence(self):
        first = self.detected(stamp="red", signature=True)
        second = self.detected(stamp="red", signature=True)
        self.assertGreaterEqual(first["confidence"], 0)
        self.assertLessEqual(first["confidence"], 1)
        self.assertEqual(first["detected"], second["detected"])
        self.assertIn("bounding_box", first["stamp"])

    def test_multi_page_pdf_checks_mark_on_later_page(self):
        first = Image.new("RGB", (800, 1000), "white")
        second = Image.new("RGB", (800, 1000), "white")
        draw = ImageDraw.Draw(second)
        draw.ellipse((480, 650, 680, 840), outline=(20, 70, 210), width=10)
        draw.ellipse((510, 680, 650, 810), outline=(20, 70, 210), width=6)
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "multipage.pdf"
            first.save(path, save_all=True, append_images=[second], resolution=150)
            result = detect_verification_marks(path)

        self.assertTrue(result["stamp"]["detected"])
        self.assertEqual(2, result["stamp"]["page"])


if __name__ == "__main__":
    unittest.main()
