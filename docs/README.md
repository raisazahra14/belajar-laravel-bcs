# Indeks Dokumentasi LogistikKu

Halaman ini adalah pintu masuk dokumentasi Tugas Bulan ke-2 Laravel LogistikKu. Gunakan urutan sumber berikut: **SRS final → ERD/Data Dictionary → Peta Konsep → aset render**. Isi unik dokumen kerja Tugas 1.1 dan 1.2 telah digabungkan ke SRS final sehingga tidak ada sumber kebutuhan yang bersaing. Perilaku aplikasi tetap mengikuti implementasi dan source migration; kondisi database deployment harus diverifikasi tersendiri.

## Gambaran sistem

LogistikKu adalah aplikasi Laravel untuk inventaris, transaksi stok, import/export, OCR verifikasi dokumen, prediksi restock berbasis Python, notifikasi, dan administrasi pengguna. Fondasi data terbaru juga mencakup Supplier dan Multi-Gudang:

- `suppliers` menyimpan master pemasok; supplier utama barang dan supplier asal transaksi bersifat opsional;
- `warehouses` dan `warehouse_stocks` menyimpan saldo per pasangan barang–gudang;
- pasangan (`barang_id`, `warehouse_id`) pada `warehouse_stocks` unik;
- workflow stok saat ini memakai `GDG-UTAMA` dan menjaga `warehouse_stocks.stok` tetap sinkron dengan `barang.stok` sebagai stok total/legacy;
- UI daftar/detail dan CRUD master supplier/gudang tersedia (mutasi hanya Admin); pemilihan supplier pada barang/transaksi, pemilihan gudang transaksi, dan transfer antargudang belum tersedia.

## Dokumen utama

| Dokumen | Fungsi | Status/cakupan |
|---|---|---|
| [SRS final (`srs.md`)](./srs.md) | **Sumber SRS final** dan acuan kebutuhan *as-built* terkini. | Versi 1.3; diaudit ulang terhadap repository dan migration bersih pada 21 September 2026. |
| [Publikasi SRS resmi (PDF)](./SRS_Sistem_Inventaris_LogistikKu.pdf) | **Publikasi resmi** SRS yang mudah dibaca/dikirim tanpa renderer Markdown. | Versi 1.3, 25 halaman, disinkronkan dari `srs.md` dan diperiksa pada 21 September 2026. |
| [Arsip PDF versi 1.1](./archive/SRS_Sistem_Inventaris_LogistikKu_v1.1.pdf) | Rekaman publikasi sebelum Supplier/Multi-Gudang. | Arsip 17 halaman bertanggal 15 September 2026; bukan spesifikasi aktif. |

Sumber normatif aktif adalah `srs.md` versi 1.3 dan PDF resmi versi 1.3. PDF versi 1.1 dipindahkan ke folder `archive/` agar riwayat publikasi tetap dapat ditelusuri dan tidak disalahartikan sebagai versi aktif.

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

Kedua dokumen database konsisten pada **20 FK hasil migration bersih**: 9 `ON DELETE CASCADE`, 7 `SET NULL`, 4 `RESTRICT`, dan seluruhnya `ON UPDATE RESTRICT`. Keduanya juga membedakan FK database dari referensi logis seperti `stock_prediction_processes.source_transaction_id`.

Migration bersih membentuk unique receipt prediksi/pengguna dan index yang dideklarasikan source. Kardinalitas process prediksi serta referensi logis tetap dicatat sebagai temuan. Database deployment MySQL/MariaDB tidak dapat dihubungi saat audit, sehingga drift, engine/collation, representasi JSON/TIMESTAMP, dan isi backfill berstatus **Perlu Uji Produksi**.

## Aset diagram/gambar

### Aset yang dirujuk aktif

| Aset | Digunakan oleh |
|---|---|
| [Use Case (SVG)](./images/tugas-1-2-use-case.svg) | Fallback render untuk source Mermaid di `srs.md`. |
| [Activity stok (SVG)](./images/tugas-1-2-activity-stok.svg) | Fallback render baseline untuk source Mermaid stok di `srs.md`. |
| [Activity verifikasi (SVG)](./images/tugas-1-2-activity-verifikasi.svg) | Fallback render untuk source Mermaid OCR di `srs.md`. |
| [ERD Database (SVG)](./images/database-erd.svg) | Render resmi source Mermaid di `database/erd.md`. |

Lima PNG infografik lama dihapus setelah seluruh informasi pentingnya diverifikasi tersedia pada SRS, Peta Konsep, atau tiga SVG di atas. Folder `docs/diagrams/` juga dihapus karena kosong dan tidak direferensikan.

## Status Tugas 1.1–2.3

| Tugas | Hasil | Status | Bukti utama |
|---|---|---|---|
| 1.1 | Kebutuhan sistem | Terverifikasi lokal; NFR produksi tetap dibatasi | [Kebutuhan SRS](./srs.md#4-kebutuhan-fungsional), [NFR](./srs.md#5-kebutuhan-nonfungsional) |
| 1.2 | Diagram proses | Terverifikasi lokal setelah source Mermaid dan render diperiksa | [Diagram SRS](./srs.md#10-use-case-dan-activity-diagram) |
| 1.3 | Publikasi SRS | Terverifikasi lokal: Markdown dan PDF resmi sama-sama versi 1.3; PDF diperiksa secara programatik dan visual | [SRS final](./srs.md), [PDF resmi](./SRS_Sistem_Inventaris_LogistikKu.pdf) |
| 2.1 | ERD | Terverifikasi terhadap source migration dan migration bersih; deployment perlu uji | [ERD](./database/erd.md) |
| 2.2 | Multi-Gudang dan Supplier | Sebagian: master/relasi/gudang utama tersedia; transaksi lintas gudang belum tersedia | [SRS](./srs.md#42-transaksi-dan-riwayat-stok), [ERD](./database/erd.md) |
| 2.3 | Data Dictionary | Terverifikasi untuk 22 tabel/201 kolom hasil migration bersih | [Data Dictionary](./data_dictionary.md) |

## Cara membuka preview Markdown

### Visual Studio Code

1. Buka file `.md` yang diinginkan.
2. Tekan `Ctrl+Shift+V` untuk membuka Markdown Preview, atau `Ctrl+K` lalu `V` untuk preview di sisi editor.
3. Gunakan versi VS Code yang mendukung Mermaid untuk merender blok `mermaid`. Jika Mermaid tidak dirender, gunakan lampiran SVG pada dokumen Tugas 1.2.

### GitHub atau renderer lain

- Buka folder `docs/` pada GitHub; tautan relatif, gambar, tabel, dan Mermaid dapat dirender langsung oleh antarmuka yang mendukungnya.
- Jangan membuka Markdown hanya sebagai file HTML mentah karena tautan relatif dan Mermaid dapat tidak diproses.
- Buka [PDF resmi](./SRS_Sistem_Inventaris_LogistikKu.pdf) dengan pembaca PDF biasa; PDF tidak memerlukan preview Markdown.

## Regenerasi PDF SRS tanpa instalasi dependency

Builder [`tools/build_srs_pdf.py`](./tools/build_srs_pdf.py) memakai Mistune, PyMuPDF, dan browser Chromium yang **sudah tersedia** pada mesin audit. Builder menolak menimpa output yang sudah ada agar PDF resmi tidak terganti sebelum kandidat diperiksa, lalu menanam SHA-256 source Markdown pada metadata kandidat.

```powershell
python docs/tools/build_srs_pdf.py --output storage/SRS_Sistem_Inventaris_LogistikKu_v1.3.candidate.pdf
```

Jangan memasang dependency hanya untuk menjalankan builder. Jika Mistune atau browser headless tidak tersedia, hentikan proses dan pertahankan PDF resmi terakhir. Kandidat harus diperiksa untuk versi/tanggal, kelengkapan bagian, halaman kosong, diagram, tabel, tautan, dan keterbacaan sebelum dipromosikan ke `docs/SRS_Sistem_Inventaris_LogistikKu.pdf`; versi lama harus diarsipkan lebih dahulu.

## Hasil audit navigasi

- Seluruh tautan relatif yang sudah ada sebelum indeks dibuat berhasil di-resolve.
- Tidak ditemukan lompatan level heading, fence Mermaid yang tidak tertutup, conflict marker, atau trailing whitespace.
- `node_modules/` tercantum di `.gitignore` dan tidak dilacak Git; folder lokal tidak dihapus.
- Dokumen kerja yang berulang dan aset yang tidak direferensikan telah dibersihkan setelah isi uniknya dikonsolidasikan; tidak ada file yang dipindahkan atau diganti nama.
