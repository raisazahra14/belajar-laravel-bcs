import unittest

from document_checker import extract_specialized_metadata

INVOICE_TEXT = """INVOICE
Kepada : SCG Barito Logistics, PT No. Invoice : 00020309/SCG-DT/VIII/2026
NPWP : 0829513688031000 No. SPK/Kontrak : -
Alamat : Wisma Barito Pacific 9th Floor Jl. Let Jend. No. PO : -
S. Parman Kav. 62-63, Jakarta 11410, Indonesia Kode Project : 2-01-002
Attn : Finance Departement
Kami sampaikan tagihan pekerjaan sebagai berikut :
No Nama Barang Jumlah Satuan Harga Satuan Jumlah
1. Invoice Number : IV2608210059 167.900 Ton 99,028 16,626,801
2. Invoice Number : IV2608240058 202.750 Ton 99,028 20,077,927
3. Invoice Number : IV2608260086 539.000 Ton 99,028 53,376,092
4. Invoice Number : IV2608270068 215.250 Ton 99,028 21,315,777
Terbilang : Seratus sebelas juta tiga ratus sembilan puluh enam ribu lima Sub Total : 111,396,597
ratus sembilan puluh tujuh rupiah,- Diskon : 0.00
Biaya Pengantaran : 0.00
Pembayaran dapat di transfer : DPP : 111,396,597
PT. Buana Centra Swakarsa PPN : 0.00
BNI Cab. Kantor Kas Serang City - IDR Total : 111,396,597
No. Rekening : 254-570-444-6 Uang Muka : 0.00
TOTAL AMOUNT : 111,396,597
Konfirmasi Pembayaran :
Email : finance@bcs-logistics.co.id
Fax : 0254-570666
WA : 0877-7174-1665 / 0812-8115-1518
Cilegon , 28 Agustus 2026
PT BUANA CENTRA SWAKARSA
Farjuni Sofiyanto
Direktur Administrasi & Keuangan
"""


class InvoiceDetailTest(unittest.TestCase):
    def setUp(self):
        self.r = extract_specialized_metadata(INVOICE_TEXT, "invoice")

    def test_items(self):
        items = self.r["items"]
        self.assertEqual(4, len(items))
        self.assertEqual("IV2608210059", items[0]["reference_number"])
        self.assertEqual(167.9, items[0]["quantity"])
        self.assertEqual(202.75, items[1]["quantity"])
        self.assertEqual(539, items[2]["quantity"])
        self.assertEqual(215.25, items[3]["quantity"])
        self.assertEqual("Ton", items[0]["unit"])
        self.assertEqual(99028, items[3]["unit_price"])
        self.assertEqual(21315777, items[3]["amount"])
        self.assertEqual([], self.r["items_unparsed"])

    def test_identity_not_confused_with_item_reference(self):
        self.assertEqual("00020309/SCG-DT/VIII/2026", self.r["invoice_number"])
        self.assertEqual("0829513688031000", self.r["npwp"])
        self.assertEqual("2024-08-28".replace("2024", "2026"), self.r["invoice_date"])

    def test_details(self):
        r = self.r
        self.assertIn("Wisma Barito Pacific", r["customer_address"])
        self.assertIn("Jakarta 11410", r["customer_address"])
        self.assertTrue(r["amount_in_words"].lower().endswith("sembilan puluh tujuh rupiah"))
        self.assertEqual("254-570-444-6", r["bank_account_number"])
        self.assertTrue(r["bank_name"].startswith("BNI"))
        self.assertEqual("finance@bcs-logistics.co.id", r["contact_email"])
        self.assertEqual("0254-570666", r["contact_fax"])
        self.assertEqual("Farjuni Sofiyanto", r["signer_name"])
        self.assertEqual("Direktur Administrasi & Keuangan", r["signer_title"])
        self.assertEqual(111396597, r["subtotal"])
        self.assertEqual(111396597, r["total_amount"])

    def test_missing_not_guessed(self):
        self.assertIsNone(self.r["contract_number"])
        self.assertIsNone(self.r["purchase_order_number"])


if __name__ == "__main__":
    unittest.main()
