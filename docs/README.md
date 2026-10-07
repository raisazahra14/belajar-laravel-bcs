# Indeks Dokumentasi LogistikKu

Folder ini berisi dokumentasi teknis dan fungsional LogistikKu. Seluruh dokumen aktif telah diselaraskan dengan kode dan seluruh migration hingga **5 Oktober 2026**.

Urutan membaca yang disarankan:

1. [README utama](../README.md) untuk instalasi dan cara menjalankan aplikasi.
2. [SRS](./srs.md) untuk kebutuhan, aktor, fitur, aturan bisnis, dan kriteria penerimaan.
3. [Peta Konsep](./PETA_KONSEP_LOGISTIKKU.md) untuk memahami aliran antarmodul.
4. [ERD](./database/erd.md) dan [Data Dictionary](./data_dictionary.md) untuk struktur database.

## Gambaran aplikasi

LogistikKu adalah aplikasi inventaris berbasis Laravel dengan bantuan Python untuk OCR dan prediksi stok. Aplikasi menyediakan:

- login session, pembatasan percobaan login, logout, dan role Admin/Manager/Staff;
- master barang, supplier, gudang, pengguna, foto, harga beli, dan parameter prediksi;
- saldo per gudang serta total stok barang;
- stok masuk/keluar, supplier transaksi, referensi dokumen, biaya unit, pelaku transaksi, dan histori;
- transfer stok antargudang dan reversal transaksi dengan jejak audit;
- pencarian, filter, dashboard, stok menipis, analitik, valuasi, dan rekonsiliasi;
- laporan mutasi dan export CSV/XLSX/PDF;
- import barang XLSX/XLS/CSV secara atomik;
- soft delete, restore, hapus permanen aman, dan aksi massal;
- verifikasi Surat Jalan, Invoice, serta Bukti Fisik melalui OCR Python/Tesseract;
- prediksi restock melalui queue dengan metode cold-start, rata-rata, atau machine learning;
- pusat notifikasi terpadu untuk aktivitas yang memerlukan perhatian.

## Dokumen aktif

| Dokumen | Kegunaan | Status |
|---|---|---|
| [SRS Markdown](./srs.md) | Spesifikasi fitur, hak akses, aturan bisnis, NFR, diagram, dan pengujian penerimaan. | Versi 1.4, baseline aktif. |
| [SRS PDF](./SRS_Sistem_Inventaris_LogistikKu.pdf) | Versi SRS yang siap dibaca atau dibagikan. | Dibangkitkan dari SRS versi 1.4. |
| [Peta Konsep](./PETA_KONSEP_LOGISTIKKU.md) | Ringkasan cara kerja setiap modul dan hubungan Laravel, database, queue, storage, dan Python. | Aktif. |
| [ERD](./database/erd.md) | Diagram relasi tabel bisnis, fungsi tabel, FK, dan integritas data. | 15 tabel bisnis/aplikasi. |
| [Data Dictionary](./data_dictionary.md) | Definisi seluruh tabel, kolom, kunci, indeks, dan aturan data. | 23 tabel, 218 kolom, 24 FK. |

## Peta fitur dan dokumentasinya

| Area | Fitur yang tersedia | Dokumen utama |
|---|---|---|
| Akses | Login, remember-me, rate limit, session, logout, tiga role | [SRS 4.1](./srs.md#41-autentikasi-dan-pengguna) |
| Barang | CRUD, foto, kode otomatis, filter, stok menipis, trash | [SRS 4.2](./srs.md#42-master-dan-inventaris) |
| Supplier | Daftar/detail semua role, CRUD Admin, supplier utama dan snapshot transaksi | [Peta Konsep 3](./PETA_KONSEP_LOGISTIKKU.md#3-master-barang-supplier-dan-gudang) |
| Gudang | Gudang Utama/A/B/C, saldo per gudang, CRUD Admin | [ERD](./database/erd.md) |
| Stok | Masuk/keluar, transfer, reversal, histori, audit pelaku dan referensi | [SRS 4.3](./srs.md#43-transaksi-transfer-reversal-dan-riwayat-stok) |
| Import/export | Import atomik XLSX/XLS/CSV dan export inventaris CSV/XLSX/PDF | [SRS 4.4](./srs.md#44-import-dan-export) |
| Laporan | Mutasi terfilter dan export CSV/XLSX/PDF | [SRS 4.5](./srs.md#45-dashboard-analitik-laporan-dan-notifikasi) |
| Analitik | Fast/Slow/Dead Stock, valuasi, tren, rekonsiliasi, ringkasan otomatis | [SRS 4.5](./srs.md#45-dashboard-analitik-laporan-dan-notifikasi) |
| OCR | Upload privat, queue, OCR, metadata, skor, audit, retry/reprocess | [SRS 4.6](./srs.md#46-verifikasi-dokumen-ocr) |
| Prediksi | Analisis satu/semua barang, fallback, proses, rekomendasi, notifikasi | [SRS 4.7](./srs.md#47-prediksi-stok) |

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

## Dokumentasi database

Migration bersih membentuk:

- **23 tabel dan 218 kolom**;
- **15 tabel bisnis/aplikasi** dengan 176 kolom;
- **8 tabel framework/internal** dengan 42 kolom;
- **24 foreign key**: 10 `CASCADE`, 10 `SET NULL`, dan 4 `RESTRICT` pada penghapusan;
- saldo unik per pasangan barang-gudang;
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

Source diagram formal berada dalam blok Mermaid pada [SRS](./srs.md) dan [ERD](./database/erd.md). SVG disediakan sebagai fallback untuk renderer yang tidak mendukung Mermaid.

## Cara melihat dokumentasi

Pada Visual Studio Code, buka file Markdown lalu tekan `Ctrl+Shift+V`. Pada GitHub, tabel, tautan relatif, gambar, dan Mermaid dapat ditampilkan langsung. Gunakan PDF jika dokumentasi perlu dibaca tanpa renderer Markdown.

## Memperbarui PDF SRS

Builder berada di [`docs/tools/build_srs_pdf.py`](./tools/build_srs_pdf.py). Builder membutuhkan Python, Mistune, PyMuPDF, dan Chrome/Edge headless yang sudah tersedia pada mesin.

```powershell
python docs/tools/build_srs_pdf.py --output storage/SRS_LogistikKu_v1.4.candidate.pdf
```

Periksa versi, jumlah halaman, tabel, diagram, tautan, dan keterbacaan kandidat sebelum mengganti PDF aktif. Builder tidak menimpa file output yang sudah ada.

## Arsip

[`archive/SRS_Sistem_Inventaris_LogistikKu_v1.1.pdf`](./archive/SRS_Sistem_Inventaris_LogistikKu_v1.1.pdf) adalah rekaman historis sebelum supplier, multi-gudang, analitik, transfer, dan audit transaksi diselesaikan. File arsip tidak menggambarkan aplikasi saat ini dan sengaja tidak diperbarui.

## Prinsip pemeliharaan dokumentasi

- Kode, migration, dan test adalah bukti perilaku aktual.
- Perubahan fitur harus memperbarui SRS, Peta Konsep, ERD/Data Dictionary bila relevan, serta README utama.
- Jangan menyatakan fitur tersedia jika route, otorisasi, proses, dan penyimpanannya belum dapat dibuktikan.
- Jangan memasukkan `.env`, password produksi, dokumen pengguna, database, atau log ke dokumentasi.
