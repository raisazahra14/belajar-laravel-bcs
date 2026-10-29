# LogistikKu

LogistikKu adalah aplikasi web untuk mengelola barang, supplier, gudang, transaksi stok, pengadaan barang (Purchase Order & Goods Receipt), laporan, verifikasi dokumen, dan prediksi kebutuhan stok. Aplikasi dibangun dengan Laravel 12 dan menggunakan Python untuk pemrosesan OCR serta prediksi.

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

### Pengadaan dan Purchase Order (PO)

- Pembuatan Purchase Order (PO) multi-item dengan kalkulasi subtotal dan total biaya dinamis secara *real-time*.
- Penomoran PO otomatis berurut per tanggal dengan format `PO-YYYYMMDD-XXXX`.
- Siklus hidup 7 status pesanan berbasis state machine terpusat: `Draft`, `Menunggu Persetujuan` (Pending Approval), `Disetujui` (Approved), `Ditolak` (Rejected), `Diterima Sebagian` (Partially Received), `Selesai` (Completed), dan `Dibatalkan` (Cancelled).
- Pemisahan tugas (*Separation of Duty*): Manager dilarang menyetujui atau menolak PO yang dibuat oleh dirinya sendiri.
- Penerimaan barang fisik (*Goods Receipt*) bertahap atau penuh langsung dialokasikan ke gudang tujuan fisik.
- Sinkronisasi otomatis: penerimaan fisik langsung menambah saldo gudang (`warehouse_stocks`) dan membukukan mutasi stok masuk (`stok_transactions`) secara atomik.
- Validasi pencegahan *over-receipt* (total kuantitas diterima tidak boleh melampaui kuantitas dipesan).
- Perlindungan konkurensi data: *pessimistic locking* (`lockForUpdate`), penguncian berurutan deterministik anti-*deadlock*, dan *atomic cache lock* pencegah *double submit*.
- Cetak dokumen resmi Purchase Order dalam format PDF siap cetak berstandar profesional.
- Endpoint internal REST API berstandar DTO dan API Resource untuk interoperabilitas modul pengadaan.

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
- Integrasi **Smart Restock**: tombol "Buat PO dari Rekomendasi" otomatis menyusun draft Purchase Order baru dengan supplier rekanan, gudang yang defisit stok, estimasi tanggal tiba, dan kuantitas barang yang dibutuhkan.

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

### Optimasi deployment produksi

Siapkan `.env` produksi final sebelum membangun cache. Minimal gunakan
`APP_ENV=production`, `APP_DEBUG=false`, driver cache bersama yang sesuai, dan
`QUEUE_CONNECTION=database` atau backend queue produksi lainnya. Setelah dependency,
aset, dan migration siap, jalankan:

```bash
composer install --no-dev --classmap-authoritative --no-interaction
npm ci
npm run build
php artisan migrate --force
php artisan optimize --no-interaction
php artisan queue:restart
```

`php artisan optimize` membangun cache konfigurasi, event, route, dan view untuk
menghilangkan kompilasi bootstrap pada request pertama. CI juga menjalankan perintah
ini sebelum PHPUnit agar route, view, dan konfigurasi yang tidak dapat di-cache
terdeteksi sebelum deployment.

Jangan membangun cache sebelum `.env` final tersedia. Setiap perubahan environment
atau konfigurasi harus diikuti dengan:

```bash
php artisan optimize:clear --no-interaction
php artisan optimize --no-interaction
php artisan queue:restart
```

Aktifkan OPcache pada PHP-FPM/web server dan gzip atau Brotli pada reverse proxy.
Direktori `bootstrap/cache` dan `storage` harus dapat ditulis oleh pengguna proses PHP.

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
| Melihat daftar dan detail Purchase Order | Ya | Ya | Ya |
| Membuat dan mengubah draft Purchase Order | Ya | Tidak | Ya (pembuat) |
| Mengajukan (submit) Purchase Order | Ya | Tidak | Ya (pembuat) |
| Menyetujui dan menolak Purchase Order | Ya* | Ya* | Tidak |
| Mencatat penerimaan fisik barang (Goods Receipt) | Ya | Tidak | Ya |
| Melihat dan mencetak PDF Goods Receipt Note (GRN) | Ya | Ya | Ya |
| Three-Way Matching (PO vs GRN vs OCR Dokumen) | Ya | Ya | Ya |
| Dashboard & Audit Performa Supplier | Ya | Ya | Ya |
| Membatalkan Purchase Order (cancel) | Ya | Ya | Ya** |
| Mencetak dokumen PDF Purchase Order | Ya | Ya | Ya |
| CRUD barang, supplier, dan gudang | Ya | Tidak | Tidak |
| Import/export inventaris dan Tong Sampah | Ya | Tidak | Tidak |
| Analitik bisnis | Ya | Tidak | Tidak |
| Kelola pengguna | Ya | Tidak | Tidak |

Catatan hak akses modul pengadaan:
- `*` Admin dan Manager hanya dapat menyetujui atau menolak PO yang diajukan oleh pengguna lain (prinsip *Separation of Duty* / Maker-Checker; tidak boleh menyetujui PO buatannya sendiri).
- `**` Staff Gudang hanya berwenang membatalkan PO yang dibuat oleh dirinya sendiri saat masih berstatus `Draft` atau `Rejected`.

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

### Alur Purchase Order dan penerimaan barang (Goods Receipt)

```text
[Draft] -------------------------> [Menunggu Persetujuan] ------------> [Disetujui] ------------> [Diterima Sebagian / Selesai]
   |                                       |                                   |                              ^
   | (Cancel)                              | (Reject / Withdraw)               | (Cancel)                     | (Goods Receipt bertahap)
   v                                       v                                   v                              |
[Dibatalkan]                           [Ditolak / Draft]                   [Dibatalkan]         ------------------
```

1. **Pembuatan PO (Draft)**:
   - Staff Gudang atau Admin memilih supplier aktif, gudang tujuan aktif, tanggal pesan, serta estimasi tanggal tiba.
   - Menginput item-item pesanan barang secara dinamis (barang, kuantitas pesan, harga beli satuan). Subtotal per item dan total akumulasi biaya pesanan dikalkulasi secara otomatis oleh sistem.
   - Sistem menghasilkan nomor pesanan otomatis dengan format `PO-YYYYMMDD-XXXX` yang tersusun berurutan per tanggal.
   - Data tersimpan dalam status `Draft` dan masih dapat disunting atau disesuaikan oleh pembuat.

2. **Pengajuan PO (Submit)**:
   - Pembuat PO atau Admin mengajukan pesanan pembelian.
   - Sistem memvalidasi guard bisnis: PO minimal harus memiliki 1 item barang.
   - Status pesanan berpindah dari `Draft` ke `Menunggu Persetujuan` (*Pending Approval*).

3. **Peninjauan dan Keputusan Manager (Approval / Rejection)**:
   - Pengguna dengan peran Manager meninjau data PO pada halaman detail `/purchase-orders/{id}`.
   - **Persetujuan (Approve)**: Manager menyetujui pesanan, status berpindah ke `Disetujui` (*Approved*), dan identitas penyetuju dicatat pada sistem.
   - **Penolakan (Reject)**: Manager menolak pesanan dengan wajib menyertakan alasan penolakan. Status berubah menjadi `Ditolak` (*Rejected*). Pembuat PO dapat melakukan revisi (*Revise*) untuk mengembalikan PO ke mode `Draft` dan memperbaikinya tanpa mengulang input dari nol.
   - **Separation of Duty**: Berdasarkan prinsip tata kelola dan kontrol internal, Manager yang merangkap sebagai pembuat PO diblokir oleh sistem agar tidak dapat menyetujui atau menolak pesanannya sendiri.

4. **Penerimaan Fisik di Gudang (Goods Receipt Note / GRN) & Sinkronisasi Stok**:
   - Setelah PO berstatus `Disetujui`, supplier mengirimkan barang ke gudang fisik tujuan.
   - Staff Gudang atau Admin membuka modal pencatatan penerimaan barang pada halaman detail PO.
   - Formulir mendukung pencatatan nomor Surat Jalan/Delivery Order vendor serta unggah berkas fisik bukti Surat Jalan (JPG, PNG, PDF).
   - Kuantitas fisik yang diterima diinput per baris item. Sistem memvalidasi kuantitas untuk mencegah *over-receipt* (total diterima tidak boleh melebihi sisa yang dipesan).
   - Seluruh proses berjalan secara atomik di dalam satu database transaction:
     - Menerbitkan dokumen Berita Acara Penerimaan Barang resmi dengan nomor urut unik `GRN-YYYYMMDD-XXXX`.
     - Saldo fisik pada tabel `warehouse_stocks` untuk gudang tujuan bertambah seketika.
     - Riwayat mutasi stok masuk dibukukan pada buku besar `stok_transactions` dan `stok_histories`.
     - Kuantitas `jumlah_diterima` pada masing-masing item PO diperbarui.
     - Setiap penerimaan dapat diunduh dalam format PDF Berita Acara Penerimaan Barang resmi via tombol **Cetak PDF GRN**.
     - State machine secara otomatis menentukan status akhir PO:
       - `Diterima Sebagian` (*Partially Received*) jika masih ada item yang belum diterima lengkap.
       - `Selesai` (*Completed*) jika seluruh kuantitas item telah diterima 100%.

5. **Riwayat Audit Status & Cetak Dokumen**:
   - **Audit Trail Linimasa**: Setiap perpindahan status (pembuatan, pengajuan, persetujuan, penolakan, pembatalan, penerimaan barang) terekam secara kronologis pada tabel `purchase_order_status_histories` dan ditampilkan dalam bentuk linimasa pada kartu detail PO.
   - **Cetak Dokumen Resmi**: Dokumen fisik Purchase Order resmi berstandar profesional dapat dicetak atau diunduh kapan saja melalui tombol **Cetak PDF PO**.
   - **Pembatalan Pesanan**: PO berstatus `Draft`, `Menunggu Persetujuan`, atau `Disetujui` (sebelum ada penerimaan barang) dapat dibatalkan menjadi `Dibatalkan` (*Cancelled*) dengan wajib mencantumkan alasan pembatalan.

6. **Notifikasi Terintegrasi & Peringatan PO Terlambat**:
   - **Pusat Notifikasi**: Terhubung ke dropdown lonceng navbar (`notifications`), memberi tahu Manager/Admin saat ada PO baru yang butuh persetujuan, serta memberi tahu Staf Gudang saat PO disetujui atau ditolak (beserta alasannya).
   - **Peringatan Otomatis PO Terlambat**: PO `Disetujui` atau `Diterima Sebagian` yang telah melewati `estimasi_tiba` ditandai dengan badge merah `Terlambat`, banner peringatan di halaman detail, filter cepat `Hanya PO Terlambat` di indeks, dan pemberitahuan berkala via cron job harian (`php artisan po:check-overdue --notify`).

7. **Integritas dan Proteksi Konkurensi**:
   - **Pencegahan Double Submit**: Menggunakan *atomic cache lock* 15 detik pada level service dan penguncian tombol formulir di sisi UI frontend.
   - **Pencegahan Deadlock**: Menggunakan *pessimistic locking* (`lockForUpdate`) dengan urutan penguncian item deterministik berdasarkan `id` barang (`orderBy('id')`) serta toleransi *deadlock auto-retry* 3 kali.

8. **Integrasi Prediksi Stok ke PO (Smart Restock)**:
   - Tombol **Buat PO dari Rekomendasi** pada halaman rekomendasi restock (`/prediksi-stok`) secara otomatis mengisi formulir pembuatan PO: supplier rekanan, gudang defisit yang membutuhkan alokasi, kuantitas rekomendasi, harga beli master barang, dan estimasi tanggal tiba sebelum stok menipis.

9. **Pencocokan Tiga Arah (Three-Way Matching: PO vs GRN vs Dokumen OCR)**:
   - Mengintegrasikan hasil pindaian berkas fisik vendor (Surat Jalan / Invoice via Tesseract OCR) dengan data pemesanan sistem (PO) dan bukti penerimaan fisik gudang (GRN).
   - Membandingkan nomor PO, nomor referensi DO/surat jalan, entitas vendor, dan total nilai tagihan finansial.
   - Matriks perbandingan per baris mendeteksi selisih kuantitas fisik vs tagihan (*overbilling*), selisih harga satuan (*price discrepancy*), dan mencatat status pencocokan secara visual.
   - Dilengkapi *deep linking* dua arah antara halaman detail PO dan halaman hasil verifikasi OCR.

10. **Dashboard Analitik Kinerja Rekanan (Vendor Performance Dashboard)**:
    - Melacak ketepatan waktu pengiriman vendor (*On-Time Delivery Rate*) berdasarkan selisih tanggal terima GRN terhadap estimasi tiba PO, rata-rata hari keterlambatan (*average delay days*), dan rasio pemenuhan kuantitas fisik (*fulfillment rate*).
    - Menghitung skor komposit performa berbobot dan mengklasifikasikan vendor ke dalam peringkat: *Sangat Baik* ($\ge 90\%$), *Baik* ($75\% - 89.9\%$), *Perlu Evaluasi* ($50\% - 74.9\%$), dan *Kritis* ($< 50\%$).
    - Tersedia ringkasan eksekutif di `/suppliers/performance` dan audit logistik per vendor di `/suppliers/{supplier}/performance`.

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
| [Arsitektur Modul Purchase Order](docs/arsitektur_modul_purchase_order.md) | Spesifikasi teknis, state machine, DTO, API Resource, dan mitigasi konkurensi modul PO |
| [Portofolio Penutupan Magang](docs/PORTOFOLIO_PENUTUPAN_MAGANG.md) | Laporan komprehensif pencapaian proyek magang, rekayasa perangkat lunak, metrik pengujian, dan dampak bisnis |
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
