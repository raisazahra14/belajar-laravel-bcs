# Indeks Dokumentasi LogistikKu

Folder ini berisi dokumentasi teknis dan fungsional LogistikKu. Seluruh dokumen aktif telah diselaraskan dengan kode dan seluruh 41 migration hingga **23 November 2026 (Penyelesaian Periode 4 & Hardening Fase 4–6: RESTful API v1 Paritas Penuh 21 Operasi, OpenAPI 3.0.3 & Swagger UI `/docs/api`, Lokasi Rak & Stok Minimum Dinamis per Gudang, Idempotensi & Jejak Audit Reversal GRN, Observabilitas Heartbeat Worker/Scheduler & Konsistensi Saldo Database, serta Deprekasi Rute Legacy `/api/*`)**.

Urutan membaca yang disarankan:

1. [README utama](../README.md) untuk instalasi dan cara menjalankan aplikasi.
2. [SRS](./srs.md) untuk kebutuhan, aktor, fitur, aturan bisnis, dan kriteria penerimaan.
3. [Peta Konsep](./PETA_KONSEP_LOGISTIKKU.md) untuk memahami aliran antarmodul.
4. [ERD](./database/erd.md) dan [Data Dictionary](./data_dictionary.md) untuk struktur database.

## Gambaran aplikasi

LogistikKu adalah aplikasi inventaris berbasis Laravel dengan bantuan Python untuk OCR dan prediksi stok. Aplikasi menyediakan:

- login session, pembatasan percobaan login, logout, dan role Admin/Manager/Staff;
- master barang, supplier, gudang, pengguna, foto, harga beli, parameter prediksi, dan panel visualisasi distribusi stok per gudang;
- saldo per gudang (`warehouse_stocks`) lengkap dengan metadata lokasi fisik rak (`zone`, `rack_location`, `bin_code`) dan konfigurasi `stok_minimum` per gudang (`PATCH /barang/{barang}/warehouses/{warehouse}/settings`), serta total stok barang (`barang.stok`);
- stok masuk/keluar, supplier transaksi, referensi dokumen, biaya unit, pelaku transaksi, dan histori;
- transfer stok antargudang dan reversal transaksi dengan jejak audit (termasuk *soft delete* & rekonsiliasi mundur item GRN/PO secara atomik);
- pencarian, filter, dashboard, stok menipis, analitik inventaris, analitik pengadaan & utilisasi anggaran (PO vs Invoice berbasis agregasi SQL & versioned cache), valuasi, dan rekonsiliasi;
- laporan mutasi dan export CSV/XLSX/PDF dengan sanitasi formula spreadsheet terpusat (`SpreadsheetSanitizer`);
- import barang XLSX/XLS/CSV secara atomik;
- soft delete, restore, hapus permanen aman, dan aksi massal;
- verifikasi Surat Jalan, Invoice, serta Bukti Fisik melalui OCR Python/Tesseract dengan antarmuka *Dual-Pane AI Review Workspace* dan *Self-Healing OCR*;
- prediksi restock melalui queue dengan metode cold-start, rata-rata, atau machine learning yang memperhitungkan mutasi operasional efektif (`effectiveOperational()`), stok pesanan terbuka (`incoming_po_stock`), ambang minimum efektif lintas gudang (`effectiveMinimumStock()`), dan terintegrasi dengan **Smart Restock AI berbasis evaluasi SLA Vendor (`1-Click Create PO`)**;
- modul pengadaan lengkap: siklus Purchase Order (PO), persetujuan *Maker-Checker*, Goods Receipt Note (GRN) dengan **Pemindai Barcode / QR Code (`BRG-######`)** & idempotensi `receipt_token`, *Three-Way Matching*, dan **Vendor Performance & SLA Governance** (ekspor Excel, CSV, PDF);
- integrasi **RESTful API v1 (`/api/v1/*`)** berotentikasi Laravel Sanctum dengan *scoped token abilities*, kontrak JSON seragam (`ApiResponses`), spesifikasi **OpenAPI 3.0.3** & portal interaktif **Swagger UI (`/docs/api`)**, serta header deprekasi standar (`Deprecation`, `Sunset`, `Link`) pada rute legacy `/api/*`;
- dasbor **System Health & Observability (`/system-health`)** lintas platform yang memantau database, konsistensi saldo master vs gudang, heartbeat scheduler & queue worker, runtime Python/Tesseract, dan storage;
- pusat notifikasi terpadu untuk aktivitas yang memerlukan perhatian.

## Dokumen aktif

| Dokumen | Kegunaan | Status |
|---|---|---|
| [SRS Markdown](./srs.md) | Spesifikasi fitur, hak akses, aturan bisnis, NFR, diagram, dan pengujian penerimaan. | Versi 1.6, baseline aktif (23 Nov 2026). |
| [SRS PDF](./SRS_Sistem_Inventaris_LogistikKu.pdf) | Versi SRS yang siap dibaca atau dibagikan. | Dibangkitkan dari SRS baseline. |
| [Peta Konsep](./PETA_KONSEP_LOGISTIKKU.md) | Ringkasan cara kerja setiap modul dan hubungan Laravel, database, queue, storage, REST API v1, dan Python. | Aktif (diperbarui 23 Nov 2026). |
| [ERD](./database/erd.md) | Diagram relasi tabel bisnis, fungsi tabel, FK, dan integritas data. | 20 tabel bisnis/aplikasi + Sanctum. |
| [Data Dictionary](./data_dictionary.md) | Definisi seluruh tabel, kolom, kunci, indeks, dan aturan data. | 29 tabel, 286 kolom, 40 FK (41 migrasi). |
| [Arsitektur Modul Purchase Order](./arsitektur_modul_purchase_order.md) | Alur bisnis, status, matriks transisi, hak akses, skema database, GRN + Barcode Scanner + `receipt_token`, Three-Way Matching, Smart Restock SLA, dan Procurement Analytics. | Modul Pengadaan & GRN (v1.8.0). |
| [Arsitektur RESTful API v1](./arsitektur_rest_api.md) | Konvensi endpoint `/api/v1`, standar HTTP status code, Unified JSON Envelope (`ApiResponses`), Global Exception Handler, peta 20 rute / 21 operasi aktif `/api/v1/*`, matriks Sanctum Token Abilities, dan kebijakan deprekasi `/api/*`. | Periode 4 & Fase 6, aktif. |
| [Spesifikasi OpenAPI 3.0.3 (Swagger)](./openapi.yaml) | Spesifikasi resmi machine-readable seluruh 20 path / 21 operasi `/api/v1`, parameter query/path/body, skema `JsonResource`, BearerAuth, dan contoh respon `200`/`201`/`400`/`401`/`403`/`404`/`409`/`422`/`429`. | Periode 4 & Fase 6, aktif & tervalidasi. |
| [Panduan Integrasi Pengembang API](./api_integration_guide.md) | Panduan langkah demi langkah memperoleh Sanctum Bearer Token, *scoped abilities*, siklus penuh PO & reversal stok via API v1, migrasi dari rute legacy (`Deprecation`/`Sunset`), serta contoh kode cURL, Python (`requests`), dan JavaScript (`fetch`/`axios`). | Periode 4 & Fase 6, aktif. |
| [Laporan Kegiatan Periode 4 MagangHub](./LAPORAN_KEGIATAN_PERIODE_4_MAGANGHUB.md) | Landasan teori RESTful API (Materi 1), realisasi endpoint internal Minggu 2, keamanan Sanctum/OWASP & Postman Collection Minggu 3, dokumentasi OpenAPI 3.0.3 & portal `/docs/api` Minggu 4, serta penguatan paritas penuh Fase 4–6. | Periode 4 (Minggu 1–4 selesai 100%). |
| [Portofolio Penutupan Magang](./PORTOFOLIO_PENUTUPAN_MAGANG.md) | Rekapitulasi komprehensif pencapaian rekayasa perangkat lunak Periode 1–4, milestone, metrik pengujian regresi (570+ test PHPUnit & 59 test Python), dan kontribusi bisnis. | Laporan Akhir Magang. |
| [Cheat-Sheet Engineering LogistikKu](./CHEAT_SHEET_ENGINEERING_LOGISTIKKU.md) | Panduan pola desain State Machine, Anti N+1 Query $O(1)$, jembatan subprocess Python, konkurensi `DatabaseConcurrency`, sanitasi spreadsheet `SpreadsheetSanitizer`, dan optimasi agregasi SQL. | Referensi Teknis Tim. |

## Peta fitur dan dokumentasinya

| Area | Fitur yang tersedia | Dokumen utama |
|---|---|---|
| Akses | Login, remember-me, rate limit, session, logout, tiga role | [SRS 4.1](./srs.md#41-autentikasi-dan-pengguna) |
| Barang | CRUD, foto, kode otomatis, filter, distribusi stok per gudang, stok menipis, trash | [SRS 4.2](./srs.md#42-master-dan-inventaris) |
| Supplier | Daftar/detail semua role, CRUD Admin, Vendor Performance & SLA Governance + ekspor Excel/CSV/PDF | [Peta Konsep 3](./PETA_KONSEP_LOGISTIKKU.md#3-master-barang-supplier-dan-gudang) |
| Gudang | Gudang Utama/A/B/C, saldo per gudang, lokasi rak (`zone`/`rack_location`/`bin_code`), stok minimum per gudang, CRUD Admin | [ERD](./database/erd.md) |
| Stok | Masuk/keluar, transfer, reversal (web & API v1), histori, audit pelaku dan referensi | [SRS 4.3](./srs.md#43-transaksi-transfer-reversal-dan-riwayat-stok) |
| Import/export | Import atomik XLSX/XLS/CSV dan export inventaris CSV/XLSX/PDF (dilindungi `SpreadsheetSanitizer`) | [SRS 4.4](./srs.md#44-import-dan-export) |
| Laporan | Mutasi terfilter dan export CSV/XLSX/PDF | [SRS 4.5](./srs.md#45-dashboard-analitik-laporan-dan-notifikasi) |
| Analitik | Tab Inventaris (Fast/Slow/Dead Stock, valuasi, rekonsiliasi) & Tab Pengadaan (`?tab=procurement`: Tren PO Bulanan/Kuartalan, Anggaran PO vs Invoice, Distribusi Gudang/Kategori berbasis SQL `GROUP BY`) | [SRS 4.5](./srs.md#45-dashboard-analitik-laporan-dan-notifikasi) |
| OCR | Upload privat, Self-Healing OCR, Dual-Pane AI Review Workspace, metadata, skor, audit, retry/reprocess | [SRS 4.6](./srs.md#46-verifikasi-dokumen-ocr) |
| Prediksi | Analisis satu/semua barang (`effectiveOperational` + `incoming_po_stock` + `effectiveMinimumStock`), fallback, proses, Smart Restock AI + Best Vendor SLA (`1-Click Create PO`), notifikasi | [SRS 4.7](./srs.md#47-prediksi-stok--smart-restock-sla) |
| Pengadaan | Siklus Purchase Order (PO), approval Maker-Checker, Goods Receipt Note (GRN) + Pemindai Barcode/QR (`BRG-######`) + `receipt_token`, Three-Way Matching OCR, Vendor Performance & SLA Dashboard, dan cetak PDF resmi | [Arsitektur Modul Purchase Order](./arsitektur_modul_purchase_order.md) |
| RESTful API v1 | 20 path / 21 operasi (`/api/v1/*`: Status, Auth Sanctum, Barang & Cek Stok, Adjust/Transfer/Reverse Stok, Siklus Penuh PO & GRN Barcode), OpenAPI 3.0.3, Swagger UI `/docs/api`, dan deprekasi `/api/*` | [Arsitektur RESTful API v1](./arsitektur_rest_api.md) |
| System Health | Dasbor kesehatan infrastruktur `/system-health` (Database + Konsistensi Saldo Master vs Gudang, Heartbeat Queue Worker & Scheduler, Python/Tesseract OCR, Storage) | [SRS 5](./srs.md#5-kebutuhan-nonfungsional) |

## Hak akses ringkas

| Kemampuan | Admin | Manager | Staff |
|---|:---:|:---:|:---:|
| Melihat inventaris, supplier, gudang, histori, prediksi tersimpan | Ya | Ya | Ya |
| Stok masuk/keluar dan transfer gudang | Ya | Ya | Ya |
| Reversal dan laporan mutasi | Ya | Ya | Tidak |
| Menjalankan prediksi dan menerapkan rekomendasi | Ya | Ya | Tidak |
| Upload dan melihat dokumen sendiri | Ya | Ya | Ya |
| Melihat seluruh dokumen | Ya | Tidak | Tidak |
| CRUD master, import/export, analitik, trash, pengguna | Ya | Tidak | Tidak |
| Membuat dan mengajukan Purchase Order | Ya | Tidak | Ya (pembuat) |
| Menyetujui/menolak Purchase Order (Maker-Checker) | Ya* | Ya* | Tidak |
| Mencatat penerimaan fisik barang (Goods Receipt / GRN) | Ya | Tidak | Ya |
| Three-Way Matching (PO/GRN/OCR) & Vendor Performance | Ya | Ya | Ya |

`*` Catatan Maker-Checker: Admin dan Manager hanya boleh menyetujui/menolak PO yang dibuat oleh pengguna lain.

## Dokumentasi database

Seluruh **41 migration** membentuk:

- **29 tabel dan 286 kolom**;
- **20 tabel bisnis/aplikasi** dengan 238 kolom (termasuk lokasi rak `warehouse_stocks` serta `receipt_token` & kolom audit reversal/`SoftDeletes` pada `goods_receipts` dan `goods_receipt_items`);
- **9 tabel framework/internal** dengan 48 kolom (termasuk `personal_access_tokens` Laravel Sanctum);
- **40 foreign key**: 15 `CASCADE`, 14 `SET NULL`, dan 11 `RESTRICT` pada penghapusan;
- saldo unik per pasangan barang-gudang dan token unik penerimaan `uq_grn_receipt_token`;
- satu record pelaku per transaksi melalui `stok_transaction_actors`;
- relasi reversal transaksi, pengelompok transfer, snapshot biaya, dan referensi dokumen.

Skema tersebut diverifikasi dengan menjalankan seluruh migration pada SQLite kosong. Perbedaan engine, collation, perilaku `CHECK`, JSON, dan timestamp pada database MySQL/MariaDB produksi tetap harus diuji di lingkungan target.

## Diagram aktif

| Aset | Isi |
|---|---|
| [Use Case](./images/tugas-1-2-use-case.svg) | Aktor dan fitur utama berdasarkan role. |
| [Activity Stok](./images/tugas-1-2-activity-stok.svg) | Stok masuk/keluar, transfer, reversal, dan pencatatan audit. |
| [Activity Verifikasi](./images/tugas-1-2-activity-verifikasi.svg) | Upload, queue, Python/Tesseract, hasil, dan notifikasi. |
| [ERD Database](./images/database-erd.svg) | Relasi inti seluruh tabel bisnis. |
| [Alur RESTful API v1](./images/periode-4-restful-api-flow.png) | Alur kerja integrasi `/api/v1` dari klien eksternal, gerbang keamanan, endpoint inti, hingga dokumentasi OpenAPI/Postman. |
| [Infografis Teori RESTful API Minggu 1](./images/teori-arsitektur-restful-api-minggu-1.png) | Rangkuman visual 4 pilar Materi 1: Stateless & RMM Level 2–3, Semantik HTTP (RFC 9110), Unified JSON Contract, dan Exception Gate & RBAC. |
| [Animasi Alur Endpoint API Minggu 2](./images/alur-endpoint-api-minggu-2.gif) | Animasi alur kerja 4 tugas Minggu 2 (`/api/v1`): Cek Stok Real-Time POS, Transfer Stok Atomik, Barcode GRN PO, dan JsonResource + HATEOAS. |
| [Animasi Alur Keamanan & Postman Minggu 3](./images/alur-keamanan-postman-minggu-3.gif) | Animasi alur kerja 4 pilar & inovasi Minggu 3 (`/api/v1`): Scoped Sanctum Token Abilities (`abilities[]`), OWASP Security Headers (`nosniff`, `DENY`), Rate Limiting (`60/m`) + CORS + Data Masking, serta Postman Folder 06 OWASP & `ApiEndpointsSecurityTest`. |
| [Animasi Problem Solving Inovasi Minggu 3](./images/inovasi-problem-solving-minggu-3.gif) | Animasi alur Masalah (Before) -> Mekanisme & Tech Stack -> Solusi & Kelebihan (After) untuk 4 inovasi Minggu 3 (Scoped Token, Idempotency Guard 409, OWASP Headers, & Postman Folder 06 Attack QA). |

Source diagram formal berada dalam blok Mermaid pada [SRS](./srs.md) dan [ERD](./database/erd.md). SVG disediakan sebagai fallback untuk renderer yang tidak mendukung Mermaid.

## Cara melihat dokumentasi

Pada Visual Studio Code, buka file Markdown lalu tekan `Ctrl+Shift+V`. Pada GitHub, tabel, tautan relatif, gambar, dan Mermaid dapat ditampilkan langsung. Gunakan PDF jika dokumentasi perlu dibaca tanpa renderer Markdown.

## Memperbarui PDF SRS

Builder berada di [`docs/tools/build_srs_pdf.py`](./tools/build_srs_pdf.py). Builder membutuhkan Python, Mistune, PyMuPDF, dan Chrome/Edge headless yang sudah tersedia pada mesin.

```powershell
python docs/tools/build_srs_pdf.py --output storage/SRS_LogistikKu_v1.5.candidate.pdf
```

Periksa versi, jumlah halaman, tabel, diagram, tautan, dan keterbacaan kandidat sebelum mengganti PDF aktif. Builder tidak menimpa file output yang sudah ada.

## Arsip

[`archive/SRS_Sistem_Inventaris_LogistikKu_v1.1.pdf`](./archive/SRS_Sistem_Inventaris_LogistikKu_v1.1.pdf) adalah rekaman historis sebelum supplier, multi-gudang, analitik, transfer, dan audit transaksi diselesaikan. File arsip tidak menggambarkan aplikasi saat ini dan sengaja tidak diperbarui.

## Prinsip pemeliharaan dokumentasi

- Kode, migration, dan test adalah bukti perilaku aktual.
- Perubahan fitur harus memperbarui SRS, Peta Konsep, ERD/Data Dictionary bila relevan, serta README utama.
- Jangan menyatakan fitur tersedia jika route, otorisasi, proses, dan penyimpanannya belum dapat dibuktikan.
- Jangan memasukkan `.env`, password produksi, dokumen pengguna, database, atau log ke dokumentasi.
