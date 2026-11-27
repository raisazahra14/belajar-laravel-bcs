<?php

namespace App\Traits;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Standar Unified API Response Contract untuk seluruh endpoint RESTful /api/v1.
 *
 * Menjamin setiap respon API memiliki amplop JSON seragam:
 * - Berhasil: { success: true, message, data, meta: { timestamp, version, ... } }
 * - Gagal:    { success: false, message, errors, code }
 */
trait ApiResponses
{
    public const API_VERSION = 'v1.0';

    /**
     * Respon standar untuk permintaan yang berhasil diproses (default HTTP 200 OK).
     *
     * @param  array<string, mixed>  $extraMeta
     * @param  array<string, string>  $headers
     */
    protected function successResponse(
        mixed $data = null,
        string $message = 'Data berhasil diambil',
        int $statusCode = 200,
        array $extraMeta = [],
        array $headers = [],
    ): JsonResponse {
        if ($data instanceof AnonymousResourceCollection && $data->resource instanceof LengthAwarePaginator) {
            return $this->paginatedResponse($data, $message, $extraMeta, $headers);
        }

        $payloadData = $data instanceof JsonResource
            ? $data->resolve(request())
            : $data;

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $payloadData,
            'meta' => $this->buildMeta($extraMeta),
        ], $statusCode, $this->defaultHeaders($headers));
    }

    /**
     * Respon standar saat resource baru berhasil dibuat (HTTP 201 Created).
     *
     * Menyertakan header HTTP `Location` apabila URI resource baru diberikan.
     *
     * @param  array<string, mixed>  $extraMeta
     */
    protected function createdResponse(
        mixed $data = null,
        string $message = 'Data berhasil dibuat',
        array $extraMeta = [],
        ?string $location = null,
    ): JsonResponse {
        $headers = ($location !== null && $location !== '')
            ? ['Location' => $location]
            : [];

        return $this->successResponse($data, $message, 201, $extraMeta, $headers);
    }

    /**
     * Respon standar saat permintaan diterima untuk pemrosesan asinkron (HTTP 202 Accepted).
     *
     * Menyertakan header `Location` dan `meta.status_url` untuk pemantauan status proses.
     *
     * @param  array<string, mixed>  $extraMeta
     */
    protected function acceptedResponse(
        mixed $data = null,
        string $message = 'Permintaan diterima dan sedang diproses secara asinkron',
        ?string $statusUrl = null,
        array $extraMeta = [],
    ): JsonResponse {
        $headers = [];

        if ($statusUrl !== null && $statusUrl !== '') {
            $headers['Location'] = $statusUrl;
            $extraMeta = array_merge(['status_url' => $statusUrl], $extraMeta);
        }

        return $this->successResponse($data, $message, 202, $extraMeta, $headers);
    }

    /**
     * Respon standar tanpa isi payload saat operasi hapus/aksi selesai (HTTP 204 No Content).
     */
    protected function noContentResponse(): JsonResponse
    {
        $response = new JsonResponse(null, 204, $this->defaultHeaders());
        $response->setContent('');

        return $response;
    }

    /**
     * Respon standar untuk koleksi data terpaginasi beserta metadata paginasi & tautan HATEOAS.
     *
     * @param  array<string, mixed>  $extraMeta
     * @param  array<string, string>  $headers
     */
    protected function paginatedResponse(
        LengthAwarePaginator|AnonymousResourceCollection $paginated,
        string $message = 'Data berhasil diambil',
        array $extraMeta = [],
        array $headers = [],
    ): JsonResponse {
        if ($paginated instanceof AnonymousResourceCollection) {
            $paginator = $paginated->resource;
            $items = $paginated->resolve(request());

            if (! $paginator instanceof LengthAwarePaginator) {
                return $this->successResponse($items, $message, 200, $extraMeta, $headers);
            }
        } else {
            $paginator = $paginated;
            $items = $paginator->items();
        }

        $paginationMeta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ];

        return $this->successResponse(
            $items,
            $message,
            200,
            array_merge($paginationMeta, $extraMeta),
            $headers,
        );
    }

    /**
     * Respon standar untuk error terstruktur sesuai kontrak API v1.
     *
     * @param  array<string, mixed>  $errors
     * @param  array<string, string>  $headers
     */
    protected function errorResponse(
        string $message,
        string $code = 'BAD_REQUEST',
        int $statusCode = 400,
        array $errors = [],
        array $headers = [],
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => (object) $errors,
            'code' => $code,
        ], $statusCode, $this->defaultHeaders($headers));
    }

    /**
     * Respon HTTP 400 Bad Request untuk payload atau parameter yang tidak valid.
     *
     * @param  array<string, mixed>  $errors
     */
    protected function badRequestResponse(
        string $message = 'Permintaan tidak valid',
        array $errors = [],
        string $code = 'BAD_REQUEST',
    ): JsonResponse {
        return $this->errorResponse($message, $code, 400, $errors);
    }

    /**
     * Respon HTTP 401 Unauthorized saat klien belum terautentikasi atau token invalid.
     *
     * @param  array<string, mixed>  $errors
     */
    protected function unauthorizedResponse(
        string $message = 'Autentikasi diperlukan atau token akses tidak valid',
        string $code = 'UNAUTHENTICATED',
        array $errors = [],
    ): JsonResponse {
        return $this->errorResponse($message, $code, 401, $errors);
    }

    /**
     * Respon HTTP 403 Forbidden saat user terautentikasi namun tidak memiliki otorisasi role/policy.
     *
     * @param  array<string, mixed>  $errors
     */
    protected function forbiddenResponse(
        string $message = 'Anda tidak memiliki hak akses untuk operasi ini',
        string $code = 'FORBIDDEN',
        array $errors = [],
    ): JsonResponse {
        return $this->errorResponse($message, $code, 403, $errors);
    }

    /**
     * Respon HTTP 404 Not Found saat resource atau endpoint tidak ditemukan.
     *
     * @param  array<string, mixed>  $errors
     */
    protected function notFoundResponse(
        string $message = 'Data atau sumber daya tidak ditemukan',
        string $code = 'RESOURCE_NOT_FOUND',
        array $errors = [],
    ): JsonResponse {
        return $this->errorResponse($message, $code, 404, $errors);
    }

    /**
     * Respon HTTP 409 Conflict saat terjadi benturan transaksi, stok, atau status data.
     *
     * @param  array<string, mixed>  $errors
     */
    protected function conflictResponse(
        string $message = 'Terjadi konflik data atau status transaksi',
        string $code = 'CONFLICT',
        array $errors = [],
    ): JsonResponse {
        return $this->errorResponse($message, $code, 409, $errors);
    }

    /**
     * Respon HTTP 422 Unprocessable Content untuk kegagalan validasi input atau aturan bisnis.
     *
     * @param  array<string, mixed>  $errors
     */
    protected function validationErrorResponse(
        array $errors,
        string $message = 'Validasi gagal',
        string $code = 'VALIDATION_ERROR',
    ): JsonResponse {
        return $this->errorResponse($message, $code, 422, $errors);
    }

    /**
     * Respon HTTP 405 Method Not Allowed ketika metode HTTP tidak didukung pada resource ini.
     *
     * @param  list<string>  $allowedMethods
     */
    protected function methodNotAllowedResponse(
        string $message = 'Metode HTTP tidak diizinkan untuk endpoint ini',
        array $allowedMethods = [],
        string $code = 'METHOD_NOT_ALLOWED',
    ): JsonResponse {
        $headers = $allowedMethods !== []
            ? ['Allow' => implode(', ', array_map('strtoupper', $allowedMethods))]
            : [];

        return $this->errorResponse($message, $code, 405, [], $headers);
    }

    /**
     * Respon HTTP 415 Unsupported Media Type ketika header Content-Type payload tidak didukung.
     */
    protected function unsupportedMediaTypeResponse(
        string $message = 'Format Content-Type tidak didukung. Gunakan application/json.',
        string $code = 'UNSUPPORTED_MEDIA_TYPE',
    ): JsonResponse {
        return $this->errorResponse($message, $code, 415);
    }

    /**
     * Respon HTTP 429 Too Many Requests ketika batas rate limiting terlampaui.
     */
    protected function tooManyRequestsResponse(
        string $message = 'Terlalu banyak permintaan, silakan coba lagi nanti',
        string $code = 'TOO_MANY_REQUESTS',
        ?int $retryAfter = null,
    ): JsonResponse {
        $headers = ($retryAfter !== null && $retryAfter > 0)
            ? ['Retry-After' => (string) $retryAfter]
            : [];

        return $this->errorResponse($message, $code, 429, [], $headers);
    }

    /**
     * Bangun metadata standar pada setiap respon berhasil.
     *
     * @param  array<string, mixed>  $extraMeta
     * @return array<string, mixed>
     */
    private function buildMeta(array $extraMeta = []): array
    {
        return array_merge([
            'timestamp' => now()->toIso8601String(),
            'version' => self::API_VERSION,
        ], $extraMeta);
    }

    /**
     * Gabungkan header standar API versi aktif dengan header tambahan per respon.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function defaultHeaders(array $headers = []): array
    {
        return array_merge([
            'X-API-Version' => self::API_VERSION,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ], $headers);
    }
}
