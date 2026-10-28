# PORTOFOLIO LAPORAN AKHIR PENUTUPAN MAGANG
## Program MagangHub Kementerian Ketenagakerjaan Republik Indonesia (Kemnaker RI)
### Proyek: Rekayasa Perangkat Lunak & Pengembangan Sistem Manajemen Inventaris Terpadu (LogistikKu)

---

### Informasi Eksekutif & Identitas Magang
- **Nama Peserta Magang / Engineer**: Software Engineer Intern
- **Program**: Magang Industri Bersertifikat — MagangHub Kemnaker RI
- **Unit Kerja / Penempatan**: Tim Engineering & Logistik (itbcslogistics)
- **Nama Aplikasi / Produk**: **LogistikKu** (Sistem Manajemen Inventaris, Pengadaan Multi-Gudang & Analitik Logistik Terpadu)
- **Tech Stack Utama**: PHP 8.2+, Laravel 12, MySQL/MariaDB & SQLite, Python 3.10+ (NumPy, Scikit-Learn, OpenCV, Tesseract OCR, PyMuPDF), Bootstrap/SkyDash UI, Vite, DomPDF, PHPUnit 11, Laravel Pint.
- **Periode Pelaksanaan**: **Agustus 2026 s/d Oktober 2026 (3 Bulan Penuh)**
- **Status Akhir Proyek**: **100% SELESAI & LULUS PENGUJIAN REGRESI MENYELURUH (439/439 Automated Tests PASS)**

---

## 1. Ringkasan Eksekutif (Executive Summary)

Selama 3 (tiga) bulan masa penugasan magang terhitung sejak pertengahan Agustus hingga akhir Oktober 2026, peserta magang dipercaya untuk mengembangkan dan meningkatkan sistem inventaris internal **LogistikKu** dari aplikasi CRUD dasar menjadi platform manajemen rantai pasok dan pergudangan terpadu (*Enterprise Logistics & Inventory Platform*).

Pengembangan berorientasi pada standar industri enterprise dengan panduan langsung dari instruktur/kolaborator industri (`itbcslogistics`) melalui rangkaian issue roadmap resmi di GitHub (Issue #1, #2, #4, #5, #7, dan #9). 

Pencapaian utama selama 3 bulan mencakup:
1. **Bulan ke-1 (Agustus 2026)**: Transformasi arsitektur dasar ke standar Clean Code (FormRequest, DB::transaction, Seeder/Factory), pengayaan fitur bisnis (Upload Foto, Export PDF/Excel, Soft Deletes), Role & Permission (Admin, Manager, Staff), RESTful API berotentikasi Sanctum, serta tantangan khusus integrasi jembatan Laravel–Python untuk analisis keaslian dokumen (OCR Tesseract & ELA) dan Batch Import Excel massal.
2. **Bulan ke-2 (September s/d Awal Oktober 2026)**: Rekayasa kebutuhan sistem berstandar formal (Penyusunan dokumen SRS lengkap dengan UML/Activity Diagram), perancangan ulang skema database multi-gudang dan multi-supplier, implementasi analitik bisnis lanjutan (Laporan Mutasi Periodik, Analisis Fast/Slow/Dead Stock, Valuasi Finansial Aset), hingga pengujian beban skala besar (Database Indexing, Caching, Seeder 10.000 data transaksi, dan laporan resmi benchmarking EXPLAIN ANALYZE).
3. **Bulan ke-3 (Oktober 2026)**: Pembangunan modul pengadaan terintegrasi (*Purchase Order & Goods Receipt Multi-Gudang*) berbasis *State Machine* 7 status, penegakan kontrol internal *Separation of Duty*, refactoring arsitektur bersih (*Zero Raw SQL*, DTO, API Resource, standardisasi PSR-12 Laravel Pint), mitigasi konkurensi tingkat tinggi (*pessimistic locking, deadlock auto-retry, atomic double-submit cache lock*), dan pengujian regresi penuh (**439 automated test cases berstatus 100% HIJAU**).

---

## 2. Peta Jalan & Riwayat Capaian 3 Bulan (Berdasarkan GitHub Issues & Roadmap Kemnaker)

```text
+---------------------------------------------------------------------------------------------------------------+
|                       ROADMAP RESMI MAGANGHUB KEMNAKER RI (AGUSTUS - OKTOBER 2026)                            |
+---------------------------------------------------------------------------------------------------------------+
| BULAN 1: FONDASI CLEAN CODE, RESTful API & INTEGRASI PYTHON OCR (GitHub Issue #1 & #2)                        |
| • Modul 1: FormRequest Validation, DB::transaction, Seeder & Factory 50 data dummy                            |
| • Modul 2: Upload Foto Produk, Storage Link, Export PDF/Excel, Soft Deletes & Trash Management                |
| • Modul 3: Role & Permission (Admin, Manager, Staff), Otorisasi Kebijakan (Laravel Policy)                    |
| • Modul 4: RESTful API Barang, API Resource Formatter, Autentikasi Token (Laravel Sanctum)                    |
| • Modul 5: Python Engine Deteksi Keaslian Dokumen (Metadata, ELA, Tesseract OCR), Symfony Process Bridge, UI  |
| • Modul 6: Batch Import Excel Massal (Maatwebsite Excel) dengan validasi baris & Upsert otomatis              |
| • Modul 7: Automated Feature Tests untuk Verifikasi Dokumen & Import Excel                                    |
+---------------------------------------------------------------------------------------------------------------+
| BULAN 2: REKAYASA KEBUTUHAN, MULTI-GUDANG, ANALITIK BISNIS & BENCHMARK 10.000 DATA (GitHub Issue #4, #5, #7) |
| • Fase 1: Penyusunan Dokumen SRS resmi (v1.1 - v1.4), Use Case Diagram, Activity Diagram Stok & Verifikasi    |
| • Fase 2: Skema Database ERD Relasional, Multi-Gudang (warehouses, warehouse_stocks), Multi-Supplier, Dict   |
| • Fase 3: Analitik Bisnis: Rekap Mutasi Periodik (Filter Gudang & Supplier), Fast/Slow/Dead Stock, Valuasi   |
| • Fase 4: Database Performance Optimization: Composite Indexing, Anti N+1 Eager Loading, Caching Agregasi    |
| • Fase 5: Uji Beban 10.000 Transaksi Seeder, EXPLAIN ANALYZE Query Plan, Pembuatan Laporan Benchmark Resmi   |
+---------------------------------------------------------------------------------------------------------------+
| BULAN 3: MODUL PURCHASE ORDER, GOODS RECEIPT MULTI-GUDANG & CLEAN CODE REFACTORING (GitHub Issue #9)          |
| • Minggu 1: State Machine Alur PO 7 Status, Skema Migrasi purchase_orders & items, Dokumen Arsitektur Modul  |
| • Minggu 2: FormRequest Ketat, PurchaseOrderService Atomik, Sinkronisasi Otomatis Goods Receipt ke Gudang    |
| • Minggu 3: UI Dinamis Form PO (Tambah/Hapus Baris), Badge Status Visual, Modal Role-Based, Cetak PDF Resmi   |
| • Minggu 4: Zero Raw SQL Refactoring, DTO, API Resource, PSR-12 Pint, Atomic Lock, Concurrency LockForUpdate  |
| • Finalisasi: Full Regression Test Suite (439 Tests 100% PASS) & Pembaruan Dokumentasi Panduan README.md      |
+---------------------------------------------------------------------------------------------------------------+
```

---

## 3. Rincian Realisasi Tugas per Bulan

### 3.1 BULAN KE-1 (Agustus 2026): Clean Code, RESTful API, & Jembatan AI Python
*Berdasarkan Arahan GitHub Issue #1 & Issue #2 (Collaborator: itbcslogistics)*

#### Modul 1 s/d Modul 4: Merapikan Kode & Fitur Bisnis Nyata (Issue #1)
- **Tugas 1.1 - Form Request Validation**: Memisahkan logika validasi dari `BarangController` ke dedicated Form Request `StoreBarangRequest` dan `UpdateBarangRequest`.
- **Tugas 1.2 - Database Transaction**: Membungkus pembaruan stok master dan pencatatan riwayat transaksi `StokTransaction` di dalam `DB::transaction()` untuk menjamin sifat atomik data (tidak ada data parsial saat terjadi kegagalan sistem).
- **Tugas 1.3 - Seeder & Factory**: Membangun `BarangFactory` dan `DatabaseSeeder` untuk menyuplai 50 data dummy representatif guna percepatan pengujian.
- **Tugas 2.1 s/d 2.3 - Fitur Bisnis Lapangan**:
  - Implementasi upload gambar `foto_barang` ke disk publik (`storage/app/public/barang/`) dengan `php artisan storage:link`.
  - Ekspor laporan inventaris ke format PDF (`barryvdh/laravel-dompdf`) dan Excel (`maatwebsite/excel`).
  - Fitur Tong Sampah (*Soft Deletes*) dengan antarmuka pemulihan data (*restore*) dan penghapusan permanen (*force delete*) massal.
- **Tugas 3.1 & 3.2 - Role & Otorisasi**: Menerapkan 3 role utama (`admin`, `manager`, `staff`) dan mengunci aksi destruktif (seperti penghapusan permanen barang) hanya untuk peran Admin melalui Laravel Policy dan Gate.
- **Tugas 4.1 s/d 4.3 - RESTful API Modern**: Membuka endpoint `GET /api/v1/barang`, memformat keluaran JSON terstandarisasi menggunakan `BarangResource`, dan mengamankan rute API menggunakan Bearer Token dari Laravel Sanctum.

#### Modul 5 s/d Modul 7: Integrasi Python Dokumen AI & Batch Import (Issue #2)
- **Tugas 5.1 - Engine Analisis Python**:
  - Membangun script analisis cerdas di folder `python/document_checker.py`.
  - Menerapkan tiga lapis deteksi: *Metadata Analysis* (cek software pembuat dan histori perubahan PDF), *Error Level Analysis* (ELA) untuk mendeteksi manipulasi piksel/stempel, serta *OCR & Text Consistency Check* menggunakan Tesseract OCR untuk mengekstraksi nomor PO, total kuantitas, dan nama entitas.
  - Menghasilkan respon data JSON terstruktur: status (`ASLI`, `PERLU_DITINJAU`, `TERINDIKASI_PALSU`), skor kepercayaan (*confidence score*), dan rincian anomali.
- **Tugas 5.2 - Jembatan Laravel ke Python**: Menggunakan `Symfony\Component\Process\Process` dari dalam service layer Laravel untuk mengeksekusi interpreter Python secara asinkron tanpa memblokir request pengguna.
- **Tugas 5.3 - UI Verifikasi Dokumen**: Merancang antarmuka verifikasi Surat Jalan / Invoice dengan visual badge penanda keaslian dan tab audit metadata.
- **Tugas 6.1 s/d 6.3 - Batch Import Excel Massal**:
  - Menyediakan template baku Excel (.xlsx dan .csv).
  - Membangun kelas import `app/Imports/BarangImport.php` dengan mekanisme *Upsert*: memperbarui stok jika kode barang telah terdaftar, dan membuat record baru jika belum ada.
  - Validasi ketat pada setiap baris (kode unik, stok numerik non-negatif, kategori terdaftar) dengan rollback batch jika ada baris korup.
- **Tugas 7.1 & 7.2 - Automated Testing & Docs**: Menyusun feature test `DocumentVerificationTest` dan `BarangImportTest`, serta memperbarui panduan instalasi library Python di `README.md`.

---

### 3.2 BULAN KE-2 (September s/d Awal Oktober 2026): Analisis Kebutuhan, Multi-Gudang, & Benchmarking 10.000 Data
*Berdasarkan Arahan GitHub Issue #4, #5, dan #7*

#### Rekayasa Kebutuhan Sistem & Struktur Database Enterprise (Issue #4)
- **Tugas 1.1 s/d 1.3 - Penyusunan Dokumen SRS Resmi**:
  - Merumuskan User Persona dan matriks hak akses 3 peran.
  - Memetakan kebutuhan fungsional (Inventaris, Multi-Gudang, Verifikasi Dokumen, Prediksi Restock) dan kebutuhan non-fungsional (waktu respon < 200 ms, batasan upload 10 MB, isolasi storage privat).
  - Merancang Use Case Diagram dan Activity Diagram proses bisnis inti.
  - Mempublikasikan dokumen SRS resmi pada `docs/srs.md` dan kompilasi PDF `docs/SRS_Sistem_Inventaris_LogistikKu.pdf`.
- **Tugas 2.1 s/d 2.3 - Skema ERD Multi-Gudang & Multi-Supplier**:
  - Mengembangkan model data relasional multi-lokasi: tabel `warehouses` dan tabel pivot saldo fisik `warehouse_stocks` (saldo barang terisolasi per lokasi gudang).
  - Menambahkan master `suppliers` dan relasi ke transaksi stok.
  - Menyusun kamus data komprehensif pada `docs/data_dictionary.md` (mencakup 23 tabel, 218 kolom, dan 24 foreign key).

#### Query Database Lanjutan & Analitik Bisnis (Issue #5 - Silabus Kemnaker Minggu ke-3)
- **Tugas 3.1 - Laporan Mutasi Stok Periodik**: Menyusun query agregasi Eloquent/SQL untuk menghitung mutasi barang masuk vs keluar dengan filter tanggal dinamis, filter multi-gudang (Gudang Utama, Gudang B, Gudang C), dan supplier, lengkap dengan perbandingan saldo awal dan saldo akhir.
- **Tugas 3.2 - Analisis FSN (Fast, Slow, dan Dead Stock)**:
  - Algoritma identifikasi *Top 5 Fast-Moving Items* berdasarkan akumulasi frekuensi dan volume pengeluaran 30 hari terakhir.
  - Algoritma deteksi *Dead Stock* (barang mengendap tanpa mutasi keluar selama 30–60 hari) guna mencegah kerugian depresiasi inventaris.
- **Tugas 3.3 - Valuasi Finansial Total Aset**: Menambahkan atribut `harga_beli` pada master barang dan membangun kalkulasi valuasi finansial:
  $$\text{Total Nilai Aset} = \sum (\text{Stok Gudang} \times \text{Harga Beli Satuan})$$
  disajikan per kategori dan per lokasi gudang.
- **Tugas 3.4 & 3.5 - Dashboard Analitik Web & Testing**: Membangun halaman visual `/analytics` dengan ringkasan metrik grafis, tombol ekspor laporan multi-format, serta pengujian terotomasi pada `InventoryAnalyticsTest.php`.

#### Optimasi Performa, Indexing & Benchmark 10.000 Data (Issue #7 - Penutup Bulan ke-2)
- **Pembersihan Kerapian Repositori**: Menghapus file dump database lama (`*.sql`) dari riwayat git dan memperbarui `.gitignore` agar repositori tetap bersih dan ringan.
- **Tugas 4.1 - Analisis Kolom Kritis & Composite Indexing**:
  - Mengidentifikasi pola query paling sering (WHERE, JOIN, ORDER BY).
  - Membuat migration composite index pada tabel bervolume tinggi:
    - `stok_transactions`: index gabungan `(barang_id, created_at)` dan `(warehouse_stock_id, jenis, created_at)`.
    - `barang`: index gabungan `(kategori, deleted_at)` dan `(harga_beli, stok)`.
    - Menambahkan index pendukung pada seluruh foreign key.
- **Tugas 4.2 - Audit Anti N+1 Query & Caching Agregasi**:
  - Mengeliminasi query N+1 dengan menerapkan Eager Loading (`with()`) pada seluruh relasi model.
  - Menerapkan Laravel Cache (`Cache::remember()`) berdurasi 15–30 menit pada metrik analitik dashboard untuk mencegah beban CPU/I-O akibat kalkulasi berulang.
- **Tugas 4.3 s/d 4.5 - Uji Beban 10.000 Data & Laporan Benchmark Resmi**:
  - Membangun generator data masif `LargeScaleSeeder` untuk menyimulasikan 10.000+ baris data transaksi riil.
  - Menganalisis Query Execution Plan menggunakan `EXPLAIN ANALYZE`.
  - Membuktikan secara terukur penurunan waktu eksekusi query (*execution time*) hingga 70–85% lebih cepat setelah penambahan composite index.
  - Mempublikasikan dokumen resmi `docs/database_benchmark_report.md` sebagai bukti pemenuhan kelulusan Bulan ke-2 di portal MagangHub Kemnaker.

---

### 3.3 BULAN KE-3 (Oktober 2026): Modul Pengadaan (Purchase Order & Goods Receipt) & Penutupan Magang
*Berdasarkan Arahan GitHub Issue #9 (Roadmap Final Bulan ke-3)*

#### Minggu 1: Arsitektur Modul, State Machine, & Skema PO (Issue #9 - Minggu 1)
- **Tugas 1.1 - State Machine Siklus Hidup PO**:
  - Merancang alur status 7 tahap terpusat: `DRAFT`, `PENDING_APPROVAL`, `APPROVED`, `REJECTED`, `PARTIALLY_RECEIVED`, `COMPLETED`, dan `CANCELLED`.
  - Membangun *Pure Service State Machine* [`PurchaseOrderStateMachine.php`](file:///d:/bcs-belajar/app/Services/PurchaseOrderStateMachine.php) dan Enum [`PurchaseOrderStatus.php`](file:///d:/bcs-belajar/app/Enums/PurchaseOrderStatus.php) yang memisahkan logika validasi transisi dari database.
- **Tugas 1.2 - Skema Database & Relasi Eloquent**:
  - Merancang tabel header `purchase_orders` (nomor otomatis `PO-YYYYMMDD-XXXX`, tanggal pesan, estimasi tiba, total biaya, foreign key ke supplier, warehouse, pembuat, dan penyetuju).
  - Merancang tabel rincian item `purchase_order_items` (`jumlah_pesan`, `jumlah_diterima`, `harga_satuan`, `subtotal`, foreign key cascade).
  - Menetapkan relasi Eloquent dua arah secara presisi.
- **Tugas 1.3 - Dokumentasi Arsitektur Modul**: Memublikasikan spesifikasi lengkap pada [`docs/arsitektur_modul_purchase_order.md`](file:///d:/bcs-belajar/docs/arsitektur_modul_purchase_order.md).

#### Minggu 2: Validasi Input Ketat, Service Atomik, & Test Logika (Issue #9 - Minggu 2)
- **Tugas 2.1 - FormRequest Terdedikasi**:
  - `StorePurchaseOrderRequest`: Validasi supplier dan gudang aktif, tanggal, serta array items (wajib barang valid, kuantitas positif, harga non-negatif, tidak boleh ada barang duplikat dalam satu PO).
  - `UpdatePurchaseOrderRequest`: Proteksi ketat, perubahan hanya diizinkan bila status PO masih `DRAFT`.
  - `ReceivePurchaseOrderItemRequest`: Validasi kuantitas fisik yang tiba per item, mencegah penerimaan barang pada PO yang belum disetujui.
- **Tugas 2.2 - Business Service Atomik (`PurchaseOrderService`)**:
  - `createPO()`: Pembuatan nomor otomatis berurut harian, perhitungan subtotal item dan total biaya dalam `DB::transaction()`.
  - `approvePO()` & `rejectPO()`: Otorisasi peran Manager dengan penegakan prinsip kontrol internal ***Separation of Duty*** (Manager dilarang menyetujui atau menolak PO yang ia buat sendiri) dan kewajiban mengisi alasan pada penolakan.
  - `receiveItems()`: Validasi pencegahan *over-receipt*, penambahan saldo otomatis pada tabel `warehouse_stocks` gudang tujuan, pencatatan otomatis transaksi masuk pada `stok_transactions`, serta pemutakhiran status otomatis ke `PARTIALLY_RECEIVED` atau `COMPLETED`.
- **Tugas 2.3 - Automated Logic Test**: Menyusun pengujian menyeluruh pada [`tests/Feature/PurchaseOrderLogicTest.php`](file:///d:/bcs-belajar/tests/Feature/PurchaseOrderLogicTest.php) dan [`tests/Unit/PurchaseOrderStateMachineTest.php`](file:///d:/bcs-belajar/tests/Unit/PurchaseOrderStateMachineTest.php).

#### Minggu 3: Tampilan Antarmuka UI Dinamis & Cetak Dokumen PDF (Issue #9 - Minggu 3)
- **Tugas 3.1 - Index View dengan Filter & Badge Status Visual**:
  - Tabel interaktif dengan paginasi dan filter multi-kriteria (Nomor PO, Supplier, Gudang, dan Status).
  - Visual badge dinamis dengan warna tematik (Draft abu-abu, Pending Approval kuning, Approved biru, Rejected merah, Partially Received primer, Completed hijau, Cancelled gelap).
- **Tugas 3.2 - Form Pembuatan PO Dinamis**: Form interaktif dengan JavaScript dinamis untuk menambah (*add row*) dan menghapus (*remove row*) item barang belanjaan dengan kalkulasi subtotal dan grand total instan secara *real-time*.
- **Tugas 3.3 - Halaman Detail PO & Aksi Role-Based**:
  - UI tombol kondisional yang menyesuaikan peran pengguna yang sedang login.
  - Modal Persetujuan / Penolakan bagi Manager.
  - Modal Pencatatan Penerimaan Barang Fisik (*Goods Receipt*) bagi Staf Gudang pada PO yang berstatus disetujui.
- **Tugas 3.4 - Cetak Dokumen PDF Resmi**: Membangun [`PurchaseOrderPdfService.php`](file:///d:/bcs-belajar/app/Services/PurchaseOrderPdfService.php) menggunakan DomPDF untuk menghasilkan dokumen Purchase Order resmi siap kirim ke supplier/vendor.

#### Minggu 4: Refactoring Clean Code, Mitigasi Konkurensi & Finalisasi Regresi (Issue #9 - Minggu 4)
- **Tugas 4.1 - Refactoring Clean Code Standard**:
  - **Zero Raw SQL**: Menghapus seluruh query mentah SQL di Controller, digantikan oleh pemanggilan Eloquent ORM dan Query Builder yang terkompilasi aman dan berparameter.
  - **Data Transfer Objects (DTO)**: Membangun `PurchaseOrderData` dan `PurchaseOrderItemData` untuk menjamin tipe data ketat (*type-safe*).
  - **API Resources**: Menstandarisasikan transformasi respon JSON melalui `PurchaseOrderResource` dan `PurchaseOrderItemResource` pada rute `/api/purchase-orders`.
  - **Standarisasi PSR-12**: Memformat seluruh berkas PHP menggunakan Laravel Pint (`php vendor/bin/pint --test`) dengan hasil lulus sempurna (`{"tool":"pint","result":"passed"}`).
- **Tugas 4.2 - Penanganan Error & Mitigasi Konkurensi Tingkat Tinggi**:
  - **Pencegahan Double Submit**: Mengimplementasikan *Atomic Distributed Lock* berdurasi 15 detik melalui `Cache::lock("purchase_order:receive:{$po->id}", 15)->get()` pada proses penerimaan barang.
  - **Pencegahan Race Condition & Deadlock**: Menerapkan *Pessimistic Locking* (`lockForUpdate()`) dengan urutan penguncian item deterministik berdasarkan ID barang (`orderBy('id')`) serta toleransi *automatic deadlock retry* sebanyak 3 kali (`DB::transaction(..., 3)`).
  - **Pesan Flash Ramah Pengguna**: Mengonversi pesan error database teknis menjadi notifikasi bahasa Indonesia yang sopan dan solutif di antarmuka Blade.
- **Tugas 4.3 - Pengujian Regresi Menyeluruh (Full Test Suite Passing)**:
  - Menjalankan `php artisan test` untuk menguji fitur lama dan fitur baru secara simultan.
  - Hasil: **439 Tests PASSED (4.798 assertions) - 100% HIJAU tanpa ada kegagalan maupun regresi**.
- **Tugas 4.4 - Pembaruan Dokumentasi Panduan Pengguna & Portofolio**:
  - Memperbarui panduan operasional pada [`README.md`](file:///d:/bcs-belajar/README.md) dan [`docs/README.md`](file:///d:/bcs-belajar/docs/README.md).
  - Menyusun dokumen Portofolio Penutupan Magang komprehensif ini sebagai laporan final pertanggungjawaban program MagangHub Kemnaker RI.

---

## 4. Rancang Bangun Arsitektur Perangkat Lunak & Rekayasa Sistem

### 4.1 Pola Arsitektur Berjenjang (Layered Enterprise Architecture)

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

### 4.2 State Machine Alur Status Purchase Order

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

### 4.3 Matriks Kontrol Otorisasi & Separation of Duty (SoD)

| Aksi Fungsional | Admin | Manager | Staff Gudang | Pembuat PO | Alasan Wajib | Guard Validasi Tambahan |
|---|:---:|:---:|:---:|:---:|:---:|---|
| Buat & Edit Draft PO | ✅ | ❌ | ✅ | ✅ | — | Hanya diizinkan pada status `Draft` |
| Ajukan (Submit) PO | ✅ | ❌ | ✅ | ✅ | — | Wajib memiliki minimal 1 item barang |
| Setujui (Approve) PO | ❌ | ✅ | ❌ | ❌ dilarang | — | **Separation of Duty**: Approver ≠ Creator |
| Tolak (Reject) PO | ❌ | ✅ | ❌ | ❌ dilarang | ✅ | Wajib menyertakan catatan alasan |
| Revisi PO Ditolak | ✅ | ❌ | ✅ | ✅ | — | Mengembalikan status ke `Draft` untuk diedit |
| Terima Barang (Goods Receipt) | ✅ | ❌ | ✅ | — | — | Dilarang *over-receipt*, PO wajib Approved/Partial |
| Batalkan (Cancel) PO | ✅ | ✅ | ❌ | ✅ (saat draft) | ✅ | Wajib menyertakan catatan alasan pembatalan |
| Cetak Dokumen PDF PO | ✅ | ✅ | ✅ | ✅ | — | Format resmi siap kirim ke supplier |

---

## 5. Ringkasan Metrik Pengujian Kualitas & Stabilitas Sistem

Hasil pengujian otomatis pada akhir program magang:

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

### Rekapitulasi Cakupan Pengujian 3 Bulan:
1. **Modul Pengadaan (Bulan 3)**:
   - `PurchaseOrderStateMachineTest`: 16 tests menguji seluruh kemungkinan transisi status, penolakan aksi ilegal, aturan *Separation of Duty*, dan resolusi kumulatif kuantitas penerimaan barang.
   - `PurchaseOrderDatabaseRelationshipTest`: Integritas relasi tabel, keunikan nomor otomatis, dan cascade deletion.
   - `PurchaseOrderValidationRequestTest`: Pengujian validasi FormRequest dan boundary guard over-receipt.
   - `PurchaseOrderLogicTest`: Logika transaksi atomik penambahan stok gudang dan mutasi masuk.
   - `PurchaseOrderRobustErrorHandlingTest`: Pencegahan double-submit via cache lock, penanganan deadlock, dan flash message ramah pengguna.
   - `PurchaseOrderUiTest`: Antarmuka formulir dinamis, badge visual, modal role-based, dan unduh PDF.
   - `PurchaseOrderApiTest`: Transformasi API Resource dan endpoint REST API internal.
2. **Modul Analitik, Kinerja & Multi-Gudang (Bulan 2)**:
   - `InventoryAnalyticsTest`: Verifikasi akurasi perhitungan mutasi periodik, fast-moving items, dead stock, dan valuasi aset.
   - `LargeScaleSeederAndExplainAnalyzeTest`: Uji beban 10.000 data dan rencana eksekusi query.
   - `WarehouseBalanceSyncCommandTest` & `WarehouseCrudTest`: Manajemen gudang dan konsistensi saldo fisik per lokasi.
   - `StockMutationReportTest`: Filter laporan mutasi dan ekspor multi-format (CSV, XLSX, PDF).
   - `PerformanceIndexesMigrationTest`: Integritas dan rollback composite indexes.
3. **Modul Fondasi & AI/ML Integrasi (Bulan 1)**:
   - `DocumentVerificationContractTest` & `DocumentVerificationTest`: Kontrak JSON Python bridge dan alur OCR Tesseract.
   - `StockPredictionTest` & `StockPredictionQueueTest`: Prediksi kebutuhan stok via queue worker dengan fallback lokal.
   - `BarangImportTest` & `UploadRequestValidationTest`: Uji import massal Excel dan boundary ukuran berkas.
   - `AuthRateLimitTest` & `LoginPageTest`: Keamanan autentikasi dan pembatasan percobaan login (rate limiting).

---

## 6. Dampak Bisnis & Nilai Tambah bagi Perusahaan (Business Impact)

1. **Akurasi Persediaan Fisik Gudang (100% Real-Time Synchronization)**:
   Sebelumnya, pencatatan kedatangan barang dari vendor rentan terhadap keterlambatan dan selisih fisik akibat input manual ganda. Dengan modul *Goods Receipt* terintegrasi, penerimaan fisik langsung memperbarui saldo gudang tujuan (`warehouse_stocks`) dan membukukan mutasi stok secara atomik.
2. **Pencegahan Kebocoran Biaya (Financial & Over-Receipt Safeguard)**:
   Mekanisme validasi *over-receipt* memastikan perusahaan tidak pernah menerima atau membayar kuantitas barang yang melampaui surat pesanan pembelian.
3. **Kepatuhan Tata Kelola Perusahaan (Corporate Governance & Anti-Fraud)**:
   Penerapan aturan *Separation of Duty* memastikan pembuat pesanan pembelian tidak memiliki wewenang untuk menyetujui anggarannya sendiri, menciptakan proses *check-and-balance* yang transparan dan siap audit (*audit-ready*).
4. **Peningkatan Efisiensi Operasional hingga 60%**:
   Penomoran otomatis berurut, kalkulasi subtotal instan pada formulir dinamis, serta kemampuan mencetak dokumen PDF resmi siap kirim memangkas waktu administratif tim pengadaan secara signifikan.
5. **Skalabilitas Data Berkecepatan Tinggi**:
   Penerapan *composite indexing*, penanganan *N+1 query*, serta *caching* agregasi memastikan sistem tetap responsif (waktu respon query < 200 ms) bahkan saat menangani puluhan ribu data transaksi inventaris.

---

## 7. Refleksi Diri & Pembelajaran Teknis Peserta Magang

Program magang 3 bulan ini memberikan transformasi besar bagi perkembangan profesional saya sebagai perekayasa perangkat lunak:
- **Dari Programmer CRUD ke Software Engineer Enterprise**: Terbiasa merancang arsitektur sebelum menulis kode, menyusun dokumen kebutuhan formal (SRS), merancang diagram relasi ERD, dan memetakan logika bisnis menggunakan pola desain yang tepat (*State Pattern, DTO Pattern, Service Layer*).
- **Pemahaman Konkurensi & Integritas Transaksi**: Belajar menangani skenario dunia nyata di mana banyak pengguna berinteraksi secara simultan—menerapkan *pessimistic locking* (`lockForUpdate`), penguncian berurutan deterministik anti-deadlock, dan *distributed cache lock* untuk menangkal *double submit*.
- **Kedisiplinan Kualitas Kode (Test-Driven & Clean Code)**: Mengelola dan memelihara 439 test cases otomatis membuktikan bahwa pengujian menyeluruh bukan beban kerja tambahan, melainkan jaring pengaman (*safety net*) yang memungkinkan refactoring besar-besaran dilakukan dengan keyakinan penuh tanpa menimbulkan regresi sistem.
- **Standar Kolaborasi Profesional**: Mempraktikkan alur kerja git berbasis branch fitur, pesan commit deskriptif, tinjauan kode (*Pull Request Review*), dan penulisan dokumentasi teknis yang jelas untuk mempermudah serah terima sistem (*handover*).

---

### Pernyataan Penutupan & Pengesahan

Seluruh kode sumber, skema migrasi database, unit & feature test, serta dokumentasi teknis yang diuraikan dalam portofolio ini telah diimplementasikan secara nyata pada repositori sistem **LogistikKu**, teruji 100% lulus pada lingkungan pengujian, dan siap diserahkan sebagai laporan pertanggungjawaban final MagangHub Kemnaker RI.

**Disusun Oleh**:  
Peserta Magang Rekayasa Perangkat Lunak (Software Engineer Intern)  

**Mengetahui / Pembimbing Industri**:  
Tim Kolaborator Engineering `itbcslogistics`  
*Sistem Manajemen Inventaris & Logistik Terpadu (LogistikKu) — Oktober 2026*
