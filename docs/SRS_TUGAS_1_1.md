# SRS LogistikKu — Roadmap 2, Tugas 1.1

## Pemetaan Kebutuhan Fungsional dan Non-Fungsional

| Atribut | Nilai |
|---|---|
| Nama sistem | LogistikKu |
| Jenis sistem | Aplikasi web pengelolaan persediaan |
| Ruang lingkup dokumen | Inventaris, riwayat dan transaksi stok, OCR verifikasi dokumen, dan prediksi restock machine learning |
| Aktor | Admin, Manager, dan Staff Gudang (`staff`) |
| Dasar audit | Source code, route, middleware, Gate, Form Request, model, service, job, migration, konfigurasi, engine Python, dan feature test per 14 September 2026 |
| Hasil validasi | PHPUnit: 216 test, 2.698 assertion, seluruhnya lulus |

Dokumen ini mendeskripsikan kondisi aplikasi yang dapat dibuktikan dari repository. Dokumen ini tidak menyatakan bahwa target yang belum diuji telah tercapai.

## 1. Definisi Status

| Status | Arti |
|---|---|
| **Sudah Tersedia** | Implementasi dan bukti pengujian yang relevan ditemukan. |
| **Tersedia Sebagian** | Sebagian kebutuhan telah diterapkan, tetapi masih ada cakupan yang belum tersedia atau belum terbukti. |
| **Direncanakan** | Belum diterapkan dan secara eksplisit menjadi kebutuhan mendatang. |
| **Perlu Konfirmasi** | Tidak ada bukti yang cukup dalam repository atau lingkungan audit. |
| **Target/Perlu Pengujian** | Target telah ditetapkan, tetapi belum ada pengujian terukur yang membuktikan pencapaiannya. |

## 2. User Persona

### 2.1 Admin

| Aspek | Definisi |
|---|---|
| Tujuan | Menjaga data master, akun pengguna, dan proses inventaris tetap valid serta dapat diawasi. |
| Tanggung jawab | Mengelola barang dan pengguna; melakukan import/export; menangani trash; mengawasi transaksi, prediksi, dan seluruh verifikasi dokumen. |
| Kebutuhan utama | Akses administratif, validasi data, pemulihan barang, laporan, status proses, dan jejak audit OCR. |
| Fitur yang dapat diakses | Seluruh halaman barang; CRUD barang; import/export; trash; stok masuk/keluar; riwayat; OCR; seluruh hasil OCR; prediksi; persetujuan restock; CRUD pengguna dan penetapan role. |
| Dilarang/dibatasi | Saldo tidak dapat diubah dari form edit barang; perubahan saldo harus menghasilkan transaksi. Admin tidak dapat menghapus akunnya sendiri melalui fitur pengguna. Sistem tidak menyediakan CRUD permission granular. |

### 2.2 Staff Gudang

| Aspek | Definisi |
|---|---|
| Tujuan | Mencatat pergerakan stok dan memeriksa dokumen operasional miliknya. |
| Tanggung jawab | Memastikan transaksi masuk/keluar dicatat dengan jumlah benar dan mengunggah dokumen yang relevan. |
| Kebutuhan utama | Pencarian barang yang cepat, transaksi stok yang aman, riwayat yang jelas, dan status OCR yang mudah dipahami. |
| Fitur yang dapat diakses | Dashboard dan data barang; detail; stok masuk/keluar; riwayat; upload OCR; hasil, download, koreksi, retry, dan proses ulang dokumen sendiri; hasil prediksi yang telah tersimpan. |
| Dilarang/dibatasi | Tidak dapat CRUD barang, import/export, trash, mengelola pengguna, mengakses dokumen pengguna lain, menjalankan prediksi, melihat detail proses aktif prediksi, atau menyetujui restock. |

### 2.3 Manager

| Aspek | Definisi |
|---|---|
| Tujuan | Memantau ketersediaan dan menggunakan hasil prediksi untuk mengambil keputusan restock. |
| Tanggung jawab | Meninjau inventaris/riwayat, menjalankan prediksi, menilai rekomendasi, dan mengonfirmasi transaksi restock. |
| Kebutuhan utama | Dashboard, status risiko, metode, confidence, alasan prediksi, status antrean, dan riwayat stok. |
| Fitur yang dapat diakses | Seluruh akses operasional Staff, ditambah menjalankan prediksi satu/semua barang, melihat proses aktif prediksi, dan menyetujui rekomendasi ke form stok masuk. |
| Dilarang/dibatasi | Tidak dapat CRUD barang, import/export, trash, mengelola pengguna, atau mengakses dokumen pengguna lain. Persetujuan restock tidak langsung mengubah stok. |

## 3. Matriks Hak Akses Aktual

Otorisasi aktual menggunakan middleware `auth`, middleware `role:admin`, Gate, Form Request, dan pemeriksaan kepemilikan pada controller. Tidak ditemukan class Policy atau sistem permission granular.

| Fitur/Aktivitas | Admin | Manager | Staff Gudang | Dasar implementasi |
|---|:---:|:---:|:---:|---|
| Melihat, mencari, memfilter, dan mengurutkan barang | Ya | Ya | Ya | Group `auth`; `InventoryFilterRequest`; `BarangController` |
| Melihat detail barang dan dashboard | Ya | Ya | Ya | Group `auth`; `BarangController` |
| Menambah dan mengubah barang | Ya | Tidak | Tidak | `role:admin`; `StoreBarangRequest`/`UpdateBarangRequest` |
| Soft delete, memulihkan, dan hapus permanen barang | Ya | Tidak | Tidak | Group route `role:admin`; `BarangTrashController` |
| Import dan export inventaris | Ya | Tidak | Tidak | Group route `role:admin`; `ImportBarangRequest` |
| Stok masuk dan stok keluar | Ya | Ya | Ya | Gate `update-stock` |
| Melihat riwayat stok | Ya | Ya | Ya | Group route `auth` |
| Mengunggah dokumen untuk OCR | Ya | Ya | Ya | Group `auth`; `VerifyDocumentRequest` |
| Melihat/mengunduh/mengoreksi/retry/proses ulang dokumen sendiri | Ya | Ya | Ya | Pemeriksaan pemilik atau Admin |
| Melihat dan mengelola dokumen pengguna lain | Ya | Tidak | Tidak | Pemeriksaan `role === admin` atau `user_id === auth()->id()` |
| Melihat hasil prediksi tersimpan | Ya | Ya | Ya | Route indeks prediksi berada dalam group `auth` |
| Melihat rincian proses aktif prediksi | Ya | Ya | Tidak | Gate `run-stock-prediction` |
| Menjalankan prediksi satu/semua barang | Ya | Ya | Tidak | Gate `run-stock-prediction` |
| Menyetujui rekomendasi ke form stok masuk | Ya | Ya | Tidak | Gate `approve-restock` |
| Mengelola akun pengguna | Ya | Tidak | Tidak | Resource route `users` dalam group `role:admin` |
| Menetapkan role `admin`, `manager`, atau `staff` | Ya | Tidak | Tidak | Validasi `UserController` |
| Mengelola permission granular | Tidak tersedia | Tidak tersedia | Tidak tersedia | Tidak ada tabel/model/UI/route permission |

Pengguna yang gagal melewati otorisasi menerima HTTP `403`. Penyembunyian tombol pada UI bukan satu-satunya kontrol; pemeriksaan juga dilakukan pada sisi server.

## 4. Kebutuhan Fungsional

Semua deskripsi memakai pola normatif “Sistem harus dapat…”. Referensi file bersifat relatif terhadap root repository.

### 4.1 Modul Inventaris

| ID dan nama | Aktor; prasyarat | Deskripsi | Alur/respons yang diharapkan; kondisi gagal | Kriteria penerimaan | Status; referensi |
|---|---|---|---|---|---|
| **FR-INV-001 — Daftar dan pencarian barang** | Semua role; pengguna login | Sistem harus dapat menampilkan daftar aktif dan mencari berdasarkan kode, nama, atau lokasi. | Hasil dipaginasi 5 baris. Input pencarian dipangkas dan maksimal 100 karakter; input invalid ditolak dengan validasi. | Kata kunci mengembalikan kecocokan yang benar; barang soft-deleted tidak tampil; pagination mempertahankan parameter. | **Sudah Tersedia**; `BarangController`, `InventoryFilterRequest`, `InventoryInstantFilterTest` |
| **FR-INV-002 — Filter dan pengurutan** | Semua role; pengguna login | Sistem harus dapat memfilter kategori/status stok dan mengurutkan nama atau stok. | Status hanya `menipis`/`aman`; urutan hanya empat nilai yang diizinkan. Nilai lain ditolak. | Kombinasi filter dan sort menghasilkan data benar tanpa menerima nama kolom bebas. | **Sudah Tersedia**; `InventoryFilterRequest`, `BarangController::filteredInventory`, `InventoryInstantFilterTest` |
| **FR-INV-003 — Detail dan dashboard** | Semua role; pengguna login | Sistem harus dapat menampilkan detail barang, ringkasan inventaris, aktivitas 7/30 hari, dan pusat perhatian. | ID tidak ditemukan menghasilkan `404`; periode selain 7/30 ditolak. Membuka dashboard tidak menjalankan prediksi. | Nilai ringkasan berasal dari data aktif; aktivitas dan perhatian sesuai status/role. | **Sudah Tersedia**; `BarangController`, `InventoryDashboardService`, `InventoryDashboardUiTest` |
| **FR-INV-004 — Penambahan barang** | Admin login; data master valid | Sistem harus dapat menambahkan barang dengan kode otomatis unik `BRG-######`. | Barang dibuat dengan stok database awal 0, lalu saldo awal diterapkan melalui transaksi. Konflik kode dicoba ulang maksimal tiga kali; data invalid ditolak. | Kode unik dibuat; saldo akhir sama dengan input; stok awal positif menghasilkan snapshot transaksi 0 ke nilai awal. | **Sudah Tersedia**; `StoreBarangRequest`, `BarangCodeGenerator`, unique index migration, `BarangCodeStandardizationTest`, `InventoryStockIntegrityTest` |
| **FR-INV-005 — Perubahan data master** | Admin login; barang ada | Sistem harus dapat mengubah nama, kategori, estimasi pemakaian, lead time, satuan, lokasi, dan foto tanpa mengubah kode atau stok melalui form edit. | ID tidak ada menghasilkan `404`; input invalid tidak disimpan. Field stok tidak diterima oleh `UpdateBarangRequest`. | Percobaan mengirim `stok` pada update tidak mengubah saldo dan tidak membuat transaksi. | **Sudah Tersedia**; `UpdateBarangRequest`, `Barang` model, `BarangController::update`, `InventoryStockIntegrityTest` |
| **FR-INV-006 — Penghapusan dan pemulihan** | Admin login; barang ada | Sistem harus dapat melakukan soft delete, restore, dan hapus permanen, termasuk aksi massal pada trash. | Data tidak ditemukan menghasilkan `404`; aksi massal invalid ditolak. Hapus permanen menghapus transaksi terkait dan foto. | Soft delete memindahkan barang ke trash; restore mengaktifkannya; role lain menerima `403`. | **Sudah Tersedia**; `BarangTrashController`, route `role:admin`, `BarangExistingFeaturesTest` |
| **FR-INV-007 — Import inventaris** | Admin login; satu file valid | Sistem harus dapat mengimpor XLSX/XLS/CSV dan melakukan upsert berdasarkan kode. | Header/row/file invalid atau kode duplikat/terpakai di trash membatalkan seluruh import. Stok target diterapkan melalui transaksi masuk/keluar. | Seluruh baris divalidasi sebelum commit; tidak ada write parsial; pesan memuat baris/kolom; re-upload data sama idempoten terhadap stok. | **Sudah Tersedia**; `ImportBarangRequest`, `BarangSpreadsheetImporter`, `BarangImport`, `BarangImportTest`, `InventoryStockIntegrityTest` |
| **FR-INV-008 — Export inventaris** | Admin login | Sistem harus dapat mengekspor seluruh barang aktif dalam XLSX, CSV, atau PDF. | Role lain ditolak `403`; kegagalan pembuatan/stream file menghasilkan respons error server. | File memiliki nama, Content-Type, dan isi inventaris sesuai format yang dipilih. | **Sudah Tersedia**; `BarangReportController`, `BarangCsv`, `InventoryPdfReport`, `BarangExistingFeaturesTest`, `BarangCsvTest` |

### 4.2 Modul Riwayat dan Transaksi Stok

| ID dan nama | Aktor; prasyarat | Deskripsi | Alur/respons yang diharapkan; kondisi gagal | Kriteria penerimaan | Status; referensi |
|---|---|---|---|---|---|
| **FR-STK-001 — Transaksi stok masuk/keluar** | Semua role; login; barang ada | Sistem harus dapat mencatat jenis `masuk`/`keluar`, jumlah bilangan bulat positif, dan keterangan opsional. | Transaksi valid memperbarui saldo dan mengarahkan ke detail; jenis/jumlah invalid ditolak. | Selisih saldo sama dengan jumlah dan tepat satu transaksi dibuat. | **Sudah Tersedia**; Gate `update-stock`, `BarangController::updateStok`, `StockAdjustmentService`, `InventoryStockActionUiTest` |
| **FR-STK-002 — Pencegahan stok negatif** | Semua role; transaksi keluar | Sistem harus dapat menolak stok keluar yang melebihi saldo tersedia. | Sistem memberi pesan “Stok tidak mencukupi”; saldo dan riwayat tidak berubah. | Uji jumlah keluar lebih besar dari stok menghasilkan validation error dan nol transaksi baru. | **Sudah Tersedia**; `StockAdjustmentService`, constraint `ck_barang_stok_nn`, `InventoryStockIntegrityTest`, `DatabaseStockConstraintsTest` |
| **FR-STK-003 — Transaksi atomik dan penguncian** | Sistem; database tersedia | Sistem harus dapat menjalankan pembacaan terkunci, perubahan saldo, dan penulisan riwayat dalam database transaction. | Kegagalan apa pun menyebabkan rollback; `lockForUpdate()` mencegah lost update pada barang yang sama. | Simulasi kegagalan tidak meninggalkan saldo/riwayat parsial; source menunjukkan transaction dan row lock. | **Sudah Tersedia**; `StockAdjustmentService`, `BarangImport`, `InventoryStockIntegrityTest` |
| **FR-STK-004 — Snapshot riwayat** | Sistem; transaksi valid | Sistem harus dapat menyimpan barang, jenis, jumlah, keterangan, waktu, `stok_sebelum`, dan `stok_sesudah`. | Database menolak jumlah nonpositif, snapshot negatif/tidak berpasangan, dan rumus snapshot yang salah. Snapshot legacy boleh sama-sama null. | Constraint database menerima snapshot valid dan menolak setiap kasus invalid. | **Sudah Tersedia**; `StokTransaction`, migration `2026_09_02_020000_*`, `DatabaseStockConstraintsTest` |
| **FR-STK-005 — Tampilan riwayat dan grafik** | Semua role; login; barang ada | Sistem harus dapat menampilkan timeline/tabel 20 baris per halaman dan grafik maksimal 100 transaksi. | ID tidak ada menghasilkan `404`; transaksi masa depan diabaikan; snapshot legacy ditampilkan sebagai data tidak tersedia, bukan direkonstruksi. | Data hanya milik barang terkait dan urut berdasarkan waktu/ID; titik grafik berasal dari snapshot asli. | **Sudah Tersedia**; `BarangController::riwayatStok`, view riwayat, feature test riwayat/dashboard |
| **FR-STK-006 — Penjadwalan prediksi setelah stok** | Sistem; transaksi berhasil | Sistem harus dapat menjadwalkan prediksi setelah commit transaksi stok. | Rollback tidak menulis job; kegagalan Python/fallback tidak membatalkan transaksi stok yang sah. | Job memiliki `afterCommit=true`; stok tersimpan walau engine Python gagal. | **Sudah Tersedia**; `StockAdjustmentService`, `StockPredictionScheduler`, `StockPredictionQueueTest`, `StockPredictionTest` |

### 4.3 Modul OCR Verifikasi Dokumen

| ID dan nama | Aktor; prasyarat | Deskripsi | Alur/respons yang diharapkan; kondisi gagal | Kriteria penerimaan | Status; referensi |
|---|---|---|---|---|---|
| **FR-OCR-001 — Upload dokumen** | Semua role; login; satu file/jenis valid | Sistem harus dapat menerima Surat Jalan, Invoice, atau Bukti Fisik dan menyimpan file pada disk privat. | File/jenis/ukuran invalid ditolak sebelum record/job dibuat. Upload identik oleh pengguna dan jenis sama dalam satu menit diarahkan ke antrean yang sudah ada. | File valid membuat satu record dengan `process_status=menunggu`, `authenticity_status=null`, dan satu job unik; file invalid tidak membuat record. | **Sudah Tersedia**; `VerifyDocumentRequest`, `DocumentVerificationController::store`, `ProcessDocumentVerification`, `DocumentVerificationTest` |
| **FR-OCR-002 — Proses OCR asinkron awal** | File tersimpan; worker queue `default` aktif | Sistem harus dapat memproses upload awal/retry melalui queue tanpa menunggu OCR pada request upload. | `process_status` mengikuti `menunggu` → `diproses` → `selesai`; file hilang, timeout, kontrak invalid, atau engine gagal menjadi `gagal`. `authenticity_status` tetap null sampai proses selesai. | Upload mengembalikan halaman proses; polling menunjukkan status terbaru; job OCR unik dan hanya mencoba sekali. | **Sudah Tersedia**; `ProcessDocumentVerification`, `DocumentVerificationController::status`, `DocumentVerificationTest` |
| **FR-OCR-003 — Ekstraksi dan penilaian** | Job berjalan; Python/Tesseract/dependency tersedia | Sistem harus dapat mengekstrak teks/metadata dan menghasilkan skor readability, completeness, authenticity, overall, confidence, serta temuan manipulasi/barcode. | Kontrak hasil di luar nilai/struktur yang diizinkan ditolak dan proses ditandai gagal. | Hasil valid menyimpan skor 0–100, metadata, OCR raw text, catatan, dan detail analisis. | **Sudah Tersedia**; `python/document_checker.py`, `DocumentVerificationService`, `DocumentVerificationResultWriter`, unit/feature test OCR |
| **FR-OCR-004 — Status dan interpretasi hasil** | Pengguna berhak; record ada | Sistem harus memisahkan `process_status` (`menunggu`, `diproses`, `selesai`, `gagal`) dari `authenticity_status` (`asli`, `mencurigakan`, `palsu`). | Status proses gagal menampilkan pesan aman dan opsi retry. Hasil otomatis tidak boleh dinyatakan sebagai bukti mutlak keaslian hukum. Riwayat/detail menampilkan kedua status secara terpisah; hasil memakai label Asli hijau, Mencurigakan kuning/oranye, dan Palsu merah. | Python hanya mengirim tiga nilai hasil lowercase; API, database, notifikasi, badge, dan riwayat mempertahankan kedua domain status tanpa mencampurnya. | **Terverifikasi**; model/migration, Python/service/job/controller, view verifikasi, `DocumentVerificationTest`, `DocumentVerificationContractTest` |
| **FR-OCR-005 — Pembatasan kepemilikan** | Pengguna login; dokumen ada | Sistem harus dapat membatasi daftar, hasil, status, download, retry, koreksi, dan proses ulang kepada pemilik; Admin dapat mengakses semua dokumen. | Manager/Staff yang bukan pemilik menerima `403`; file privat hilang saat download/retry/reprocess menghasilkan `404`. | Pengujian lintas pengguna menolak akses; pemilik dan Admin berhasil pada aksi yang diperbolehkan. | **Sudah Tersedia**; pemeriksaan `authorizeAccess`, `UpdateDocumentMetadataRequest`, `DocumentVerificationTest` |
| **FR-OCR-006 — Koreksi dan audit** | Pemilik/Admin; hasil ada | Sistem harus dapat menyimpan koreksi metadata dan jejak audit aktor, waktu, nilai sebelum/sesudah, sumber, dan idempotency key. | Data koreksi invalid ditolak; pengguna lain menerima `403`. | Hanya nilai berubah tercermin dalam audit dan original file tidak berubah. | **Sudah Tersedia**; `UpdateDocumentMetadataRequest`, `DocumentVerificationAuditService`, migration audit, `DocumentVerificationAuditNotificationTest` |
| **FR-OCR-007 — Retry dan proses ulang** | Pemilik/Admin; file privat tersedia | Sistem harus dapat memasukkan ulang proses gagal ke queue dan menjalankan proses ulang hasil lama. | **Retry** bersifat asinkron. **Proses ulang** saat ini bersifat sinkron melalui controller dan dapat mempertahankan atau mengganti koreksi manual. Saat proses ulang dimulai, status menjadi `diproses` dan hasil keaslian lama dikosongkan. File hilang menghasilkan `404`; kegagalan teknis menghasilkan `process_status=gagal` dan `authenticity_status=null`. | Retry membuat job; proses ulang mencatat pilihan preserve/replace; hasil baru hanya disimpan bersama `process_status=selesai`; kegagalan tidak pernah menjadi hasil `palsu`. | **Terverifikasi**; `DocumentVerificationController::retry/reprocess`, `ProcessDocumentVerification`, `DocumentVerificationTest` |
| **FR-OCR-008 — Notifikasi hasil** | Job selesai/gagal; pemilik masih ada | Sistem harus dapat memberi notifikasi database yang idempoten kepada pengunggah. | Kegagalan menyimpan notifikasi dicatat di log dan tidak mengubah hasil OCR; pengguna lain tidak dapat membaca notifikasi. | Notifikasi menunjuk hasil yang tepat, tidak duplikat, dan status baca terisolasi per pemilik. | **Sudah Tersedia**; `DocumentVerificationNotificationService`, controller notifikasi, `DocumentVerificationAuditNotificationTest` |

#### 4.3.1 Kontrak dan Kompatibilitas Status OCR

- `process_status` hanya boleh berisi `menunggu`, `diproses`, `selesai`, atau `gagal`.
- `authenticity_status` hanya boleh berisi `asli`, `mencurigakan`, atau `palsu`, dan wajib null selama proses belum `selesai`.
- Pemetaan data lama yang aman, setelah normalisasi kapitalisasi dan pemisah: `valid`/`lengkap` → `asli`; `review`/`suspicious`/`perlu_ditinjau`/`terindikasi_manipulasi` → `mencurigakan`; `terindikasi_palsu` → `palsu`; `sedang_dianalisis` → `diproses`; `gagal_diproses`/`tidak_terbaca`/`failed` → `gagal`.
- Nilai lama `palsu` dengan `error_message` diperlakukan sebagai kegagalan proses (`gagal`, hasil null); tanpa error tetap merupakan hasil `palsu`. Nilai lain yang tidak dikenal harus menghentikan migrasi sebelum perubahan data.
- Audit sebelum migrasi menemukan 21 record: 11 `asli`, 9 `mencurigakan`, dan 1 `palsu`; tidak ada nilai ambigu. Setelah migrasi, seluruh record tersebut mempunyai `process_status=selesai` dan jumlah hasil keaslian tidak berubah. Payload notifikasi lama `completed`/`failed` hanya dibaca pada lapisan kompatibilitas.

### 4.4 Modul Prediksi Restock Machine Learning

| ID dan nama | Aktor; prasyarat | Deskripsi | Alur/respons yang diharapkan; kondisi gagal | Kriteria penerimaan | Status; referensi |
|---|---|---|---|---|---|
| **FR-ML-001 — Penjadwalan analisis** | Admin/Manager login; barang ada | Sistem harus dapat menjadwalkan analisis satu barang atau seluruh barang melalui queue `stock-predictions`. | Staff ditolak `403`; kegagalan penjadwalan massal dihitung dan dilaporkan. Request tidak menjalankan Python secara langsung. | Proses menjadi `waiting` dan job dibuat; pemanggilan berulang tidak menciptakan job aktif ganda. | **Sudah Tersedia**; Gate `run-stock-prediction`, `StockPredictionController`, `StockPredictionScheduler`, `StockPredictionTest` |
| **FR-ML-002 — Masukan prediksi** | Barang dan histori tersedia | Sistem harus dapat memakai stok saat ini, minimum global 5, transaksi `keluar`, estimasi pemakaian harian, lead time, horizon, dan ambang histori. | Transaksi masuk dan transaksi bertanggal masa depan tidak menjadi konsumsi; input tak cukup dicatat. | Payload hanya memuat transaksi keluar yang relevan dan ringkasan input tersimpan bersama hasil. | **Sudah Tersedia**; `StockPredictionService::payloadFor`, `python/stock_predictor.py`, `StockPredictionTest` |
| **FR-ML-003 — Pemilihan metode** | Job berjalan | Sistem harus dapat memilih `cold_start` tanpa histori, `simple_average` untuk histori terbatas, dan `machine_learning` saat data cukup. | Default data cukup adalah histori ≥30 hari dan transaksi keluar pada ≥5 hari; tanpa histori, estimasi harian dan lead time diperlukan. | Skenario tanpa, sedikit, dan cukup histori menghasilkan metode yang sesuai. | **Sudah Tersedia**; `config/services.php`, `python/stock_predictor.py`, `StockPredictionTest` |
| **FR-ML-004 — Hasil dan kekurangan data** | Analisis selesai | Sistem harus dapat menghasilkan kebutuhan horizon 30 hari, tanggal minimum/habis, safety stock, jumlah restock, confidence, alasan, metode, dan status risiko. | Jika input cold-start belum lengkap, hasil menyatakan `Perlu Ditinjau`, `prediction_available=false`, dan daftar input yang kurang. | Nilai tersedia ditampilkan; nilai yang tidak tersedia tetap eksplisit dan tidak diganti angka rekaan. | **Sudah Tersedia**; `StockPrediction`, `StockPredictionPresenter`, `StockPresentationUiTest`, `StockPredictionTest` |
| **FR-ML-005 — Status proses dan konkurensi** | Worker database aktif | Sistem harus dapat menyimpan status `waiting`, `processing`, `completed`, atau `failed`, mencegah overlap per barang, dan menjaga generation terbaru. | Perubahan stok selama proses menyebabkan generation terbaru dijadwalkan; kegagalan akhir menyimpan pesan aman. | Satu proses aktif per barang; hasil idempoten per barang/generation; status polling sesuai database. | **Sudah Tersedia**; `StockPredictionProcess`, `ProcessStockPrediction`, migration proses, `StockPredictionQueueTest`, `StockPredictionActiveProcessTest` |
| **FR-ML-006 — Fallback kegagalan Python** | Engine timeout/gagal/JSON invalid | Sistem harus dapat menghitung fallback lokal dengan cold-start atau rata-rata sederhana. | Jika fallback juga gagal, job dicoba maksimal tiga kali lalu proses menjadi `failed`. Data stok utama tidak di-rollback. | Hasil fallback memiliki `fallback_used=true`; transaksi stok sah tetap tersimpan. | **Sudah Tersedia**; `StockPredictionService`, `ProcessStockPrediction`, `StockPredictionQueueTest` |
| **FR-ML-007 — Tampilan dan persetujuan** | Semua role login untuk melihat; Admin/Manager untuk aksi | Sistem harus dapat menampilkan hasil tersimpan kepada semua role dan membatasi analisis/detail proses/persetujuan kepada Admin/Manager. | Staff menerima `403` pada endpoint aksi. Rekomendasi nol ditolak `422`. | Persetujuan hanya membuka form stok masuk terisi dan tidak langsung mengubah saldo. | **Sudah Tersedia**; `StockPredictionController`, Gates, `StockPredictionTest` |
| **FR-ML-008 — Notifikasi risiko** | Hasil baru berubah ke `Perlu Restock`/`Mendesak` | Sistem harus dapat membuat notifikasi untuk seluruh pengguna dengan receipt baca per pengguna. | Status sama berulang tidak membuat notifikasi perubahan yang sama; pengguna tanpa receipt ditolak `403`. | Membaca notifikasi satu pengguna tidak mengubah status baca pengguna lain. | **Sudah Tersedia**; `StockPredictionService::storePrediction`, model receipt, `StockPredictionNotificationOwnershipTest` |

## 5. Aturan Bisnis Terverifikasi

| ID | Aturan dan bukti |
|---|---|
| **BR-001** | Kode barang unik melalui unique index; kode produksi otomatis berbentuk `BRG-######` dan tidak menggunakan kembali kode soft-deleted. |
| **BR-002** | Stok tidak boleh negatif; dilindungi oleh validasi/service, tipe unsigned pada skema awal, dan CHECK constraint. |
| **BR-003** | Form edit barang tidak dapat mengubah stok. Penambahan barang dan import juga menerapkan saldo melalui `StockAdjustmentService`, sehingga perubahan saldo menghasilkan transaksi `masuk`/`keluar`. |
| **BR-004** | Stok keluar yang melebihi saldo ditolak dan seluruh perubahan di-rollback. |
| **BR-005** | Transaksi baru menyimpan snapshot sebelum/sesudah; pasangan snapshot null hanya dipertahankan untuk data legacy. |
| **BR-006** | Operasi stok menggunakan database transaction dan `lockForUpdate()` pada baris barang. |
| **BR-007** | OCR memiliki status proses, hasil akhir, pesan kegagalan aman, retry, dan audit. Hasilnya merupakan indikator pemeriksaan, bukan bukti hukum mutlak. |
| **BR-008** | Prediksi memakai histori transaksi stok keluar; data masa depan diabaikan. Kekurangan input/histori ditampilkan melalui metode, reason, `prediction_available`, dan `missing_inputs`. |
| **BR-009** | Prediksi dan persetujuan rekomendasi tidak mengubah saldo otomatis; perubahan tetap melalui transaksi stok. |

## 6. Kebutuhan Non-Fungsional

### 6.1 Performa

| ID dan nama | Kebutuhan dan kondisi ukur | Kriteria penerimaan | Status; referensi |
|---|---|---|---|
| **NFR-PERF-001 — Respons operasi biasa <200 ms** | Sistem menargetkan waktu respons sisi server **p95 warm request <200 ms** untuk dashboard, daftar/detail barang, pencarian/filter/sorting/pagination, riwayat stok, stok masuk/keluar, daftar/detail verifikasi, dan polling status. Durasi dihitung sejak Laravel menerima request sampai respons siap dikirim. Cold request dicatat terpisah dan tidak dinilai dengan ambang ini. | Minimal 5 warm-up dan 30 sampel terukur per endpoint dengan pengguna terautentikasi; laporan mencatat cold request, p50, p95, maksimum, query, HTTP status, dan error. | **Perlu Uji Produksi**; validasi lokal Terverifikasi, validasi produksi Belum Dilakukan |
| **NFR-PERF-002 — Penerimaan proses background** | Penerimaan upload OCR kecil dan permintaan prediksi harus selesai **p95 warm request <200 ms** setelah validasi, penyimpanan, dan enqueue; eksekusi worker/Python tidak termasuk. | Benchmark acknowledgement OCR dan prediksi menjalankan 5 warm-up dan 30 sampel menggunakan queue database tanpa menjalankan worker serta memverifikasi satu job tersimpan per request. | **Perlu Uji Produksi**; p95 lokal OCR 18,837 ms dan prediksi 6,520 ms, keduanya tanpa error |
| **NFR-PERF-003 — Pengecualian proses panjang** | OCR/ELA/Python/ML end-to-end, waktu tunggu dan eksekusi queue worker, upload besar, import/export, serta OCR **reprocess** sinkron tidak menggunakan target p95 200 ms dan harus dilaporkan terpisah. | Benchmark respons sinkron tidak memasukkan waktu proses panjang; pengujian end-to-end proses tersebut harus memiliki laporan durasi tersendiri. | **Terverifikasi untuk batas ruang lingkup**; durasi proses panjang tidak diukur dalam benchmark ini |

#### 6.1.1 Profil dan Metode Benchmark

- Tanggal: **14 September 2026**, timezone Asia/Jakarta.
- Runtime: PHP 8.2.12, Laravel 12.68.0, SQLite 3.39.2 in-memory, Windows 11 build 26200 AMD64.
- Mode: `APP_DEBUG=false`, configuration cache tidak aktif, route cache tidak aktif, konkurensi satu pengguna Admin terautentikasi. Tidak ada web server atau jaringan database; request dijalankan melalui Laravel HTTP test kernel.
- Queue benchmark: konfigurasi pengukuran memakai driver `database` pada SQLite test. Setiap enqueue wajib menambah tepat satu baris pada tabel `jobs`; worker tidak dijalankan. Hasil ini mencakup persistensi enqueue lokal, tetapi tidak mencakup waktu tunggu atau eksekusi job. `Queue::fake()` tidak digunakan pada run final.
- Dataset sintetis: 1 pengguna, 1.000 barang, 10.000 transaksi stok, 1.000 hasil prediksi, dan 300 hasil verifikasi. Database dibuat ulang khusus benchmark dan tidak memakai data pengguna/produksi.
- Metode: satu cold request per endpoint setelah bootstrap/seeding, 5 warm-up, kemudian 30 sampel. Timer monotonic `hrtime`; p50/p95 memakai nearest-rank. Query log Laravel aktif selama sampel terukur. Nilai cold tidak memasukkan waktu bootstrap proses PHP.
- Benchmark dapat diulang dengan `php vendor/bin/phpunit tests/Benchmark/SyncEndpointBenchmark.php`; file ini sengaja berada di luar suite Unit/Feature utama agar tidak menjadi assertion waktu yang flaky.

#### 6.1.2 Hasil Setelah Optimasi

| Endpoint | Cold | p50 | p95 | Maksimum | Query |
|---|---:|---:|---:|---:|---:|
| GET `/barang` — dashboard dan daftar | 323,802 ms | 60,885 ms | 84,400 ms | 85,059 ms | 21/21 |
| GET `/barang/dashboard/activity?period=30` | 73,920 ms | 72,081 ms | 104,835 ms | 115,215 ms | 1/1 |
| GET `/barang/{id}` — detail | 14,175 ms | 10,244 ms | 13,459 ms | 16,913 ms | 5/5 |
| GET `/barang/results?...&page=2` — pencarian/filter/sort/pagination | 26,882 ms | 19,210 ms | 25,158 ms | 25,502 ms | 2/2 |
| GET `/barang/{id}/riwayat-stok?page=2` | 26,626 ms | 26,296 ms | 37,854 ms | 42,776 ms | 8/8 |
| POST `/barang/{id}/stok` — masuk | 61,355 ms | 3,839 ms | 7,802 ms | 8,147 ms | 10/10 |
| POST `/barang/{id}/stok` — keluar | 5,626 ms | 5,648 ms | 8,623 ms | 9,635 ms | 10/10 |
| GET `/verifications?page=2` — daftar | 23,051 ms | 16,448 ms | 37,290 ms | 43,035 ms | 7/7 |
| GET `/verifications/{id}` — detail | 25,261 ms | 18,543 ms | 28,319 ms | 68,768 ms | 6/6 |
| GET `/verifications/{id}/status` | 2,194 ms | 1,716 ms | 1,969 ms | 2,037 ms | 2/2 |
| POST `/verifications` — penerimaan/enqueue OCR | 63,143 ms | 10,541 ms | 18,837 ms | 21,653 ms | 6/6 |
| POST `/prediksi-stok/barang/{id}` — penerimaan/enqueue prediksi | 5,415 ms | 3,389 ms | 6,520 ms | 6,939 ms | 7/7 |

Kolom Query menyatakan p50/maksimum. Seluruh 30 sampel terukur menghasilkan HTTP 200 atau redirect 302 yang diharapkan dan nol error. Untuk stok masuk dan keluar, setiap cold, warm-up, dan sampel terukur memakai barang berbeda. Benchmark memeriksa tidak adanya validation error, perubahan saldo tepat `+1`/`-1`, penambahan tepat satu riwayat, serta jenis dan jumlah transaksi; 30/30 sampel terukur per operasi lulus. Enqueue OCR dan prediksi juga memverifikasi 30/30 request menyimpan tepat satu job.

Target hanya berlaku pada **p95 warm request <200 ms**. Cold dashboard 218,985 ms dari run terdokumentasi sebelumnya tetap dicatat sebagai cold terpisah; run final dengan queue database mencatat cold 323,802 ms. Keduanya tidak diklaim memenuhi target warm. OCR/ELA/Python/ML end-to-end, queue wait/worker, upload besar, import/export, dan OCR reprocess sinkron **tidak dijalankan** dalam benchmark ini; durasinya tidak dicampur dengan acknowledgement request.

#### 6.1.3 Bottleneck dan Pengukuran Ulang

Benchmark awal membuktikan `InventoryDashboardService::activity()` mengambil seluruh transaksi mentah lalu mengagregasikannya di PHP. Agregasi dipindahkan ke satu query database dengan batas hari UTC yang dibentuk dari timezone tampilan; format respons, rentang tanggal, dan jumlah query tidak berubah.

| Endpoint | p50 sebelum → sesudah (ms) | p95 sebelum → sesudah (ms) | Maks. sebelum → sesudah (ms) | Query sebelum → sesudah |
|---|---:|---:|---:|---:|
| GET dashboard dan daftar barang | 374,117 → 58,005 | 464,875 → 105,218 | 527,040 → 115,953 | 21 → 21 |
| GET aktivitas dashboard | 352,308 → 60,817 | 446,320 → 68,900 | 454,012 → 79,083 | 1 → 1 |

#### 6.1.4 Kompatibilitas dan Status Validasi

- **Audit sintaks MySQL:** query agregasi memakai parameter binding, `COALESCE`, `SUM`, dan `CASE WHEN`, yang didukung MySQL. Batas setiap hari dihitung Laravel menjadi timestamp UTC sehingga query tidak bergantung pada fungsi timezone khusus SQLite.
- **Uji MySQL:** **Belum Dilakukan / Perlu Uji MySQL**. Repository hanya menyediakan `.env.testing` dan PHPUnit dengan SQLite in-memory; tidak ditemukan database MySQL khusus test. Koneksi MySQL aplikasi biasa tidak digunakan agar data non-test tidak berisiko berubah.
- **Validasi lokal:** **Terverifikasi**.
- **Validasi produksi:** **Belum Dilakukan**.
- **Status keseluruhan Poin 2:** **Perlu Uji Produksi**.

### 6.2 Format dan Batas Upload

Audit dilakukan **14 September 2026** pada environment test lokal dengan file sintetis. Laravel menyatakan rule `max` file dalam KiB; 1 MiB = 1.024 KiB. Batas efektif HTTP adalah nilai terkecil dari rule Laravel, parser/engine, `upload_max_filesize`, kapasitas `post_max_size` setelah overhead multipart, batas body web server, serta kapasitas/izin penyimpanan.

`php --ini` dan `php -i` hanya membuktikan konfigurasi **PHP CLI** 8.2.12: `file_uploads=On`, `upload_max_filesize=40M`, `post_max_size=40M`, `max_file_uploads=20`, dan `upload_tmp_dir=C:\xampp\tmp`. Konfigurasi statis XAMPP Apache memuat `php8apache2_4.dll`, menunjuk `PHPINIDir C:/xampp/php`, dan tidak memuat `LimitRequestBody` pada file konfigurasi yang diperiksa. Nginx tidak ditemukan. Nilai runtime PHP Web SAPI, virtual host/override lain, kapasitas disk, dan konfigurasi produksi tidak tersedia sehingga tidak disamakan dengan hasil CLI.

| Jenis Upload | Format | MIME | Batas Laravel | Batas PHP/Web | Batas Efektif | Status |
|---|---|---|---:|---|---|---|
| Dokumen OCR (`document`) | PDF, JPG, JPEG, PNG | `application/pdf`, `image/jpeg`, `image/png` | 10.240 KiB (10 MiB) | CLI lokal: upload 40M/post 40M; Web SAPI dan produksi belum terukur; limit body Apache tidak ditemukan pada konfigurasi yang diperiksa | **Lokal: 10 MiB**. HTTP deployment: `min(10 MiB, limit Web SAPI, limit body server, kapasitas storage)`; nilai aktual belum dapat ditetapkan | **Perlu Konfirmasi Web SAPI** |
| Foto barang (`foto_barang`) | JPG, JPEG, PNG, WebP | `image/jpeg`, `image/png`, `image/webp` | 2.048 KiB (2 MiB) | CLI lokal: upload 40M/post 40M; Web SAPI dan produksi belum terukur | **Lokal: 2 MiB**. HTTP deployment mengikuti nilai terkecil seluruh lapisan | **Perlu Konfirmasi Web SAPI** |
| Import inventaris (`spreadsheet`) | XLSX, XLS, CSV | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, `application/vnd.ms-excel`; CSV menerima MIME teks/CSV yang dipetakan Symfony/Laravel | 5.120 KiB (5 MiB) | CLI lokal: upload 40M/post 40M; Web SAPI dan produksi belum terukur | **Lokal: 5 MiB**. HTTP deployment mengikuti nilai terkecil seluruh lapisan | **Perlu Konfirmasi Web SAPI** |

#### 6.2.1 Endpoint, Penyimpanan, dan Pesan Gagal

| Jenis Upload | Endpoint dan field | Penyimpanan | Penanganan kegagalan |
|---|---|---|---|
| Dokumen OCR | `POST /verifications`, field `document` | Disk `local`, direktori privat `storage/app/private/document-verifications/{user}`; nama storage dibuat oleh Laravel dan tidak memakai nama asli sebagai path | Validasi menampilkan format atau batas 10 MB tanpa path internal. File yang gagal validasi tidak disimpan dan tidak membuat record/job. Kegagalan pembuatan record/audit/enqueue membersihkan record serta file upload baru. File rusak atau PDF terenkripsi yang baru dapat diketahui saat worker berjalan ditandai gagal oleh OCR; file privat tetap direferensikan record untuk audit/retry sehingga bukan file yatim. |
| Foto barang | `POST /barang` dan `PUT /barang/{id}`, field opsional `foto_barang` | Disk `public`, direktori `storage/app/public/barang`; nama storage dibuat oleh Laravel | File kosong, non-image, pasangan MIME/ekstensi salah, atau >2 MiB menghasilkan validation error. Penolakan terjadi sebelum controller sehingga tidak membuat barang atau file. Foto baru dibersihkan bila persistensi gagal; penggantian sukses menghapus foto lama. |
| Import inventaris | `POST /barang-import` dan `POST /document-tools/import`, field `spreadsheet` | Tidak disimpan permanen oleh aplikasi; parser membaca temporary upload PHP | Pesan menyatakan file tidak dapat dibaca/format salah tanpa path internal. Workbook/CSV kosong, rusak, encoding/header/baris invalid membatalkan seluruh batch; tidak ada write parsial atau file permanen. |

#### 6.2.2 Kontrak Validasi dan Bukti Keamanan

- OCR memakai `file`, `mimes`, `extensions`, dan `max:10240`; foto memakai `filled`, `image`, `mimes`, `extensions`, pemeriksaan dimensi agar isi benar-benar dapat dibaca, dan `max:2048`; import memakai `file`, `mimes`, `extensions`, dan `max:5120`. Rule server adalah kontrol utama. Atribut HTML `accept` hanya membatasi pilihan pada UI dan bukan kontrol keamanan.
- Ekstensi executable, ekstensi ganda dengan suffix terlarang, MIME yang tidak cocok, file kosong, dan ukuran di atas batas ditolak oleh Laravel atau parser sebelum penyimpanan permanen. Test memastikan penolakan OCR tidak membuat record/job/file, penolakan foto tidak membuat barang/file, penolakan import tidak menulis barang/transaksi, serta kegagalan setup OCR/persistensi foto tidak meninggalkan artefak yatim.
- Python menerima hanya suffix lowercase-insensitive `.pdf`, `.jpg`, `.jpeg`, dan `.png`, dengan ukuran `1..10 MiB`. PDF wajib memiliki signature PDF, tidak terenkripsi, dan dapat dibuka PyMuPDF; gambar wajib benar-benar berformat JPEG/PNG dan lolos verifikasi Pillow. Format yang lolos kontrak Laravel dengan konten valid terbukti dapat dibaca Python.
- Import CSV dibaca secara streaming dengan UTF-8, struktur quote, enam header wajib, batas panjang row, dan penolakan NUL. XLSX/XLS dibaca melalui PhpSpreadsheet/Laravel Excel; workbook malformed ditolak dengan pesan aman. Tidak ada package baru.
- Penyimpanan OCR dan foto menggunakan nama hasil `store()`, bukan nama asli. Nama asli OCR hanya menjadi metadata tampilan/download yang diotorisasi.

Boundary sintetis yang lulus: OCR 10.239 dan 10.240 KiB, foto 2.047 dan 2.048 KiB, serta validasi import 5.119 dan 5.120 KiB. Nilai 10.241/2.049/5.121 KiB ditolak. PDF/image malformed, PDF terenkripsi, pertukaran isi PDF-gambar, MIME benar dengan ekstensi salah, ekstensi benar dengan MIME salah, ekstensi ganda, executable, serta file kosong juga ditolak pada lapisan yang bertanggung jawab.

- **Validasi lokal:** **Terverifikasi Lokal**.
- **PHP Web SAPI:** **Perlu Konfirmasi Web SAPI** melalui endpoint diagnostik/deployment yang aman; hasil CLI tidak boleh dipakai sebagai bukti SAPI web.
- **Produksi:** **Perlu Uji Produksi**, termasuk request multipart nyata, konfigurasi reverse proxy/load balancer, kapasitas temporary/storage, izin tulis, dan pesan untuk kegagalan sebelum request mencapai Laravel.
- **Status keseluruhan Poin 3:** **Perlu Uji Produksi**. Tidak ada kondisi aplikasi lokal yang berstatus **Belum Sesuai** setelah perbaikan.

### 6.3 Keamanan dan Audit

| ID dan nama | Kebutuhan | Kriteria penerimaan | Status; referensi |
|---|---|---|---|
| **NFR-SEC-001 — Autentikasi dan sesi** | Sistem harus membatasi fitur bisnis kepada pengguna login, meregenerasi session saat login, invalidasi saat logout, memakai CSRF web middleware, dan membatasi login gagal lima percobaan per kombinasi email/IP selama 60 detik. | Guest dialihkan ke login; login sukses meregenerasi sesi; request mutasi tanpa CSRF pada alur web ditolak; rate limit bekerja. | **Sudah Tersedia**; `AuthController`, route `auth`, `AuthRateLimitTest`, `CsrfSessionTest` |
| **NFR-SEC-002 — Role dan otorisasi server** | Sistem harus menerapkan role `admin/manager/staff` melalui route middleware/Gate/Form Request dan ownership check. | Uji URL langsung tiap role menghasilkan akses sesuai matriks; aksi terlarang `403` dan tidak mengubah data. | **Sudah Tersedia**; routes, `EnsureUserHasRole`, `AppServiceProvider`, feature test akses |
| **NFR-SEC-003 — Validasi dan proteksi file** | Sistem harus memvalidasi jenis/ukuran file serta menyimpan dokumen OCR di `storage/app/private`, terpisah dari disk foto publik. | Dokumen OCR tidak berada di symlink `public/storage`; download melewati controller dan ownership check. | **Sudah Tersedia**; `config/filesystems.php`, controller OCR, `DocumentVerificationTest` |
| **NFR-SEC-004 — Atribusi aktivitas penting** | Sistem harus menyimpan pelaku untuk koreksi/audit OCR dan permintaan/hasil prediksi. Atribusi pengguna untuk transaksi stok dan CRUD barang juga dibutuhkan agar audit operasional lengkap. | OCR audit dan prediksi memuat user; transaksi stok/CRUD barang dapat ditelusuri ke aktor. Saat ini `stok_transactions` tidak memiliki `user_id` dan tidak ditemukan audit CRUD barang. | **Tersedia Sebagian**; audit OCR dan `requested_by/analyzed_by` tersedia; atribusi stok/CRUD **belum tersedia** |
| **NFR-SEC-005 — Permission granular** | Jika bisnis memerlukan permission selain tiga role tetap, sistem harus menyediakan definisi, assignment, dan enforcement permission. | Admin dapat mengelola permission dan pengujian membuktikan enforcement. | **Perlu Konfirmasi**; implementasi saat ini hanya tiga nilai role, tanpa sistem permission |

### 6.4 Integritas dan Reliabilitas

| ID dan nama | Kebutuhan | Kriteria penerimaan | Status; referensi |
|---|---|---|---|
| **NFR-REL-001 — Integritas stok** | Sistem harus mencegah saldo/jumlah/snapshot negatif atau tidak konsisten melalui validasi, transaction, row lock, dan constraint database. | Uji service dan penulisan database langsung menolak semua keadaan invalid tanpa data parsial. | **Sudah Tersedia**; `StockAdjustmentService`, migration constraint, `DatabaseStockConstraintsTest` |
| **NFR-REL-002 — Atomic import** | Import harus all-or-nothing dan bounded ketika melaporkan error. | Satu row gagal membatalkan semua perubahan; maksimal 100 alasan rinci ditampilkan; batch valid tersimpan penuh. | **Sudah Tersedia**; `BarangImport`, `BarangImportTest`, `InventoryStockIntegrityTest` |
| **NFR-REL-003 — Reliabilitas OCR** | OCR harus mempunyai status proses dan hasil keaslian yang persisten dan terpisah, job unik, timeout, pesan aman, retry, dan audit. Job timeout 300 detik; service menerapkan timeout minimum efektif 240 detik; database queue `retry_after` default 360 detik. | File/engine/kontrak gagal menghasilkan `process_status=gagal` dan `authenticity_status=null`; kegagalan notifikasi tidak mengubah hasil OCR. | **Sudah Tersedia**; job/service OCR, `config/queue.php`, feature test OCR |
| **NFR-REL-004 — Reliabilitas prediksi** | Prediksi harus memakai queue khusus, overlap lock, generation guard, maksimal tiga percobaan, timeout job 60 detik, dan fallback lokal. | Python gagal tidak merusak stok; fallback ditandai; kegagalan total berakhir `failed` dengan pesan aman. | **Sudah Tersedia**; `ProcessStockPrediction`, `StockPredictionService`, `StockPredictionQueueTest` |
| **NFR-REL-005 — Ketersediaan worker** | Operasi background membutuhkan worker queue `default` dan `stock-predictions`. Sistem seharusnya dapat memastikan operator mengetahui bila worker tidak berjalan. | Health/status worker dapat dibuktikan dari aplikasi atau monitoring deployment. | **Perlu Konfirmasi**; worker diperlukan, tetapi tidak ada health monitoring worker di source |

### 6.5 Kemudahan Penggunaan dan Kompatibilitas

| ID dan nama | Kebutuhan | Kriteria penerimaan | Status; referensi |
|---|---|---|---|
| **NFR-USE-001 — Pesan dan status** | Sistem harus menampilkan pesan validasi yang spesifik, status antrean/proses/gagal yang jelas, empty/loading/error state, dan nilai prediksi yang tidak tersedia secara eksplisit. | Skenario input invalid dan proses gagal menampilkan pesan aman yang dapat ditindaklanjuti tanpa stack trace. | **Sudah Tersedia**; Form Request/controller/presenter dan feature test UI |
| **NFR-USE-002 — Responsivitas UI** | Aksi utama harus tetap dapat digunakan pada desktop, tablet, dan ponsel; tabel kecil memakai overflow internal dan label “Kelola Stok” tetap terlihat. | Pemeriksaan pada 1440 px, 834 px, dan 390 px tidak kehilangan aksi utama atau menyebabkan overflow seluruh halaman. Test response/CSS ada, tetapi tidak ditemukan automated browser visual/E2E test. | **Tersedia Sebagian**; view/CSS, `InventoryStockActionUiTest`; verifikasi browser manual masih diperlukan |
| **NFR-COMP-001 — Browser yang didukung** | Sistem harus menetapkan nama dan versi minimum browser yang didukung. | Matriks browser/version disetujui dan seluruh alur utama lulus cross-browser test. | **Perlu Konfirmasi**; repository hanya menyebut browser modern tanpa versi minimum |
| **NFR-COMP-002 — Lingkungan aplikasi** | Runtime aplikasi memerlukan PHP 8.2+, database MySQL/MariaDB untuk penggunaan normal, Python beserta dependency, dan Tesseract pada PATH untuk OCR. Node/npm hanya diperlukan untuk rebuild aset. | Pemeriksaan platform dan smoke test deployment lulus pada lingkungan target. OS/web server/database/Python/Tesseract versi produksi belum ditetapkan dalam repository. | **Tersedia Sebagian**; `composer.json`, README, `python/requirements.txt`; detail deployment **Perlu Konfirmasi** |

## 7. Matriks Ketertelusuran

| ID Kebutuhan | Modul | Aktor | Status | Referensi Implementasi | Kriteria Penerimaan Ringkas |
|---|---|---|---|---|---|
| FR-INV-001 | Inventaris | Semua role | Sudah Tersedia | Controller/filter/test inventaris | Cari aktif, paginasi, soft-delete terpisah |
| FR-INV-002 | Inventaris | Semua role | Sudah Tersedia | Filter request/controller/test | Filter/sort hanya nilai whitelist |
| FR-INV-003 | Inventaris | Semua role | Sudah Tersedia | Dashboard service/test | Detail/ringkasan/periode benar |
| FR-INV-004 | Inventaris | Admin | Sudah Tersedia | Code generator/migration/test | Kode unik dan saldo awal tercatat |
| FR-INV-005 | Inventaris | Admin | Sudah Tersedia | Update request/model/test | Edit tidak mengubah kode/stok |
| FR-INV-006 | Inventaris | Admin | Sudah Tersedia | Trash controller/routes/test | Delete/restore sesuai role |
| FR-INV-007 | Inventaris | Admin | Sudah Tersedia | Import request/service/test | Atomic upsert dan error per baris |
| FR-INV-008 | Inventaris | Admin | Sudah Tersedia | Report controller/services/tests | XLSX/CSV/PDF dapat diunduh |
| FR-STK-001 | Stok | Semua role | Sudah Tersedia | Controller/Gate/service/test | Saldo dan satu transaksi sesuai |
| FR-STK-002 | Stok | Semua role | Sudah Tersedia | Service/constraint/test | Overdraw ditolak tanpa perubahan |
| FR-STK-003 | Stok | Sistem | Sudah Tersedia | Stock service/import/test | Transaction+lock; rollback utuh |
| FR-STK-004 | Stok | Sistem | Sudah Tersedia | Model/migration/test | Snapshot valid; invalid ditolak DB |
| FR-STK-005 | Stok | Semua role | Sudah Tersedia | Controller/view/tests | Timeline/grafik dari data asli |
| FR-STK-006 | Stok/ML | Sistem | Sudah Tersedia | Scheduler/queue tests | Job hanya sesudah commit |
| FR-OCR-001 | OCR | Semua role | Sudah Tersedia | Request/controller/job/test | Satu upload valid menjadi antrean unik |
| FR-OCR-002 | OCR | Sistem | Sudah Tersedia | Job/status controller/test | Status proses dan gagal persisten |
| FR-OCR-003 | OCR | Sistem | Sudah Tersedia | Python/service/writer/tests | Kontrak skor/metadata tervalidasi |
| FR-OCR-004 | OCR | Semua role | Sudah Tersedia | Status payload/views/tests | Status proses dan hasil akhir tepat |
| FR-OCR-005 | OCR | Semua role | Sudah Tersedia | Ownership checks/tests | Pemilik/Admin saja; lainnya 403 |
| FR-OCR-006 | OCR | Pemilik/Admin | Sudah Tersedia | Request/audit service/tests | Koreksi dan before/after tercatat |
| FR-OCR-007 | OCR | Pemilik/Admin | Sudah Tersedia | Controller/job/tests | Retry async; reprocess sync aman |
| FR-OCR-008 | OCR | Sistem | Sudah Tersedia | Notification service/tests | Idempoten dan terisolasi per pemilik |
| FR-ML-001 | Prediksi | Admin/Manager | Sudah Tersedia | Gate/controller/scheduler/tests | Queue tanpa job aktif duplikat |
| FR-ML-002 | Prediksi | Sistem | Sudah Tersedia | Prediction service/Python/tests | Hanya histori keluar relevan |
| FR-ML-003 | Prediksi | Sistem | Sudah Tersedia | Config/Python/tests | Metode sesuai kecukupan data |
| FR-ML-004 | Prediksi | Semua role | Sudah Tersedia | Model/presenter/tests | Hasil/missing input eksplisit |
| FR-ML-005 | Prediksi | Sistem | Sudah Tersedia | Process model/job/tests | Status, lock, generation konsisten |
| FR-ML-006 | Prediksi | Sistem | Sudah Tersedia | Service/job/tests | Fallback aman; kegagalan akhir jelas |
| FR-ML-007 | Prediksi | Semua role | Sudah Tersedia | Controller/Gates/tests | View umum; aksi terbatas; no auto-stock |
| FR-ML-008 | Prediksi | Sistem | Sudah Tersedia | Receipt models/service/test | Status baca per pengguna |
| NFR-PERF-001 | Performa | Sistem | Perlu Uji Produksi | Benchmark sinkron lokal | Seluruh p95 lokal <200 ms; ulang pada stack produksi |
| NFR-PERF-002 | Performa | Sistem | Perlu Uji Produksi | Benchmark enqueue database lokal | OCR 18,837 ms; prediksi 6,520 ms; worker dikecualikan |
| NFR-PERF-003 | Performa | Sistem | Terverifikasi untuk ruang lingkup | Benchmark/controller/job | Proses panjang tidak dicampur dengan acknowledgement |
| NFR-FILE-001 | File OCR | Semua role | Perlu Uji Produksi | Verify request/Python/test | Lokal Terverifikasi; Web SAPI/produksi dikonfirmasi |
| NFR-FILE-002 | File import | Admin | Perlu Uji Produksi | Import request/service/test | Lokal Terverifikasi; Web SAPI/produksi dikonfirmasi |
| NFR-FILE-003 | Foto | Admin | Perlu Uji Produksi | Store request/photo test | Lokal Terverifikasi; Web SAPI/produksi dikonfirmasi |
| NFR-FILE-004 | Export | Admin | Sudah Tersedia | Report services/tests | Content-Type/nama/isi benar |
| NFR-SEC-001 | Keamanan | Semua role | Sudah Tersedia | Auth/routes/tests | Auth/session/CSRF/rate limit lulus |
| NFR-SEC-002 | Keamanan | Semua role | Sudah Tersedia | Middleware/Gates/tests | Matriks role ditegakkan server-side |
| NFR-SEC-003 | Keamanan | Pemilik/Admin | Sudah Tersedia | Filesystem/controller/test | OCR privat dan download berotorisasi |
| NFR-SEC-004 | Audit | Sistem | Tersedia Sebagian | OCR/prediction actor fields | Aktor stok/CRUD belum tercatat |
| NFR-SEC-005 | Permission | Admin | Perlu Konfirmasi | Tidak ada implementasi | Kebutuhan permission granular diputuskan |
| NFR-REL-001 | Reliabilitas | Sistem | Sudah Tersedia | Service/constraint/tests | Data stok invalid ditolak |
| NFR-REL-002 | Reliabilitas | Sistem | Sudah Tersedia | Import/tests | Import atomic dan error bounded |
| NFR-REL-003 | Reliabilitas | Sistem | Sudah Tersedia | OCR job/service/tests | Gagal aman, retry, audit, timeout |
| NFR-REL-004 | Reliabilitas | Sistem | Sudah Tersedia | Prediction job/service/tests | Retry/fallback/generation aman |
| NFR-REL-005 | Operasional | Operator | Perlu Konfirmasi | Tidak ada health monitor | Status worker dapat dipantau |
| NFR-USE-001 | Usability | Semua role | Sudah Tersedia | Requests/views/tests | Pesan/status dapat ditindaklanjuti |
| NFR-USE-002 | Usability | Semua role | Tersedia Sebagian | View/CSS/response tests | Uji manual/browser tiga viewport lulus |
| NFR-COMP-001 | Kompatibilitas | Semua role | Perlu Konfirmasi | Tidak ada matriks browser | Browser/version minimum ditetapkan |
| NFR-COMP-002 | Kompatibilitas | Operator | Tersedia Sebagian | README/dependency files | Versi deployment target disetujui |

## 8. Temuan Ketidaksesuaian dan Hal yang Perlu Dikonfirmasi

### 8.1 Ketidaksesuaian SRS Lama yang Telah Diperbaiki

1. **Poin 1 — Konsistensi Status OCR: Terverifikasi.** Status proses kini disimpan pada `process_status` dengan nilai `menunggu`, `diproses`, `selesai`, atau `gagal`; hasil keaslian disimpan terpisah pada `authenticity_status` dengan nilai `asli`, `mencurigakan`, atau `palsu`. Respons Python/API dan UI memakai kontrak yang sama. Istilah proses Inggris hanya dibaca untuk kompatibilitas payload notifikasi lama dan tidak ditulis oleh alur baru.
2. SRS sebelumnya dapat dibaca seolah semua OCR berjalan asinkron. Upload awal dan retry memakai queue, tetapi aksi **proses ulang** memanggil engine secara sinkron dari controller.
3. **Poin 2 — Target respons p95 `<200 ms`: Perlu Uji Produksi.** Benchmark lokal awal menemukan dashboard 464,875 ms dan activity 446,320 ms. Setelah agregasi transaksi dipindahkan dari PHP ke database, p95 turun menjadi 105,218 ms dan 68,900 ms; seluruh endpoint lokal lulus tanpa error. Status belum dinaikkan menjadi Terverifikasi penuh karena MySQL, web server, jaringan database, cache, dan konkurensi produksi belum diuji.
4. SRS sebelumnya tidak membedakan batas Laravel dengan batas PHP/web server. Batas CLI lokal adalah 40M, tetapi SAPI web/deployment belum diverifikasi.
5. **Poin 3 — Format dan batas upload: Perlu Uji Produksi.** Validasi lokal untuk OCR, foto, dan import telah Terverifikasi: MIME dan ekstensi diperiksa bersama, boundary inklusif lulus, file berbahaya/rusak ditolak, nama storage aman, serta kegagalan tidak membuat artefak yatim. Nilai Web SAPI dan limit body produksi belum tersedia sehingga batas efektif produksi belum dapat diklaim.
6. “Mengelola pengguna, role, dan permission” perlu dipisah. Admin dapat CRUD pengguna dan menetapkan tiga role tetap, tetapi tidak ada sistem permission granular.
7. Atribusi aktivitas belum lengkap: audit OCR serta pelaku permintaan/analisis prediksi tersedia, sedangkan transaksi stok dan CRUD barang tidak menyimpan pelaku.

### 8.2 Perlu Konfirmasi

1. Uji produksi performa: ulangi benchmark pada MySQL/web server target dengan jaringan database, cache deployment, volume aktual, dan tingkat konkurensi yang disetujui.
2. Nilai runtime `upload_max_filesize`, `post_max_size`, `max_file_uploads`, limit body reverse proxy/web server, kapasitas temporary/storage, dan izin tulis pada Web SAPI/deployment produksi.
3. Apakah bisnis memerlukan permission granular di luar tiga role tetap.
4. Apakah audit pelaku wajib ditambahkan untuk transaksi stok dan CRUD barang.
5. Browser dan versi minimum yang didukung serta hasil uji lintas browser/viewport aktual.
6. OS, web server, versi database, versi Python/Tesseract, kapasitas worker, target waktu OCR/ML end-to-end, dan mekanisme health monitoring produksi.

## 9. Kesimpulan Validasi Tugas 1.1

Ruang lingkup dokumentasi Tugas 1.1 telah lengkap: persona, hak akses aktual, kebutuhan fungsional empat modul, kebutuhan non-fungsional, aturan bisnis, alur gagal, kriteria penerimaan, status, referensi, dan matriks ketertelusuran telah tersedia dengan ID unik.

Namun, **Tugas 1.1 belum dapat dinyatakan memenuhi seluruh target operasional tanpa syarat**. Target respons `<200 ms` sudah lulus pada profil benchmark lokal, tetapi masih memerlukan uji produksi; konfigurasi upload SAPI web, browser minimum, kebutuhan permission granular, audit aktor stok/CRUD, dan detail lingkungan deployment juga masih memerlukan pengujian atau konfirmasi stakeholder. Seluruh fungsi yang diberi status **Sudah Tersedia** didukung implementasi dan suite test yang lulus pada lingkungan audit.
