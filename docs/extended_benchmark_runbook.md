# Runbook Benchmark Lanjutan LogistikKu

Dokumen ini melengkapi `database_benchmark_report.md`. Semua hasil harus menyebut
target, waktu, commit, konfigurasi, dan jenis dataset. Hasil lokal atau sintetis
tidak boleh diberi label sebagai hasil produksi.

## Cakupan

| Area | Runner | Metrik utama |
|---|---|---|
| Load, stress, soak | `scripts/benchmark/http-load.mjs` | RPS, error rate, p50/p95/p99/max, status HTTP |
| Network, web server, TLS | probe pada HTTP runner | DNS, TCP, TLS, TTFB, total, payload |
| Resource server | HTTP runner dengan `--server-pid` | CPU sistem, RAM sistem, peak RSS server |
| Core Web Vitals lab | `scripts/benchmark/core-web-vitals.mjs` | Lighthouse score, FCP, LCP, CLS, TBT, Speed Index |
| OCR end-to-end | `python/benchmarks/ocr_benchmark.py` | latency, CER, WER, exact field accuracy |
| Prediksi stok | `python/benchmarks/prediction_benchmark.py` | MAE, RMSE, MAPE, bias, cold/warm latency |
| MySQL concurrency | `benchmark:mysql-concurrency` | throughput, p50/p95/max, query/tx, gagal, integritas ledger |

## Prasyarat umum

1. Gunakan commit dan dataset tetap untuk setiap perbandingan.
2. Jalankan `composer install`, `npm ci`, dan instal `python/requirements.txt`.
3. Gunakan `APP_DEBUG=false`, cache konfigurasi/rute/view, queue worker asli, dan
   web server yang sama dengan target deployment.
4. Profil selain `smoke` wajib mendapat izin pemilik target. Jangan menjalankan
   stress/soak pada layanan live tanpa jadwal, observability, dan rollback.
5. Simpan JSON pada direktori artefak di luar repository atau direktori yang
   memang disiapkan CI. Jangan memasukkan cookie, password, atau dokumen pengguna.

## 1. Smoke, load, stress, dan soak

Runner tidak membutuhkan k6; Node.js 18+ sudah cukup. Endpoint default hanya
`/up,/login`. Untuk halaman internal, masukkan credential melalui environment,
bukan argumen shell agar tidak masuk history.

```powershell
$env:BENCHMARK_BASE_URL = 'https://staging.example.test'
$env:BENCHMARK_EMAIL = 'benchmark@example.test'
$env:BENCHMARK_PASSWORD = '<secret>'
npm run benchmark:http -- --profile smoke --paths /up,/login --output artifacts/smoke.json

$env:BENCHMARK_ALLOW_LOAD = 'true'
npm run benchmark:http -- --profile load --paths /barang,/barang/results --server-pid 1234 --output artifacts/load.json
npm run benchmark:http -- --profile stress --paths /barang,/analytics --output artifacts/stress.json
npm run benchmark:http -- --profile soak --paths /barang,/notifikasi/feed --output artifacts/soak.json
```

Profil bawaan:

- Smoke: 2 VU selama 10 detik.
- Load: 100 VU selama 5 menit.
- Stress: 50, 100, 250, 500, 750, lalu 1.000 VU; masing-masing 2 menit.
- Soak: 50 VU selama 2 jam.

Ambang default adalah error rate maksimal 1% dan p95 maksimal 1.000 ms. Ubah
dengan `--max-error-rate` dan `--max-p95` hanya jika SLO resmi juga diubah.
Stress capacity adalah tingkat terakhir sebelum ambang dilanggar; jangan hanya
mencatat tingkat tertinggi yang sempat dimulai.

## 2. Network, TLS, dan resource

Probe awal HTTP runner membuka koneksi baru dan melaporkan DNS, TCP, TLS, TTFB,
total, serta ukuran respons. `tls_ms` bernilai `null` pada HTTP biasa. Untuk klaim
TLS produksi, URL wajib HTTPS dan diuji dari host yang mewakili lokasi pengguna.

Jika `--server-pid` diberikan, runner mengambil peak RSS proses tersebut. CPU dan
RAM sistem diambil setiap detik. Pada container, jalankan runner di host/container
yang mempunyai visibilitas PID yang benar. Korelasikan hasil dengan metrik database,
queue depth, disk IOPS, dan log error dari platform observability.

## 3. Core Web Vitals

```powershell
$env:CHROME_PATH = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
npm run benchmark:web-vitals -- --base-url https://staging.example.test --paths /login --runs 3 --output artifacts/lighthouse-login.json
```

Untuk halaman terautentikasi, gunakan environment email/password seperti bagian
load test. Runner mengambil median dari tiga run. Ambang default: performance score
80, LCP 2.500 ms, CLS 0,1, dan TBT 200 ms. Lighthouse menghasilkan metrik lab;
TBT bukan INP. INP produksi memerlukan Real User Monitoring atau CrUX selama
periode pengamatan yang cukup.

Jika Edge headless menghasilkan `NO_FCP`, gunakan Google Chrome stable pada runner
CI Linux atau host staging. Jangan mengganti `NO_FCP` dengan angka nol atau PASS.

## 4. OCR latency dan akurasi

```powershell
python python/benchmarks/ocr_benchmark.py --output artifacts/ocr.json
php vendor/bin/phpunit tests/Benchmark/OcrWorkerEndToEndBenchmark.php --do-not-cache-result
```

Baseline deterministik membuat tiga surat jalan sintetis: bersih, rotasi 0,7
derajat, dan blur/kontras rendah. Yang dihitung adalah character error rate (CER),
word error rate (WER), exact match setiap field, dan latency seluruh
`verify_document()` termasuk analisis bukti.

PHPUnit benchmark kedua mengukur alur worker lengkap: membaca private storage,
menjalankan Python, menyimpan hasil dan audit, lalu membuat notifikasi database.
Set `BENCHMARK_OCR_SAMPLES=3` atau lebih pada host benchmark untuk percentile yang
bermakna; default satu sampel menjaga pemeriksaan lokal tetap praktis.

Untuk kelayakan produksi, siapkan corpus riil berlabel dan teranonimisasi yang
mewakili scanner, kamera, vendor, layout, bahasa, resolusi, rotasi, dan kualitas
buruk. Pisahkan train/tuning/test serta laporkan interval kepercayaan. Jangan
memasukkan dokumen pengguna ke Git.

## 5. Akurasi prediksi stok

```powershell
python python/benchmarks/prediction_benchmark.py --output artifacts/prediction.json
```

Runner melakukan rolling-origin backtest horizon 30 hari pada pola stabil, tren,
mingguan, dan noisy. Metrik: MAE, RMSE, MAPE, bias, cold-start latency, dan warm
latency. Untuk produksi, ganti seri sintetis dengan histori OUT riil yang sudah
dibersihkan; jangan melakukan random split karena akan membocorkan masa depan.
Bandingkan model terhadap naive-last-value dan seasonal-naive sebelum menyimpulkan
model ML lebih baik.

## 6. MySQL/MariaDB row-level concurrency

Benchmark ini sengaja menolak SQLite dan database live. Gunakan clone disposable
atau staging dengan nama database mengandung `benchmark`, `bench`, `test`, atau
`staging`, jalankan migrasi, lalu:

```powershell
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'logistikku_benchmark'
$env:BENCHMARK_MYSQL_ALLOW_WRITES = 'true'
php artisan benchmark:mysql-concurrency --workers=1,4,8 --transactions=24 --history=2000 --output=artifacts/mysql-concurrency.json
```

Runner membuat dua hot items, menyinkronkan start worker, menjalankan jalur
`StockAdjustmentService`, dan memeriksa saldo ledger akhir. Database bersifat
disposable karena data benchmark serta job queue sengaja dibiarkan untuk audit;
hapus database clone setelah artefak disimpan.

## Hasil baseline lokal 20 Oktober 2026

| Pengujian | Hasil | Status/interpretasi |
|---|---:|---|
| HTTP smoke, 2 VU, `/up` + `/login` | 27 request; 0 error; 2,59 RPS; p50 637,02 ms; p95 1.730,75 ms | Gagal target p95 1.500 ms. Server `artisan serve` single-threaded; bukan kapasitas produksi. |
| OCR sintetis, 3 dokumen | p50 8.906,66 ms; p95 9.821,71 ms; CER 15,63%; WER 31,01%; field accuracy 87,88% | Lulus ambang awal CER/field, belum mewakili dokumen produksi. |
| Worker OCR end-to-end, 1 dokumen | 19.371,05 ms; 15 query; peak PHP 44 MB; 3 audit; 1 notifikasi | Alur lengkap berhasil. Satu sampel hanya smoke baseline, bukan p95 representatif. |
| Prediksi sintetis, 16 origin | MAE 6,2003; RMSE 10,3790; MAPE 1,6238%; bias -2,1181 | Lulus ambang sintetis; perlu backtest data OUT riil. |
| Lighthouse Edge headless | `NO_FCP`/timeout | Tidak ada angka yang sah. Ulangi di Chrome stable/CI Linux dengan asset produksi. |
| MySQL concurrency | Belum dieksekusi | MySQL client/server tidak tersedia pada host ini; runner sudah siap dan menolak fallback SQLite. |
| Load/stress/soak produksi | Belum dieksekusi | Memerlukan URL, izin pemilik, web stack produksi, dan observability. |

## Kriteria laporan sah

Laporan akhir minimal memuat commit SHA, target dan region, spesifikasi mesin,
web server/PHP worker, database/connection pool, cache, queue worker, ukuran dataset,
durasi, profil beban, raw JSON, threshold sebelum test, serta seluruh pelanggaran.
Test yang tidak berjalan ditulis `BELUM DIEKSEKUSI` atau `BLOCKED`, bukan `PASS`.
