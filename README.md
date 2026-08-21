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
