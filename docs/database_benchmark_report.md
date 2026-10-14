# LAPORAN RESMI OPTIMASI PERFORMA DATABASE, INDEXING, & BENCHMARK 10.000 DATA
**Sistem Manajemen Inventaris & Logistik (LogistikKu / BCS Logistic)**  
*Dokumen Hasil Uji Tugas Penutup Bulan ke-2 (#7) & Modul 2*  
*Tanggal: 13 Oktober 2026*  
*Branch: `feature/database-optimization-and-benchmarking`*  
*Penyusun: Software Engineering Team*

---

## DAFTAR ISI
1. [Ringkasan Eksekutif](#1-ringkasan-eksekutif)
2. [Spesifikasi Lingkungan & Volume Dataset](#2-spesifikasi-lingkungan--volume-dataset)
3. [Analisis & Penambahan Index Database](#3-analisis--penambahan-index-database)
4. [Audit Query & Eliminasi Masalah N+1](#4-audit-query--eliminasi-masalah-n1)
5. [Strategi Caching Metrik Dashboard](#5-strategi-caching-metrik-dashboard)
6. [Analisis Query Execution Plan (EXPLAIN / EXPLAIN ANALYZE)](#6-analisis-query-execution-plan-explain--explain-analyze)
7. [Hasil Uji Benchmark Skala Besar (10.000 Data Transaksi)](#7-hasil-uji-benchmark-skala-besar-10000-data-transaksi)
8. [Analisis Dampak Performa & Rekomendasi](#8-analisis-dampak-performa--rekomendasi)
9. [Status Pengujian & Riwayat Commit](#9-status-pengujian--riwayat-commit)
10. [Audit Checklist & Prioritas Optimasi Lanjutan](#10-audit-checklist--prioritas-optimasi-lanjutan)

---

## 1. RINGKASAN EKSEKUTIF

Sebagai bagian dari standarisasi sistem inventaris skala enterprise, dilakukan pengujian beban dan optimasi komprehensif pada lapisan database, query Eloquent/SQL, dan caching aplikasi. 

Pada volume transaksi besar (>10.000 record), sistem sebelumnya memiliki potensi hambatan (*bottleneck*):
1. **Table Scan & Filesort**: Query laporan mutasi barang dan kartu stok menyaring data tanpa index komposit yang tepat, menyebabkan database membaca seluruh baris dan melakukan sorting manual di memori (*filesort*).
2. **Query N+1**: Iterasi perenderan daftar barang pada controller/view memicu pemanggilan query terpisah untuk setiap relasi supplier dan alokasi gudang.
3. **Agregasi Berulang**: Penghitungan total nilai valuasi inventaris (`SUM(stok * harga_beli)`) diproses berulang dari database setiap kali dashboard dimuat.

### Hasil Utama Optimasi:
* **Penambahan Composite Index**: Konsisten pada dua kali pengukuran, query filter + `ORDER BY created_at` untuk laporan mutasi per gudang membaca **93.8% lebih sedikit rows** (113 ke 7) dan 1.5x-1.8x lebih cepat; `EXPLAIN` menunjukkan `Using filesort` hilang. Query lain (kartu stok, valuasi per kategori, rekap gudang) selisih waktunya sub-milidetik dan berubah arah antar pengukuran (noise), sehingga **belum terbukti** lebih cepat pada skala data saat ini; lihat bagian 6.
* **Eliminasi N+1**: Pada benchmark terisolasi 50 model, eager loading mengurangi **98 query (97%)**, waktu eksekusi **82,17%**, dan memori **46,3%**. Test endpoint terpisah memastikan jumlah query tetap terbatas/O(1).
* **Caching Agregasi Valuasi**: Pada benchmark terisolasi, pemanggilan *warm cache* menjalankan **0 query database** dengan waktu **0,04 ms (296x)**; hasil produksi tetap perlu dipantau lewat metrik endpoint.
* **Integritas Test Suite**: status test penuh dicatat bersama tanggal dan command aktual pada bagian 9; test khusus optimasi juga mengaudit indeks seluruh foreign key secara otomatis.

---

## 2. SPESIFIKASI LINGKUNGAN & VOLUME DATASET

### A. Environment Sistem
* **Framework**: Laravel 12.68.0
* **Bahasa Pemrograman**: PHP 8.2.12 (CLI / FPM)
* **Database Produksi/Lokal**: MariaDB 10.4.32 / MySQL Community Server
* **Database Testing/CI**: SQLite 3.x (In-Memory Isolation)
* **Cache Driver**: File / Array (didukung universal invalidation)

### B. Profil Volume Data Uji Beban
Data dihasilkan menggunakan seeder otomatis [`LargeScaleStockTransactionSeeder`](../database/seeders/LargeScaleStockTransactionSeeder.php) yang memanfaatkan [`BarangFactory`](../database/factories/BarangFactory.php):
* **Total Transaksi Mutasi (`stok_transactions`)**: **10.406 baris** (jenis `masuk` dan `keluar`, tersebar 90 hari terakhir).
* **Master Barang (`barang`)**: **131 barang aktif & berharga beli**.
* **Alokasi Multi-Gudang (`warehouse_stocks`)**: **393 alokasi** (Gudang Pusat, Distribusi Barat, Distribusi Timur).
* **Mitra Vendor (`suppliers`)**: **10 supplier aktif**.

---

## 3. ANALISIS & PENAMBAHAN INDEX DATABASE

Melalui migration [`2026_10_13_000000_add_performance_and_analytics_indexes.php`](../database/migrations/2026_10_13_000000_add_performance_and_analytics_indexes.php), ditambahkan indeks komposit strategis yang dirancang ramah *rollback* dan kompatibel lintas database (MySQL & SQLite):

| Tabel | Nama Index | Kolom Index | Karakteristik Index | Tujuan & Alasan Bisnis |
|---|---|---|---|---|
| `stok_transactions` | `idx_stok_warehouse_jenis_created` | `(warehouse_stock_id, jenis, created_at)` | Composite B-Tree Index | Mempercepat laporan mutasi per gudang, filter jenis transaksi, dan pengurutan kronologis tanpa sorting manual. |
| `stok_transactions` | `idx_stok_barang_created` | `(barang_id, created_at)` | Composite B-Tree Index | Mempercepat pencarian kartu stok barang spesifik dalam rentang tanggal analitik 30/60/90 hari. |
| `barang` | `idx_barang_kategori_deleted_at` | `(kategori, deleted_at)` | Composite B-Tree Index | Mendukung filter kategori + status aktif. Pada agregasi seluruh kategori di dataset saat ini, manfaat waktunya belum terbukti. |
| `barang` | `idx_barang_harga_beli_stok` | `(harga_beli, stok)` | Composite B-Tree Index | Mendukung filter/range harga yang dilanjutkan kondisi stok. Index ini bukan jaminan percepatan `SUM(stok * harga_beli)` tanpa filter; benchmark saat ini belum membuktikan manfaat agregasinya. |
| `warehouse_stocks` | `idx_warehouse_stocks_wh_stok` | `(warehouse_id, stok)` | Composite Index | Mempercepat rekap saldo total stok fisik per gudang dan audit selisih saldo. |

### Hasil Audit Foreign Key
Migration lanjutan [`2026_10_14_000000_add_missing_foreign_key_indexes.php`](../database/migrations/2026_10_14_000000_add_missing_foreign_key_indexes.php) menambahkan leading index yang belum eksplisit, tetapi melewati pembuatan bila engine sudah menyediakan indeks setara. Test `PerformanceIndexesMigrationTest` memeriksa seluruh tabel secara otomatis: setiap kolom FK wajib menjadi kolom pertama pada setidaknya satu indeks. Relasi kritis laporan meliputi:
- `stok_transactions(barang_id)` -> Didukung `stok_transactions_barang_id_foreign` & `idx_stok_barang_created`
- `stok_transactions(warehouse_stock_id)` -> Didukung `stok_transactions_warehouse_stock_id_index` & `idx_stok_warehouse_jenis_created`
- `stok_transactions(supplier_id)` -> Didukung `stok_transactions_supplier_id_index` & `idx_stok_supplier_created`
- `warehouse_stocks(barang_id, warehouse_id)` -> Didukung `UNIQUE (barang_id, warehouse_id)`
- `barang(supplier_id)` -> Didukung `barang_supplier_id_index`

---

## 4. AUDIT QUERY & ELIMINASI MASALAH N+1

### A. Lokasi Temuan & Rekayasa Perbaikan
1. **Laporan Mutasi Stok ([`StockMutationReportService.php`](../app/Services/StockMutationReportService.php))**:
   - *Masalah*: Saat menampilkan ringkasan tabel barang, relasi `supplier` dimuat secara terpisah untuk setiap baris barang, dan membaca seluruh kolom database yang tidak relevan.
   - *Solusi*: Eager loading dengan proyeksi kolom ketat:
     ```php
     $barangQuery = Barang::query()
         ->with(['supplier' => fn ($query) => $query->select(['id', 'nama_supplier', 'kode_supplier', 'is_active', 'deleted_at'])])
         ->orderBy('nama_barang')
         ->orderBy('id');
     ```
2. **Pencarian & Daftar Inventaris ([`BarangController.php`](../app/Http/Controllers/BarangController.php))**:
   - *Masalah*: Rendering supplier, saldo gudang, dan master gudang berisiko memicu query relasi per barang bila relasi diakses secara lazy.
   - *Solusi*: Eager loading supplier serta relasi gudang bertingkat, sekaligus membatasi saldo gudang yang ditampilkan:
     ```php
     $query = Barang::query()->with([
         'supplier:id,nama_supplier,kode_supplier,is_active,deleted_at',
         'warehouseStocks' => fn ($stock) => $stock
             ->where('stok', '>', 0)
             ->with('warehouse:id,kode_gudang,nama_gudang,is_active,deleted_at'),
     ]);
     ```

### B. Perbandingan Benchmark Sintetis Eager Loading (50 Baris)

```
[SEBELUM]  101 Queries  ████████████████████████████████████████  (35.32 ms | 1.091 KB)
[SESUDAH]    3 Queries  █                                         (6.30 ms  |   585 KB)
```

Angka ini membandingkan loop lazy-loading dengan eager-loading pada benchmark terisolasi, bukan waktu respons penuh endpoint produksi.

* **Penurunan Jumlah Query**: Dari 101 query menjadi **3 query (97.0% query dieliminasi)**.
* **Waktu Respon Halaman**: Membaik dari 35.32 ms menjadi **6.30 ms (82.17% lebih cepat)**.
* **Konsumsi Memori**: Menurun dari 1.091 KB menjadi **585 KB (penghematan 46.3%)**.

---

## 5. STRATEGI CACHING METRIK DASHBOARD

Diimplementasikan pada [`InventoryAnalyticsService.php`](../app/Services/InventoryAnalyticsService.php) untuk metrik agregasi berat `valuationSummary()`:

### A. Desain Arsitektur Caching
1. **TTL (Time-to-Live)**: `VALUATION_CACHE_TTL = 900` (15 menit), menjamin efisiensi server tanpa mengorbankan relevansi laporan bisnis.
2. **Format Cache Key Terisolasi**:
   ```text
   inventory:analytics:valuation:v{version}:w_{warehouseId}:s_{supplierId}:c_{categoryMd5}
   ```
   *Contoh*: `inventory:analytics:valuation:v1:w_all:s_all:c_all` atau `inventory:analytics:valuation:v1:w_2:s_1:c_e4d9...`
3. **Invalidasi Universal Berbasis Versi (Tag-Free)**:
   Karena driver cache bawaan pada server lokal/staging umumnya adalah `file` atau `database` yang **tidak mendukung Cache Tags** (`Cache::tags()` memicu exception fatal), invalidasi dirancang melalui *version rotation*:
   ```php
   public static function clearValuationCache(): void
   {
       $current = (int) Cache::get('inventory:analytics:valuation:version', 1);
       Cache::forever('inventory:analytics:valuation:version', $current + 1);
   }
   ```
   Menaikkan nilai versi global secara instan membuat seluruh key lama kedaluwarsa secara atomik tanpa perlu melakukan scan file cache.
4. **Otomatisasi Model Lifecycle Hook**:
   - `Barang`: event `saved`, `deleted`, `restored`
   - `WarehouseStock`: event `saved`, `deleted`
   - `StokTransaction`: event `created`, `updated`, `deleted`
   Jalur aplikasi yang menyimpan lewat model akan menginvalidasi cache. Operasi bulk langsung melalui `DB::table()` tidak memicu event Eloquent dan wajib memanggil invalidasi secara eksplisit; ini menjadi batasan operasional yang perlu dijaga pada seeder/import baru.
5. **Keamanan Data**:
   Cache hanya menyimpan skalar numerik ringkasan agregat (`total_nilai`, `total_unit`, `kategori_count`). Tidak ada data pengguna (PII, user tokens) yang tersimpan di cache.

---

## 6. ANALISIS QUERY EXECUTION PLAN (EXPLAIN / EXPLAIN ANALYZE)

Pengujian dilakukan langsung pada database MariaDB/MySQL dengan **10.406 baris transaksi mutasi stok** melalui perintah:
```bash
php artisan db:explain-analyze --iterations=10
```

Nama command dipertahankan untuk kompatibilitas, tetapi metode aktualnya adalah `EXPLAIN` untuk rencana/estimasi rows dan `hrtime()` di aplikasi untuk waktu respons query. Jadi angka di bawah bukan output server-side `EXPLAIN ANALYZE`. Setiap sisi mendapat tiga warm-up lalu sepuluh pengukuran; hasil masih sensitif terhadap cache dan urutan eksekusi, sehingga dua run disajikan dan selisih sub-milidetik tidak dijadikan kesimpulan.

### Tabel Perbandingan Kinerja Nyata (14 Oktober 2026, 10 iterasi, dua kali run pada data yang sama):

Bukti output mentah (run 2): [`docs/images/explain-analyze-output.txt`](images/explain-analyze-output.txt).

| Query Pengujian | Run 1 Sebelum → Sesudah | Run 2 Sebelum → Sesudah | Key Sesudah | Rows Sebelum → Sesudah |
|---|---|---|---|---|
| **1. Laporan Mutasi per Gudang & Jenis** | 1.722 → 0.959 ms (**1.8x**) | 0.767 → 0.503 ms (**1.52x**) | `idx_stok_warehouse_jenis_created` | 113 → **7 (-93.8%)** |
| **2. Kartu Stok Barang** | 1.982 → 1.676 ms (1.18x) | 0.856 → 0.859 ms (1.0x) | `idx_stok_barang_created` | 172 → **74 (-56.4%)** |
| **3. Valuasi per Kategori** | 0.92 → 1.504 ms (0.61x) | 0.575 → 0.568 ms (1.01x) | `idx_barang_kategori_deleted_at` | 131 → 131 |
| **4. Rekap Mutasi Gudang & Jenis** | 0.947 → 1.321 ms (0.72x) | 0.72 → 0.625 ms (1.15x) | `idx_stok_warehouse_jenis_created` | 113 → 113 |

> **Catatan jujur:** Hanya query 1 yang konsisten lebih cepat di kedua run, dan query 1 juga satu-satunya yang `EXPLAIN`-nya menunjukkan perubahan jelas (rows 113 → 7, `filesort` hilang). Query 2 mengurangi rows (172 → 74) tetapi waktunya sama dalam run 2. Query 3 dan 4 berubah arah antar run (0.61x vs 1.01x; 0.72x vs 1.15x): selisih sub-milidetik pada tabel kecil (131 baris barang; 113 baris per gudang) adalah noise pengukuran, bukan bukti index memperlambat atau mempercepat. Manfaat index pada query tersebut baru dapat dibuktikan pada volume data yang jauh lebih besar. Angka versi awal laporan ini (query 3 dan 4 "1.8x-2.1x lebih cepat") tidak dapat direproduksi dan sudah diganti.



### Bukti Diagnostik Engine `EXPLAIN`:

```sql
-- KASUS 1: SEBELUM MENGGUNAKAN COMPOSITE INDEX
EXPLAIN SELECT * FROM stok_transactions WHERE warehouse_stock_id = 96 AND jenis = 'masuk' ORDER BY created_at DESC;
-- Hasil:
-- Key: stok_transactions_warehouse_stock_id_foreign | Type: ref | Rows: 113
-- Extra: Using where; Using filesort  <== BOTTLENECK: Operasi filesort membebani CPU/Buffer!

-- KASUS 1: SESUDAH MENGGUNAKAN COMPOSITE INDEX
EXPLAIN SELECT * FROM stok_transactions WHERE warehouse_stock_id = 96 AND jenis = 'masuk' ORDER BY created_at DESC;
-- Hasil:
-- Key: idx_stok_warehouse_jenis_created | Type: range | Rows: 7
-- Extra: Using where  <== OPTIMAL: filesort HILANG TOTAL, data terbaca langsung secara sekuensial!
```

---

## 7. HASIL UJI BENCHMARK SKALA BESAR (10.000 DATA TRANSAKSI)

Hasil eksekusi dari automated benchmark suite [`InventoryAnalyticsPerformanceBenchmark.php`](../tests/Benchmark/InventoryAnalyticsPerformanceBenchmark.php):

### A. Uji Caching Valuasi Inventaris
* **Cold Cache (Perhitungan DB Pertama)**: Waktu eksekusi **12.36 ms** (5 query database).
* **Warm Cache (Dilayani dari Cache Memory)**: Waktu eksekusi **0.042 ms** (**0 query database**).
* **Dampak**: Peningkatan kecepatan respon sebesar **296x lebih cepat**, beban CPU database terpangkas **100%**.

### B. Uji Operasional Kueri Utama pada Volume 10.000 Data
* `StockMutationReportService::summary`: **18.41 ms** (7 queries terisolasi, memori 186 KB).
* `StockMutationReportService::paginatedRows` (25 data): **8.45 ms** (8 queries terisolasi, memori 136 KB).
* `InventoryAnalyticsService::paginatedValuationWarehouses`: **5.30 ms** (2 queries agregasi, memori 10 KB).

---

## 8. ANALISIS DAMPAK PERFORMA & REKOMENDASI

### A. Komponen dengan Dampak Terbesar:
1. **Eliminasi N+1 (Dampak User Experience Terbesar)**:
   Pada koneksi jaringan nyata antara web server dan database server (misal jeda 2-5 ms per network round-trip), memangkas 98 query langsung menghemat 200–500 ms waktu tunggu perenderan halaman oleh pengguna.
2. **Penghapusan Operasi `Using filesort` pada Query Filter + ORDER BY**:
   Index komposit `(warehouse_stock_id, jenis, created_at)` dan `(barang_id, created_at)` menjaga urutan `created_at` langsung dari index sehingga sorting manual di memori tidak diperlukan. Dampak pada dataset 10.406 baris masih kecil dalam milidetik; manfaatnya diperkirakan membesar seiring pertumbuhan volume data.
   *Keterbatasan:* index `(kategori, deleted_at)` dan agregasi per gudang tidak menunjukkan percepatan yang konsisten pada tabel kecil saat ini (hasil berubah arah antar run). Index tersebut dipertahankan untuk antisipasi pertumbuhan data, tetapi manfaatnya belum terbukti pada skala sekarang.
3. **Caching Metrik Valuasi**:
   Mencegah kalkulasi perkalian matriks harga x stok saat dashboard diakses secara bersamaan oleh banyak pengguna (*high concurrency*).

### B. Rekomendasi Operasional Lanjutan:
* **Pemeliharaan Indeks Berbasis Bukti**: Pantau slow-query log, ukuran tabel/index, dan statistik engine. `OPTIMIZE TABLE` membangun ulang tabel InnoDB dan dapat mahal; jalankan hanya setelah fragmentasi/ruang terbuang terbukti serta ada jendela pemeliharaan, bukan sebagai jadwal bulanan otomatis.
* **Pembersihan Log Analitik**: Transaksi mutasi lama (>2 tahun) yang telah direkonsiliasi tutup buku dapat diarsipkan ke tabel partisi historis untuk menjaga ukuran indeks aktif tetap pas di memori RAM server.

---

## 9. STATUS PENGUJIAN & RIWAYAT COMMIT

### A. Status Automated Test Suite
Pengujian penuh terakhir dijalankan pada 14 Oktober 2026 menggunakan `php vendor/bin/phpunit --do-not-cache-result --colors=never`:
```text
   PASS  Tests\Unit\...
   PASS  Tests\Feature\...
   PASS  Tests\Feature\LargeScaleSeederAndExplainAnalyzeTest
   PASS  Tests\Feature\PerformanceIndexesMigrationTest
   PASS  Tests\Feature\DashboardValuationCacheTest
   PASS  Tests\Feature\StockMutationReportTest
   PASS  Tests\Feature\AnalyticsPageTest

   Tests:    371 passed (4360 assertions)
   Duration: 68.424s
   Status:   100% HIJAU (PASSING)
```

### B. Daftar Commit Terstruktur pada Branch `feature/database-optimization-and-benchmarking`:
1. `bd1e1e3` — `perf: add database indexes for analytics queries` *(Migrasi indeks performa)*
2. `fb6f4a6` — `perf: optimize report queries with eager loading` *(Eliminasi query N+1)*
3. `1583665` — `perf: cache dashboard aggregate metrics` *(Caching valuasi & auto-invalidation)*
4. `157ecf0` — `test: add 10000-row database benchmark` *(Skrip benchmark 10k data)*
5. `0971bed` — `feat: add large-scale 10000-row seeder and query execution plan analysis command` *(BarangFactory, Seeder 10k, & Command EXPLAIN ANALYZE)*

## 10. AUDIT CHECKLIST & PRIORITAS OPTIMASI LANJUTAN

### A. Status Checklist Minggu Ke-4

| Item | Status | Bukti / Catatan Audit |
|---|---|---|
| Kerapian repository | **Selesai** | Commit `d722d04` menghapus dump SQL dan 1.000+ artefak profil browser dari tracking. `.gitignore` mencakup `*.sql`, `storage/recovery/`, dan profil browser. |
| 4.1 Analisis kolom & composite index | **Selesai** | Empat index wajib tersedia; index gudang tambahan tersedia. Migration lanjutan dan test skema memastikan seluruh FK memiliki leading index tanpa membuat duplikat jika engine sudah menyediakan indeks setara. |
| 4.2 Audit anti-N+1 | **Selesai** | Eager loading/proyeksi relasi diterapkan pada daftar inventaris dan laporan mutasi. Test halaman membatasi jumlah query agar tetap O(1) terhadap jumlah baris. |
| 4.2 Cache agregasi | **Selesai** | `Cache::remember()` memakai TTL 900 detik (15 menit), key terisolasi per filter, dan invalidasi versi pada perubahan barang/saldo/transaksi. Warm-cache diverifikasi menghasilkan nol query DB. |
| 4.3 Seeder 10.000+ transaksi | **Selesai** | Seeder default 10.000 transaksi, memakai `BarangFactory`, batch insert 1.000, dan dibatasi untuk environment local/testing. |
| 4.4 Execution plan & perbandingan | **Selesai dengan keterbatasan terdokumentasi** | `EXPLAIN` + timing sebelum/sesudah tersedia. Query 1 menunjukkan bukti konsisten; hasil query lain dinyatakan noise, bukan dipaksakan sebagai peningkatan. |
| 4.5 Laporan resmi & bukti | **Selesai** | Laporan, tabel indeks, analisis, dan output mentah tersedia. File teks adalah bukti angka yang dapat diaudit; screenshot terminal hanya diperlukan bila format portal/mentor mewajibkan gambar. |
| Branch dan commit | **Selesai** | Branch sesuai: `feature/database-optimization-and-benchmarking`; perubahan disusun dalam commit terpisah. |
| Push dan Pull Request | **Ditunda sesuai instruksi** | Tidak dilakukan pada audit ini. |

### B. Prioritas Optimasi Berikutnya

1. **P0 — Perkuat metodologi benchmark sebelum membuat klaim tambahan.** Uji 100.000–1.000.000 transaksi dengan distribusi yang membuat satu barang/gudang memiliki ribuan baris, jalankan urutan index/non-index secara bergantian, dan laporkan median serta p95. Jika engine mendukung, tambahkan server-side `EXPLAIN ANALYZE` (MySQL 8) atau `ANALYZE FORMAT=JSON` (MariaDB) untuk memisahkan waktu server dari overhead aplikasi.
2. **P1 — Validasi biaya tulis dan manfaat index yang belum terbukti.** `idx_barang_harga_beli_stok` belum terbukti mempercepat agregasi tanpa filter. Bandingkan ukuran index serta latency insert/update sebelum mempertahankan atau memperlebar index. Evaluasi `(deleted_at, kategori)` untuk agregasi seluruh barang aktif dan `(warehouse_stock_id, created_at, jenis)` untuk range tanggal, tetapi tambahkan hanya bila execution plan skala besar membuktikan manfaatnya.
3. **P1 — Aktifkan observability query produksi.** Gunakan slow-query log dan metrik p50/p95/p99 endpoint agar optimasi diarahkan ke query yang benar-benar mahal. Tetapkan ambang awal, misalnya 200 ms, lalu tinjau query teratas secara berkala.
4. **P2 — Profilkan ringkasan dashboard di luar valuasi.** Kartu jumlah barang, total stok, low-stock, serta prediksi saat ini masih memakai beberapa agregasi terpisah. Gabungkan atau cache hanya jika profiling menunjukkan endpoint dashboard menjadi bottleneck; invalidasi harus tetap diuji.
5. **P2 — Jaga ekspor besar tetap streaming/chunked.** Saat data tumbuh, hindari memuat seluruh laporan ke memori. Ukur peak memory dan durasi ekspor CSV/PDF pada 100.000+ transaksi, lalu gunakan cursor/chunk untuk format yang mendukung streaming.

---
*Dokumen ini diterbitkan sebagai bukti otentik pengujian performa sistem inventaris dan siap diajukan untuk review teknis.*
