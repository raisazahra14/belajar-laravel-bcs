# Panduan Integrasi Pengembang — RESTful API LogistikKu (`/api/v1`)

> **Dokumen Panduan Integrasi (Tugas 4.3 — Periode 4 MagangHub Kemnaker RI)**  
> **Versi Kontrak API:** `v1.0` (`X-API-Version: v1.0`)  
> **Target Pembaca:** Developer sistem Kasir (POS), aplikasi *Handheld Barcode Scanner*, sistem ERP/Pengadaan, dan QA/Integrasi Eksternal.

---

## 1. Gambaran Umum & Tautan Referensi

**LogistikKu RESTful API (`/api/v1`)** menyediakan antarmuka HTTP/JSON *stateless* untuk membaca katalog barang, mengecek kecukupan stok gudang secara *real-time*, mencatat mutasi/transfer stok antar-gudang secara atomik, serta mengelola *Purchase Order* (PO) dan penerimaan fisik barang (*Goods Receipt Note* / GRN).

| Sumber Daya | Lokasi / URL | Kegunaan |
|---|---|---|
| **Portal Interaktif (Swagger UI)** | [`/docs/api`](/docs/api) *(contoh lokal: `http://127.0.0.1:8000/docs/api`)* | Membaca kontrak endpoint dan mencoba request langsung (*Try it out*). |
| **Spesifikasi OpenAPI 3.0.3** | [`docs/openapi.yaml`](./openapi.yaml) · Rute publik: [`/docs/openapi.yaml`](/docs/openapi.yaml) | Kontrak mesin standar OpenAPI 3.0.3 (YAML) untuk Swagger UI, Postman, atau *code generator*. |
| **Endpoint Status Layanan** | `GET /api/v1/status` | Pemeriksaan kesehatan layanan & penemuan tautan dokumentasi (*HATEOAS*). |
| **Postman Collection v2.1** | [`docs/postman/LogistikKu_API_v1.postman_collection.json`](./postman/LogistikKu_API_v1.postman_collection.json) | 16 skenario pengujian otomatis lengkap dengan skrip `pm.test(...)`. |
| **Postman Environment Lokal** | [`docs/postman/LogistikKu_Local.postman_environment.json`](./postman/LogistikKu_Local.postman_environment.json) | Variabel environment siap pakai untuk pengujian lokal (`base_url`, `bearer_token`, dll.). |
| **Dokumen Arsitektur Teknis** | [`docs/arsitektur_rest_api.md`](./arsitektur_rest_api.md) | Penjelasan keputusan desain REST, RMM Level 2–3, dan *Defense in Depth*. |

### 1.1 Header Standar Request & Response

- **Header Request Wajib:**
  - `Accept: application/json`
  - `Content-Type: application/json` *(untuk metode `POST`, `PUT`, `PATCH` yang mengirim body JSON)*
  - `Authorization: Bearer <token>` *(untuk seluruh endpoint privat selain `/api/v1/status` dan `/api/v1/auth/login`)*
- **Header Response Otomatis dari Server:**
  - `X-API-Version: v1.0`
  - `X-Content-Type-Options: nosniff`
  - `X-Frame-Options: DENY`
  - `Location: <uri>` *(pada respon `201 Created` pembuatan Purchase Order)*
  - `Retry-After: <detik>` *(pada respon `429 TOO_MANY_REQUESTS`)*

---

## 2. Cara Mendapatkan & Mengelola API Token (Laravel Sanctum)

Seluruh endpoint bisnis `/api/v1/*` bersifat *stateless* tanpa *session cookie* maupun CSRF token. Akses dilakukan menggunakan **Bearer Token** yang diterbitkan melalui endpoint login API.

### 2.1 Langkah Mendapatkan Token (`POST /api/v1/auth/login`)

Kirim permintaan `POST` ke `/api/v1/auth/login` berisi `email`, `password`, dan label perangkat `device_name`:

```json
{
  "email": "staff@logistikku.test",
  "password": "password",
  "device_name": "Handheld-Scanner-Gudang-01"
}
```

**Contoh Respon Berhasil (`200 OK`):**

```json
{
  "success": true,
  "message": "Autentikasi berhasil",
  "data": {
    "token": "1|xYzAbCdEfGhIjKlMnOpQrStUvWxYz1234567890",
    "token_type": "Bearer",
    "abilities": [
      "barang:read",
      "stok:check",
      "stok:adjust",
      "stok:transfer",
      "po:read",
      "po:create",
      "po:update",
      "po:submit",
      "po:receive"
    ],
    "expires_at": "2026-11-21T14:30:00+07:00",
    "user": {
      "id": 3,
      "name": "Staff Gudang",
      "email": "staff@logistikku.test",
      "role": "staff"
    }
  },
  "meta": {
    "timestamp": "2026-11-20T14:30:00+07:00",
    "version": "v1.0"
  }
}
```

> **Akun Demo Seeder Lokal (`DatabaseSeeder`):**
> Pada lingkungan pengembangan lokal (`php artisan db:seed`), tersedia tiga akun uji coba dengan password default `password`:
> - `admin@logistikku.test` (`role: admin`)
> - `manager@logistikku.test` (`role: manager`)
> - `staff@logistikku.test` (`role: staff`)

### 2.2 Prinsip *Least Privilege*: Meminta *Scoped Token* (`abilities[]`)

Jika aplikasi klien hanya membutuhkan akses terbatas (misalnya mesin **Kasir/POS** yang hanya boleh membaca katalog dan mengecek stok tanpa boleh mengubah saldo stok), kirimkan parameter opsional `abilities` saat login:

```json
{
  "email": "staff@logistikku.test",
  "password": "password",
  "device_name": "Kasir-POS-Depan",
  "abilities": ["barang:read", "stok:check"]
}
```

Jika klien mencoba meminta *ability* yang tidak dimiliki oleh role user tersebut (misalnya `staff` meminta `po:approve`), server akan langsung menolak dengan **`422 VALIDATION_ERROR`** (*anti-privilege escalation*).

### 2.3 Matriks Role & Token Abilities (`User::ROLE_API_ABILITIES`)

| Ability Scope | Admin | Manager | Staff | Endpoint Terkait |
|---|:---:|:---:|:---:|---|
| `barang:read` | Ya | Ya | Ya | `GET /barang`, `GET /barang/{barang}`, `GET /barang/{barang}/check-stock` |
| `barang:write` | Ya | Tidak | Tidak | Manajemen master barang (cadangan ekspansi API) |
| `stok:check` | Ya | Ya | Ya | `GET /barang/{barang}/check-stock` |
| `stok:adjust` | Ya | Ya | Ya | `POST /stok/adjust` |
| `stok:transfer` | Ya | Ya | Ya | `POST /stok/transfer` |
| `stok:reverse` | Ya | Ya | Tidak | `POST /stok/{stokTransaction}/reverse` |
| `po:read` | Ya | Ya | Ya | `GET /purchase-orders`, `GET /purchase-orders/{purchaseOrder}` |
| `po:create` | Ya | Ya | Ya | `POST /purchase-orders` |
| `po:update` | Ya | Ya | Ya | `PUT/PATCH /purchase-orders/{purchaseOrder}`, `POST /purchase-orders/{purchaseOrder}/revise`, `POST /purchase-orders/{purchaseOrder}/withdraw`, `POST /purchase-orders/{purchaseOrder}/cancel` |
| `po:submit` | Ya | Ya | Ya | `POST /purchase-orders/{purchaseOrder}/submit` *(khusus `admin` atau pembuat PO)* |
| `po:approve` | Ya | Ya | Tidak | `POST /purchase-orders/{purchaseOrder}/approve`, `POST /purchase-orders/{purchaseOrder}/reject`, `POST /purchase-orders/{purchaseOrder}/cancel` (*Maker-Checker*) |
| `po:receive` | Ya | Tidak | Ya | `POST /purchase-orders/{purchaseOrder}/receive` *(hanya role `admin` & `staff`)* |

### 2.4 Masa Berlaku, Inspeksi, & Pencabutan Token

- **Masa Berlaku Default (`TTL`):** **24 jam** (`1440` menit via `SANCTUM_TOKEN_EXPIRATION` pada `config/sanctum.php`). Token yang kedaluwarsa otomatis ditolak dengan `401 UNAUTHENTICATED` dan dibersihkan oleh scheduler `sanctum:prune-expired --hours=24`.
- **Inspeksi Token Aktif:** Panggil `GET /api/v1/auth/me` untuk memeriksa identitas user, `token_abilities`, dan `token_expires_at`.
- **Logout Perangkat Ini:** Panggil `POST /api/v1/auth/logout` dengan header `Authorization: Bearer <token>`.
- **Cabut Darurat Semua Perangkat:** Jika perangkat scanner atau server POS hilang/bocor, kirim `POST /api/v1/auth/logout` dengan body `{"all_devices": true}` untuk mencabut seluruh token milik akun tersebut sekaligus.

### 2.5 Praktik Keamanan Penyimpanan Token

1. Simpan token di *environment variable* server integrasi (`LOGISTIKKU_API_TOKEN`) atau *credential store* perangkat — **jangan pernah** menaruh token di source code Git atau `localStorage` aplikasi web publik.
2. Gunakan akun integrasi terpisah per layanan dengan `device_name` yang jelas agar mudah diaudit.
3. Selalu minta `abilities` seminimal mungkin sesuai kebutuhan integrasi.

---

## 3. Aturan Integrasi Penting

1. **Rate Limiting (`throttle:api`):**
   - Batas global endpoint `/api/v1/*`: **60 request per menit** per user ID (atau per IP untuk endpoint publik).
   - Batas login gagal `POST /api/v1/auth/login`: **5 kali percobaan gagal dalam 60 detik** per kombinasi email + IP.
   - Jika menerima `429 TOO_MANY_REQUESTS`, baca header HTTP `Retry-After` (dalam detik) sebelum melakukan *retry*.
2. **Idempotency Guard (Wajib untuk Jaringan Gudang yang Tidak Stabil):**
   - Pada `POST /api/v1/stok/adjust`, selalu sertakan `reference_number` unik dari dokumen sumber (misal `DO-2026-0012`). Jika terjadi timeout dan klien mengirim ulang payload yang sama, server mengembalikan `409 CONFLICT` alih-alih menggandakan mutasi stok.
   - Pada `POST /api/v1/stok/transfer`, kirimkan `reference_number` unik atau biarkan server membangkitkan otomatis (`TRF-YYYYMMDD-XXXXXX`).
   - Pada `POST /api/v1/purchase-orders/{purchaseOrder}/receive`, selalu sertakan `receipt_token` unik per aksi penerimaan barang.
3. **Data Masking (OWASP API3):**
   - Field `harga_beli` pada `/api/v1/barang` otomatis bernilai `null` jika diakses menggunakan token ber-role `staff` (hanya `admin` dan `manager` yang melihat nilai HPP/harga beli).
   - Objek `creator` dan `approver` pada `/api/v1/purchase-orders` hanya menampilkan `id`, `name`, dan `role` tanpa alamat `email` internal pegawai.
4. **Paginasi & Pengurutan Aman:**
   - Endpoint daftar (`GET /api/v1/barang` dan `GET /api/v1/purchase-orders`) mendukung `page` (default `1`) dan `per_page` (default `15`, maksimal `100`), serta mengembalikan tautan HATEOAS pada `meta.links` (`first`, `last`, `prev`, `next`).
   - Parameter `sort` hanya menerima nilai pada *whitelist*: `id`, `kode_barang`, `nama_barang`, `kategori`, `stok`, `harga_beli`, `created_at`, `nama_asc`, `nama_desc`, `stok_asc`, `stok_desc`.
5. **Deprekasi Rute Legacy Non-Versioned (`/api/*`):**
   - Rute lama tanpa prefix versi (`/api/login`, `/api/logout`, `/api/user`, `/api/purchase-orders/*`) masih aktif untuk kompatibilitas mundur, namun setiap responsnya menyertakan header HTTP standar:
     - `Deprecation: true`
     - `Sunset: Wed, 30 Jun 2027 23:59:59 GMT` (dikonfigurasi melalui `LEGACY_API_SUNSET`)
     - `Link: </api/v1/...>; rel="successor-version"` (menunjuk langsung ke endpoint pengganti di `/api/v1/*`)
   - Seluruh integrasi baru wajib menggunakan kontrak tunggal `/api/v1/*`.

---

## 4. Panduan Error Code & Troubleshooting

### 4.1 Format Standar Respon Error

Setiap kegagalan pada `/api/v1/*` (termasuk error routing 404/405 maupun exception tak terduga) dijamin mengembalikan JSON terstruktur berikut, **tanpa** halaman HTML dan **tanpa** kunci `meta`:

```json
{
  "success": false,
  "message": "Validasi gagal",
  "errors": {
    "sort": [
      "Kolom pengurutan barang tidak valid."
    ]
  },
  "code": "VALIDATION_ERROR"
}
```

> **Catatan Kontrak:** Properti `errors` **selalu** bertipe JSON Object (`{}`). Untuk error umum tanpa rincian per-field (seperti `401`, `403`, `404`, `409`, `429`), nilainya adalah objek kosong `{}`.

### 4.2 Matriks HTTP Status & Kode Error (`code`)

| HTTP Status | Nilai `code` | Penyebab Umum | Cara Penanganan / Perbaikan |
|---|---|---|---|
| `400` | `BAD_REQUEST` | JSON body rusak (*syntax error*) atau parameter request malformed. | Validasi sintaks JSON sebelum kirim (`json.dumps` / `JSON.stringify`) dan pastikan `Content-Type: application/json`. |
| `401` | `UNAUTHENTICATED` | Header `Authorization: Bearer ...` tidak dikirim, token salah, sudah dicabut (`logout`), atau kedaluwarsa (>24 jam), atau email/password login salah. | Lakukan ulang `POST /api/v1/auth/login` untuk memperoleh token baru dan periksa format header `Bearer <token>`. |
| `403` | `FORBIDDEN` | Token valid tetapi tidak memiliki *ability* yang diperlukan (`MissingAbilityException`), atau role user ditolak oleh Policy/Middleware (misal `manager` mengakses `/receive`). | Periksa daftar `token_abilities` lewat `GET /api/v1/auth/me` atau gunakan akun dengan role yang berwenang. |
| `403` | `UNAUTHORIZED_ROLE` | Transisi status Purchase Order ditolak karena role pengguna tidak berhak. | Pastikan aksi dilakukan oleh role yang sesuai aturan *Maker-Checker* (Admin/Manager). |
| `404` | `RESOURCE_NOT_FOUND` | ID `{barang}` / `{purchaseOrder}` tidak ada (atau sudah di-soft delete) atau path URI endpoint salah ketik. | Periksa kembali ID resource melalui endpoint daftar atau cek penulisan path `/api/v1/...`. |
| `405` | `METHOD_NOT_ALLOWED` | HTTP verb salah pada URI yang valid (misal `DELETE /api/v1/status`). | Periksa header response `Allow` untuk melihat metode HTTP yang didukung pada URI tersebut. |
| `409` | `CONFLICT` | Duplikasi `reference_number` pada mutasi/transfer stok, stok gudang tidak mencukupi saat keluar/transfer, atau deadlock transaksi database. | Untuk retry akibat jaringan, anggap `409` duplikat referensi sebagai transaksi yang sudah tercatat; untuk stok kurang, cek saldo via `/check-stock`. |
| `415` | `UNSUPPORTED_MEDIA_TYPE` | Payload dikirim dengan media type yang tidak didukung. | Gunakan header `Content-Type: application/json`. |
| `422` | `VALIDATION_ERROR` | Input payload/query gagal validasi, permintaan eskalasi `abilities` saat login, `supplier_id` dikirim saat stok `keluar`, atau PO masih berstatus `draft` saat `/receive`. | Baca kunci di dalam objek `errors` (`errors.<field>`) untuk menampilkan pesan error spesifik per kolom. |
| `422` | `GUARD_FAILED` / `TRANSITION_NOT_ALLOWED` / `REASON_REQUIRED` | Pelanggaran aturan domain Purchase Order (misal menerima barang melebihi `sisa` atau status PO belum `approved`). | Pastikan status PO adalah `approved` atau `partially_received` dan jumlah diterima <= `sisa`. |
| `429` | `TOO_MANY_REQUESTS` | Melebihi kuota **60 req/menit** atau **5x gagal login / 60 detik**. | Hentikan pengiriman sementara selama jumlah detik pada header `Retry-After` (*exponential backoff*). |
| `500` | `INTERNAL_SERVER_ERROR` | Kegagalan tak terduga pada sisi server. | Ulangi hanya untuk request `GET` atau `POST` yang disertai `reference_number`/`receipt_token`; hubungi tim backend jika berlanjut. |
| `503` | `SERVICE_UNAVAILABLE` | Aplikasi sedang berada dalam mode pemeliharaan (*maintenance*). | Tunda integrasi sementara dan pantau `GET /api/v1/status`. |

### 4.3 Daftar Troubleshooting Cepat

1. **Error CORS saat memanggil API dari aplikasi frontend browser lain:**  
   Pastikan origin frontend (misal `http://localhost:3000`) terdaftar pada variabel environment `CORS_ALLOWED_ORIGINS` di file `.env` server Laravel (`config/cors.php` menolak wildcard `*`). Untuk aplikasi backend-to-backend (Python/Node CLI/cURL), CORS tidak berlaku.
2. **Menyertakan `supplier_id` saat `POST /api/v1/stok/adjust` dengan `jenis: "keluar"`:**  
   Server mengembalikan `422` karena supplier hanya relevan untuk barang masuk. Hapus key `supplier_id` dari payload saat mencatat stok keluar.
3. **Menerima barang PO (`POST /api/v1/purchase-orders/{id}/receive`) gagal `422`:**  
   PO yang baru dibuat lewat `POST /api/v1/purchase-orders` berstatus `draft`. Ajukan terlebih dahulu via `POST /api/v1/purchase-orders/{id}/submit` lalu setujui dengan akun `admin`/`manager` yang berbeda dari pembuat PO via `POST /api/v1/purchase-orders/{id}/approve` sebelum memanggil `/receive`.

---

## 5. Contoh Kode Pemanggilan API (End-to-End)

Ganti `BASE_URL` (misalnya `http://127.0.0.1:8000`) dan kredensial sesuai lingkungan target Anda.

### 5.1 cURL (Command Line / Shell)

```bash
# 0. Cek status layanan API (Publik)
curl -sS -H "Accept: application/json" \
  "http://127.0.0.1:8000/api/v1/status"

# 1. Login dan simpan Bearer Token (Scoped untuk integrasi gudang & PO)
TOKEN=$(curl -sS -X POST "http://127.0.0.1:8000/api/v1/auth/login" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "staff@logistikku.test",
    "password": "password",
    "device_name": "CLI-Integration-Script",
    "abilities": ["barang:read", "stok:check", "stok:adjust", "stok:transfer", "po:read", "po:create", "po:receive"]
  }' | python -c "import sys, json; print(json.load(sys.stdin)['data']['token'])")

echo "Token aktif: $TOKEN"

# 2. Cek profil & daftar ability token aktif
curl -sS -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  "http://127.0.0.1:8000/api/v1/auth/me"

# 3. Ambil daftar barang dengan filter & sorting aman
curl -sS -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  "http://127.0.0.1:8000/api/v1/barang?per_page=10&sort=nama_barang&direction=asc"

# 4. Cek kecukupan stok real-time di gudang 1 untuk kebutuhan 2 unit
curl -sS -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  "http://127.0.0.1:8000/api/v1/barang/1/check-stock?warehouse_id=1&qty=2"

# 5. Penyesuaian stok masuk (+5 unit) dengan idempotency key reference_number
curl -sS -X POST "http://127.0.0.1:8000/api/v1/stok/adjust" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "barang_id": 1,
    "warehouse_id": 1,
    "supplier_id": 1,
    "jenis": "masuk",
    "jumlah": 5,
    "reference_type": "DO",
    "reference_number": "DO-CURL-20261120-01",
    "document_date": "2026-11-20",
    "keterangan": "Penerimaan via integrasi cURL"
  }'

# 6. Transfer stok antar-gudang (Gudang 1 -> Gudang 2)
curl -sS -X POST "http://127.0.0.1:8000/api/v1/stok/transfer" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "barang_id": 1,
    "source_warehouse_id": 1,
    "destination_warehouse_id": 2,
    "jumlah": 2,
    "keterangan": "Distribusi ke gudang cabang"
  }'

# 7. Buat Purchase Order baru (status awal: draft)
curl -sS -i -X POST "http://127.0.0.1:8000/api/v1/purchase-orders" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "supplier_id": 1,
    "warehouse_id": 1,
    "tanggal_pesan": "2026-11-20",
    "estimasi_tiba": "2026-11-27",
    "catatan": "PO otomatis dari skrip cURL",
    "items": [
      { "barang_id": 1, "jumlah_pesan": 5, "harga_satuan": 250000 }
    ]
  }'

# 8. Catat penerimaan fisik via Barcode Scanner (PO harus sudah approved/partially_received)
curl -sS -X POST "http://127.0.0.1:8000/api/v1/purchase-orders/1/receive" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "barcode": "BRG-000001",
    "jumlah_diterima": 1,
    "nomor_surat_jalan": "SJ-SCAN-001",
    "receipt_token": "SCAN-CURL-20261120-01"
  }'

# 9. Logout & cabut token aktif
curl -sS -X POST "http://127.0.0.1:8000/api/v1/auth/logout" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN"
```

---

### 5.2 Python (`requests`)

> **Prasyarat:** `pip install requests`

```python
import time
import uuid
from typing import Any
import requests


class LogistikKuApiError(Exception):
    def __init__(self, status: int, code: str, message: str, errors: dict | None = None):
        self.status = status
        self.code = code
        self.message = message
        self.errors = errors or {}
        super().__init__(f"[{status} {code}] {message}")


class LogistikKuClient:
    def __init__(self, base_url: str = "http://127.0.0.1:8000", timeout: int = 15):
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout
        self.session = requests.Session()
        self.session.headers.update({
            "Accept": "application/json",
            "Content-Type": "application/json",
        })

    def _request(self, method: str, path: str, *, retry_on_429: bool = True, **kwargs: Any) -> dict[str, Any]:
        url = f"{self.base_url}{path}"
        response = self.session.request(method, url, timeout=self.timeout, **kwargs)

        if response.status_code == 429 and retry_on_429:
            retry_after = int(response.headers.get("Retry-After", "5"))
            time.sleep(min(retry_after, 10))
            response = self.session.request(method, url, timeout=self.timeout, **kwargs)

        payload = response.json() if response.content else {}
        if not response.ok:
            raise LogistikKuApiError(
                status=response.status_code,
                code=payload.get("code", f"HTTP_{response.status_code}"),
                message=payload.get("message", response.reason),
                errors=payload.get("errors", {}),
            )
        return payload

    def login(
        self,
        email: str,
        password: str,
        device_name: str = "Python-ERP-Client",
        abilities: list[str] | None = None,
    ) -> dict[str, Any]:
        body: dict[str, Any] = {
            "email": email,
            "password": password,
            "device_name": device_name,
        }
        if abilities:
            body["abilities"] = abilities

        result = self._request("POST", "/api/v1/auth/login", json=body)
        token = result["data"]["token"]
        self.session.headers["Authorization"] = f"Bearer {token}"
        return result["data"]

    def check_stock(self, barang_id: int, warehouse_id: int, qty: int) -> dict[str, Any]:
        return self._request(
            "GET",
            f"/api/v1/barang/{barang_id}/check-stock",
            params={"warehouse_id": warehouse_id, "qty": qty},
        )["data"]

    def adjust_stock(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self._request("POST", "/api/v1/stok/adjust", json=payload)["data"]

    def create_purchase_order(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self._request("POST", "/api/v1/purchase-orders", json=payload)["data"]

    def logout(self, all_devices: bool = False) -> None:
        self._request("POST", "/api/v1/auth/logout", json={"all_devices": all_devices})
        self.session.headers.pop("Authorization", None)


if __name__ == "__main__":
    client = LogistikKuClient("http://127.0.0.1:8000")

    try:
        # 1. Login dengan token ber-scope sesuai kebutuhan layanan
        auth = client.login(
            email="staff@logistikku.test",
            password="password",
            device_name="Python-Warehouse-Sync",
            abilities=["barang:read", "stok:check", "stok:adjust", "po:read", "po:create"],
        )
        print("Login OK, expires_at:", auth["expires_at"])

        # 2. Cek kecukupan stok real-time
        stock_info = client.check_stock(barang_id=1, warehouse_id=1, qty=3)
        print(
            f"Barang {stock_info['barang']['kode_barang']}: "
            f"available={stock_info['available_stock']}, is_available={stock_info['is_available']}"
        )

        # 3. Penyesuaian stok masuk dengan reference_number unik (idempoten)
        tx = client.adjust_stock({
            "barang_id": 1,
            "warehouse_id": 1,
            "jenis": "masuk",
            "jumlah": 2,
            "reference_type": "DO",
            "reference_number": f"DO-PY-{uuid.uuid4().hex[:8].upper()}",
            "keterangan": "Sinkronisasi stok dari layanan Python",
        })
        print(f"Mutasi dicatat: ID #{tx['id']} (saldo akhir gudang: {tx['warehouse']['saldo_gudang_terkini']})")

    except LogistikKuApiError as err:
        print(f"API Error: {err} | Detail errors: {err.errors}")
    finally:
        if "Authorization" in client.session.headers:
            client.logout()
            print("Token dicabut.")
```

---

### 5.3 JavaScript (`fetch` API — Node.js 18+ / Browser Terdaftar CORS)

```javascript
const BASE_URL = 'http://127.0.0.1:8000';

class LogistikKuFetchError extends Error {
    constructor(status, payload) {
        super(`[${status} ${payload?.code ?? 'API_ERROR'}] ${payload?.message ?? 'Permintaan gagal'}`);
        this.status = status;
        this.code = payload?.code ?? 'API_ERROR';
        this.errors = payload?.errors ?? {};
    }
}

async function apiFetch(path, { method = 'GET', token = null, body = null } = {}) {
    const headers = {
        Accept: 'application/json',
    };
    if (body !== null) {
        headers['Content-Type'] = 'application/json';
    }
    if (token) {
        headers.Authorization = `Bearer ${token}`;
    }

    const response = await fetch(`${BASE_URL}${path}`, {
        method,
        headers,
        body: body !== null ? JSON.stringify(body) : undefined,
    });

    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new LogistikKuFetchError(response.status, payload);
    }

    return payload;
}

async function runPosAndScannerFlow() {
    let token = null;

    try {
        // 1. Login untuk mesin POS & Scanner
        const loginRes = await apiFetch('/api/v1/auth/login', {
            method: 'POST',
            body: {
                email: 'staff@logistikku.test',
                password: 'password',
                device_name: 'JS-Fetch-POS-Terminal',
                abilities: ['barang:read', 'stok:check', 'po:read'],
            },
        });
        token = loginRes.data.token;
        console.log('Berhasil login dengan abilities:', loginRes.data.abilities);

        // 2. Ambil daftar barang halaman 1
        const barangList = await apiFetch('/api/v1/barang?per_page=5&sort=nama_barang&direction=asc', { token });
        console.log(`Total barang katalog: ${barangList.meta.total}`);

        // 3. Cek stok barang pertama jika ada
        if (barangList.data.length > 0) {
            const firstId = barangList.data[0].id;
            const check = await apiFetch(`/api/v1/barang/${firstId}/check-stock?warehouse_id=1&qty=2`, { token });
            console.log('Hasil cek stok:', check.data.is_available, 'tersedia:', check.data.available_stock);
        }
    } catch (error) {
        if (error instanceof LogistikKuFetchError) {
            console.error(`Gagal (${error.status} ${error.code}):`, error.message, error.errors);
        } else {
            console.error('Kesalahan jaringan:', error);
        }
    } finally {
        if (token) {
            await apiFetch('/api/v1/auth/logout', { method: 'POST', token }).catch(() => {});
            console.log('Sesi token berhasil dicabut.');
        }
    }
}

runPosAndScannerFlow();
```

---

### 5.4 JavaScript (`axios` dengan Interceptor)

> **Prasyarat:** `npm install axios` (sudah tersedia di `package.json` proyek LogistikKu).

```javascript
import axios from 'axios';

const api = axios.create({
    baseURL: 'http://127.0.0.1:8000/api/v1',
    timeout: 15000,
    headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
    },
});

let activeToken = null;

// Sisipkan Bearer Token secara otomatis ke setiap request privat
api.interceptors.request.use((config) => {
    if (activeToken) {
        config.headers.Authorization = `Bearer ${activeToken}`;
    }
    return config;
});

// Normalisasi error response API v1 agar konsisten di seluruh aplikasi klien
api.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error.response?.status;
        const data = error.response?.data;

        if (status === 429) {
            const retryAfter = error.response?.headers['retry-after'] ?? '60';
            console.warn(`Rate limit terlampaui. Coba lagi setelah ${retryAfter} detik.`);
        } else if (status === 401) {
            activeToken = null;
            console.warn('Token tidak valid atau kedaluwarsa. Perlu login ulang.');
        }

        return Promise.reject({
            status,
            code: data?.code ?? 'NETWORK_ERROR',
            message: data?.message ?? error.message,
            errors: data?.errors ?? {},
        });
    },
);

async function runAxiosIntegrationExample() {
    try {
        // 1. Login
        const { data: loginPayload } = await api.post('/auth/login', {
            email: 'admin@logistikku.test',
            password: 'password',
            device_name: 'Axios-ERP-Bridge',
        });
        activeToken = loginPayload.data.token;
        console.log('Login sukses, versi API:', loginPayload.meta.version);

        // 2. Transfer stok antar-gudang secara atomik
        const { data: transferPayload } = await api.post('/stok/transfer', {
            barang_id: 1,
            source_warehouse_id: 1,
            destination_warehouse_id: 2,
            jumlah: 1,
            keterangan: 'Transfer antar-gudang via Axios',
        });
        console.log('Nomor Referensi Transfer:', transferPayload.data.reference_number);

        // 3. Buat Purchase Order baru (respon 201 + header Location)
        const poResponse = await api.post('/purchase-orders', {
            supplier_id: 1,
            warehouse_id: 1,
            tanggal_pesan: '2026-11-20',
            estimasi_tiba: '2026-11-27',
            catatan: 'PO integrasi via Axios',
            items: [{ barang_id: 1, jumlah_pesan: 4, harga_satuan: 250000 }],
        });
        console.log('PO dibuat:', poResponse.data.data.nomor_po, '| Location:', poResponse.headers.location);
    } catch (apiErr) {
        console.error(`Error [${apiErr.status} ${apiErr.code}]: ${apiErr.message}`, apiErr.errors);
    } finally {
        if (activeToken) {
            await api.post('/auth/logout').catch(() => {});
            activeToken = null;
        }
    }
}

runAxiosIntegrationExample();
```
