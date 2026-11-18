<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\InvalidPurchaseOrderTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\Barang;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Traits\ApiResponses;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;
use JsonException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ApiV1ArchitectureContractTest extends TestCase
{
    use RefreshDatabase;

    private function makeResponder(): object
    {
        return new class
        {
            use ApiResponses {
                successResponse as public;
                createdResponse as public;
                acceptedResponse as public;
                noContentResponse as public;
                paginatedResponse as public;
                errorResponse as public;
                badRequestResponse as public;
                unauthorizedResponse as public;
                forbiddenResponse as public;
                notFoundResponse as public;
                methodNotAllowedResponse as public;
                conflictResponse as public;
                unsupportedMediaTypeResponse as public;
                validationErrorResponse as public;
                tooManyRequestsResponse as public;
            }
        };
    }

    public function test_base_controller_uses_api_responses_trait(): void
    {
        $traits = class_uses_recursive(Controller::class);

        $this->assertContains(ApiResponses::class, $traits);
    }

    public function test_api_v1_status_endpoint_returns_unified_success_envelope(): void
    {
        $response = $this->getJson('/api/v1/status');

        $response->assertOk()
            ->assertHeader('X-API-Version', 'v1.0')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'service',
                    'architecture',
                    'maturity_model',
                    'status',
                    'supported_verbs',
                    'documentation',
                    'links' => ['self'],
                ],
                'meta' => [
                    'timestamp',
                    'version',
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Layanan RESTful API LogistikKu v1 siap digunakan')
            ->assertJsonPath('data.service', 'LogistikKu RESTful API')
            ->assertJsonPath('data.maturity_model', 'Richardson Maturity Model Level 2-3 (REST + HATEOAS)')
            ->assertJsonPath('data.status', 'operational')
            ->assertJsonPath('meta.version', 'v1.0');

        $this->assertNotEmpty($response->json('meta.timestamp'));
        $this->assertStringEndsWith('/api/v1/status', (string) $response->json('data.links.self'));
    }

    public function test_success_created_accepted_and_no_content_responses_follow_microsoft_api_headers(): void
    {
        $responder = $this->makeResponder();
        $resource = new class(['id' => 7, 'kode_barang' => 'BRG-000007']) extends JsonResource
        {
            public function toArray(Request $request): array
            {
                return [
                    'id' => $this->resource['id'],
                    'kode_barang' => $this->resource['kode_barang'],
                ];
            }
        };

        $okResponse = $responder->successResponse($resource, 'Detail barang berhasil diambil');
        $okPayload = $okResponse->getData(true);

        $this->assertSame(200, $okResponse->getStatusCode());
        $this->assertSame('v1.0', $okResponse->headers->get('X-API-Version'));
        $this->assertTrue($okPayload['success']);
        $this->assertSame('Detail barang berhasil diambil', $okPayload['message']);
        $this->assertSame(['id' => 7, 'kode_barang' => 'BRG-000007'], $okPayload['data']);
        $this->assertArrayNotHasKey('data', $okPayload['data']);
        $this->assertSame('v1.0', $okPayload['meta']['version']);

        $createdResponse = $responder->createdResponse(
            ['id' => 99],
            'Purchase Order berhasil dibuat',
            [],
            'http://localhost/api/v1/purchase-orders/99',
        );
        $createdPayload = $createdResponse->getData(true);

        $this->assertSame(201, $createdResponse->getStatusCode());
        $this->assertSame('v1.0', $createdResponse->headers->get('X-API-Version'));
        $this->assertSame('http://localhost/api/v1/purchase-orders/99', $createdResponse->headers->get('Location'));
        $this->assertTrue($createdPayload['success']);
        $this->assertSame('Purchase Order berhasil dibuat', $createdPayload['message']);
        $this->assertSame(['id' => 99], $createdPayload['data']);
        $this->assertSame('v1.0', $createdPayload['meta']['version']);

        $acceptedResponse = $responder->acceptedResponse(
            ['process_id' => 12],
            'Prediksi stok sedang diproses secara asinkron',
            'http://localhost/api/v1/stock-predictions/processes/12',
        );
        $acceptedPayload = $acceptedResponse->getData(true);

        $this->assertSame(202, $acceptedResponse->getStatusCode());
        $this->assertSame('v1.0', $acceptedResponse->headers->get('X-API-Version'));
        $this->assertSame(
            'http://localhost/api/v1/stock-predictions/processes/12',
            $acceptedResponse->headers->get('Location')
        );
        $this->assertSame(
            'http://localhost/api/v1/stock-predictions/processes/12',
            $acceptedPayload['meta']['status_url']
        );
        $this->assertTrue($acceptedPayload['success']);

        $noContentResponse = $responder->noContentResponse();

        $this->assertSame(204, $noContentResponse->getStatusCode());
        $this->assertSame('v1.0', $noContentResponse->headers->get('X-API-Version'));
        $this->assertSame('', $noContentResponse->getContent());
    }

    public function test_paginated_response_formats_items_pagination_meta_and_hateoas_links(): void
    {
        $responder = $this->makeResponder();

        $items = collect([
            ['id' => 1, 'nama' => 'Scanner Zebra'],
            ['id' => 2, 'nama' => 'Label Thermal'],
        ]);

        $paginator = new LengthAwarePaginator(
            items: $items,
            total: 5,
            perPage: 2,
            currentPage: 1,
            options: ['path' => 'http://localhost/api/v1/barang'],
        );

        $resourceCollection = new class($paginator) extends JsonResource {};
        $anonymousCollection = $resourceCollection::collection($paginator);

        $response = $responder->paginatedResponse($anonymousCollection, 'Daftar barang berhasil diambil');
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('v1.0', $response->headers->get('X-API-Version'));
        $this->assertTrue($payload['success']);
        $this->assertCount(2, $payload['data']);
        $this->assertSame(1, $payload['meta']['current_page']);
        $this->assertSame(2, $payload['meta']['per_page']);
        $this->assertSame(5, $payload['meta']['total']);
        $this->assertSame(3, $payload['meta']['last_page']);
        $this->assertSame(1, $payload['meta']['from']);
        $this->assertSame(2, $payload['meta']['to']);
        $this->assertSame('v1.0', $payload['meta']['version']);
        $this->assertSame([
            'first' => 'http://localhost/api/v1/barang?page=1',
            'last' => 'http://localhost/api/v1/barang?page=3',
            'prev' => null,
            'next' => 'http://localhost/api/v1/barang?page=2',
        ], $payload['meta']['links']);
    }

    public function test_all_standard_http_error_responses_serialize_errors_as_json_object(): void
    {
        $responder = $this->makeResponder();

        $cases = [
            [$responder->badRequestResponse(), 400, 'BAD_REQUEST'],
            [$responder->unauthorizedResponse(), 401, 'UNAUTHENTICATED'],
            [$responder->forbiddenResponse(), 403, 'FORBIDDEN'],
            [$responder->notFoundResponse(), 404, 'RESOURCE_NOT_FOUND'],
            [$responder->methodNotAllowedResponse('Metode ditolak', ['get', 'post']), 405, 'METHOD_NOT_ALLOWED'],
            [$responder->conflictResponse(), 409, 'CONFLICT'],
            [$responder->unsupportedMediaTypeResponse(), 415, 'UNSUPPORTED_MEDIA_TYPE'],
            [$responder->validationErrorResponse(['qty' => ['Minimal 1 unit.']]), 422, 'VALIDATION_ERROR'],
            [$responder->tooManyRequestsResponse('Batas request tercapai.', 'TOO_MANY_REQUESTS', 45), 429, 'TOO_MANY_REQUESTS'],
        ];

        foreach ($cases as [$response, $expectedStatus, $expectedCode]) {
            $decodedObject = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);

            $this->assertSame($expectedStatus, $response->getStatusCode());
            $this->assertSame('v1.0', $response->headers->get('X-API-Version'));
            $this->assertFalse($decodedObject->success);
            $this->assertSame($expectedCode, $decodedObject->code);
            $this->assertIsObject($decodedObject->errors);
        }

        $methodNotAllowedResponse = $cases[4][0];
        $this->assertSame('GET, POST', $methodNotAllowedResponse->headers->get('Allow'));

        $rateLimitResponse = $cases[8][0];
        $this->assertSame('45', $rateLimitResponse->headers->get('Retry-After'));
    }

    public function test_global_exception_handler_renders_standard_json_envelope_for_api_v1_routes(): void
    {
        Route::prefix('api/v1/__test')->group(function () {
            Route::post('/validate', function (Request $request) {
                $request->validate(['kode_barang' => ['required', 'string']]);
            });

            Route::middleware('auth:sanctum')->get('/protected', function () {
                return response()->json(['ok' => true]);
            });

            Route::middleware(['auth:sanctum', 'role:admin'])->get('/admin-only', function () {
                return response()->json(['ok' => true]);
            });

            Route::get('/authorize-fail', function () {
                throw new AuthorizationException('Akses ditolak oleh policy.');
            });

            Route::get('/model-missing', function () {
                throw new ModelNotFoundException('Barang tidak ditemukan.');
            });

            Route::get('/po-guard', function () {
                throw InvalidPurchaseOrderTransitionException::guardFailed(
                    'Pembuat PO tidak diperbolehkan menyetujui PO miliknya sendiri.'
                );
            });

            Route::get('/po-role', function () {
                throw InvalidPurchaseOrderTransitionException::unauthorized(
                    PurchaseOrderStatus::PENDING_APPROVAL,
                    PurchaseOrderStatus::APPROVED,
                );
            });

            Route::get('/conflict', function () {
                throw new ConflictHttpException('Stok gudang mengalami konflik transaksi.');
            });

            Route::get('/bad-request', function () {
                throw new BadRequestHttpException('Format parameter tidak valid.');
            });

            Route::get('/throttle', function () {
                throw new ThrottleRequestsException('Too many attempts.', null, ['Retry-After' => 30]);
            });

            Route::get('/unsupported-media', function () {
                abort(415);
            });

            Route::get('/maintenance', function () {
                abort(503);
            });

            Route::get('/deadlock', function () {
                throw new DeadlockException('Deadlock found when trying to get lock.');
            });

            Route::get('/malformed-json', function () {
                throw new JsonException('Syntax error', JSON_ERROR_SYNTAX);
            });
        });

        // 404 tanpa header Accept: application/json pun tetap mengembalikan amplop JSON standar
        $this->get('/api/v1/endpoint-tidak-ditemukan')
            ->assertStatus(404)
            ->assertHeader('X-API-Version', 'v1.0')
            ->assertJson([
                'success' => false,
                'code' => 'RESOURCE_NOT_FOUND',
            ]);

        // 405 Method Not Allowed
        $this->deleteJson('/api/v1/status')
            ->assertStatus(405)
            ->assertJson([
                'success' => false,
                'code' => 'METHOD_NOT_ALLOWED',
            ]);

        // 422 ValidationException
        $this->postJson('/api/v1/__test/validate', [])
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validasi gagal',
                'code' => 'VALIDATION_ERROR',
            ])
            ->assertJsonStructure(['errors' => ['kode_barang']]);

        // 401 AuthenticationException
        $this->getJson('/api/v1/__test/protected')
            ->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 'UNAUTHENTICATED',
            ]);

        // 403 EnsureUserHasRole middleware (staff mencoba endpoint admin)
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/__test/admin-only')
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'code' => 'FORBIDDEN',
            ]);

        // 403 AuthorizationException langsung
        $this->getJson('/api/v1/__test/authorize-fail')
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'code' => 'FORBIDDEN',
                'message' => 'Akses ditolak oleh policy.',
            ]);

        // 404 ModelNotFoundException
        $this->getJson('/api/v1/__test/model-missing')
            ->assertStatus(404)
            ->assertJson([
                'success' => false,
                'code' => 'RESOURCE_NOT_FOUND',
            ]);

        // 422 DomainException InvalidPurchaseOrderTransitionException (GUARD_FAILED)
        $this->getJson('/api/v1/__test/po-guard')
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'code' => 'GUARD_FAILED',
            ]);

        // 403 DomainException InvalidPurchaseOrderTransitionException (UNAUTHORIZED_ROLE)
        $this->getJson('/api/v1/__test/po-role')
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'code' => 'UNAUTHORIZED_ROLE',
            ]);

        // 409 ConflictHttpException
        $this->getJson('/api/v1/__test/conflict')
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'code' => 'CONFLICT',
            ]);

        // 400 BadRequestHttpException
        $this->getJson('/api/v1/__test/bad-request')
            ->assertStatus(400)
            ->assertJson([
                'success' => false,
                'code' => 'BAD_REQUEST',
            ]);

        // 429 ThrottleRequestsException
        $this->getJson('/api/v1/__test/throttle')
            ->assertStatus(429)
            ->assertHeader('Retry-After', '30')
            ->assertHeader('X-API-Version', 'v1.0')
            ->assertJson([
                'success' => false,
                'code' => 'TOO_MANY_REQUESTS',
            ]);

        // 415 Unsupported Media Type
        $this->getJson('/api/v1/__test/unsupported-media')
            ->assertStatus(415)
            ->assertHeader('X-API-Version', 'v1.0')
            ->assertJson([
                'success' => false,
                'code' => 'UNSUPPORTED_MEDIA_TYPE',
            ]);

        // 503 Service Unavailable
        $this->getJson('/api/v1/__test/maintenance')
            ->assertStatus(503)
            ->assertHeader('X-API-Version', 'v1.0')
            ->assertJson([
                'success' => false,
                'code' => 'SERVICE_UNAVAILABLE',
            ]);

        // 409 DeadlockException
        $this->getJson('/api/v1/__test/deadlock')
            ->assertStatus(409)
            ->assertHeader('X-API-Version', 'v1.0')
            ->assertJson([
                'success' => false,
                'code' => 'CONFLICT',
            ]);

        // 400 JsonException (Malformed JSON)
        $this->getJson('/api/v1/__test/malformed-json')
            ->assertStatus(400)
            ->assertHeader('X-API-Version', 'v1.0')
            ->assertJson([
                'success' => false,
                'code' => 'BAD_REQUEST',
            ]);
    }

    public function test_paginated_response_handles_empty_paginator_without_errors(): void
    {
        $responder = $this->makeResponder();
        $emptyPaginator = new LengthAwarePaginator(
            items: collect(),
            total: 0,
            perPage: 15,
            currentPage: 1,
            options: ['path' => 'http://localhost/api/v1/barang'],
        );

        $response = $responder->paginatedResponse($emptyPaginator, 'Tidak ada data barang');
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('v1.0', $response->headers->get('X-API-Version'));
        $this->assertTrue($payload['success']);
        $this->assertSame([], $payload['data']);
        $this->assertSame(0, $payload['meta']['total']);
        $this->assertNull($payload['meta']['from']);
        $this->assertNull($payload['meta']['to']);
        $this->assertNull($payload['meta']['links']['prev']);
        $this->assertNull($payload['meta']['links']['next']);
    }

    public function test_eloquent_purchase_order_resource_works_cleanly_inside_success_response(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $supplier = Supplier::create([
            'kode_supplier' => 'SUP-V1-01',
            'nama_supplier' => 'PT Kontrak Mitra',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::create([
            'kode_gudang' => 'GDG-V1-01',
            'nama_gudang' => 'Gudang Utama v1',
            'is_active' => true,
        ]);
        $barang = Barang::create([
            'kode_barang' => 'BRG-V1-001',
            'nama_barang' => 'Barcode Scanner Wireless',
            'kategori' => 'Elektronik',
            'stok' => 10,
            'satuan' => 'Unit',
            'lokasi' => 'Rak B1',
            'harga_beli' => 450000,
            'supplier_id' => $supplier->id,
        ]);
        $po = PurchaseOrder::create([
            'nomor_po' => 'PO-20261118-V101',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => PurchaseOrderStatus::DRAFT,
            'tanggal_pesan' => '2026-11-18',
            'total_biaya' => 900000,
            'created_by' => $user->id,
        ]);
        $po->items()->create([
            'barang_id' => $barang->id,
            'jumlah_pesan' => 2,
            'jumlah_diterima' => 0,
            'harga_satuan' => 450000,
            'subtotal' => 900000,
        ]);

        $this->actingAs($user, 'sanctum');
        $responder = $this->makeResponder();

        // Tanpa relasi termuat -> key opsional tidak bocor
        $unloadedResponse = $responder->successResponse(new PurchaseOrderResource($po));
        $unloadedPayload = $unloadedResponse->getData(true);

        $this->assertSame('PO-20261118-V101', $unloadedPayload['data']['nomor_po']);
        $this->assertArrayNotHasKey('items', $unloadedPayload['data']);
        $this->assertArrayNotHasKey('data', $unloadedPayload['data']);

        // Dengan relasi lengkap termuat -> nested items resource ter-serialize rapi
        $po->load(['items.barang', 'supplier', 'warehouse', 'creator']);
        $loadedResponse = $responder->successResponse(new PurchaseOrderResource($po));
        $loadedPayload = $loadedResponse->getData(true);

        $this->assertCount(1, $loadedPayload['data']['items']);
        $this->assertSame('BRG-V1-001', $loadedPayload['data']['items'][0]['barang']['kode_barang']);
        $this->assertSame('PT Kontrak Mitra', $loadedPayload['data']['supplier']['nama_supplier']);
    }
}
