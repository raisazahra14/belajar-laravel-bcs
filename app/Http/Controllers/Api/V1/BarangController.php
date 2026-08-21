<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexBarangRequest;
use App\Http\Requests\StoreBarangRequest;
use App\Http\Requests\UpdateBarangRequest;
use App\Http\Requests\UpdateStokRequest;
use App\Http\Resources\BarangResource;
use App\Models\Barang;
use App\Services\BarangService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class BarangController extends Controller
{
    public function __construct(private readonly BarangService $barangService) {}

    public function index(IndexBarangRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Barang::class);

        $query = Barang::query();

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($query) use ($search) {
                $query->where('kode_barang', 'like', "%{$search}%")
                    ->orWhere('nama_barang', 'like', "%{$search}%")
                    ->orWhere('lokasi', 'like', "%{$search}%");
            });
        }

        if ($request->filled('kategori')) {
            $query->where('kategori', $request->string('kategori')->toString());
        }

        $sorts = [
            'nama_asc' => ['nama_barang', 'asc'],
            'nama_desc' => ['nama_barang', 'desc'],
            'stok_asc' => ['stok', 'asc'],
            'stok_desc' => ['stok', 'desc'],
            'terbaru' => ['id', 'desc'],
        ];
        [$column, $direction] = $sorts[$request->input('sort', 'terbaru')];

        return BarangResource::collection(
            $query->orderBy($column, $direction)
                ->paginate($request->integer('per_page', 15))
                ->withQueryString(),
        )->additional(['message' => 'Daftar barang berhasil diambil.']);
    }

    public function store(StoreBarangRequest $request): JsonResponse
    {
        Gate::authorize('create', Barang::class);

        $barang = $this->barangService->create(
            $request->safe()->except('foto_barang'),
            $request->file('foto_barang'),
        );

        return (new BarangResource($barang))
            ->additional(['message' => 'Barang berhasil ditambahkan.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Barang $barang): BarangResource
    {
        Gate::authorize('view', $barang);

        return (new BarangResource($barang))
            ->additional(['message' => 'Detail barang berhasil diambil.']);
    }

    public function update(UpdateBarangRequest $request, Barang $barang): BarangResource
    {
        Gate::authorize('update', $barang);

        $barang = $this->barangService->update(
            $barang,
            $request->safe()->except('foto_barang'),
            $request->file('foto_barang'),
        );

        return (new BarangResource($barang))
            ->additional(['message' => 'Barang berhasil diperbarui.']);
    }

    public function destroy(Barang $barang): JsonResponse
    {
        Gate::authorize('delete', $barang);
        $this->barangService->delete($barang);

        return response()->json([
            'message' => 'Barang berhasil dihapus.',
            'data' => null,
        ]);
    }

    public function updateStok(UpdateStokRequest $request, Barang $barang): BarangResource
    {
        Gate::authorize('update', $barang);
        $barang = $this->barangService->updateStok($barang, $request->validated());

        return (new BarangResource($barang))
            ->additional(['message' => 'Stok barang berhasil diperbarui.']);
    }
}
