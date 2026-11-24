<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CheckStockRequest;
use App\Http\Requests\Api\V1\IndexBarangRequest;
use App\Http\Requests\StoreBarangRequest;
use App\Http\Requests\UpdateBarangRequest;
use App\Http\Resources\BarangResource;
use App\Models\Barang;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\BarangPersistenceService;
use Illuminate\Http\JsonResponse;

class BarangApiController extends Controller
{
    /**
     * Daftar master barang dengan filter kategori, pencarian nama/kode, sorting, dan paginasi dinamis.
     */
    public function index(IndexBarangRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 15);

        $barang = Barang::query()
            ->with(Barang::apiDetailRelations())
            ->filter($filters)
            ->paginate($perPage)
            ->withQueryString();

        return $this->paginatedResponse(
            BarangResource::collection($barang),
            'Daftar barang berhasil diambil',
        );
    }

    /**
     * Detail master barang lengkap beserta distribusi saldo stok per gudang (warehouse_stocks).
     */
    public function show(Barang $barang): JsonResponse
    {
        $barang->load(Barang::apiDetailRelations());

        return $this->successResponse(
            new BarangResource($barang),
            'Detail barang berhasil diambil',
        );
    }

    /**
     * Buat master barang baru beserta inisialisasi saldo stok awal gudang secara atomik (khusus Admin).
     */
    public function store(StoreBarangRequest $request, BarangPersistenceService $persistence): JsonResponse
    {
        $barang = $persistence->store(
            $request->safe()->except('foto_barang'),
            $request->file('foto_barang'),
        );

        $barang->load(Barang::apiDetailRelations());

        return $this->createdResponse(
            new BarangResource($barang),
            'Data barang berhasil ditambahkan',
            [],
            route('api.v1.barang.show', $barang),
        );
    }

    /**
     * Perbarui atribut master barang secara utuh (PUT) atau parsial (PATCH) (khusus Admin).
     */
    public function update(UpdateBarangRequest $request, Barang $barang, BarangPersistenceService $persistence): JsonResponse
    {
        $barang = $persistence->update(
            $barang,
            $request->safe()->except('foto_barang'),
            $request->file('foto_barang'),
        );

        $barang->load(Barang::apiDetailRelations());

        return $this->successResponse(
            new BarangResource($barang),
            'Data barang berhasil diperbarui',
        );
    }

    /**
     * Hapus master barang secara aman menggunakan Soft Delete (khusus Admin).
     */
    public function destroy(Barang $barang): JsonResponse
    {
        $this->authorize('manage-barang');

        $barang->delete();

        return $this->successResponse([
            'id' => (int) $barang->id,
            'kode_barang' => $barang->kode_barang,
            'deleted_at' => $barang->deleted_at?->toIso8601String(),
        ], 'Data barang berhasil dihapus');
    }

    /**
     * Pemeriksaan cepat kecukupan stok fisik barang di gudang target secara real-time.
     */
    public function checkStock(CheckStockRequest $request, Barang $barang): JsonResponse
    {
        $warehouseId = $request->integer('warehouse_id');
        $requestedQty = $request->integer('qty');

        $warehouse = Warehouse::query()
            ->select(['id', 'kode_gudang', 'nama_gudang', 'alamat', 'is_active'])
            ->findOrFail($warehouseId);

        $warehouseStock = WarehouseStock::query()
            ->where('barang_id', $barang->id)
            ->where('warehouse_id', $warehouseId)
            ->first();

        $availableStock = (int) ($warehouseStock?->stok ?? 0);
        $isAvailable = $availableStock >= $requestedQty;
        $shortage = max(0, $requestedQty - $availableStock);
        $totalStockAllWarehouses = $barang->warehouseStocks()->exists()
            ? (int) $barang->warehouseStocks()->sum('stok')
            : (int) $barang->stok;

        return $this->successResponse([
            'barang' => [
                'id' => (int) $barang->id,
                'kode_barang' => $barang->kode_barang,
                'nama_barang' => $barang->nama_barang,
                'satuan' => $barang->satuan,
            ],
            'warehouse' => [
                'id' => (int) $warehouse->id,
                'kode_gudang' => $warehouse->kode_gudang,
                'nama_gudang' => $warehouse->nama_gudang,
                'alamat' => $warehouse->alamat,
            ],
            'requested_qty' => $requestedQty,
            'available_stock' => $availableStock,
            'is_available' => $isAvailable,
            'shortage' => $shortage,
            'minimum_stock' => (int) ($warehouseStock?->stok_minimum ?? 0),
            'total_stock_all_warehouses' => $totalStockAllWarehouses,
        ], $isAvailable ? 'Stok mencukupi di gudang terpilih' : 'Stok tidak mencukupi di gudang terpilih');
    }
}
