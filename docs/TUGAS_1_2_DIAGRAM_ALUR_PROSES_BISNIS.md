# Tugas 1.2 — Diagram Alur Proses Bisnis LogistikKu

## 1. Use Case Diagram Sistem

Diagram berikut menggambarkan hak akses aktual pada sistem. Diagram ini merupakan representasi Use Case UML menggunakan `flowchart` Mermaid: batas sistem ditunjukkan oleh subgraf, use case oleh bentuk oval, dan generalisasi aktor oleh panah putus-putus. Semua fitur aplikasi selain login berada di balik middleware `auth`. Admin memiliki akses administratif, sedangkan Manager dan Staff Gudang hanya dapat mengakses dokumen yang mereka unggah sendiri. Hasil verifikasi dokumen merupakan bantuan pemeriksaan dan bukan bukti mutlak keaslian hukum.

```mermaid
flowchart LR
    pengguna[Pengguna]
    admin[Admin]
    manager[Manager]
    staff[Staff Gudang]

    subgraph sistem["Sistem LogistikKu"]
        direction TB

        UC01([Login dan logout])
        UC02([Melihat dashboard dan inventaris])
        UC03([Melihat detail dan riwayat stok])
        UC04([Mencatat stok masuk atau keluar])
        UC05([Mengajukan verifikasi dokumen])
        UC06([Melihat status dan hasil dokumen sendiri])
        UC07([Mengunduh dokumen sendiri])
        UC08([Mengoreksi metadata OCR dokumen sendiri])
        UC09([Retry OCR dokumen sendiri yang gagal])
        UC10([Memproses ulang hasil OCR dokumen sendiri])
        UC11([Melihat hasil prediksi stok])
        UC11A([Menerima notifikasi hasil OCR])
        UC11B([Menerima notifikasi risiko stok])

        UC12([Menjalankan prediksi stok])
        UC13([Menyetujui rekomendasi restock])

        UC14([Mengelola data barang])
        UC15([Import dan export inventaris])
        UC16([Mengelola trash barang])
        UC17([Mengelola pengguna dan role])
        UC18([Mengakses seluruh verifikasi dokumen])

        UC19([Memvalidasi transaksi stok])
        UC20([Menyimpan transaksi dan snapshot stok])
        UC21([Memvalidasi dan menyimpan upload privat])
        UC22([Memasukkan pekerjaan OCR ke antrean])
        UC23([Membuka form stok masuk terisi])
    end

    admin -. "peran" .-> pengguna
    manager -. "peran" .-> pengguna
    staff -. "peran" .-> pengguna

    pengguna --- UC01
    pengguna --- UC02
    pengguna --- UC03
    pengguna --- UC04
    pengguna --- UC05
    pengguna --- UC06
    pengguna --- UC07
    pengguna --- UC08
    pengguna --- UC09
    pengguna --- UC10
    pengguna --- UC11
    pengguna --- UC11A
    pengguna --- UC11B

    manager --- UC12
    manager --- UC13
    admin --- UC12
    admin --- UC13
    admin --- UC14
    admin --- UC15
    admin --- UC16
    admin --- UC17
    admin --- UC18

    UC04 -. "«include»" .-> UC19
    UC04 -. "«include»" .-> UC20
    UC05 -. "«include»" .-> UC21
    UC05 -. "«include»" .-> UC22
    UC08 -. "«extend», bila metadata perlu dikoreksi" .-> UC06
    UC09 -. "«extend», bila proses gagal" .-> UC06
    UC09 -. "«include»" .-> UC22
    UC10 -. "«extend», bila hasil perlu dianalisis ulang" .-> UC06
    UC13 -. "«include»" .-> UC23

    classDef actor fill:#fff,stroke:#334155,stroke-width:2px,color:#0f172a;
    classDef common fill:#e0f2fe,stroke:#0284c7,color:#0c4a6e;
    classDef elevated fill:#fef3c7,stroke:#d97706,color:#78350f;
    classDef adminOnly fill:#fee2e2,stroke:#dc2626,color:#7f1d1d;
    classDef internal fill:#f1f5f9,stroke:#64748b,color:#334155;
    class pengguna,admin,manager,staff actor;
    class UC01,UC02,UC03,UC04,UC05,UC06,UC07,UC08,UC09,UC10,UC11,UC11A,UC11B common;
    class UC12,UC13 elevated;
    class UC14,UC15,UC16,UC17,UC18 adminOnly;
    class UC19,UC20,UC21,UC22,UC23 internal;
```

`«include»` digunakan untuk langkah wajib dari use case induk. `«extend»` digunakan untuk tindakan opsional yang hanya tersedia pada kondisi tertentu. Persetujuan rekomendasi restock hanya membuka form stok masuk yang telah terisi; saldo belum berubah sampai pengguna mengirim transaksi stok yang valid.

### Ringkasan hak akses

| Kelompok use case | Admin | Manager | Staff Gudang |
|---|:---:|:---:|:---:|
| Dashboard, inventaris, detail, dan riwayat | Ya | Ya | Ya |
| Stok masuk dan stok keluar | Ya | Ya | Ya |
| Upload dan pengelolaan dokumen sendiri | Ya | Ya | Ya |
| Melihat hasil prediksi tersimpan | Ya | Ya | Ya |
| Menjalankan prediksi dan menyetujui restock | Ya | Ya | Tidak |
| CRUD barang, import/export, dan trash | Ya | Tidak | Tidak |
| Mengelola pengguna dan role | Ya | Tidak | Tidak |
| Mengakses dokumen pengguna lain | Ya | Tidak | Tidak |

## 2. Activity Diagram — Alur Stok Masuk/Keluar

```mermaid
flowchart TD
    start((Mulai)) --> A[Pengguna meminta form stok]
    A --> B{Lolos middleware auth?}
    B -- Tidak --> C[Arahkan ke halaman login]
    C --> D{Login berhasil?}
    D -- Tidak --> C
    D -- Ya --> A
    B -- Ya --> E{Lolos Gate update-stock?}
    E -- Tidak --> F[Tolak akses dengan HTTP 403]
    F --> stopDenied((Selesai))
    E -- Ya --> G[Tampilkan barang dan form stok]

    G --> H[Pengguna memilih masuk atau keluar lalu mengisi jumlah dan keterangan]
    H --> I[Kirim POST transaksi stok]
    I --> J[Middleware auth dan Gate diperiksa kembali]
    J --> K{Request tetap berhak?}
    K -- Tidak --> L[Redirect login atau respons HTTP 403]
    L --> stopDenied
    K -- Ya --> M{Jenis valid dan jumlah bilangan bulat positif?}
    M -- Tidak --> N[Tampilkan kesalahan validasi]
    N --> G

    M -- Ya --> O[Mulai transaksi database]
    O --> P[Kunci baris barang dengan lockForUpdate]
    P --> Q[Ambil stok sebelum dan hitung stok sesudah]
    Q --> R{Stok sesudah bernilai negatif?}
    R -- Ya --> S[Batalkan transaksi]
    S --> T[Tampilkan pesan: Stok tidak mencukupi]
    T --> G
    R -- Tidak --> U[Perbarui saldo barang]
    U --> V[Simpan transaksi, keterangan, dan snapshot sebelum/sesudah]
    V --> W{Seluruh operasi database berhasil?}
    W -- Tidak --> X[Rollback seluruh perubahan; job tidak dibuat]
    X --> stopFailed((Request gagal))
    W -- Ya --> Y[Commit transaksi database]
    Y --> Z[Dispatch job prediksi melalui afterCommit ke queue stock-predictions]
    Z --> AA[Arahkan ke detail barang dengan stok terbaru]
    AA --> stopSuccess((Selesai))

    classDef user fill:#e0f2fe,stroke:#0284c7,color:#0c4a6e;
    classDef system fill:#f1f5f9,stroke:#64748b,color:#334155;
    classDef decision fill:#fef3c7,stroke:#d97706,color:#78350f;
    classDef error fill:#fee2e2,stroke:#dc2626,color:#7f1d1d;
    classDef success fill:#dcfce7,stroke:#16a34a,color:#14532d;
    class A,C,G,H,I,AA user;
    class J,O,P,Q,U,V,Y,Z system;
    class B,D,E,K,M,R,W decision;
    class F,L,N,S,T,X,stopFailed error;
    class start,stopDenied,stopSuccess success;
```

Aturan bisnis utama:

1. Saldo stok hanya berubah melalui transaksi stok, bukan melalui form edit barang.
2. Stok keluar tidak boleh membuat saldo negatif.
3. Perubahan saldo dan pencatatan riwayat dilakukan secara atomik dalam satu transaksi database.
4. Setiap transaksi menyimpan `stok_sebelum` dan `stok_sesudah`.
5. `StockPredictionScheduler` mendaftarkan `ProcessStockPrediction` dengan `afterCommit`; job baru dilepas ke queue `stock-predictions` setelah transaksi database berhasil di-commit. Rollback tidak meninggalkan job.
6. Transaksi stok belum menyimpan `user_id`, sehingga diagram tidak mengklaim adanya audit pelaku untuk stok.

## 3. Activity Diagram — Alur Verifikasi Dokumen

```mermaid
flowchart TD
    start((Mulai)) --> A[Pengguna meminta halaman verifikasi]
    A --> B{Lolos middleware auth?}
    B -- Tidak --> C[Arahkan ke halaman login]
    C --> A
    B -- Ya --> D[Tampilkan form dan riwayat sesuai kepemilikan]
    D --> E[Pilih jenis dan unggah satu dokumen]
    E --> F{Jenis, format, ekstensi, dan ukuran file valid?}
    F -- Tidak --> G[Tampilkan kesalahan validasi]
    G --> D

    F -- Ya --> H{Hash file, pengguna, dan jenis sama masih tercatat di cache 1 menit?}
    H -- Ya --> I[Arahkan ke halaman proses record yang sudah ada]
    H -- Tidak --> J[Simpan file pada disk local privat]
    J --> K{File berhasil disimpan?}
    K -- Tidak --> L[Tampilkan pesan gagal]
    L --> D
    K -- Ya --> M[Buat record: process_status menunggu dan authenticity_status null]
    M --> N[Catat audit upload dan antrean]
    N --> O[Dispatch job unik ProcessDocumentVerification ke queue default]
    O --> P{Record, audit, dan dispatch berhasil?}
    P -- Tidak --> Q[Hapus record serta file baru dan tampilkan pesan gagal]
    Q --> D
    P -- Ya --> I

    I --> R[Halaman melakukan polling status setiap 2 detik hingga selesai atau gagal]
    R --> S[Worker mengambil job; tries 1 dan timeout 300 detik]
    S --> T[Ubah status menjadi diproses dan catat audit]
    T --> U{File privat masih tersedia?}
    U -- Tidak --> V[Tandai gagal; authenticity_status tetap null]
    U -- Ya --> W[Jalankan engine Python OCR]
    W --> X[Ekstrak teks dan metadata serta hitung skor]
    X --> Y{Kontrak hasil valid?}
    Y -- Tidak --> V
    Y -- Ya --> Z[Simpan hasil dan status selesai dalam transaksi]
    Z --> AA[Catat audit lalu coba kirim notifikasi database]
    V --> AB[Catat audit lalu coba kirim notifikasi database]

    AA --> AC[Polling menampilkan tautan hasil]
    AB --> AD[Polling menampilkan pesan aman dan opsi retry]
    AD --> AE{Pemilik atau Admin memilih retry?}
    AE -- Ya --> AF{File masih tersedia?}
    AF -- Tidak --> AG[Respons HTTP 404]
    AF -- Ya --> AH[Ubah status menjadi menunggu dan catat audit retry]
    AH --> O
    AE -- Tidak --> stopFailed((Selesai))
    AG --> stopFailed

    AC --> AI[Pengguna meninjau hasil dan metadata]
    AI --> AJ{Tindakan lanjutan?}
    AJ -- Koreksi --> AK[Validasi dan simpan perubahan metadata serta audit]
    AK --> AI
    AJ -- Proses ulang --> AL[Periksa kepemilikan dan keberadaan file]
    AL --> AM[Catat pilihan pertahankan atau ganti koreksi; kosongkan authenticity_status lama]
    AM --> AN[Jalankan OCR sinkron melalui controller]
    AN --> AO{Proses ulang berhasil?}
    AO -- Ya --> AP[Simpan hasil baru dan audit]
    AP --> AI
    AO -- Tidak --> AQ[Simpan status gagal, authenticity_status null, dan audit]
    AQ --> AI
    AJ -- Selesai --> stopSuccess((Selesai))

    classDef user fill:#e0f2fe,stroke:#0284c7,color:#0c4a6e;
    classDef system fill:#f1f5f9,stroke:#64748b,color:#334155;
    classDef decision fill:#fef3c7,stroke:#d97706,color:#78350f;
    classDef error fill:#fee2e2,stroke:#dc2626,color:#7f1d1d;
    classDef success fill:#dcfce7,stroke:#16a34a,color:#14532d;
    class A,C,D,E,I,AI,AK user;
    class J,M,N,O,R,S,T,W,X,Z,AA,AB,AH,AM,AN,AP,AQ system;
    class B,F,H,K,P,U,Y,AE,AF,AJ,AO decision;
    class G,L,Q,V,AD,AG error;
    class start,stopFailed,stopSuccess success;
```

Aturan bisnis utama:

1. Jenis dokumen aktual adalah Surat Jalan (`surat_jalan`), Invoice (`invoice`), dan Bukti Fisik (`bukti_fisik`). Judul “Informasi Bukti Penerimaan” pada form metadata tidak mengubah nama jenis dokumen `bukti_fisik`.
2. File PDF, JPG, JPEG, atau PNG maksimal 10 MB disimpan pada disk `local`, yang berakar di `storage/app/private`.
3. Upload awal dan retry diproses asinkron melalui queue konfigurasi default (`default` pada konfigurasi database bawaan). Proses ulang berjalan sinkron melalui controller dan tidak dimasukkan ke queue.
4. Duplikat ditentukan oleh hash isi file, ID pengguna, dan jenis dokumen yang sama selama entri cache satu menit masih ada; record lama juga harus masih ditemukan dan dimiliki pengguna tersebut.
5. `process_status` dipisahkan dari `authenticity_status`; kegagalan teknis bukan berarti dokumen palsu.
6. Manager dan Staff Gudang hanya dapat mengakses dokumen sendiri, sedangkan Admin dapat mengakses seluruh dokumen.
7. Upload, antrean, mulai/selesai/gagal OCR, retry, proses ulang, dan koreksi metadata dicatat dalam jejak audit sesuai jalurnya. Notifikasi database dikirim secara aman setelah job upload awal/retry selesai atau gagal; kegagalan notifikasi tidak mengubah hasil OCR.
8. Hasil otomatis tetap perlu ditinjau manusia dan bukan keputusan hukum final.

## 4. Ketertelusuran ke Kebutuhan

| Diagram | Kebutuhan terkait |
|---|---|
| Use Case Diagram | FR-INV-001–008, FR-STK-001–006, FR-OCR-001–008, dan FR-ML-001–008. Manajemen pengguna/role berasal dari route resource `users` dan `UserController`; SRS Tugas 1.1 tidak memberinya ID FR tersendiri. |
| Activity Diagram Stok | FR-STK-001, FR-STK-002, FR-STK-003, FR-STK-004, FR-STK-006 |
| Activity Diagram Verifikasi Dokumen | FR-OCR-001, FR-OCR-002, FR-OCR-003, FR-OCR-004, FR-OCR-005, FR-OCR-006, FR-OCR-007, FR-OCR-008 |

## 5. Dasar Validasi

Diagram dicocokkan dengan `routes/web.php`, middleware `EnsureUserHasRole`, Gate pada `AppServiceProvider`, Form Request, controller barang/prediksi/verifikasi, `StockAdjustmentService`, `StockPredictionScheduler`, job OCR/prediksi, model dan migration terkait, engine `python/document_checker.py`, feature/unit test, serta `SRS_TUGAS_1_1.md`.

Otorisasi aktual tidak menggunakan class Policy atau Spatie Permission. Aplikasi menggunakan middleware `auth`, middleware `role:admin`, Gate `update-stock`, `run-stock-prediction`, dan `approve-restock`, otorisasi Form Request, serta pemeriksaan pemilik dokumen atau Admin di controller.
