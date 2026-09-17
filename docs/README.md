# Indeks Dokumentasi LogistikKu

Halaman ini adalah pintu masuk dokumentasi Tugas Bulan ke-2 Laravel LogistikKu. Gunakan urutan sumber berikut: **SRS final → ERD/Data Dictionary → Peta Konsep → aset render**. Isi unik dokumen kerja Tugas 1.1 dan 1.2 telah digabungkan ke SRS final sehingga tidak ada sumber kebutuhan yang bersaing. Perilaku aplikasi tetap mengikuti implementasi dan database aktual.

## Gambaran sistem

LogistikKu adalah aplikasi Laravel untuk inventaris, transaksi stok, import/export, OCR verifikasi dokumen, prediksi restock berbasis Python, notifikasi, dan administrasi pengguna. Fondasi data terbaru juga mencakup Supplier dan Multi-Gudang:

- `suppliers` menyimpan master pemasok; supplier utama barang dan supplier asal transaksi bersifat opsional;
- `warehouses` dan `warehouse_stocks` menyimpan saldo per pasangan barang–gudang;
- pasangan (`barang_id`, `warehouse_id`) pada `warehouse_stocks` unik;
- workflow stok saat ini memakai `GDG-UTAMA` dan menjaga `warehouse_stocks.stok` tetap sinkron dengan `barang.stok` sebagai stok total/legacy;
- UI master supplier, pemilihan gudang transaksi, dan transfer antargudang belum termasuk implementasi saat ini.

## Dokumen utama

| Dokumen | Fungsi | Status/cakupan |
|---|---|---|
| [SRS final (`srs.md`)](./srs.md) | **Sumber SRS final** dan acuan kebutuhan *as-built* terkini. | Versi 1.2; dikonsolidasikan sampai Supplier, Multi-Gudang, ERD, dan Data Dictionary. |
| [Publikasi SRS resmi (PDF)](./SRS_Sistem_Inventaris_LogistikKu.pdf) | **Publikasi resmi** SRS yang mudah dibaca/dikirim tanpa renderer Markdown. | Snapshot versi 1.1, 17 halaman, diterbitkan 15 September 2026 sebelum implementasi Supplier/Multi-Gudang. Gunakan `srs.md` untuk revisi final terbaru. |

PDF tidak ditimpa agar bukti publikasi tetap utuh. Perbedaan tanggal dan cakupan PDF terhadap sumber Markdown adalah riwayat revisi, bukan dua spesifikasi yang sama-sama mutakhir.

## Dokumen ringkasan visual

| Dokumen | Fungsi | Kedudukan |
|---|---|---|
| [Peta Konsep LogistikKu](./PETA_KONSEP_LOGISTIKKU.md) | Empat visual ringkas yang tidak diduplikasi SRS: arsitektur umum, alur stok ringkas, sequence OCR Laravel–Python, dan alur prediksi. | Dokumen orientasi cepat, bukan sumber kebutuhan normatif; telah diselaraskan dengan Supplier/Multi-Gudang. |

Source Mermaid formal Use Case, Activity stok, dan Activity OCR kini berada langsung di [bagian diagram SRS](./srs.md#10-use-case-dan-activity-diagram). Bukti benchmark, kontrak upload, kompatibilitas status OCR, aturan bisnis, kriteria penerimaan, dan ketertelusuran Tugas 1.1 juga telah dikonsolidasikan ke SRS.

## Dokumentasi database

| Dokumen | Fungsi | Cakupan |
|---|---|---|
| [ERD Database](./database/erd.md) | **Visual relasi** dan kardinalitas database, fungsi tabel, aturan FK, serta audit migration/model. | Menampilkan **14 tabel bisnis/aplikasi** agar diagram tetap terbaca. Delapan tabel framework/internal tetap diinventarisasi, tetapi tidak digambar. |
| [Data Dictionary](./data_dictionary.md) | **Detail struktur 22 tabel aktual**, termasuk semua kolom, tipe, nullable, default, PK, FK, unique/index, referential action, AI, soft delete, dan aturan bisnis. | **14 tabel bisnis/aplikasi + 8 tabel framework/internal = 22 tabel dan 201 kolom**. |

Kedua dokumen database konsisten pada **20 FK aktual**: 9 `ON DELETE CASCADE`, 7 `SET NULL`, 4 `RESTRICT`, dan seluruhnya `ON UPDATE RESTRICT`. Keduanya juga membedakan FK database dari referensi logis seperti `stock_prediction_processes.source_transaction_id`.

Catatan perbedaan migration/model/database—antara lain unique receipt yang tidak ada di database aktual, perilaku fisik `stock_predictions.analyzed_at`, alias JSON MariaDB, dan kardinalitas process prediksi—sengaja tetap dicatat sebagai temuan teknis. Tugas dokumentasi ini tidak mengubah migration, model, atau database untuk menutup perbedaan tersebut.

## Aset diagram/gambar

### Aset yang dirujuk aktif

| Aset | Digunakan oleh |
|---|---|
| [Use Case (SVG)](./images/tugas-1-2-use-case.svg) | Fallback render untuk source Mermaid di `srs.md`. |
| [Activity stok (SVG)](./images/tugas-1-2-activity-stok.svg) | Fallback render baseline untuk source Mermaid stok di `srs.md`. |
| [Activity verifikasi (SVG)](./images/tugas-1-2-activity-verifikasi.svg) | Fallback render untuk source Mermaid OCR di `srs.md`. |

Lima PNG infografik lama dihapus setelah seluruh informasi pentingnya diverifikasi tersedia pada SRS, Peta Konsep, atau tiga SVG di atas. Folder `docs/diagrams/` juga dihapus karena kosong dan tidak direferensikan.

## Status Tugas 1.1–2.3

| Tugas | Hasil | Status | Bukti utama |
|---|---|---|---|
| 1.1 | Pemetaan kebutuhan SRS | Selesai; isi unik dikonsolidasikan ke SRS final | [Kebutuhan SRS](./srs.md#4-kebutuhan-fungsional), [NFR](./srs.md#5-kebutuhan-nonfungsional) |
| 1.2 | Diagram alur proses bisnis | Selesai; source Mermaid digabungkan dan tiga render SVG dipertahankan | [Diagram SRS](./srs.md#10-use-case-dan-activity-diagram) |
| 1.3 | Konsolidasi/publikasi SRS | Selesai; sumber final dan snapshot PDF resmi tersedia | [SRS final](./srs.md), [PDF resmi](./SRS_Sistem_Inventaris_LogistikKu.pdf) |
| 2.1 | Peta konsep sistem | Selesai; alur modul dan integrasi dirangkum | [Peta Konsep](./PETA_KONSEP_LOGISTIKKU.md) |
| 2.2 | ERD database | Selesai; 14 tabel bisnis divisualisasikan dan 8 tabel internal diinventarisasi | [ERD](./database/erd.md) |
| 2.3 | Data Dictionary | Selesai; 22 tabel/201 kolom didokumentasikan dari database aktual | [Data Dictionary](./data_dictionary.md) |

## Cara membuka preview Markdown

### Visual Studio Code

1. Buka file `.md` yang diinginkan.
2. Tekan `Ctrl+Shift+V` untuk membuka Markdown Preview, atau `Ctrl+K` lalu `V` untuk preview di sisi editor.
3. Gunakan versi VS Code yang mendukung Mermaid untuk merender blok `mermaid`. Jika Mermaid tidak dirender, gunakan lampiran SVG pada dokumen Tugas 1.2.

### GitHub atau renderer lain

- Buka folder `docs/` pada GitHub; tautan relatif, gambar, tabel, dan Mermaid dapat dirender langsung oleh antarmuka yang mendukungnya.
- Jangan membuka Markdown hanya sebagai file HTML mentah karena tautan relatif dan Mermaid dapat tidak diproses.
- Buka [PDF resmi](./SRS_Sistem_Inventaris_LogistikKu.pdf) dengan pembaca PDF biasa; PDF tidak memerlukan preview Markdown.

## Hasil audit navigasi

- Seluruh tautan relatif yang sudah ada sebelum indeks dibuat berhasil di-resolve.
- Tidak ditemukan lompatan level heading, fence Mermaid yang tidak tertutup, conflict marker, atau trailing whitespace.
- `node_modules/` tercantum di `.gitignore` dan tidak dilacak Git; folder lokal tidak dihapus.
- Dokumen kerja yang berulang dan aset yang tidak direferensikan telah dibersihkan setelah isi uniknya dikonsolidasikan; tidak ada file yang dipindahkan atau diganti nama.
