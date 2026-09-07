# LogistikKu

LogistikKu adalah aplikasi web pengelolaan persediaan berbasis Laravel 12. Aplikasi mencatat barang dan pergerakan stok, membantu pengguna menemukan item yang perlu diperhatikan, memverifikasi dokumen melalui engine Python, serta membuat prediksi kebutuhan stok melalui antrean terpisah.

Dokumentasi konsep dan alur per fitur tersedia di [docs/PETA_KONSEP_LOGISTIKKU.md](docs/PETA_KONSEP_LOGISTIKKU.md).

## Fitur yang tersedia

- Login berbasis session dengan rate limit lima percobaan per identitas/IP.
- CRUD barang, foto barang, kode otomatis, soft delete, restore, dan hapus permanen.
- Pencarian instan berdasarkan kode, nama, atau lokasi; filter kategori/status stok; pengurutan nama/stok; dan pagination.
- Transaksi stok masuk/keluar atomik beserta snapshot `stok_sebelum` dan `stok_sesudah`.
- Riwayat stok berbentuk timeline, tabel alternatif, dan grafik saldo dari snapshot asli.
- Import XLSX/XLS/CSV dengan validasi menyeluruh dan upsert berdasarkan kode barang.
- Export persediaan ke XLSX dan PDF 1.4 yang dibuat oleh service internal.
- Dashboard dengan kartu ringkasan, Pusat Perhatian, dan grafik aktivitas stok 7/30 hari.
- Verifikasi Surat Jalan, Invoice, dan Bukti Fisik melalui Laravel–Python: OCR, metadata, skor, indikasi manipulasi, audit koreksi, proses ulang, retry, dan notifikasi.
- Prediksi stok dengan mode Estimasi Awal (`cold_start`), Rata-rata Historis (`simple_average`), Machine Learning (`machine_learning`), dan perhitungan cadangan lokal bila engine Python gagal.
- Antrean prediksi khusus `stock-predictions` dengan status Menunggu, Diproses, Selesai, atau Gagal, generation guard, retry, dan pencegahan proses tumpang tindih.
- Notifikasi prediksi per pengguna serta notifikasi hasil verifikasi dokumen.

## Role dan hak akses

| Kemampuan | Admin | Manager | Staff Gudang (`staff`) |
|---|:---:|:---:|:---:|
| Melihat, mencari, memfilter barang dan dashboard | Ya | Ya | Ya |
| Melihat detail/riwayat dan transaksi stok masuk/keluar | Ya | Ya | Ya |
| Menjalankan analisis prediksi dan melihat status proses | Ya | Ya | Tidak |
| Menyetujui rekomendasi restock ke form stok masuk | Ya | Ya | Tidak |
| Mengunggah dan melihat verifikasi dokumen sendiri | Ya | Ya | Ya |
| Melihat seluruh verifikasi dokumen | Ya | Tidak | Tidak |
| CRUD barang, import/export, trash, dan kelola pengguna | Ya | Tidak | Tidak |

Hak akses diterapkan melalui middleware `auth`, middleware `role:admin`, Gate pada `App\Providers\AppServiceProvider`, `FormRequest`, dan pemeriksaan kepemilikan dokumen.

## Alur Kelola Stok

Tombol **Kelola Stok** tersedia secara langsung, dengan label teks, untuk Admin, Manager, dan Staff Gudang. Tombol menggunakan Gate `update-stock` yang sama dengan authorization pada endpoint form dan penyimpanan transaksi. UI tidak memakai nama permission lain dan tidak memberikan hak akses baru.

Lokasi tombol:

- setiap baris tabel Daftar Barang;
- halaman Detail Barang;
- halaman Edit Barang milik Admin.

Daftar Barang tidak memiliki salinan tabel terpisah di halaman utamanya. `resources/views/barang/index.blade.php` selalu memasukkan `resources/views/barang/partials/inventory-results.blade.php`. Pencarian, filter, sorting, pagination, serta navigasi Back/Forward mengambil endpoint `barang.results`, kemudian JavaScript mengganti isi `#inventory-results-content` dengan partial yang sama. Karena tombol berada di partial tersebut, aksi tetap tersedia setelah render awal maupun pembaruan AJAX.

```mermaid
flowchart TD
    A[GET /barang] --> B[BarangController index]
    B --> C[barang/index.blade.php]
    C --> D[Include inventory-results.blade.php]

    E[Pencarian, filter, sorting, pagination, Back/Forward] --> F[inventory-filter.js]
    F --> G[GET /barang/results]
    G --> H[BarangController inventoryResults]
    H --> D

    D --> I{Gate update-stock}
    I -->|Admin, Manager, Staff| J[Tampilkan tombol Kelola Stok]
    I -->|Role tanpa izin| K[Sembunyikan tombol]
    J --> L[GET /barang/{id}/stok]
    L --> M{authorize update-stock}
    M -->|Diizinkan| N[Form transaksi stok HTTP 200]
    M -->|Ditolak| O[HTTP 403]
```

Route stok yang dipakai bukan endpoint baru:

| Method | URL | Nama route | Authorization |
|---|---|---|---|
| `GET` | `/barang/{id}/stok` | `barang.stok` | `auth` + Gate `update-stock` di controller |
| `POST` | `/barang/{id}/stok` | - | `auth` + Gate `update-stock` di controller |

Form Edit Barang tetap tidak dapat mengubah stok. Halaman edit menampilkan nilai stok dalam input read-only dan mengarahkan pengguna ke **Kelola Stok** untuk mencatat barang masuk atau keluar. Perubahan stok tetap melalui `StockAdjustmentService`; mekanisme database transaction, `lockForUpdate()`, snapshot, histori, queue, dan prediksi tidak dilewati.

```mermaid
flowchart LR
    A[Klik Kelola Stok] --> B[Form barang masuk atau keluar]
    B --> C[POST /barang/{id}/stok]
    C --> D{Gate update-stock}
    D -->|Lolos| E[StockAdjustmentService]
    D -->|Gagal| F[HTTP 403]
    E --> G[Transaction dan lockForUpdate]
    G --> H[Perbarui saldo dan simpan snapshot transaksi]
    H --> I[Redirect ke Detail Barang]
    H --> J[Jadwalkan analisis prediksi]
```

Pada viewport mobile, tabel tetap berada di dalam `.table-responsive` sehingga scroll terjadi pada area tabel, bukan halaman. CSS boleh menyederhanakan label aksi lain menjadi ikon, tetapi `.stock-management-action` mempertahankan teks **Kelola Stok** agar aksi utama selalu dikenali dan tidak bergantung pada hover atau dropdown.

## Integritas data dan indeks

`StockAdjustmentService` mengunci baris barang (`lockForUpdate`) dan menyimpan perubahan stok bersama transaksi dalam satu database transaction. Database menolak:

- stok atau snapshot negatif;
- jumlah transaksi nol/negatif;
- pasangan snapshot yang hanya terisi salah satu;
- perhitungan snapshot masuk/keluar yang tidak sesuai jumlah transaksi.

Indeks domain yang ditambahkan migration meliputi:

- `stok_transactions (barang_id, created_at)`;
- `stok_transactions (barang_id, jenis, created_at)`;
- `stock_predictions (barang_id, process_generation)` unik;
- `stock_prediction_processes.barang_id` unik;
- `stock_prediction_processes (status, updated_at)`;
- indeks pendukung notifikasi, receipt per pengguna, audit dokumen, serta relasi terkait.

Migration memeriksa data lama sebelum memasang constraint. Perbaiki data invalid yang dilaporkan migration; jangan melewati pemeriksaan tersebut.

## Requirement

- PHP 8.2+ dengan ekstensi yang dipakai dependency Laravel/PhpSpreadsheet, termasuk `ctype`, `dom`, `fileinfo`, `gd`, `mbstring`, `openssl`, `pdo`, `xml`, dan `zip`. Jalankan `composer check-platform-reqs` untuk memeriksa runtime setempat.
- Composer.
- MySQL/MariaDB untuk penggunaan normal; SQLite `:memory:` dipakai PHPUnit.
- Python dengan dependency pada `python/requirements.txt`.
- Tesseract OCR pada `PATH` untuk OCR gambar/PDF hasil scan. Pemrosesan PDF memakai PyMuPDF; implementasi saat ini tidak memanggil Poppler.
- Node/npm hanya diperlukan bila aset frontend perlu dibangun ulang.

Dependency utama Python: PyMuPDF, Pillow, pytesseract, OpenCV, dan scikit-learn. `pytesseract` tidak menyertakan program Tesseract.

## Instalasi

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan storage:link
```

Windows PowerShell dapat memakai `Copy-Item .env.example .env`. Atur `DB_*` di `.env` sesuai database lokal sebelum migration. Jangan commit `.env`.

Siapkan Python:

```bash
python -m venv python/.venv
python -m pip install -r python/requirements.txt
```

Aktifkan virtual environment bila diperlukan, atau isi `PYTHON_EXECUTABLE` dengan path interpreter yang benar. Pastikan Tesseract tersedia:

```bash
tesseract --version
tesseract --list-langs
```

## Konfigurasi penting

`.env.example` hanya memuat contoh aman. Nilai yang memengaruhi integrasi:

```dotenv
QUEUE_CONNECTION=database
PYTHON_EXECUTABLE=python
DOCUMENT_CHECKER_TIMEOUT=120
STOCK_PREDICTION_TIMEOUT=30
STOCK_PREDICTION_BATCH_TIMEOUT=120
DB_QUEUE_RETRY_AFTER=360
```

Konfigurasi opsional prediksi mempunyai default di `config/services.php`: histori minimal 30 hari, minimal lima hari transaksi OUT, dan horizon 30 hari.

## Menjalankan aplikasi

Terminal aplikasi:

```bash
php artisan serve
```

Worker prediksi (perintah minimal yang sesuai queue aktual):

```bash
php artisan queue:work database --queue=stock-predictions
```

Parameter eksplisit yang sesuai dengan `ProcessStockPrediction` adalah tiga percobaan dan timeout 60 detik:

```bash
php artisan queue:work database --queue=stock-predictions --sleep=1 --tries=3 --timeout=60
```

Worker verifikasi dokumen memakai queue `default`; job memiliki satu percobaan dan timeout 300 detik:

```bash
php artisan queue:work database --queue=default --sleep=1 --tries=1 --timeout=300
```

Untuk menjalankan keduanya dalam satu worker pengembangan:

```bash
php artisan queue:work database --queue=stock-predictions,default --sleep=1 --tries=3 --timeout=300
```

Setelah deployment atau perubahan kode worker:

```bash
php artisan queue:restart
```

## Menjalankan script Python manual

Kedua engine Python dapat diuji langsung dari terminal tanpa melalui Laravel — berguna saat memeriksa kenapa sebuah dokumen gagal dianalisis atau prediksi menghasilkan angka yang tidak terduga. Jalankan dari dalam `python/` dengan interpreter yang sama dengan `PYTHON_EXECUTABLE` (misal `python/.venv/Scripts/python.exe` di Windows) agar dependency-nya konsisten.

### document_checker.py (verifikasi dokumen OCR)

Script menerima satu argumen posisi berupa path dokumen (`pdf`, `jpg`, `jpeg`, `png`, maksimal 10 MB) dan opsi `--document-type` dengan pilihan `surat_jalan` (default), `invoice`, atau `bukti_fisik`:

```bash
cd python
python document_checker.py dokumen/surat-jalan.pdf --document-type surat_jalan
```

Hasil analisis dicetak ke stdout sebagai JSON (status, confidence, scores, document_metadata, analysis) — sama dengan yang diterima Laravel melalui bridge. Exit code `0` berarti analisis berjalan; exit code `2` berarti dokumen tidak dapat diproses (`DocumentError`), dan JSON `status: "PALSU"` beserta catatan penyebabnya tetap dicetak ke stdout.

Output lengkap biasanya panjang; simpan ke file agar mudah dibaca:

```bash
python document_checker.py dokumen/invoice.pdf --document-type invoice > hasil.json
```

Perintah ini membutuhkan Tesseract OCR pada `PATH` yang sama dengan terminal yang dipakai; jika `tesseract --version` gagal, OCR juga akan gagal di sini.

### stock_predictor.py (prediksi stok)

Berbeda dari document_checker, script ini membaca payload JSON dari stdin dan mencetak hasil prediksi ke stdout:

```bash
cd python
echo '{"item":{"id":1,"current_stock":10,"minimum_stock":5},"out_transactions":[{"id":"t1","quantity":3,"date":"2026-09-01"}]}' | python stock_predictor.py
```

Payload intinya: `item` (`id`, `current_stock`, `minimum_stock`, opsional `daily_usage_estimate` dan `lead_time_days` untuk cold start) serta `out_transactions` berisi histori transaksi keluar (`id`, `quantity`, `date`). Exit code dan format kesalahan mengikuti pola yang sama: `0` berhasil, `2` gagal dengan pesan `error` pada stderr.

## Menjalankan test dan pemeriksaan

```bash
php vendor/bin/phpunit --do-not-cache-result
php vendor/bin/phpunit --do-not-cache-result tests/Feature/DocumentVerificationTest.php
php vendor/bin/phpunit --do-not-cache-result tests/Feature/BarangImportTest.php
php vendor/bin/phpunit --do-not-cache-result tests/Feature/InventoryStockActionUiTest.php
cd python
python -m unittest discover -s tests -v
cd ..
php vendor/bin/pint --test
php artisan view:cache
php artisan route:list
php artisan migrate:status
composer check-platform-reqs
```

PHPUnit memakai SQLite `:memory:` serta queue/cache/session in-memory sesuai `phpunit.xml`, sehingga tidak membaca atau menghapus data database development.

Regression test `InventoryStockActionUiTest` memeriksa response halaman dan partial yang benar-benar dipakai. Cakupannya meliputi tombol beserta URL barang yang tepat untuk Admin, Manager, dan Staff; hasil filter AJAX; halaman Detail dan Edit; HTTP 200 pada form stok; serta ketiadaan tombol dan HTTP 403 untuk role tanpa izin.

Untuk verifikasi manual di browser:

1. Login sebagai Admin, Manager, lalu Staff Gudang.
2. Buka `/barang` dan pastikan setiap baris menampilkan **Kelola Stok**.
3. Jalankan pencarian, filter, sorting, pagination, Back/Forward, dan refresh; tombol harus tetap ada.
4. Klik tombol dan pastikan `/barang/{id}/stok` terbuka tanpa error.
5. Ulangi pemeriksaan pada lebar 1440 px, 834 px, dan 390 px. Pada mobile, label harus tetap terlihat dan overflow horizontal hanya boleh berada di dalam area tabel.
6. Periksa console dan Network browser; request `/barang/results` serta form stok harus berhasil tanpa error JavaScript.

## Troubleshooting

### Prediksi tetap Menunggu

Status Menunggu berarti job sudah dijadwalkan, bukan bukti worker rusak. Periksa secara berurutan:

1. Pastikan database aktif dan `.env` menunjuk database yang benar.
2. Jalankan worker queue `stock-predictions` dengan salah satu perintah di atas.
3. Pastikan `QUEUE_CONNECTION=database` dan tabel `jobs` sudah dimigrasikan.
4. Pastikan `PYTHON_EXECUTABLE` dapat menjalankan `python/stock_predictor.py`.
5. Setelah mengubah `.env`, jalankan `php artisan optimize:clear`, lalu restart worker.
6. Periksa `storage/logs/laravel.log` secara lokal; jangan commit log.
7. Gunakan `php artisan queue:failed` untuk melihat job yang gagal dan analisis ulang dari UI setelah penyebabnya selesai.

Jangan menyatakan worker offline hanya berdasarkan status Menunggu; aplikasi belum mempunyai health monitoring worker.

### Login gagal dengan koneksi ditolak

Pastikan MySQL/MariaDB berjalan dan nilai `DB_HOST`, `DB_PORT`, serta `DB_DATABASE` benar. Error `SQLSTATE[HY000] [2002]` adalah kegagalan koneksi database, bukan password pengguna.

### Verifikasi dokumen tetap Menunggu

Jalankan worker queue `default`, periksa ketersediaan file privat, interpreter Python, dependency Python, dan Tesseract. Retry tersedia untuk hasil `gagal_diproses` bila file masih ada.

### Python executable tidak ditemukan

Pastikan `PYTHON_EXECUTABLE` menunjuk interpreter yang dapat dijalankan oleh PHP. Di Windows, nilai dapat diarahkan ke `python/.venv/Scripts/python.exe`; jangan menuliskan path pribadi tersebut ke repository. Setelah mengubah konfigurasi, jalankan `php artisan optimize:clear` dan restart worker.

### Tesseract tidak ditemukan

Jalankan `tesseract --version` dari terminal yang sama dengan worker dan pastikan executable tersedia pada `PATH`. Poppler tidak diwajibkan oleh implementasi sekarang karena PDF dibaca melalui PyMuPDF.

### OCR gagal atau metadata kosong

Pastikan file tidak rusak, formatnya PDF/JPG/JPEG/PNG, ukurannya maksimal 10 MB, dan scan cukup jelas. Metadata kosong tidak otomatis berarti dokumen palsu; periksa hasil OCR dan lakukan koreksi manual bila diperlukan.

### Import ditolak

Gunakan template dari aplikasi dan format XLSX, XLS, atau CSV dengan ukuran maksimal 5 MB. Header wajib adalah `kode_barang`, `nama_barang`, `kategori`, `stok`, `satuan`, dan `lokasi`. Kode baru harus berbentuk `BRG-` diikuti enam angka; kategori/satuan harus berasal dari pilihan aplikasi dan stok harus berupa bilangan bulat nonnegatif. Pesan validasi menyebut baris yang perlu diperbaiki.

### Foto atau storage link bermasalah

Jalankan `php artisan storage:link` dan pastikan web server dapat membaca `storage/app/public`. Dokumen verifikasi disimpan pada disk `local` privat dan tidak boleh dipindahkan ke direktori publik.

### Worker masih memakai kode lama

Jalankan `php artisan queue:restart`, lalu pastikan proses worker hidup kembali. Web server dan worker harus berjalan pada terminal atau proses yang berbeda.

## Keamanan repository

- Jangan commit `.env`, kredensial, token, email nyata, password/hash, path pribadi, log, atau upload privat.
- `storage/recovery/` dan seluruh backup database tidak boleh masuk repository.
- Repository ini masih memiliki artefak recovery sensitif pada history remote commit `5c9f69b`. Jangan push sampai pembersihan history disetujui dan dilakukan secara terpisah serta terkoordinasi.
- Dokumen verifikasi pada `storage/app/private` dan file pengguna lainnya harus tetap di luar Git.

## Batasan dan risiko yang diketahui

- Prediksi adalah alat bantu perencanaan, bukan jaminan kebutuhan atau tanggal stok habis.
- Cold-start memerlukan estimasi pemakaian harian dan lead time agar dapat menghasilkan angka.
- Kualitas prediksi bergantung pada histori transaksi OUT; transaksi masa depan diabaikan.
- Fallback lokal menjaga hasil tetap tersedia ketika Python gagal, tetapi menggunakan rata-rata/estimasi yang lebih sederhana.
- Verifikasi dokumen adalah pemeriksaan otomatis, bukan penetapan keaslian hukum; scan buram, tulisan tangan, dan template baru dapat memerlukan pemeriksaan manusia.
- Dokumen berada pada disk `local` privat, tetapi keamanan deployment tetap bergantung pada permission filesystem dan backup yang benar.
- Tidak ada REST API, bearer token/Sanctum, dark mode, bulk action, atau health monitoring worker dalam source saat ini.
- Tabel legacy `stok_histories` masih ada dari migration awal, tetapi alur stok aktif memakai `stok_transactions`.

## Struktur penting

```text
app/Http/Controllers/       endpoint web dan otorisasi alur
app/Http/Requests/          validasi input
app/Jobs/                   job OCR dan prediksi
app/Services/               transaksi, dashboard, report, Python bridge
database/migrations/        skema, constraint, dan indeks
python/                     engine OCR dan prediksi
public/assets/js/           interaksi dashboard/filter/prediksi/riwayat
resources/views/            Blade SkyDash
tests/                      test Laravel
python/tests/               test Python
```

## Lisensi

Laravel Framework menggunakan lisensi [MIT](https://opensource.org/licenses/MIT).
