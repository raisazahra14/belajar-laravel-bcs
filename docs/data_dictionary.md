# Data Dictionary Database LogistikKu

Dokumen ini mencatat skema fisik database MySQL/MariaDB `db_logistik` setelah implementasi Supplier dan Multi-Gudang. Audit dilakukan pada 17 September 2026 secara read-only terhadap `information_schema.TABLES`, `COLUMNS`, `STATISTICS`, `KEY_COLUMN_USAGE`, `REFERENTIAL_CONSTRAINTS`, dan `CHECK_CONSTRAINTS`, kemudian dibandingkan dengan seluruh migration, model Eloquent, dan [`docs/database/erd.md`](database/erd.md).

Skema aktual adalah sumber utama untuk tipe, nullable, default, PK, FK, indeks, dan referential action. Perbedaan terhadap migration atau model dicatat secara eksplisit pada bagian ketidaksesuaian.

## Daftar isi

- [Cakupan dan legenda](#cakupan-dan-legenda)
- [Ringkasan relasi utama](#ringkasan-relasi-utama)
- [Tabel bisnis dan aplikasi](#tabel-bisnis-dan-aplikasi)
  - [`users`](#users)
  - [`suppliers`](#suppliers)
  - [`warehouses`](#warehouses)
  - [`warehouse_stocks`](#warehouse_stocks)
  - [`barang`](#barang)
  - [`stok_transactions`](#stok_transactions)
  - [`stok_histories`](#stok_histories)
  - [`document_verifications`](#document_verifications)
  - [`document_verification_audits`](#document_verification_audits)
  - [`notifications`](#notifications)
  - [`stock_predictions`](#stock_predictions)
  - [`stock_prediction_notifications`](#stock_prediction_notifications)
  - [`stock_prediction_notification_reads`](#stock_prediction_notification_reads)
  - [`stock_prediction_processes`](#stock_prediction_processes)
- [Tabel framework dan internal](#tabel-framework-dan-internal)
- [Ringkasan constraint dan referential action](#ringkasan-constraint-dan-referential-action)
- [Ketidaksesuaian hasil audit](#ketidaksesuaian-hasil-audit)
- [Rekonsiliasi dengan ERD](#rekonsiliasi-dengan-erd)

## Cakupan dan legenda

Database aktual memiliki **22 tabel dan 201 kolom**: **14 tabel bisnis/aplikasi dengan 159 kolom** serta **8 tabel framework/internal dengan 42 kolom**. Seluruh tabel memakai InnoDB dan collation `utf8mb4_unicode_ci`, kecuali kolom JSON fisik yang memakai `utf8mb4_bin`.

| Istilah | Arti |
|---|---|
| PK | Primary key. |
| FK | Foreign key yang benar-benar ada sebagai constraint database. |
| UK | Unique key/index. |
| IDX | Index non-unique. |
| AI | `AUTO_INCREMENT`. |
| NN | `NOT NULL`. |
| `NULL` | Kolom boleh kosong; default `NULL` berarti database mengisi `NULL` bila nilai tidak diberikan. |
| Soft delete | Penghapusan logis melalui `deleted_at`; tidak menjalankan referential action FK. |
| Referensi logis | Nilai menunjuk entitas lain secara konvensi/aplikasi, tetapi **tanpa FK database**. |
| `RESTRICT` | Perubahan/penghapusan induk ditolak selama record anak masih merujuknya. |
| `CASCADE` | Penghapusan induk otomatis menghapus record anak. |
| `SET NULL` | Penghapusan induk mempertahankan anak dan mengosongkan FK nullable. |
| `—` pada default | Tidak ada klausa default; berbeda dari default eksplisit `NULL`. |

Tipe di bawah adalah representasi fisik `COLUMN_TYPE` database aktual. Pada MariaDB, kolom yang dibuat migration sebagai `json` disimpan sebagai `longtext` dengan check `json_valid(...)`.

## Ringkasan relasi utama

- Supplier dapat menjadi supplier utama banyak barang melalui `barang.supplier_id`, serta supplier asal banyak transaksi melalui `stok_transactions.supplier_id`. Keduanya nullable dan menjadi `NULL` ketika supplier dihapus permanen.
- Satu barang dapat memiliki banyak saldo gudang dan satu gudang dapat memiliki banyak saldo barang. `warehouse_stocks` menjadi tabel penghubung, dengan UK komposit (`barang_id`, `warehouse_id`) sehingga satu pasangan barang–gudang hanya memiliki satu saldo.
- `warehouse_stocks.stok` adalah saldo per gudang. `barang.stok` tetap dipertahankan sebagai **stok total/legacy** untuk kompatibilitas fitur lama dan input prediksi. Migration Multi-Gudang membuat `GDG-UTAMA`, menyalin stok lama ke sana, dan menghubungkan transaksi lama.
- `stok_transactions` adalah ledger utama mutasi. FK ke barang dan saldo gudang memakai `ON DELETE RESTRICT`; supplier opsional memakai `SET NULL`. Snapshot sebelum/sesudah menggambarkan total legacy pada workflow saat ini.
- Barang menjadi induk histori lama, prediksi, notifikasi prediksi, dan state proses prediksi. Sebagian besar relasi prediksi memakai `CASCADE` saat barang dihapus permanen.
- Pengguna memiliki dokumen verifikasi; verifikasi memiliki audit. Notifikasi Laravel ke pengguna bersifat polymorphic tanpa FK.
- `stock_prediction_processes.source_transaction_id` adalah referensi logis ke transaksi sumber, bukan FK. Integritasnya tidak dijaga database.

## Tabel bisnis dan aplikasi

### `users`

Fungsi: akun pengguna, peran aplikasi, pemilik dokumen, aktor analisis/audit, peminta proses, dan penerima notifikasi.

PK: `id` (AI). UK: `users_email_unique` (`email`). Soft delete: tidak.

| Kolom | Tipe | Null | Default | Kunci/referensi | Keterangan |
|---|---|---:|---|---|---|
| `id` | `bigint(20) unsigned` | Tidak | — | PK, AI | Identitas pengguna. |
| `name` | `varchar(255)` | Tidak | — | — | Nama pengguna. |
| `email` | `varchar(255)` | Tidak | — | UK | Alamat login unik. |
| `role` | `varchar(255)` | Tidak | `'staff'` | — | Peran aplikasi. |
| `email_verified_at` | `timestamp` | Ya | `NULL` | — | Waktu verifikasi email. |
| `password` | `varchar(255)` | Tidak | — | — | Hash kata sandi. |
| `remember_token` | `varchar(100)` | Ya | `NULL` | — | Token remember-me. |
| `created_at` | `timestamp` | Ya | `NULL` | — | Waktu dibuat. |
| `updated_at` | `timestamp` | Ya | `NULL` | — | Waktu diperbarui. |

Aturan penting: model menyembunyikan `password`/`remember_token`, mencast password sebagai hash, dan menyediakan relasi langsung hanya ke dokumen; relasi lain tetap dijamin oleh FK database walaupun inverse Eloquent tidak seluruhnya tersedia.

### `suppliers`

Fungsi: master supplier aktif/nonaktif untuk supplier utama barang dan supplier asal mutasi stok.

PK: `id` (AI). UK: `suppliers_kode_supplier_unique` (`kode_supplier`). Soft delete: ya, `deleted_at`.

| Kolom | Tipe | Null | Default | Kunci/referensi | Keterangan |
|---|---|---:|---|---|---|
| `id` | `bigint(20) unsigned` | Tidak | — | PK, AI | Identitas supplier. |
| `kode_supplier` | `varchar(255)` | Tidak | — | UK | Kode supplier unik. |
| `nama_supplier` | `varchar(255)` | Tidak | — | — | Nama supplier. |
| `contact_person` | `varchar(255)` | Ya | `NULL` | — | Narahubung. |
| `telepon` | `varchar(255)` | Ya | `NULL` | — | Nomor telepon. |
| `email` | `varchar(255)` | Ya | `NULL` | — | Email supplier; tidak unik. |
| `alamat` | `text` | Ya | `NULL` | — | Alamat supplier. |
| `is_active` | `tinyint(1)` | Tidak | `1` | — | Status aktif boolean. |
| `created_at` | `timestamp` | Ya | `NULL` | — | Waktu dibuat. |
| `updated_at` | `timestamp` | Ya | `NULL` | — | Waktu diperbarui. |
| `deleted_at` | `timestamp` | Ya | `NULL` | Soft delete | Waktu penghapusan logis. |

Aturan penting: soft delete tidak mengubah FK anak. Force delete mengubah `barang.supplier_id` dan `stok_transactions.supplier_id` menjadi `NULL` karena `ON DELETE SET NULL`.

### `warehouses`

Fungsi: master gudang aktif/nonaktif untuk penempatan saldo per barang.

PK: `id` (AI). UK: `warehouses_kode_gudang_unique` (`kode_gudang`). Soft delete: ya, `deleted_at`.

| Kolom | Tipe | Null | Default | Kunci/referensi | Keterangan |
|---|---|---:|---|---|---|
| `id` | `bigint(20) unsigned` | Tidak | — | PK, AI | Identitas gudang. |
| `kode_gudang` | `varchar(255)` | Tidak | — | UK | Kode gudang unik. |
| `nama_gudang` | `varchar(255)` | Tidak | — | — | Nama gudang. |
| `alamat` | `text` | Ya | `NULL` | — | Alamat gudang. |
| `keterangan` | `text` | Ya | `NULL` | — | Catatan gudang. |
| `is_active` | `tinyint(1)` | Tidak | `1` | — | Status aktif boolean. |
| `created_at` | `timestamp` | Ya | `NULL` | — | Waktu dibuat. |
| `updated_at` | `timestamp` | Ya | `NULL` | — | Waktu diperbarui. |
| `deleted_at` | `timestamp` | Ya | `NULL` | Soft delete | Waktu penghapusan logis. |

Aturan penting: migration membuat gudang default `GDG-UTAMA` secara idempotent. Force delete gudang ditolak bila masih memiliki `warehouse_stocks` karena `ON DELETE RESTRICT`.

### `warehouse_stocks`

Fungsi: saldo dan ambang minimum suatu barang pada satu gudang.

PK: `id` (AI). UK: `warehouse_stocks_barang_id_warehouse_id_unique` (`barang_id`, `warehouse_id`). IDX implisit/pendukung FK: sisi kiri UK melayani `barang_id`; `warehouse_stocks_warehouse_id_foreign` (`warehouse_id`). Soft delete: tidak.

| Kolom | Tipe | Null | Default | Kunci/referensi | Keterangan |
|---|---|---:|---|---|---|
| `id` | `bigint(20) unsigned` | Tidak | — | PK, AI | Identitas saldo gudang. |
| `barang_id` | `bigint(20) unsigned` | Tidak | — | FK → `barang.id`; delete/update `RESTRICT` | Barang pemilik saldo; bagian UK komposit. |
| `warehouse_id` | `bigint(20) unsigned` | Tidak | — | FK → `warehouses.id`; delete/update `RESTRICT` | Gudang penyimpan; bagian UK komposit. |
| `stok` | `int(10) unsigned` | Tidak | `0` | — | Saldo aktual di gudang; nonnegatif melalui tipe unsigned. |
| `stok_minimum` | `int(10) unsigned` | Tidak | `0` | — | Ambang stok minimum gudang; nonnegatif. |
| `created_at` | `timestamp` | Ya | `NULL` | — | Waktu dibuat. |
| `updated_at` | `timestamp` | Ya | `NULL` | — | Waktu diperbarui. |

Aturan penting: pasangan barang–gudang wajib unik. Penghapusan permanen barang/gudang atau saldo yang masih direferensikan transaksi ditahan dengan `RESTRICT`.

### `barang`

Fungsi: master barang, stok total/legacy, supplier utama, atribut penyimpanan lama, dan input estimasi prediksi.

PK: `id` (AI). UK: `barang_kode_barang_unique` (`kode_barang`). IDX: `barang_supplier_id_foreign` (`supplier_id`). Soft delete: ya, `deleted_at`. Model menonaktifkan `updated_at` karena kolom tersebut tidak ada.

| Kolom | Tipe | Null | Default | Kunci/referensi | Keterangan |
|---|---|---:|---|---|---|
| `id` | `bigint(20) unsigned` | Tidak | — | PK, AI | Identitas barang. |
| `supplier_id` | `bigint(20) unsigned` | Ya | `NULL` | FK → `suppliers.id`; delete `SET NULL`, update `RESTRICT` | Supplier utama opsional. |
| `kode_barang` | `varchar(255)` | Tidak | — | UK | Kode unik; model mencegah perubahan setelah tersimpan. |
| `nama_barang` | `varchar(255)` | Tidak | — | — | Nama barang. |
| `kategori` | `varchar(255)` | Tidak | — | — | Kategori aplikasi. |
| `stok` | `int(10) unsigned` | Tidak | `0` | CHECK `ck_barang_stok_nn` | **Stok total/legacy**, bukan saldo satu gudang; wajib `>= 0`. |
| `daily_usage_estimate` | `decimal(10,2)` | Ya | `NULL` | — | Estimasi konsumsi harian. |
| `lead_time_days` | `smallint(5) unsigned` | Ya | `NULL` | — | Lead time pemasokan dalam hari. |
| `satuan` | `varchar(255)` | Tidak | — | — | Satuan barang. |
| `lokasi` | `varchar(255)` | Tidak | — | — | Lokasi legacy/deskriptif. |
| `foto_barang` | `varchar(255)` | Ya | `NULL` | — | Path foto barang. |
| `created_at` | `timestamp` | Ya | `NULL` | — | Waktu dibuat. |
| `deleted_at` | `timestamp` | Ya | `NULL` | Soft delete | Waktu penghapusan logis. |

Aturan penting: saldo per gudang berada di `warehouse_stocks`; `barang.stok` tetap menjadi agregat/legacy. Soft delete tidak memicu FK. Force delete ditolak bila masih ada transaksi/saldo karena FK `RESTRICT`; controller juga memeriksa dependensi bisnis lain sebelum force delete.

### `stok_transactions`

Fungsi: ledger mutasi stok masuk/keluar, supplier asal, saldo gudang asal, dan snapshot stok total sebelum/sesudah.

PK: `id` (AI). IDX: `stok_transactions_barang_id_foreign` (`barang_id`), `stok_transactions_supplier_id_foreign` (`supplier_id`), `stok_transactions_warehouse_stock_id_foreign` (`warehouse_stock_id`), `idx_stok_barang_created` (`barang_id`, `created_at`), dan `idx_stok_barang_jenis_created` (`barang_id`, `jenis`, `created_at`). Soft delete: tidak.

| Kolom | Tipe | Null | Default | Kunci/referensi | Keterangan |
|---|---|---:|---|---|---|
| `id` | `bigint(20) unsigned` | Tidak | — | PK, AI | Identitas transaksi. |
| `barang_id` | `bigint(20) unsigned` | Tidak | — | FK → `barang.id`; delete/update `RESTRICT` | Barang wajib; histori tidak boleh menjadi yatim. |
| `supplier_id` | `bigint(20) unsigned` | Ya | `NULL` | FK → `suppliers.id`; delete `SET NULL`, update `RESTRICT` | Supplier asal opsional. |
| `warehouse_stock_id` | `bigint(20) unsigned` | Ya | `NULL` | FK → `warehouse_stocks.id`; delete/update `RESTRICT` | Saldo gudang terkait; nullable untuk kompatibilitas data lama. |
| `jenis` | `enum('masuk','keluar')` | Tidak | — | — | Arah mutasi. |
| `jumlah` | `int(11)` | Tidak | — | CHECK `ck_stok_jumlah_pos` | Kuantitas mutasi, wajib `> 0`. |
| `stok_sebelum` | `int(10) unsigned` | Ya | `NULL` | CHECK snapshot | Stok total legacy sebelum mutasi. |
| `stok_sesudah` | `int(10) unsigned` | Ya | `NULL` | CHECK snapshot | Stok total legacy sesudah mutasi. |
| `keterangan` | `text` | Ya | `NULL` | — | Catatan transaksi. |
| `created_at` | `timestamp` | Ya | `NULL` | — | Waktu transaksi dibuat. |
| `updated_at` | `timestamp` | Ya | `NULL` | — | Waktu diperbarui. |

Aturan penting: kedua snapshot harus sama-sama `NULL` atau sama-sama terisi, harus nonnegatif, dan harus memenuhi `sesudah = sebelum + jumlah` untuk masuk atau `sebelum = sesudah + jumlah` untuk keluar. Implementasi terbaru memperbarui saldo gudang dan total legacy dalam satu transaksi database.

### `stok_histories`

Fungsi: histori mutasi stok lama/alternatif yang masih memiliki model dan FK aktual.

PK: `id` (AI). IDX: `stok_histories_barang_id_foreign` (`barang_id`). Soft delete: tidak.

| Kolom | Tipe | Null | Default | Kunci/referensi | Keterangan |
|---|---|---:|---|---|---|
| `id` | `bigint(20) unsigned` | Tidak | — | PK, AI | Identitas histori. |
| `barang_id` | `bigint(20) unsigned` | Tidak | — | FK → `barang.id`; delete `CASCADE`, update `RESTRICT` | Barang terkait. |
| `jenis` | `enum('masuk','keluar')` | Tidak | — | — | Arah mutasi. |
| `jumlah` | `int(11)` | Tidak | — | — | Kuantitas; tidak memiliki CHECK positif aktual. |
| `keterangan` | `varchar(255)` | Ya | `NULL` | — | Catatan. |
| `created_at` | `timestamp` | Ya | `NULL` | — | Waktu dibuat. |
| `updated_at` | `timestamp` | Ya | `NULL` | — | Waktu diperbarui. |

Aturan penting: berbeda dari ledger utama, tabel ini tidak memiliki supplier, gudang, snapshot, atau CHECK matematika. Penghapusan permanen barang menghapus histori ini secara cascade.

### `document_verifications`

Fungsi: dokumen yang diproses OCR, metadata hasil ekstraksi, skor, status proses, status keaslian, dan koreksi OCR.

PK: `id` (AI). IDX: (`user_id`, `created_at`), (`process_status`, `created_at`), (`authenticity_status`, `created_at`), serta index tunggal pada `document_number`, `document_date`, `purchase_order_number`, `sender`, `recipient`, `vehicle_number`, dan `ocr_corrected_by`. Soft delete: tidak.

| Kolom | Tipe | Null | Default | Kunci/referensi | Keterangan |
|---|---|---:|---|---|---|
| `id` | `bigint(20) unsigned` | Tidak | — | PK, AI | Identitas verifikasi. |
| `user_id` | `bigint(20) unsigned` | Tidak | — | FK → `users.id`; delete `CASCADE`, update `RESTRICT` | Pengunggah/pemilik. |
| `document_type` | `varchar(30)` | Tidak | — | — | Jenis dokumen. |
| `original_filename` | `varchar(255)` | Tidak | — | — | Nama file asli. |
| `file_path` | `varchar(255)` | Tidak | — | — | Path penyimpanan. |
| `process_status` | `varchar(20)` | Tidak | `'menunggu'` | IDX komposit | Status proses OCR. |
| `authenticity_status` | `varchar(20)` | Ya | `NULL` | IDX komposit | Hasil keaslian setelah proses selesai. |
| `readability_score` | `tinyint(3) unsigned` | Tidak | `0` | — | Skor keterbacaan. |
| `completeness_score` | `tinyint(3) unsigned` | Tidak | `0` | — | Skor kelengkapan. |
| `authenticity_score` | `tinyint(3) unsigned` | Tidak | `50` | — | Skor keaslian. |
| `overall_score` | `tinyint(3) unsigned` | Tidak | `0` | — | Skor keseluruhan. |
| `message` | `text` | Tidak | — | — | Ringkasan hasil. |
| `analysis_details` | `longtext` | Tidak | — | CHECK `json_valid` | JSON fisik MariaDB. |
| `extracted_metadata` | `longtext` | Ya | `NULL` | CHECK `json_valid` | Metadata JSON hasil ekstraksi. |
| `error_message` | `text` | Ya | `NULL` | — | Pesan kegagalan. |
| `created_at` | `timestamp` | Ya | `NULL` | — | Waktu dibuat. |
| `updated_at` | `timestamp` | Ya | `NULL` | — | Waktu diperbarui. |
| `document_number` | `varchar(255)` | Ya | `NULL` | IDX | Nomor dokumen. |
| `document_date` | `date` | Ya | `NULL` | IDX | Tanggal dokumen. |
| `purchase_order_number` | `varchar(255)` | Ya | `NULL` | IDX | Nomor PO. |
| `sender` | `varchar(255)` | Ya | `NULL` | IDX | Pengirim. |
| `recipient` | `varchar(255)` | Ya | `NULL` | IDX | Penerima. |
| `vehicle_number` | `varchar(30)` | Ya | `NULL` | IDX | Nomor kendaraan. |
| `total_items` | `int(10) unsigned` | Ya | `NULL` | — | Jumlah item hasil OCR. |
| `ocr_raw_text` | `longtext` | Ya | `NULL` | — | Teks OCR mentah. |
| `ocr_corrected_at` | `timestamp` | Ya | `NULL` | — | Waktu koreksi OCR. |
| `ocr_corrected_by` | `bigint(20) unsigned` | Ya | `NULL` | FK → `users.id`; delete `SET NULL`, update `RESTRICT` | Korektor OCR. |
| `do_number` | `varchar(255)` | Ya | `NULL` | — | Nomor delivery order; tidak berindeks. |

Aturan penting: model membatasi `process_status` pada `menunggu`, `diproses`, `selesai`, atau `gagal`. `authenticity_status` hanya boleh terisi ketika proses selesai dan bernilai `asli`, `mencurigakan`, atau `palsu`. Aturan status ini berada di event model, bukan CHECK database.

### `document_verification_audits`

Fungsi: jejak append-only perubahan/proses verifikasi dokumen dengan idempotency key.

PK: `id` (AI). UK: `document_verification_audits_idempotency_key_unique` (`idempotency_key`). IDX: `verification_audit_timeline` (`document_verification_id`, `created_at`), (`event`, `created_at`), (`source`, `created_at`), serta FK index `user_id`. Soft delete: tidak; `updated_at` tidak ada.

| Kolom | Tipe | Null | Default | Kunci/referensi | Keterangan |
|---|---|---:|---|---|---|
| `id` | `bigint(20) unsigned` | Tidak | — | PK, AI | Identitas audit. |
| `document_verification_id` | `bigint(20) unsigned` | Tidak | — | FK → `document_verifications.id`; delete `CASCADE`, update `RESTRICT` | Verifikasi induk. |
| `user_id` | `bigint(20) unsigned` | Ya | `NULL` | FK → `users.id`; delete `SET NULL`, update `RESTRICT` | Aktor opsional. |
| `event` | `varchar(60)` | Tidak | — | IDX komposit | Nama kejadian. |
| `source` | `varchar(20)` | Tidak | — | IDX komposit | Sumber kejadian. |
| `before_values` | `longtext` | Ya | `NULL` | CHECK `json_valid` | JSON nilai sebelumnya. |
| `after_values` | `longtext` | Ya | `NULL` | CHECK `json_valid` | JSON nilai sesudah. |
| `changed_fields` | `longtext` | Ya | `NULL` | CHECK `json_valid` | JSON daftar perubahan. |
| `confidence` | `longtext` | Ya | `NULL` | CHECK `json_valid` | JSON confidence. |
| `extraction_status` | `longtext` | Ya | `NULL` | CHECK `json_valid` | JSON status ekstraksi. |
| `technical_metadata` | `longtext` | Ya | `NULL` | CHECK `json_valid` | JSON metadata teknis. |
| `idempotency_key` | `varchar(191)` | Tidak | — | UK | Pencegah pencatatan audit ganda. |
| `created_at` | `timestamp` | Tidak | `current_timestamp()` | — | Waktu audit. |

### `notifications`

Fungsi: notifikasi database Laravel yang dipakai aplikasi, termasuk hasil verifikasi dokumen.

PK: `id` (UUID, bukan AI). IDX: `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`, `notifiable_id`). Tidak ada FK dan tidak ada soft delete.

| Kolom | Tipe | Null | Default | Kunci/referensi | Keterangan |
|---|---|---:|---|---|---|
| `id` | `char(36)` | Tidak | — | PK | UUID notifikasi. |
| `type` | `varchar(255)` | Tidak | — | — | Class/jenis notifikasi. |
| `notifiable_type` | `varchar(255)` | Tidak | — | Referensi polymorphic logis, IDX | Tipe penerima. |
| `notifiable_id` | `bigint(20) unsigned` | Tidak | — | Referensi polymorphic logis, IDX | ID penerima; tanpa FK. |
| `data` | `text` | Tidak | — | Referensi payload logis | Payload JSON secara konvensi/cast Laravel; ID dokumen di dalam payload bukan FK. |
| `read_at` | `timestamp` | Ya | `NULL` | — | Waktu dibaca. |
| `created_at` | `timestamp` | Ya | `NULL` | — | Waktu dibuat. |
| `updated_at` | `timestamp` | Ya | `NULL` | — | Waktu diperbarui. |

### `stock_predictions`

Fungsi: snapshot hasil prediksi kebutuhan/restock barang pada waktu analisis tertentu.

PK: `id` (AI). UK: `uq_pred_barang_generation` (`barang_id`, `process_generation`). IDX: (`barang_id`, `analyzed_at`), (`status`, `analyzed_at`), dan `analyzed_by`. Soft delete: tidak.

| Kolom | Tipe | Null | Default | Kunci/referensi | Keterangan |
|---|---|---:|---|---|---|
| `id` | `bigint(20) unsigned` | Tidak | — | PK, AI | Identitas prediksi. |
| `barang_id` | `bigint(20) unsigned` | Tidak | — | FK → `barang.id`; delete `CASCADE`, update `RESTRICT` | Barang yang dianalisis. |
| `analyzed_by` | `bigint(20) unsigned` | Ya | `NULL` | FK → `users.id`; delete `SET NULL`, update `RESTRICT` | Pengguna analis. |
| `process_generation` | `bigint(20) unsigned` | Ya | `NULL` | UK komposit | Generasi proses; nilai `NULL` dapat berulang menurut semantik unique MySQL. |
| `current_stock` | `int(10) unsigned` | Tidak | — | — | Snapshot stok saat analisis. |
| `predicted_30_day_need` | `decimal(12,2)` | Ya | `NULL` | — | Prediksi kebutuhan 30 hari. |
| `predicted_minimum_date` | `date` | Ya | `NULL` | — | Perkiraan tanggal menyentuh minimum. |
| `predicted_depletion_date` | `date` | Ya | `NULL` | — | Perkiraan tanggal habis. |
| `safety_stock` | `int(10) unsigned` | Ya | `NULL` | — | Safety stock. |
| `recommended_restock` | `int(10) unsigned` | Tidak | `0` | — | Rekomendasi restock. |
| `status` | `varchar(30)` | Tidak | — | IDX komposit | Status prediksi. |
| `method` | `varchar(30)` | Ya | `NULL` | — | Metode prediksi. |
| `analysis_status` | `varchar(40)` | Tidak | `'completed'` | — | Status analisis. |
| `metrics` | `longtext` | Ya | `NULL` | CHECK `json_valid` | JSON metrik. |
| `input_summary` | `longtext` | Ya | `NULL` | CHECK `json_valid` | JSON ringkasan input. |
| `analyzed_at` | `timestamp` | Tidak | `current_timestamp()` | `ON UPDATE current_timestamp()` | Waktu analisis; perilaku fisik berbeda dari deklarasi migration. |
| `created_at` | `timestamp` | Ya | `NULL` | — | Waktu dibuat. |
| `updated_at` | `timestamp` | Ya | `NULL` | — | Waktu diperbarui. |

### `stock_prediction_notifications`

Fungsi: notifikasi bisnis yang dihasilkan oleh prediksi untuk kombinasi prediksi, barang, dan status.

PK: `id` (AI). UK: `prediction_notification_unique` (`stock_prediction_id`, `barang_id`, `status`). IDX: `stock_prediction_notifications_barang_id_foreign` (`barang_id`) dan (`read_at`, `created_at`). Soft delete: tidak.

| Kolom | Tipe | Null | Default | Kunci/referensi | Keterangan |
|---|---|---:|---|---|---|
| `id` | `bigint(20) unsigned` | Tidak | — | PK, AI | Identitas notifikasi. |
| `stock_prediction_id` | `bigint(20) unsigned` | Tidak | — | FK → `stock_predictions.id`; delete `CASCADE`, update `RESTRICT`; UK | Prediksi sumber. |
| `barang_id` | `bigint(20) unsigned` | Tidak | — | FK → `barang.id`; delete `CASCADE`, update `RESTRICT`; UK | Barang subjek. |
| `status` | `varchar(30)` | Tidak | — | UK komposit | Status pemicu. |
| `read_at` | `timestamp` | Ya | `NULL` | IDX komposit | Status baca global/legacy. |
| `created_at` | `timestamp` | Ya | `NULL` | IDX komposit | Waktu dibuat. |
| `updated_at` | `timestamp` | Ya | `NULL` | — | Waktu diperbarui. |

Aturan penting: status baca per pengguna sekarang berada di `stock_prediction_notification_reads`; `read_at` di tabel ini masih dipertahankan sebagai field legacy/global.

### `stock_prediction_notification_reads`

Fungsi: status baca notifikasi prediksi per pengguna.

PK: `id` (AI). IDX aktual hanya `prediction_read_notification_fk` (`stock_prediction_notification_id`) dan `prediction_read_user_fk` (`user_id`). **Tidak ada UK pasangan notifikasi–pengguna di database aktual.** Soft delete: tidak.

| Kolom | Tipe | Null | Default | Kunci/referensi | Keterangan |
|---|---|---:|---|---|---|
| `id` | `bigint(20) unsigned` | Tidak | — | PK, AI | Identitas receipt. |
| `stock_prediction_notification_id` | `bigint(20) unsigned` | Tidak | — | FK → `stock_prediction_notifications.id`; delete `CASCADE`, update `RESTRICT` | Notifikasi. |
| `user_id` | `bigint(20) unsigned` | Tidak | — | FK → `users.id`; delete `CASCADE`, update `RESTRICT` | Penerima. |
| `read_at` | `timestamp` | Ya | `NULL` | — | Waktu dibaca. |
| `created_at` | `timestamp` | Ya | `NULL` | — | Waktu dibuat. |
| `updated_at` | `timestamp` | Ya | `NULL` | — | Waktu diperbarui. |

### `stock_prediction_processes`

Fungsi: satu state proses prediksi aktif per barang, termasuk generasi, peminta, transaksi pemicu, hasil, dan error.

PK: `id` (AI). UK: `stock_prediction_processes_barang_id_unique` (`barang_id`). IDX: `requested_by`, `stock_prediction_id`, dan `idx_pred_process_status` (`status`, `updated_at`). Soft delete: tidak.

| Kolom | Tipe | Null | Default | Kunci/referensi | Keterangan |
|---|---|---:|---|---|---|
| `id` | `bigint(20) unsigned` | Tidak | — | PK, AI | Identitas state proses. |
| `barang_id` | `bigint(20) unsigned` | Tidak | — | FK → `barang.id`; delete `CASCADE`, update `RESTRICT`; UK | Tepat maksimal satu state per barang. |
| `requested_by` | `bigint(20) unsigned` | Ya | `NULL` | FK → `users.id`; delete `SET NULL`, update `RESTRICT` | Peminta proses. |
| `status` | `varchar(20)` | Tidak | `'waiting'` | IDX komposit | State proses. |
| `generation` | `bigint(20) unsigned` | Tidak | `1` | — | Nomor generasi proses. |
| `source_transaction_id` | `bigint(20) unsigned` | Ya | `NULL` | **Referensi logis** → `stok_transactions.id`; tanpa FK/index | Transaksi pemicu. |
| `stock_prediction_id` | `bigint(20) unsigned` | Ya | `NULL` | FK → `stock_predictions.id`; delete `SET NULL`, update `RESTRICT` | Prediksi hasil. |
| `error_message` | `varchar(255)` | Ya | `NULL` | — | Pesan kegagalan. |
| `started_at` | `timestamp` | Ya | `NULL` | — | Waktu mulai. |
| `completed_at` | `timestamp` | Ya | `NULL` | — | Waktu selesai. |
| `created_at` | `timestamp` | Ya | `NULL` | — | Waktu dibuat. |
| `updated_at` | `timestamp` | Ya | `NULL` | IDX komposit | Waktu diperbarui. |

Aturan penting: status model adalah `waiting`, `processing`, `completed`, atau `failed`, tetapi tidak ada CHECK status di database. `stock_prediction_id` tidak unik, sehingga database mengizinkan banyak process menunjuk prediksi yang sama.

## Tabel framework dan internal

Tabel berikut mendukung cache, queue, migration, reset password, dan session Laravel. Tidak ada soft delete atau FK database pada delapan tabel ini. `sessions.user_id` dan `password_reset_tokens.email` adalah referensi logis saja.

### `cache`

Fungsi: penyimpanan cache database. PK: `key`; tanpa AI/UK/IDX lain.

| Kolom | Tipe | Null | Default | Kunci | Keterangan |
|---|---|---:|---|---|---|
| `key` | `varchar(255)` | Tidak | — | PK | Kunci cache. |
| `value` | `mediumtext` | Tidak | — | — | Nilai serialisasi. |
| `expiration` | `int(11)` | Tidak | — | — | Waktu kedaluwarsa epoch. |

### `cache_locks`

Fungsi: distributed lock untuk cache database. PK: `key`; tanpa AI/UK/IDX lain.

| Kolom | Tipe | Null | Default | Kunci | Keterangan |
|---|---|---:|---|---|---|
| `key` | `varchar(255)` | Tidak | — | PK | Kunci lock. |
| `owner` | `varchar(255)` | Tidak | — | — | Token pemilik lock. |
| `expiration` | `int(11)` | Tidak | — | — | Waktu kedaluwarsa epoch. |

### `failed_jobs`

Fungsi: arsip job queue yang gagal. PK: `id` (AI). UK: `failed_jobs_uuid_unique` (`uuid`).

| Kolom | Tipe | Null | Default | Kunci | Keterangan |
|---|---|---:|---|---|---|
| `id` | `bigint(20) unsigned` | Tidak | — | PK, AI | Identitas kegagalan. |
| `uuid` | `varchar(255)` | Tidak | — | UK | UUID job. |
| `connection` | `text` | Tidak | — | — | Nama koneksi. |
| `queue` | `text` | Tidak | — | — | Nama antrean. |
| `payload` | `longtext` | Tidak | — | — | Payload job. |
| `exception` | `longtext` | Tidak | — | — | Exception. |
| `failed_at` | `timestamp` | Tidak | `current_timestamp()` | — | Waktu gagal. |

### `jobs`

Fungsi: antrean job database. PK: `id` (AI). IDX: `jobs_queue_index` (`queue`).

| Kolom | Tipe | Null | Default | Kunci | Keterangan |
|---|---|---:|---|---|---|
| `id` | `bigint(20) unsigned` | Tidak | — | PK, AI | Identitas job. |
| `queue` | `varchar(255)` | Tidak | — | IDX | Nama antrean. |
| `payload` | `longtext` | Tidak | — | — | Payload job. |
| `attempts` | `tinyint(3) unsigned` | Tidak | — | — | Jumlah percobaan. |
| `reserved_at` | `int(10) unsigned` | Ya | `NULL` | — | Waktu reservasi epoch. |
| `available_at` | `int(10) unsigned` | Tidak | — | — | Waktu tersedia epoch. |
| `created_at` | `int(10) unsigned` | Tidak | — | — | Waktu dibuat epoch. |

### `job_batches`

Fungsi: metadata batch job Laravel. PK: `id` string; tanpa AI/UK/IDX lain.

| Kolom | Tipe | Null | Default | Kunci | Keterangan |
|---|---|---:|---|---|---|
| `id` | `varchar(255)` | Tidak | — | PK | ID batch. |
| `name` | `varchar(255)` | Tidak | — | — | Nama batch. |
| `total_jobs` | `int(11)` | Tidak | — | — | Jumlah job. |
| `pending_jobs` | `int(11)` | Tidak | — | — | Job tertunda. |
| `failed_jobs` | `int(11)` | Tidak | — | — | Job gagal. |
| `failed_job_ids` | `longtext` | Tidak | — | — | Daftar ID job gagal. |
| `options` | `mediumtext` | Ya | `NULL` | — | Opsi batch. |
| `cancelled_at` | `int(11)` | Ya | `NULL` | — | Waktu pembatalan epoch. |
| `created_at` | `int(11)` | Tidak | — | — | Waktu dibuat epoch. |
| `finished_at` | `int(11)` | Ya | `NULL` | — | Waktu selesai epoch. |

### `migrations`

Fungsi: catatan migration Laravel yang sudah dijalankan. PK: `id` (AI); tanpa UK/IDX lain.

| Kolom | Tipe | Null | Default | Kunci | Keterangan |
|---|---|---:|---|---|---|
| `id` | `int(10) unsigned` | Tidak | — | PK, AI | Identitas catatan. |
| `migration` | `varchar(255)` | Tidak | — | — | Nama migration. |
| `batch` | `int(11)` | Tidak | — | — | Nomor batch. |

### `password_reset_tokens`

Fungsi: token reset kata sandi. PK: `email`; tanpa AI/UK/IDX lain dan tanpa FK.

| Kolom | Tipe | Null | Default | Kunci | Keterangan |
|---|---|---:|---|---|---|
| `email` | `varchar(255)` | Tidak | — | PK; referensi logis → `users.email` | Email pemilik token; tidak dijaga FK. |
| `token` | `varchar(255)` | Tidak | — | — | Token reset. |
| `created_at` | `timestamp` | Ya | `NULL` | — | Waktu dibuat. |

### `sessions`

Fungsi: penyimpanan session database. PK: `id`. IDX: `sessions_user_id_index` (`user_id`) dan `sessions_last_activity_index` (`last_activity`). Tanpa AI/FK.

| Kolom | Tipe | Null | Default | Kunci | Keterangan |
|---|---|---:|---|---|---|
| `id` | `varchar(255)` | Tidak | — | PK | ID session. |
| `user_id` | `bigint(20) unsigned` | Ya | `NULL` | IDX; referensi logis → `users.id` | Pengguna opsional; bukan FK. |
| `ip_address` | `varchar(45)` | Ya | `NULL` | — | IPv4/IPv6. |
| `user_agent` | `text` | Ya | `NULL` | — | User agent. |
| `payload` | `longtext` | Tidak | — | — | Payload session. |
| `last_activity` | `int(11)` | Tidak | — | IDX | Waktu aktivitas epoch. |

## Ringkasan constraint dan referential action

### Primary key, unique, dan index

- Semua 22 tabel memiliki PK: **16 PK AI** dan **6 PK non-AI** (`cache.key`, `cache_locks.key`, `job_batches.id`, `notifications.id`, `password_reset_tokens.email`, dan `sessions.id`).
- UK bisnis: `users.email`, `suppliers.kode_supplier`, `warehouses.kode_gudang`, pasangan `warehouse_stocks(barang_id, warehouse_id)`, `barang.kode_barang`, `document_verification_audits.idempotency_key`, pasangan `stock_predictions(barang_id, process_generation)`, tripel notifikasi prediksi, dan `stock_prediction_processes.barang_id`.
- UK internal tambahan: `failed_jobs.uuid`. Database aktual tidak mempunyai UK pasangan pada `stock_prediction_notification_reads` walaupun migration mendeklarasikannya.
- Semua indeks yang tercatat di tiap bagian adalah BTREE aktual. Index FK dapat juga dilayani oleh sisi kiri UK komposit, seperti `warehouse_stocks.barang_id`.

### Foreign key

Terdapat **20 FK database aktual**. Semuanya memakai `ON UPDATE RESTRICT`.

| ON DELETE | Jumlah | FK |
|---|---:|---|
| `CASCADE` | 9 | `document_verifications.user_id`; `document_verification_audits.document_verification_id`; `stock_predictions.barang_id`; kedua FK `stock_prediction_notifications`; kedua FK `stock_prediction_notification_reads`; `stock_prediction_processes.barang_id`; `stok_histories.barang_id`. |
| `SET NULL` | 7 | `barang.supplier_id`; `document_verifications.ocr_corrected_by`; `document_verification_audits.user_id`; `stock_predictions.analyzed_by`; `stock_prediction_processes.requested_by`; `stock_prediction_processes.stock_prediction_id`; `stok_transactions.supplier_id`. |
| `RESTRICT` | 4 | `stok_transactions.barang_id`; `stok_transactions.warehouse_stock_id`; `warehouse_stocks.barang_id`; `warehouse_stocks.warehouse_id`. |

### Referensi logis tanpa FK

- `stock_prediction_processes.source_transaction_id` → `stok_transactions.id`.
- `notifications.notifiable_type` + `notifications.notifiable_id` → model penerima polymorphic.
- ID verifikasi atau objek lain di `notifications.data` → entitas aplikasi di dalam payload.
- `sessions.user_id` → `users.id`.
- `password_reset_tokens.email` → `users.email`.

## Ketidaksesuaian hasil audit

1. Migration `2026_09_02_010000_create_stock_prediction_notification_reads_table.php` mendeklarasikan UK `prediction_notification_user_unique` pada (`stock_prediction_notification_id`, `user_id`) dan index (`user_id`, `read_at`). Keduanya **tidak ada** di database aktual; yang ada hanya dua index FK tunggal non-unique. Duplikasi pasangan notifikasi–pengguna secara fisik masih mungkin.
2. `stock_predictions.analyzed_at` dideklarasikan migration sebagai `timestamp('analyzed_at')`, tetapi pada MariaDB 10.4.32 aktual mempunyai `DEFAULT current_timestamp()` dan `ON UPDATE current_timestamp()`. Model hanya mencast kolom sebagai datetime dan tidak mengungkap side effect `ON UPDATE` tersebut.
3. Kolom yang dibuat migration sebagai `json` tersimpan secara fisik sebagai `longtext` ber-collation `utf8mb4_bin` dengan CHECK `json_valid(...)`. Ini perbedaan representasi MariaDB, bukan kehilangan validasi.
4. Model `StockPrediction::process()` mendeklarasikan `hasOne`, tetapi `stock_prediction_processes.stock_prediction_id` tidak unik. Database memungkinkan lebih dari satu proses menunjuk prediksi yang sama.
5. `StockPredictionProcess` mencast `source_transaction_id` sebagai integer, tetapi tidak mempunyai FK, index, atau relasi Eloquent ke `stok_transactions`. Referensi tersebut hanya logis.
6. Beberapa FK aktual tidak memiliki relasi inverse Eloquent: antara lain aktor/korektor/analis/peminta pada `User`, dan `StockPredictionProcess` ke `requested_by`/`stock_prediction_id`. Sebaliknya `StokHistory::barang()` ada tetapi `Barang` tidak mempunyai inverse `stokHistories()`.
7. `stock_prediction_notifications.read_at` masih menyimpan status baca legacy/global, sementara model dan tabel receipt terbaru memakai `stock_prediction_notification_reads` untuk status per pengguna.
8. Migration awal transaksi stok memakai cascade delete dan migration Multi-Gudang sempat membuat relasi nullable/`SET NULL`; migration koreksi `2026_09_16_030000_enforce_stock_history_item_integrity.php` menghasilkan kontrak akhir aktual yang wajib/`RESTRICT` untuk `stok_transactions.barang_id` serta `RESTRICT` untuk saldo gudang. Dokumentasi ini memakai kondisi akhir aktual.
9. `stok_histories` dan `stok_transactions` sama-sama menyimpan mutasi, tetapi hanya `stok_transactions` memiliki supplier, gudang, snapshot, dan CHECK integritas. Keduanya tetap aktual dan bermodel, sehingga keduanya didokumentasikan.

Hal yang selaras: `Barang` memakai nama tabel nonstandar, soft delete, dan `UPDATED_AT = null`; Supplier dan Warehouse memakai soft delete; seluruh `belongsTo` yang dideklarasikan memiliki kolom/FK yang sesuai; relasi Multi-Gudang pada Barang, Warehouse, WarehouseStock, Supplier, dan StokTransaction sesuai skema fisik.

## Rekonsiliasi dengan ERD

Jumlah tabel bisnis, seluruh 20 FK, nullable, UK pasangan barang–gudang, aturan `RESTRICT`/`SET NULL`, peran `barang.stok` sebagai stok total/legacy, serta referensi logis `source_transaction_id` sesuai dengan [`docs/database/erd.md`](database/erd.md). ERD memang sengaja tidak menggambar delapan tabel framework/internal, tetapi inventaris ERD mencatat keberadaannya; kamus data ini melengkapinya dengan seluruh kolom aktual.

ERD juga sudah mencerminkan ketidaksesuaian penting database aktual: tidak adanya UK receipt per pengguna, perilaku fisik `analyzed_at`, bentuk fisik JSON MariaDB, dan kardinalitas model-vs-database untuk process prediksi. Tidak ditemukan tabel aktual yang hilang dari inventaris ERD.
