# PORTOFOLIO PROGRES & EVALUASI RESMI MAGANG
## Program MagangHub Kementerian Ketenagakerjaan Republik Indonesia (Kemnaker RI)
### Proyek: Rekayasa Perangkat Lunak & Pengembangan Sistem Manajemen Inventaris Terpadu (LogistikKu)

---

### Informasi Eksekutif & Identitas Magang
- **Nama Peserta Magang / Engineer**: Software Engineer Intern
- **Program**: Magang Industri Bersertifikat — MagangHub Kemnaker RI (Durasi Penuh: 6 Bulan)
- **Unit Kerja / Penempatan**: Tim Engineering & Logistik (`itbcslogistics`)
- **Nama Aplikasi / Produk**: **LogistikKu** (Sistem Manajemen Inventaris, Pengadaan Multi-Gudang & Analitik Logistik Terpadu)
- **Tech Stack Utama**: PHP 8.2+, Laravel 12, MySQL/MariaDB & SQLite, Python 3.10+ (NumPy, Scikit-Learn, OpenCV, Tesseract OCR, PyMuPDF), Bootstrap/SkyDash UI, Vite, DomPDF, PHPUnit 11, Laravel Pint.
- **Periode Pelaksanaan**: **Agustus 2026 s/d Januari/Februari 2027 (Total Durasi 6 Bulan)**
- **Status Evaluasi Saat Ini**: **Laporan Capaian Tengah Periode (Bulan ke-1 s/d Bulan ke-3) — 100% SELESAI & LULUS UJI REGRESI (439/439 Tests PASS)**
- **Sifat Dokumen**: **Living Portfolio Document** (Akan terus diperbarui secara berkala hingga evaluasi akhir penutupan Bulan ke-6).

---

## 1. Ringkasan Eksekutif (Executive Summary)

Dokumen ini merupakan **Portofolio Rekayasa Perangkat Lunak Berkala (*Living Portfolio*)** yang disusun sebagai instrumen evaluasi kinerja resmi untuk program **MagangHub Kementerian Ketenagakerjaan Republik Indonesia (Kemnaker RI)** selama 6 (enam) bulan.

Pada milestone **Evaluasi Tengah Periode (Bulan ke-1 hingga Bulan ke-3 / Agustus – Oktober 2026)**, peserta magang bersama kolaborator industri (`itbcslogistics`) telah berhasil mentransformasi aplikasi internal **LogistikKu** dari sistem inventaris dasar menjadi platform rantai pasok dan pergudangan terpadu (*Enterprise Logistics Platform*).

### Capaian Inti Paruh Waktu (Bulan 1 s/d 3):
1. **Bulan 1 (Agustus 2026)**: Fondasi *Clean Code* (FormRequest, DB::transaction, Seeder 50 data), fitur bisnis lapangan (Upload Foto, Export PDF/Excel, Soft Deletes & Trash), kontrol hak akses 3 role (Admin, Manager, Staff), RESTful API berotentikasi Sanctum, jembatan integrasi Laravel–Python untuk deteksi keaslian dokumen via OCR Tesseract & ELA, serta Batch Import massal Excel dengan Upsert otomatis (GitHub Issue #1 & #2).
2. **Bulan 2 (September s/d Awal Oktober 2026)**: Penyusunan spesifikasi formal SRS (v1.1 - v1.4) berstandar industri dengan diagram UML/Activity, restrukturisasi skema relasional multi-gudang dan multi-supplier, implementasi analitik bisnis canggih (mutasi periodik, klasifikasi FSN Fast/Slow/Dead Stock, valuasi aset inventaris), serta uji beban dan benchmarking 10.000 data transaksi dengan optimasi composite indexing dan caching agregasi (GitHub Issue #4, #5, dan #7).
3. **Bulan 3 (Oktober 2026)**: Pembangunan modul pengadaan terpadu (*Purchase Order & Goods Receipt Multi-Gudang*) berbasis *State Machine* 7 status, penegakan tata kelola *Separation of Duty*, refactoring arsitektur bersih (*Zero Raw SQL*, DTO, API Resource, standardisasi PSR-12 Laravel Pint), mitigasi konkurensi tingkat enterprise (*pessimistic locking*, penguncian deterministik anti-deadlock, *atomic cache lock* anti-double submit), serta pengujian regresi penuh (**439 automated tests 100% HIJAU** tanpa regresi) (GitHub Issue #9).

---

## 2. Peta Jalan 6 Bulan Penuh (Master Timeline 6-Month Journey)

```text
+--------------------------------------------------------------------------------------------------------------------+
|                         PETA JALAN RESMI 6 BULAN MAGANGHUB KEMNAKER RI (LOGISTIKKU)                                |
+--------------------------------------------------------------------------------------------------------------------+
|  [TEREALISASI 100% - EVALUASI TENGAH PERIODE (MID-TERM)]                                                           |
|  • BULAN 1 (Agustus 2026)   : Clean Code Dasar, RESTful API & Integrasi Python OCR Keaslian Dokumen                |
|  • BULAN 2 (September 2026) : SRS Resmi, Multi-Gudang, Analitik Bisnis & Uji Beban 10.000 Transaksi (Benchmark)   |
|  • BULAN 3 (Oktober 2026)   : Modul Pengadaan (Purchase Order & Goods Receipt), Concurrency & 439 Tests Green      |
+--------------------------------------------------------------------------------------------------------------------+
|  [RENCANA KERJA LANJUTAN MENUJU BULAN KE-6 (UPCOMING SECOND HALF)]                                                 |
|  • BULAN 4 (November 2026)  : Modul Distribusi & Pengiriman (Delivery Order, Surat Jalan & Dispatching Multi-Armada) |
|  • BULAN 5 (Desember 2026)  : Notifikasi Real-Time (WebSockets), Audit Logging Terdistribusi, & Multi-Cabang       |
|  • BULAN 6 (Januari 2027)   : Containerization (Docker), CI/CD Deployment, Security Hardening & Laporan Final Akhir|
+--------------------------------------------------------------------------------------------------------------------+
```

---

## 3. Realisasi Progres Mendalam (Bulan 1 s/d Bulan 3)

### 3.1 BULAN KE-1 (Agustus 2026): Clean Code, RESTful API & Integrasi AI Python
*Berdasarkan Arahan Resmi GitHub Issue #1 & Issue #2 (`itbcslogistics`)*

- **Modul 1: Merapikan Kode (Clean Code Standard)**
  - Pemisahan aturan validasi dari Controller ke FormRequest mandiri (`StoreBarangRequest`, `UpdateBarangRequest`).
  - Penjaminan sifat atomik pembaruan stok dan buku besar transaksi dengan pembungkusan `DB::transaction()`.
  - Pembuatan generator data tiruan `BarangFactory` dan `DatabaseSeeder` untuk menyuplai 50 data master siap uji.
- **Modul 2: Fitur Bisnis Nyata (Real-World Features)**
  - Pengelolaan berkas media gambar produk (`foto_barang`) dengan penyimpanan privat/publik via `php artisan storage:link`.
  - Fitur ekspor persediaan ke format PDF (`barryvdh/laravel-dompdf`) dan spreadsheet Excel (`maatwebsite/excel`).
  - Implementasi *Soft Deletes* (Tong Sampah) untuk pemulihan (*restore*) atau pembersihan (*force delete*) aman.
- **Modul 3: Hak Akses & Kebijakan Otorisasi**
  - Pembagian 3 peran kerja: Admin (pengelola master), Manager (pemantau & pengambil keputusan), dan Staff (pelaksana operasional).
  - Pembatasan aksi destruktif hanya untuk peran Admin melalui Laravel Policy dan Gate.
- **Modul 4: Pembuatan RESTful API Berstandar Industri**
  - Endpoint `GET /api/v1/barang` dengan transformasi format JSON konsisten menggunakan `BarangResource`.
  - Pengamanan rute API menggunakan otentikasi Bearer Token (Laravel Sanctum).
- **Modul 5: Mesin Python Deteksi Keaslian Dokumen (Document Verification)**
  - Membangun script analisis cerdas di `python/document_checker.py` dengan tiga metode: Metadata EXIF/PDF Analysis, Image Error Level Analysis (ELA) untuk deteksi editan visual, dan Tesseract OCR Text Consistency Check.
  - Integrasi jembatan proses asinkronus menggunakan `Symfony\Component\Process\Process` dari Laravel Service Layer.
  - Tampilan UI verifikasi dokumen dengan badge visual (🟢 Valid/Asli, 🟡 Perlu Ditinjau, 🔴 Terindikasi Palsu) beserta confidence score.
- **Modul 6 & 7: Batch Import Excel Massal & Automated Testing**
  - Penyediaan template Excel baku dengan header terstandarisasi.
  - Class `BarangImport` dengan validasi per baris dan logika *Upsert* (otomatis perbarui stok bila kode sudah ada, buat baru bila belum ada).
  - Penyusunan automated test `DocumentVerificationTest` dan `BarangImportTest`.

---

### 3.2 BULAN KE-2 (September s/d Awal Oktober 2026): Rekayasa Kebutuhan, Multi-Gudang & Benchmarking 10.000 Data
*Berdasarkan Arahan Resmi GitHub Issue #4, #5, dan #7*

- **Penyusunan Dokumen SRS (System Requirement Specification)**
  - Merumuskan User Persona, batasan hak akses, kebutuhan fungsional (Inventaris, Riwayat Stok, OCR, Prediksi ML), dan kebutuhan non-fungsional (respon query < 200 ms, batasan upload 10 MB).
  - Merancang Use Case Diagram dan Activity Diagram proses bisnis inti.
  - Kompilasi dokumen formal `docs/srs.md` dan publikasi PDF resmi `docs/SRS_Sistem_Inventaris_LogistikKu.pdf`.
- **Perancangan Skema Database Multi-Gudang & Multi-Supplier**
  - Pembuatan diagram visual Entity Relationship Diagram (ERD).
  - Skema partisi lokasi fisik gudang: tabel `warehouses` dan saldo fisik unik per lokasi `warehouse_stocks`.
  - Master `suppliers` dengan relasi Eloquent (`belongsTo`, `hasMany`, `hasManyThrough`).
  - Penyusunan kamus data teknis pada `docs/data_dictionary.md` (23 tabel, 218 kolom, 24 FK).
- **Query Database Lanjutan & Analitik Bisnis (Silabus Kemnaker Minggu ke-3)**
  - Laporan mutasi periodik dengan rentang tanggal fleksibel (zona waktu Jakarta), filter gudang, supplier, serta saldo awal vs akhir.
  - Analisis perputaran barang FSN: identifikasi *Top 5 Fast-Moving Items* dan deteksi *Dead Stock* (mengendap 30–60 hari).
  - Valuasi total aset finansial: kalkulasi nilai total persediaan berdasarkan `harga_beli` riil per kategori dan per gudang.
  - Halaman dashboard analitik `/analytics` lengkap dengan ekspor laporan multi-format dan automated test `InventoryAnalyticsTest.php`.
- **Strategi Optimasi Performa, Indexing & Benchmark 10.000 Data (Tugas Penutup Bulan ke-2)**
  - Pembersihan repositori: pembuangan dump SQL recovery lama dari git tracking dan penyesuaian `.gitignore`.
  - Penerapan *Composite Indexing* pada kolom berfrekuensi tinggi di `stok_transactions` dan `barang`.
  - Audit Anti N+1 query melalui Eager Loading (`with()`) dan implementasi Laravel Cache (`Cache::remember()`) pada metrik agregasi analitik.
  - Pembuatan seeder beban tinggi (`LargeScaleSeeder`) untuk menyimulasikan 10.000 data transaksi mutasi.
  - Analisis rencana eksekusi query (`EXPLAIN ANALYZE`), membuktikan penurunan waktu eksekusi query sebesar 70–85%.
  - Publikasi dokumen laporan resmi `docs/database_benchmark_report.md` sebagai syarat penilaian kelulusan Bulan ke-2 di portal MagangHub Kemnaker.

---

### 3.3 BULAN KE-3 (Oktober 2026): Modul Pengadaan (Purchase Order & Goods Receipt) & Penutupan Paruh Waktu
*Berdasarkan Arahan Resmi GitHub Issue #9*

- **Minggu 1: Arsitektur Modul, State Machine, & Skema PO**
  - Perancangan siklus hidup status PO 7 tahap: `DRAFT`, `PENDING_APPROVAL`, `APPROVED`, `REJECTED`, `PARTIALLY_RECEIVED`, `COMPLETED`, dan `CANCELLED`.
  - Pemisahan logika status ke kelas murni `PurchaseOrderStateMachine.php` dan Enum bertipe `PurchaseOrderStatus.php`.
  - Migrasi skema database `purchase_orders` (nomor otomatis `PO-YYYYMMDD-XXXX`, tanggal, estimasi tiba, total biaya, foreign key) dan `purchase_order_items` (`jumlah_pesan`, `jumlah_diterima`, `harga_satuan`, `subtotal`).
  - Publikasi dokumen arsitektur teknis pada `docs/arsitektur_modul_purchase_order.md`.
- **Minggu 2: Validasi Input Ketat, Business Service Atomik, & Test Logika**
  - FormRequest terdedikasi: `StorePurchaseOrderRequest`, `UpdatePurchaseOrderRequest` (draft-only guard), dan `ReceivePurchaseOrderItemRequest`.
  - `PurchaseOrderService.php` dengan transaksi atomik `DB::transaction()` untuk kalkulasi biaya dan nomor otomatis.
  - Aturan kontrol internal ***Separation of Duty***: Manager dilarang menyetujui atau menolak PO yang diajukan oleh dirinya sendiri.
  - Logika *Goods Receipt*: validasi pencegahan *over-receipt*, otomatisasi penambahan saldo gudang fisik pada `warehouse_stocks`, pencatatan mutasi masuk pada `stok_transactions`, serta pemutakhiran status otomatis.
  - Automated tests logika pada `PurchaseOrderLogicTest.php` dan `PurchaseOrderStateMachineTest.php`.
- **Minggu 3: Tampilan Antarmuka UI Dinamis & Cetak Dokumen PDF**
  - Halaman index PO dengan paginasi, filter pencarian multi-kriteria, dan badge status visual tematik.
  - Formulir dinamis penambahan dan penghapusan baris item pesanan (*add/remove row*) dengan kalkulasi subtotal instan.
  - Halaman detail PO dengan tombol aksi role-based (Modal Approve/Reject untuk Manager dan Modal Goods Receipt untuk Staf Gudang).
  - Layanan cetak PDF resmi berstandar dokumen pengadaan perusahaan via `PurchaseOrderPdfService.php`.
- **Minggu 4: Refactoring Clean Code, Mitigasi Konkurensi & Finalisasi Pengujian Regresi**
  - **Zero Raw SQL**: Menghapus seluruh kueri mentah SQL dari Controller, menggantinya dengan Eloquent ORM dan Query Builder berparameter.
  - **DTO & API Resource**: Penerapan `PurchaseOrderData`, `PurchaseOrderItemData`, serta transformasi respon JSON pada `PurchaseOrderResource`.
  - **Standarisasi PSR-12**: Pemformatan kode terotomasi menggunakan Laravel Pint (`php vendor/bin/pint --test`) dengan hasil 100% lulus.
  - **Mitigasi Konkurensi Tingkat Tinggi**:
    - *Atomic Distributed Lock* berdurasi 15 detik via `Cache::lock("purchase_order:receive:{$po->id}", 15)->get()` untuk mencegah insiden mutasi ganda (*double submit*).
    - *Pessimistic Locking* (`lockForUpdate()`) dengan pengurutan penguncian item deterministik berdasarkan ID barang (`orderBy('id')`) serta toleransi *auto-retry deadlock* 3 kali.
    - Penanganan pesan flash ramah pengguna (*user-friendly flash messages*) di antarmuka Blade.
  - **Pengujian Regresi Menyeluruh**:
    - Eksekusi `php artisan test` menghasilkan **439 Tests PASSED (4.798 assertions) — 100% HIJAU tanpa regresi**.
  - Pembaruan dokumentasi operasional pengguna pada `README.md` dan `docs/README.md`.

---

## 4. Rancang Bangun Arsitektur & Rekayasa Sistem

### 4.1 Diagram Arsitektur Berjenjang (Layered Enterprise Architecture)

```text
               [Web Browser / User UI]            [External REST API Clients]
                          │                                    │
                          ▼                                    ▼
           [Web Controllers & Blade Views]        [API Controllers & Resources]
           - Autentikasi & Session                - Bearer Token Auth (Sanctum)
           - Dynamic JavaScript & Modals          - JSON Response Transformation
                          │                                    │
                          └─────────────────┬──────────────────┘
                                            │
                                            ▼
                               [Form Request Validation]
                               - StorePurchaseOrderRequest
                               - UpdatePurchaseOrderRequest
                               - ReceivePurchaseOrderItemRequest
                                            │
                                            ▼
                           [Data Transfer Objects (DTO)]
                           - Type-safe payload transfer
                                            │
                                            ▼
                                 [Domain Service Layer]
                        - PurchaseOrderService (Business Logic)
                        - StockAdjustmentService (Stock Mutations)
                        - PurchaseOrderPdfService (Document Engine)
                                            │
                                            ▼
                             [State Machine Logic Engine]
                            (PurchaseOrderStateMachine)
                            - 7 Status Workflow Validation
                            - Separation of Duty Guard
                            - Cumulative Receiving Resolver
                                            │
                                            ▼
                             [Data Access Layer / Eloquent]
                        - PurchaseOrder & PurchaseOrderItem
                        - WarehouseStock & StokTransaction
                        - Pessimistic Lock (lockForUpdate)
                                            │
                                            ▼
                          [Database Relasional & Cache Lock]
                            (MySQL / MariaDB / SQLite)
```

### 4.2 State Machine Siklus Hidup Status Purchase Order

```mermaid
stateDiagram-v2
    [*] --> DRAFT: Buat PO (Staff/Admin)
    DRAFT --> PENDING_APPROVAL: Ajukan (Submit) [min. 1 item]
    DRAFT --> CANCELLED: Batalkan + Alasan
    
    PENDING_APPROVAL --> APPROVED: Disetujui (Manager, bukan pembuat)
    PENDING_APPROVAL --> REJECTED: Ditolak + Alasan (Manager, bukan pembuat)
    PENDING_APPROVAL --> DRAFT: Tarik Kembali (Withdraw)
    PENDING_APPROVAL --> CANCELLED: Batalkan + Alasan
    
    REJECTED --> DRAFT: Revisi Pesanan (Pembuat)
    REJECTED --> CANCELLED: Batalkan + Alasan
    
    APPROVED --> PARTIALLY_RECEIVED: Goods Receipt Sebagian
    APPROVED --> COMPLETED: Goods Receipt Tuntas (100%)
    APPROVED --> CANCELLED: Batalkan (sebelum ada penerimaan) + Alasan
    
    PARTIALLY_RECEIVED --> PARTIALLY_RECEIVED: Penerimaan Lanjutan
    PARTIALLY_RECEIVED --> COMPLETED: Penerimaan Tuntas (100%)
    
    COMPLETED --> [*]
    CANCELLED --> [*]
```

### 4.3 Matriks Hak Akses & Pemisahan Tugas (Separation of Duty)

| Operasi Bisnis | Admin | Manager | Staff Gudang | Pembuat PO | Alasan Wajib | Guard Validasi Tambahan |
|---|:---:|:---:|:---:|:---:|:---:|---|
| Buat & Edit Draft PO | ✅ | ❌ | ✅ | ✅ | — | Hanya diizinkan pada status `Draft` |
| Ajukan (Submit) PO | ✅ | ❌ | ✅ | ✅ | — | Wajib memiliki minimal 1 item barang |
| Setujui (Approve) PO | ❌ | ✅ | ❌ | ❌ dilarang | — | **Separation of Duty**: Approver ≠ Creator |
| Tolak (Reject) PO | ❌ | ✅ | ❌ | ❌ dilarang | ✅ | Wajib menyertakan catatan alasan penolakan |
| Revisi PO Ditolak | ✅ | ❌ | ✅ | ✅ | — | Mengembalikan status ke `Draft` untuk perbaikan |
| Terima Barang (Goods Receipt) | ✅ | ❌ | ✅ | — | — | Dilarang *over-receipt*, PO wajib Approved/Partial |
| Batalkan (Cancel) PO | ✅ | ✅ | ❌ | ✅ (saat draft) | ✅ | Wajib menyertakan catatan alasan pembatalan |
| Cetak Dokumen PDF PO | ✅ | ✅ | ✅ | ✅ | — | Dokumen resmi siap kirim ke vendor/supplier |

---

## 5. Ringkasan Metrik Kualitas & Stabilitas Sistem

```text
========================================================================================
                          HASIL AKHIR AUTOMATED TEST SUITE
========================================================================================
  Perintah Uji     : php artisan test
  Framework Test   : PHPUnit 11.5
  Konfigurasi DB   : SQLite in-memory (:memory:)
  Total Test Cases : 439 PASSED (100% HIJAU)
  Total Assertions : 4.798 Assertions Terverifikasi
  Waktu Eksekusi   : ~126 Detik
  Status Kualitas  : ZERO REGRESSION (Semua modul dari Bulan 1 - 3 teruji sempurna)
========================================================================================
```

- **Unit Tests (`tests/Unit/`)**: Pengujian logika State Machine PO, pemetaan metadata OCR, dan validasi kontrak integrasi Python.
- **Feature Tests (`tests/Feature/`)**: Pengujian integrasi end-to-end mencakup database, form request, service layer, proteksi konkurensi (atomic lock, deadlock recovery), UI responsif, analitik bisnis, dan benchmark performa.
- **Standar Kode (PSR-12)**: 100% berkas PHP terformat rapi sesuai standar resmi Laravel Pint.
- **Keamanan Kueri**: 100% bebas dari query mentah (*Zero Raw SQL*), mengeliminasi risiko injeksi SQL secara menyeluruh.

---

## 6. Prospek & Rencana Kerja Lanjutan (Bulan ke-4 s/d Bulan ke-6)

Sebagai komitmen untuk menyelesaikan seluruh masa magang 6 bulan secara maksimal, berikut adalah proyeksi modul dan target kerja lanjutan untuk paruh kedua masa magang:

```text
+--------------------------------------------------------------------------------------------------------------------+
|                         RENCANA MODUL LANJUTAN MENUJU EVALUASI AKHIR BULAN KE-6                                    |
+--------------------------------------------------------------------------------------------------------------------+
| BULAN 4 (November 2026) : MODUL DISTRIBUSI & PENGIRIMAN (OUTBOUND & DISPATCHING)                                  |
| • Pembuatan Delivery Order (DO) otomatis dari pengeluaran stok.                                                    |
| • Surat Jalan digital terstandarisasi dengan QR Code pelacakan dokumen.                                            |
| • Pemetaan armada pengiriman dan integrasi multi-ekspedisi logistik.                                               |
+--------------------------------------------------------------------------------------------------------------------+
| BULAN 5 (Desember 2026) : REAL-TIME MONITORING, AUDIT TERDISTRIBUSI & INTEGRASI MULTI-CABANG                       |
| • Integrasi event-driven WebSockets / Pusher untuk notifikasi live pergerakan stok antar-gudang.                   |
| • Audit logging terdistribusi untuk melacak setiap perubahan data krusial per sesi pengguna.                       |
| • Dukungan multi-cabang regional (Branch Office Architecture).                                                     |
+--------------------------------------------------------------------------------------------------------------------+
| BULAN 6 (Januari 2027)  : SECURITY HARDENING, DOCKER DEPLOYMENT & PENUTUPAN AKHIR MAGANG                           |
| • Kontainerisasi aplikasi menggunakan Docker & Docker Compose (Nginx, PHP-FPM, MySQL, Redis, Python Worker).       |
| • Pipeline CI/CD otomatis di GitHub Actions (Linting, Testing, Auto-Deploy Staging).                               |
| • Security Hardening: proteksi CSRF ketat, header keamanan HTTP, audit penetrasi dasar.                            |
| • Penyusunan Laporan Penutupan Akhir 6 Bulan & Serah Terima Sistem (Final Project Handover).                       |
+--------------------------------------------------------------------------------------------------------------------+
```

---

### Pernyataan Penutupan & Pengesahan

Seluruh pencapaian yang tertulis dalam portofolio paruh waktu ini merupakan hasil kerja nyata, terverifikasi oleh 439 test cases otomatis yang lulus 100%, serta siap diajukan sebagai bukti pemenuhan target kompetensi **Evaluasi Tengah Periode (Triwulan I / Bulan 1–3) Program MagangHub Kemnaker RI**. Dokumen ini akan terus diperbarui hingga penutupan resmi di Bulan ke-6.

**Disusun Oleh**:  
Peserta Magang Rekayasa Perangkat Lunak (Software Engineer Intern)  

**Mengetahui / Pembimbing Industri**:  
Tim Kolaborator Engineering `itbcslogistics`  
*Sistem Manajemen Inventaris & Logistik Terpadu (LogistikKu) — Oktober 2026*
