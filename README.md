# LogistikKu

LogistikKu adalah aplikasi web untuk mengelola barang, supplier, gudang, transaksi stok, laporan, verifikasi dokumen, dan prediksi kebutuhan stok. Aplikasi dibangun dengan Laravel 12 dan menggunakan Python untuk pemrosesan OCR serta prediksi.

> Proyek ini ditujukan untuk pengembangan dan evaluasi lokal. Sebelum dipakai di produksi, ganti seluruh akun demo, nonaktifkan mode debug, dan sesuaikan konfigurasi database, queue, storage, serta web server.

## Daftar isi

- [Fitur utama](#fitur-utama)
- [Teknologi](#teknologi)
- [Persyaratan](#persyaratan)
- [Instalasi cepat](#instalasi-cepat)
- [Login dan akun demo](#login-dan-akun-demo)
- [Menjalankan aplikasi](#menjalankan-aplikasi)
- [Konfigurasi penting](#konfigurasi-penting)
- [Role dan hak akses](#role-dan-hak-akses)
- [Cara kerja modul utama](#cara-kerja-modul-utama)
- [Pengujian](#pengujian)
- [Struktur proyek](#struktur-proyek)
- [Troubleshooting](#troubleshooting)
- [Dokumentasi lanjutan](#dokumentasi-lanjutan)
- [Keamanan dan batasan](#keamanan-dan-batasan)

## Fitur utama

### Autentikasi dan pengguna

- Login berbasis session menggunakan email dan password.
- Opsi **Ingat saya**, normalisasi email, regenerasi session, dan pembatasan lima percobaan login gagal per email/IP selama 60 detik.
- Logout aman dengan invalidasi session dan regenerasi token CSRF.
- Tiga role: `admin`, `manager`, dan `staff`.
- Admin dapat mengelola akun pengguna.

### Inventaris

- Daftar, pencarian, filter, pengurutan, dan pagination barang.
- CRUD barang, foto barang, kode barang otomatis, dan soft delete.
- Halaman stok menipis untuk barang dengan stok `<= 5`.
- Import XLSX, XLS, atau CSV secara atomik.
- Export persediaan ke XLSX, CSV, dan PDF.
- Tong Sampah dengan pemulihan, penghapusan permanen, dan aksi massal.

### Supplier dan gudang

- Daftar dan detail supplier serta gudang dapat dilihat pengguna yang sudah login.
- Admin dapat menambah, mengubah, dan menghapus master supplier serta gudang.
- Saldo barang dicatat per gudang melalui `warehouse_stocks`.
- Transfer stok antargudang dan rekonsiliasi saldo tersedia.

### Transaksi dan laporan stok

- Pencatatan stok masuk dan keluar dengan validasi saldo.
- Riwayat transaksi dan grafik perubahan saldo.
- Snapshot pelaku, supplier, saldo sebelum/sesudah, dan informasi audit transaksi.
- Transfer stok antargudang.
- Pembalikan transaksi untuk Admin dan Manager.
- Laporan mutasi stok dengan filter tanggal, supplier, gudang, dan kategori.
- Export laporan mutasi ke CSV, XLSX, dan PDF.

### Analitik dan notifikasi

- Ringkasan jumlah barang, total stok, kategori, aktivitas 7/30 hari, dan stok menipis.
- Klasifikasi Fast, Slow, dan Dead Stock.
- Valuasi stok berdasarkan harga beli.
- Analitik mutasi dan rekonsiliasi saldo master dengan saldo gudang.
- Pusat notifikasi untuk hasil prediksi, verifikasi dokumen, dan perhatian operasional.

### Verifikasi dokumen

- Upload PDF, JPG, JPEG, atau PNG sampai 10 MiB.
- Mendukung Surat Jalan, Invoice, dan Bukti Fisik.
- File disimpan secara privat.
- Job Laravel menjalankan Python dan Tesseract OCR untuk mengekstrak metadata serta melakukan pemeriksaan awal.
- Status proses, hasil pemeriksaan, retry, edit metadata, audit, dan notifikasi tersedia.

Verifikasi ini adalah alat bantu pemeriksaan awal, bukan penetapan keaslian hukum.

### Prediksi stok

- Analisis histori transaksi keluar untuk membantu menentukan kebutuhan restock.
- Metode `cold_start`, rata-rata sederhana, atau regresi linear dipilih berdasarkan data yang tersedia.
- Analisis satu barang atau semua barang berjalan melalui queue `stock-predictions`.
- Laravel menyediakan fallback lokal jika proses Python gagal.
- Admin dan Manager dapat menjalankan analisis serta menerapkan rekomendasi restock.

## Teknologi

| Bagian | Teknologi |
|---|---|
| Backend | PHP 8.2+, Laravel 12, Eloquent ORM, Blade |
| Database | SQLite atau MySQL/MariaDB |
| Frontend | Bootstrap/SkyDash, JavaScript, Chart.js, Vite 6 |
| Queue | Laravel Queue dengan driver database |
| Import/export | Maatwebsite Excel, PhpSpreadsheet, generator CSV/PDF internal |
| Integrasi Python | Symfony Process |
| OCR | Python, Tesseract, Pillow, OpenCV, PyMuPDF, pytesseract |
| Prediksi | Python, NumPy, pandas, scikit-learn |
| Test | PHPUnit 11 dan Python `unittest` |

## Persyaratan

Pastikan perangkat memiliki:

- PHP 8.2 atau lebih baru;
- Composer;
- Node.js 18/20 atau 22+ dan npm;
- SQLite, atau MySQL/MariaDB;
- Python 3.10 atau lebih baru;
- Tesseract OCR beserta bahasa `eng`, `ind`, atau keduanya;
- Git.

Ekstensi PHP yang umum diperlukan: `ctype`, `dom`, `fileinfo`, `gd`, `iconv`, `libxml`, `mbstring`, `openssl`, `pdo`, `simplexml`, `xml`, `xmlreader`, `xmlwriter`, dan `zip`. Periksa kebutuhan aktual dengan:

```bash
composer check-platform-reqs
```

## Instalasi cepat

### 1. Ambil kode dan pasang dependency

```bash
git clone <URL_REPOSITORY> LogistikKu
cd LogistikKu
composer install
npm ci
```

### 2. Siapkan environment

Linux/macOS:

```bash
cp .env.example .env
php artisan key:generate
```

Windows PowerShell:

```powershell
Copy-Item .env.example .env
php artisan key:generate
```

### 3. Siapkan database

Konfigurasi bawaan memakai SQLite.

Linux/macOS:

```bash
touch database/database.sqlite
php artisan migrate --seed
```

Windows PowerShell:

```powershell
if (-not (Test-Path database/database.sqlite)) {
    New-Item -ItemType File database/database.sqlite
}
php artisan migrate --seed
```

Untuk MySQL/MariaDB, buat database kosong lalu ubah bagian berikut di `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=logistikku
DB_USERNAME=root
DB_PASSWORD=
```

Setelah itu jalankan:

```bash
php artisan migrate --seed
```

### 4. Siapkan storage dan aset

```bash
php artisan storage:link
npm run build
```

`storage:link` diperlukan agar foto barang pada disk publik dapat ditampilkan. Dokumen verifikasi tetap disimpan pada storage privat.

### 5. Siapkan Python

Buat virtual environment:

```bash
python -m venv python/.venv
```

Windows PowerShell:

```powershell
python/.venv/Scripts/python.exe -m pip install -r python/requirements.txt
```

Linux/macOS:

```bash
python/.venv/bin/python -m pip install -r python/requirements.txt
```

Ubah `PYTHON_EXECUTABLE` di `.env` agar menunjuk ke interpreter tersebut. Contoh Windows:

```dotenv
PYTHON_EXECUTABLE=python/.venv/Scripts/python.exe
```

### 6. Pasang Tesseract

Pastikan perintah berikut berhasil dari terminal yang juga akan menjalankan queue worker:

```bash
tesseract --version
tesseract --list-langs
```

Script juga mendeteksi lokasi instalasi Windows standar `C:\Program Files\Tesseract-OCR\tesseract.exe`. Bila `eng` dan `ind` tersedia, OCR menggunakan keduanya.

## Login dan akun demo

Jalankan seeder untuk membuat akun lokal:

```bash
php artisan db:seed
```

| Role | Email | Password |
|---|---|---|
| Admin | `admin@logistikku.test` | `password` |
| Manager | `manager@logistikku.test` | `password` |
| Staff | `staff@logistikku.test` | `password` |

Buka [http://127.0.0.1:8000/login](http://127.0.0.1:8000/login), lalu gunakan salah satu akun di atas. Kredensial demo sengaja tidak ditampilkan pada halaman login; gunakan tabel dokumentasi ini hanya untuk pengembangan lokal.

> Akun ini hanya untuk pengembangan. Ganti password atau hapus akun demo sebelum aplikasi dapat diakses dari jaringan publik.

Jika login tidak muncul:

1. pastikan server Laravel aktif;
2. buka `/login` secara langsung;
3. jalankan `php artisan migrate --seed` agar tabel dan akun tersedia;
4. jalankan `php artisan optimize:clear` setelah mengubah `.env`;
5. hapus cookie situs atau logout jika browser masih menyimpan session lama.

## Menjalankan aplikasi

### Cara paling ringkas

Perintah berikut menjalankan server Laravel, queue listener, dan Vite sekaligus:

```bash
composer run dev
```

Lalu buka [http://127.0.0.1:8000](http://127.0.0.1:8000). Pengguna yang belum login otomatis diarahkan ke halaman login.

### Menjalankan proses secara terpisah

Terminal 1 - server Laravel:

```bash
php artisan serve
```

Terminal 2 - Vite:

```bash
npm run dev
```

Terminal 3 - queue prediksi:

```bash
php artisan queue:work database --queue=stock-predictions --sleep=1 --tries=3 --timeout=60
```

Terminal 4 - queue verifikasi dokumen:

```bash
php artisan queue:work database --queue=default --sleep=1 --tries=1 --timeout=300
```

Untuk pengembangan, kedua queue dapat ditangani satu worker:

```bash
php artisan queue:work database --queue=stock-predictions,default --sleep=1 --tries=3 --timeout=300
```

Setelah kode job atau konfigurasi berubah, jalankan:

```bash
php artisan queue:restart
```

## Konfigurasi penting

Contoh konfigurasi lokal pada `.env`:

```dotenv
APP_NAME=LogistikKu
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
APP_TIMEZONE=UTC
APP_DISPLAY_TIMEZONE=Asia/Jakarta

DB_CONNECTION=sqlite
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

PYTHON_EXECUTABLE=python
DOCUMENT_CHECKER_TIMEOUT=120
STOCK_PREDICTION_TIMEOUT=30
STOCK_PREDICTION_BATCH_TIMEOUT=120
STOCK_PREDICTION_MINIMUM_HISTORY_DAYS=30
STOCK_PREDICTION_MINIMUM_OUT_DAYS=5
STOCK_PREDICTION_HORIZON_DAYS=30
DB_QUEUE_RETRY_AFTER=360
```

Catatan:

- jangan commit file `.env`;
- buat `APP_KEY` dengan `php artisan key:generate`;
- setelah konfigurasi berubah, jalankan `php artisan optimize:clear` dan restart queue worker;
- timeout job OCR adalah 300 detik, sehingga `retry_after` database sebaiknya lebih besar;
- pada produksi gunakan `APP_DEBUG=false` dan kredensial database yang aman.

## Role dan hak akses

| Kemampuan | Admin | Manager | Staff |
|---|:---:|:---:|:---:|
| Login, dashboard, barang, supplier, dan gudang | Ya | Ya | Ya |
| Melihat detail dan riwayat stok | Ya | Ya | Ya |
| Mencatat stok masuk/keluar | Ya | Ya | Ya |
| Transfer stok antargudang | Ya | Ya | Ya |
| Membalikkan transaksi stok | Ya | Ya | Tidak |
| Melihat dan export laporan mutasi | Ya | Ya | Tidak |
| Menjalankan prediksi dan menyetujui restock | Ya | Ya | Tidak |
| Upload dan melihat verifikasi dokumen sendiri | Ya | Ya | Ya |
| Melihat semua verifikasi dokumen | Ya | Tidak | Tidak |
| CRUD barang, supplier, dan gudang | Ya | Tidak | Tidak |
| Import/export inventaris dan Tong Sampah | Ya | Tidak | Tidak |
| Analitik bisnis | Ya | Tidak | Tidak |
| Kelola pengguna | Ya | Tidak | Tidak |

Otorisasi diterapkan melalui middleware `auth`, middleware role, Laravel Gate, validasi request, dan pemeriksaan kepemilikan data.

## Cara kerja modul utama

### Alur autentikasi

1. Pengguna membuka `/login`.
2. Laravel memvalidasi email dan password.
3. Login gagal dihitung oleh rate limiter berdasarkan email dan alamat IP.
4. Jika berhasil, session diregenerasi dan pengguna diarahkan ke halaman yang sebelumnya diminta atau `/barang`.
5. Logout menghapus session dan kembali ke `/login`.

### Alur perubahan stok

1. Pengguna memilih barang dan jenis transaksi.
2. Request memvalidasi jumlah, gudang, supplier, catatan, dan kewenangan pengguna.
3. Service mengunci data yang relevan dan menjalankan perubahan dalam transaksi database.
4. Saldo barang, saldo gudang, serta snapshot sebelum/sesudah diperbarui secara konsisten.
5. Transaksi tersimpan pada `stok_transactions` dan tampil pada riwayat/laporan.

### Alur verifikasi dokumen

```text
Upload -> validasi -> storage privat -> queue default
       -> Python/Tesseract -> JSON hasil -> database
       -> status, metadata, audit, dan notifikasi
```

Laravel menangani autentikasi, file, job, dan database. Python bukan server terpisah; script dijalankan oleh Laravel melalui Symfony Process.

### Alur prediksi stok

```text
Histori transaksi keluar -> queue stock-predictions -> Python
                         -> metode prediksi -> rekomendasi
                         -> database dan notifikasi
```

Jika Python gagal, service Laravel menghasilkan fallback sederhana agar proses tetap memberikan hasil yang aman untuk ditinjau.

### Import barang

Import menerima tepat enam kolom berikut:

| Kolom | Aturan ringkas |
|---|---|
| `kode_barang` | Wajib; format barang baru `BRG-` + 6 angka |
| `nama_barang` | Wajib; maksimal 255 karakter |
| `kategori` | Harus termasuk kategori yang diizinkan aplikasi |
| `stok` | Bilangan bulat, minimal 0 |
| `satuan` | Harus termasuk satuan yang diizinkan aplikasi |
| `lokasi` | Wajib; maksimal 255 karakter |

Contoh CSV:

```csv
kode_barang,nama_barang,kategori,stok,satuan,lokasi
BRG-000001,Kabel LAN Cat6,Jaringan,20,Pcs,Gudang B
```

Seluruh batch dibatalkan jika ada baris tidak valid, duplikasi kode, atau konflik dengan barang di Tong Sampah.

## Pengujian

Suite Laravel:

```bash
php artisan test --do-not-cache-result
```

Pada sebagian lingkungan Windows, `php artisan test` dapat gagal karena Symfony Process tidak mengenali working directory. Gunakan PHPUnit langsung:

```bash
php vendor/bin/phpunit --do-not-cache-result
```

Menjalankan tes login saja:

```bash
php vendor/bin/phpunit --filter="LoginPageTest|AuthRateLimitTest" --do-not-cache-result
```

Suite Python dijalankan dari folder `python`:

```bash
cd python
python -m unittest discover -s tests -v
```

Pemeriksaan gaya PHP opsional:

```bash
php vendor/bin/pint --test
```

PHPUnit menggunakan SQLite in-memory dan driver cache, session, mail, serta queue in-memory sesuai [`phpunit.xml`](phpunit.xml), sehingga tidak mengubah database development.

## Struktur proyek

```text
app/
|-- Http/Controllers/       controller halaman dan endpoint internal
|-- Http/Middleware/        middleware role
|-- Http/Requests/          validasi dan otorisasi request
|-- Jobs/                   job OCR dan prediksi stok
|-- Models/                 model Eloquent
`-- Services/               logika stok, laporan, analitik, dan Python bridge
config/                      konfigurasi Laravel dan service
database/
|-- migrations/             struktur tabel, constraint, dan indeks
`-- seeders/                akun lokal dan data demo
docs/                        SRS, ERD, data dictionary, dan diagram
public/                      aset CSS, JavaScript, gambar, dan entry point
python/
|-- document_checker.py     OCR dan pemeriksaan dokumen
|-- stock_predictor.py      prediksi kebutuhan stok
`-- tests/                  unit test Python
resources/views/             halaman Blade dan komponen UI
routes/web.php               route web aplikasi
tests/                       unit dan feature test Laravel
```

LogistikKu belum menyediakan REST API publik. Endpoint JSON yang ada dipakai antarmuka internal dan tetap dilindungi autentikasi session.

## Troubleshooting

### Halaman login tidak tampil

- Buka `http://127.0.0.1:8000/login` secara langsung.
- Pastikan route tersedia dengan `php artisan route:list --path=login`.
- Bersihkan cache menggunakan `php artisan optimize:clear`.
- Jika sedang login, lakukan logout atau hapus cookie situs; middleware `guest` memang mengalihkan pengguna aktif ke `/barang`.

### Email atau password tidak sesuai

- Jalankan `php artisan migrate --seed`.
- Gunakan akun demo persis seperti tabel pada bagian login.
- Email boleh memakai huruf besar/kecil, tetapi password bersifat case-sensitive.
- Setelah lima kegagalan, tunggu 60 detik sebelum mencoba lagi.

### Database tidak tersedia

- SQLite: pastikan `database/database.sqlite` ada dan dapat ditulis.
- MySQL/MariaDB: pastikan service aktif dan nilai `DB_*` benar.
- Jalankan `php artisan migrate:status` untuk memeriksa koneksi dan migration.

### Foto barang tidak tampil

Jalankan `php artisan storage:link`, lalu pastikan web server dapat membaca `storage/app/public`.

### Prediksi tetap berstatus Menunggu

- Pastikan `QUEUE_CONNECTION=database`.
- Jalankan worker queue `stock-predictions`.
- Periksa kegagalan dengan `php artisan queue:failed`.
- Periksa `PYTHON_EXECUTABLE` dan dependency Python.

### Verifikasi dokumen tetap berstatus Menunggu

- Jalankan worker queue `default`.
- Periksa Tesseract dengan `tesseract --version`.
- Pastikan file privat dapat dibaca worker dan dependency pada `python/requirements.txt` terpasang.

### Worker memakai kode lama

```bash
php artisan optimize:clear
php artisan queue:restart
```

Kemudian pastikan proses worker dimulai kembali.

### Import ditolak

Gunakan template dari aplikasi, ukuran maksimum 5 MiB, header yang benar, kategori/satuan yang diizinkan, dan nilai stok berupa bilangan bulat nonnegatif.

## Dokumentasi lanjutan

| Dokumen | Keterangan |
|---|---|
| [Indeks dokumentasi](docs/README.md) | Peta seluruh dokumentasi proyek |
| [SRS](docs/srs.md) | Kebutuhan, use case, activity diagram, dan kriteria penerimaan |
| [SRS PDF](docs/SRS_Sistem_Inventaris_LogistikKu.pdf) | Versi PDF dokumen kebutuhan |
| [Peta konsep](docs/PETA_KONSEP_LOGISTIKKU.md) | Ringkasan modul dan alur Laravel-Python |
| [ERD](docs/database/erd.md) | Relasi tabel dan aturan database |
| [Data dictionary](docs/data_dictionary.md) | Penjelasan tabel, kolom, constraint, dan indeks |
| [Dokumentasi Python](python/README.md) | Cara kerja script OCR dan prediksi |

## Keamanan dan batasan

- Jangan commit `.env`, database lokal, token, log, backup, atau dokumen pengguna.
- Jangan gunakan akun dan password demo di produksi.
- Dokumen verifikasi harus tetap berada di storage privat.
- Verifikasi OCR dapat keliru pada scan buram, tulisan tangan, atau format dokumen baru; hasil tetap perlu diperiksa manusia.
- Prediksi adalah alat bantu dan sangat bergantung pada kualitas histori transaksi.
- Belum ada REST API publik, autentikasi token, OpenAPI, atau health monitoring worker.
- Setelah deployment, gunakan HTTPS, `APP_DEBUG=false`, cookie aman, proses queue terkelola, backup database, dan rotasi kredensial.

## Lisensi

Metadata [`composer.json`](composer.json) mendeklarasikan lisensi MIT. Repository saat ini belum memiliki file `LICENSE` terpisah.
