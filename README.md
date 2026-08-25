# LogistikKu

LogistikKu adalah aplikasi inventaris berbasis Laravel untuk mencatat barang dan pergerakan stok gudang. Aplikasi menyediakan antarmuka web untuk operasional inventaris serta REST API yang dilindungi token.

Dokumentasi ini disusun dari implementasi yang tersedia di repository.

## Teknologi yang digunakan

- PHP `^8.2` dan Laravel `^12.0`
- Laravel Sanctum `^4.3` untuk token API
- Spatie Laravel Permission `^6.25` untuk role pengguna
- Laravel DOMPDF `^3.1` untuk ekspor PDF
- Laravel Excel `3.1.69` untuk ekspor Excel
- Blade dengan aset tema SkyDash
- Vite `^6`, Tailwind CSS `^4`, Axios, dan Concurrently
- Eloquent ORM; konfigurasi bawaan `.env.example` menggunakan SQLite

## Jawaban dan penyelesaian modul

Seluruh tugas pada Modul 1 sampai Modul 4 sudah diterapkan di repository ini. Berikut jawaban, lokasi implementasi, dan cara memverifikasi setiap tugas.

### Modul 1: Merapikan Kode (Clean Code Standard)

#### ✅ Tugas 1.1: Form Request Validation

Validasi barang sudah dipisahkan dari controller ke `app/Http/Requests/StoreBarangRequest.php`. Request ini memvalidasi kode barang unik, nama, kategori, stok minimal 0, satuan yang diizinkan, lokasi, dan foto maksimal 2 MB.

Method `BarangController::store()` menerima `StoreBarangRequest`, kemudian hanya meneruskan data yang lolos validasi ke `BarangService`. Validasi edit dan transaksi stok juga dipisahkan ke `UpdateBarangRequest` dan `UpdateStokRequest`.

#### ✅ Tugas 1.2: Database Transaction (`DB::transaction`)

Proses perubahan stok dan pencatatan riwayat berada di `app/Services/BarangService.php`, method `updateStok()`. Kedua operasi dibungkus dengan `DB::transaction()` sehingga perubahan stok akan dibatalkan apabila penyimpanan transaksi gagal.

Data barang juga diambil ulang menggunakan `lockForUpdate()` untuk mencegah perubahan stok bersamaan menghasilkan jumlah yang tidak konsisten. Stok keluar yang melebihi stok tersedia ditolak melalui validation exception.

#### ✅ Tugas 1.3: Seeder dan Factory Data Dummy

`database/factories/BarangFactory.php` menghasilkan data barang realistis berdasarkan konfigurasi produk, kategori, satuan, dan lokasi. `database/seeders/DatabaseSeeder.php` membuat 50 barang serta tiga akun demo.

Jalankan:

```bash
php artisan migrate:fresh --seed
```

Perintah tersebut membangun ulang database dan mengisi data demo. Jika tabel sudah tersedia dan tidak ingin menghapus data, gunakan `php artisan db:seed`.

### Modul 2: Fitur Bisnis Dunia Nyata (Real-World Features)

#### ✅ Tugas 2.1: Upload Foto Produk (`foto_barang`)

Kolom `foto_barang` ditambahkan melalui migration `database/migrations/2026_08_19_171054_add_foto_barang_to_barang_table.php`. Form tambah/edit menggunakan `multipart/form-data` dan menyediakan input file pada `resources/views/barang/partials/form.blade.php`.

Foto divalidasi sebagai JPEG, PNG, JPG, atau WebP maksimal 2 MB, lalu disimpan oleh `BarangService` ke disk `public` dalam folder `barang`. URL gambar disediakan oleh accessor `foto_url` pada model `Barang` dan ditampilkan pada halaman inventaris.

Aktifkan akses file publik satu kali dengan:

```bash
php artisan storage:link
```

#### ✅ Tugas 2.2: Export Laporan PDF dan Excel

Ekspor PDF menggunakan `barryvdh/laravel-dompdf`, view `resources/views/barang/pdf.blade.php`, dan route `GET /barang-export/pdf`. Ekspor Excel menggunakan `maatwebsite/excel`, class `app/Exports/BarangExport.php`, dan route `GET /barang-export/excel`.

Tombol ekspor tersedia pada halaman daftar barang. File yang dihasilkan bernama `laporan_stok_barang.pdf` dan `laporan_stok_barang.xlsx`.

#### ✅ Tugas 2.3: Tong Sampah (Soft Deletes)

Model `Barang` memakai trait `SoftDeletes`, sedangkan kolom `deleted_at` dibuat melalui migration. Penghapusan biasa hanya memindahkan data ke tong sampah.

Admin dapat membuka `GET /barang-trash`, memulihkan data melalui aksi restore, atau menghapusnya secara permanen melalui force delete. Foto barang ikut dihapus dari storage ketika force delete dilakukan.

### Modul 3: Hak Akses (Role dan Permission)

#### ✅ Tugas 3.1: Spatie Laravel Permission

Package `spatie/laravel-permission` sudah terpasang. Model `User` memakai trait `HasRoles`, migration tabel permission tersedia, dan `RoleSeeder` membuat tiga role:

- Admin
- Staff Gudang
- Manager

`DatabaseSeeder` membuat satu akun demo untuk setiap role dan menyinkronkan role menggunakan `syncRoles()`.

#### ✅ Tugas 3.2: Otorisasi dengan Laravel Policy

`app/Policies/BarangPolicy.php` membatasi operasi `delete`, `restore`, dan `forceDelete` hanya untuk pengguna dengan role Admin. Controller menjalankan pemeriksaan melalui `Gate::authorize()`, sehingga pembatasan tetap berlaku walaupun URL dipanggil secara langsung.

Halaman tong sampah dan manajemen pengguna juga dilindungi middleware `role:Admin`.

### Modul 4: Membuat RESTful API

#### ✅ Tugas 4.1: Endpoint API Barang

Endpoint barang didaftarkan di `routes/api.php` dengan prefix `/api/v1`. Endpoint `GET /api/v1/barang` tersedia bersama endpoint tambah, detail, edit, hapus, dan transaksi stok.

#### ✅ Tugas 4.2: Format JSON dengan API Resource

Semua respons data barang menggunakan `app/Http/Resources/BarangResource.php`. Resource memberikan struktur JSON yang konsisten, meliputi identitas barang, stok, lokasi, path foto, URL foto, dan timestamp. Respons daftar juga menyertakan metadata pagination bawaan Laravel.

#### ✅ Tugas 4.3: Keamanan API Token (Laravel Sanctum)

Laravel Sanctum sudah terpasang dan model `User` memakai `HasApiTokens`. Token dibuat melalui `POST /api/v1/tokens`, sedangkan seluruh endpoint barang berada di dalam middleware `auth:sanctum`.

Contoh membuat dan memakai token:

```bash
curl -X POST http://127.0.0.1:8000/api/v1/tokens \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@logistikku.test","password":"password","device_name":"laptop"}'

curl http://127.0.0.1:8000/api/v1/barang \
  -H "Accept: application/json" \
  -H "Authorization: Bearer TOKEN_DARI_RESPONS_LOGIN"
```

### Ringkasan checklist

| Tugas | Status | Implementasi utama |
|---|:---:|---|
| 1.1 Form Request Validation | ✅ | `StoreBarangRequest`, `UpdateBarangRequest`, `UpdateStokRequest` |
| 1.2 Database Transaction | ✅ | `BarangService::updateStok()` |
| 1.3 Seeder dan Factory | ✅ | `BarangFactory`, `DatabaseSeeder`, 50 barang dummy |
| 2.1 Upload Foto | ✅ | Public storage, validasi gambar, accessor `foto_url` |
| 2.2 Export PDF dan Excel | ✅ | DOMPDF dan Laravel Excel |
| 2.3 Soft Deletes | ✅ | Trash, restore, dan force delete |
| 3.1 Role dan Permission | ✅ | Spatie; Admin, Staff Gudang, Manager |
| 3.2 Laravel Policy | ✅ | Hapus hanya untuk Admin |
| 4.1 Endpoint API | ✅ | `/api/v1/barang` |
| 4.2 API Resource | ✅ | `BarangResource` |
| 4.3 Sanctum | ✅ | Bearer token dan `auth:sanctum` |

## Fitur utama

- Login dan logout berbasis session, termasuk opsi **remember me**.
- Daftar barang dengan pencarian berdasarkan kode, nama, atau lokasi; filter kategori; pengurutan nama/stok; pagination; dan statistik inventaris.
- Tambah, lihat detail, edit, dan hapus barang, termasuk gambar katalog khusus per jenis barang serta unggah foto JPEG, PNG, JPG, atau WebP maksimal 2 MB.
- Pencatatan stok masuk/keluar beserta keterangan dan validasi ketersediaan stok.
- Riwayat transaksi stok dan daftar stok menipis (stok maksimal 5).
- Ekspor seluruh data barang ke PDF dan Excel.
- Soft delete, tong sampah, pemulihan, dan penghapusan permanen barang.
- Pengelolaan akun pengguna oleh Admin.
- REST API v1 berbasis Sanctum untuk CRUD barang, pagination/filter, dan transaksi stok.

## Role dan hak akses

Role dikelola melalui Spatie Laravel Permission. Kolom `users.role` juga menyimpan nilai ringkas (`admin`, `staff`, atau `manager`) untuk kebutuhan data/tampilan pengguna.

| Kemampuan | Admin | Staff Gudang | Manager |
|---|:---:|:---:|:---:|
| Melihat daftar/detail barang dan stok menipis | Ya | Ya | Ya |
| Menambah dan mengedit barang | Ya | Ya | Ya |
| Memperbarui dan melihat riwayat stok | Ya | Ya | Ya |
| Mengekspor PDF/Excel | Ya | Ya | Ya |
| Menghapus barang (soft delete) | Ya | Tidak | Tidak |
| Melihat tong sampah, memulihkan, dan menghapus permanen | Ya | Tidak | Tidak |
| Mengelola pengguna | Ya | Tidak | Tidak |

Semua halaman operasional barang memerlukan autentikasi. Pembatasan Admin pada tong sampah dan pengguna dilakukan oleh middleware `role:Admin`; operasi hapus, restore, dan force delete juga diperiksa oleh `BarangPolicy`.

## Struktur database

### Tabel domain utama

| Tabel | Kolom penting | Relasi/keterangan |
|---|---|---|
| `users` | `id`, `name`, `email` unik, `role`, `email_verified_at`, `password`, `remember_token`, timestamps | Model autentikasi; terhubung ke role Spatie dan token Sanctum. Nilai default `role` adalah `staff`. |
| `barang` | `id`, `kode_barang` unik, `nama_barang`, `kategori`, `stok`, `satuan`, `lokasi`, `foto_barang`, `created_at`, `deleted_at` | Stok default 0 dan menggunakan soft delete. Model menonaktifkan `updated_at`. |
| `stok_transactions` | `id`, `barang_id`, `jenis`, `jumlah`, `keterangan`, timestamps | Relasi ke `barang` dengan cascade delete; `jenis` adalah `masuk` atau `keluar`. Dipakai fitur riwayat stok. |

Tabel pendukung mencakup `password_reset_tokens`, `sessions`, `personal_access_tokens`; tabel otorisasi Spatie (`roles`, `permissions`, dan tabel pivot); serta tabel cache dan queue bawaan Laravel.

## Route web

| Method | URI | Akses | Fungsi |
|---|---|---|---|
| `ANY` | `/` | Publik | Redirect ke `/barang` |
| `GET` | `/login` | Guest | Form login |
| `POST` | `/login` | Guest | Proses login |
| `POST` | `/logout` | Login | Logout |
| `GET` | `/barang` | Login | Daftar, pencarian, filter, dan statistik |
| `GET` | `/barang/create` | Login | Form tambah barang |
| `POST` | `/barang` | Login | Simpan barang |
| `GET` | `/barang/low-stock` | Login | Daftar stok maksimal 5 |
| `GET` | `/barang-export/pdf` | Login | Unduh laporan PDF |
| `GET` | `/barang-export/excel` | Login | Unduh laporan Excel |
| `GET` | `/barang/{id}` | Login | Detail barang |
| `GET` | `/barang/{id}/edit` | Login | Form edit barang |
| `PUT` | `/barang/{id}` | Login | Perbarui barang |
| `DELETE` | `/barang/{id}` | Admin | Soft delete barang |
| `GET` | `/barang/{id}/stok` | Login | Form transaksi stok |
| `POST` | `/barang/{id}/stok` | Login | Simpan transaksi stok |
| `GET` | `/barang/{id}/riwayat-stok` | Login | Riwayat stok |
| `GET` | `/barang-trash` | Admin | Tong sampah |
| `POST` | `/barang/{id}/restore` | Admin | Pulihkan barang |
| `DELETE` | `/barang/{id}/force-delete` | Admin | Hapus permanen |
| `GET` | `/users` | Admin | Daftar pengguna |
| `GET` | `/users/create` | Admin | Form pengguna baru |
| `POST` | `/users` | Admin | Simpan pengguna |
| `GET` | `/users/{user}/edit` | Admin | Form edit pengguna |
| `PUT/PATCH` | `/users/{user}` | Admin | Perbarui pengguna |
| `DELETE` | `/users/{user}` | Admin | Hapus pengguna; akun sendiri tidak dapat dihapus |

Laravel juga menyediakan health check bawaan pada `GET /up`.

## Route API

| Method | URI | Akses | Fungsi |
|---|---|---|---|
| `POST` | `/api/v1/tokens` | Publik | Memvalidasi `email`, `password`, dan `device_name`, lalu membuat Bearer token Sanctum |
| `GET` | `/api/v1/barang` | `auth:sanctum` | Daftar barang dengan pagination, pencarian, filter, dan sorting |
| `POST` | `/api/v1/barang` | `auth:sanctum` | Menambahkan barang |
| `GET` | `/api/v1/barang/{barang}` | `auth:sanctum` | Detail barang |
| `PUT/PATCH` | `/api/v1/barang/{barang}` | `auth:sanctum` | Memperbarui barang |
| `DELETE` | `/api/v1/barang/{barang}` | Admin + `auth:sanctum` | Soft delete barang |
| `POST` | `/api/v1/barang/{barang}/stok` | `auth:sanctum` | Mencatat stok masuk atau keluar |

Contoh penggunaan:

```bash
curl -X POST http://localhost:8000/api/v1/tokens \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@logistikku.test","password":"password","device_name":"demo"}'

curl http://localhost:8000/api/v1/barang \
  -H "Accept: application/json" \
  -H "Authorization: Bearer TOKEN"
```

## Instalasi dan menjalankan project

Prasyarat: PHP 8.2 atau lebih baru, Composer, Node.js/npm, dan ekstensi PHP yang dipersyaratkan paket Composer.

1. Pasang dependency.

   ```bash
   composer install
   npm install
   ```

2. Salin konfigurasi dan buat application key.

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

   Pada Windows PowerShell, gunakan `Copy-Item .env.example .env` sebagai pengganti `cp`.

3. Konfigurasi database di `.env`. Konfigurasi contoh memakai SQLite; pastikan `database/database.sqlite` tersedia, atau ubah variabel `DB_*` untuk database lain.

4. Buat database dan data demo.

   ```bash
   php artisan migrate --seed
   ```

   Seeder membuat tiga akun demo dan 50 barang contoh.

5. Publikasikan foto dari disk `public`.

   ```bash
   php artisan storage:link
   ```

6. Jalankan aplikasi secara terpadu:

   ```bash
   composer run dev
   ```

   Perintah tersebut menjalankan server Laravel, queue listener, Pail, dan Vite. Alternatifnya:

   ```bash
   php artisan serve
   npm run dev
   ```

Aplikasi tersedia secara default di `http://127.0.0.1:8000`.

## Akun demo

Akun berikut dibuat oleh `DatabaseSeeder`; semuanya memakai password `password`.

| Role | Email |
|---|---|
| Admin | `admin@logistikku.test` |
| Staff Gudang | `staff@logistikku.test` |
| Manager | `manager@logistikku.test` |

Gunakan akun tersebut hanya untuk lingkungan pengembangan/demo dan ganti kredensial untuk penggunaan nyata.

## Struktur folder penting

```text
app/
├── Exports/                 # Penyusun data ekspor Excel
├── Http/
│   ├── Controllers/         # Autentikasi, barang, pengguna, dan API v1
│   ├── Middleware/          # Pemeriksaan role
│   ├── Requests/            # Validasi pembuatan barang
│   └── Resources/           # Format respons API barang
├── Models/                  # User, Barang, dan catatan stok
├── Policies/                # Otorisasi tindakan pada Barang
└── Services/                # Logika bisnis barang yang dipakai web dan API
database/
├── factories/               # Generator data contoh
├── migrations/              # Definisi tabel
└── seeders/                 # Role, akun demo, dan barang contoh
resources/views/             # Blade login, barang, pengguna, dan layout
routes/                      # Route web dan API
public/                      # Aset tema dan CSS publik
tests/Feature/               # Pengujian otorisasi barang dan API
```

## Kendala dan fitur yang belum selesai

- Hak akses Staff Gudang dan Manager saat ini sama pada route web; belum ada pembeda kemampuan.
- Role tersimpan dalam dua bentuk: kolom string `users.role` dan relasi Spatie. Controller pengguna menyinkronkan keduanya, sehingga struktur ganda ini perlu dijaga konsistensinya.
- API belum menyediakan endpoint untuk mencabut token.
- `welcome.blade.php` masih memuat tautan register/dashboard yang tidak memiliki route, tetapi view tersebut tidak dipakai karena `/` diarahkan ke `/barang`.
- `resources/views/barang/index.blade copy.php` tampak sebagai salinan dan tidak dirujuk controller.
- Belum tersedia registrasi mandiri, UI lupa/reset password, atau verifikasi email.
- Test bawaan Laravel (`ExampleTest`) masih ada di samping test fitur otorisasi dan API.
