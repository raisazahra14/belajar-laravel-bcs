<?php

namespace App\Console\Commands;

use App\Models\Barang;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncWarehouseBalances extends Command
{
    private const LOCATION_TARGETS = [
        'gudang a' => 'GDG-A',
        'rak a1' => 'GDG-A',
        'rak a2' => 'GDG-A',
        'gudang a, rak a1' => 'GDG-A',
        'gudang b' => 'GDG-B',
        'rak b2' => 'GDG-B',
        'rak b3' => 'GDG-B',
        'gudang b - rak b2' => 'GDG-B',
        'gudang c' => 'GDG-C',
        'gudang utama' => 'GDG-UTAMA',
        'ruang it' => 'GDG-UTAMA',
        'rak demo prediksi' => 'GDG-UTAMA',
    ];

    protected $signature = 'inventory:sync-warehouse-balances
        {--dry-run : Audit tanpa mengubah saldo (mode default)}
        {--apply : Terapkan hanya mapping saldo yang aman}';

    protected $description = 'Audit dan reklasifikasi saldo lama dari Gudang Utama berdasarkan lokasi Barang';

    /** @var array<string, Warehouse> */
    private array $warehouses = [];

    public function handle(): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Gunakan salah satu opsi --dry-run atau --apply, bukan keduanya.');

            return self::INVALID;
        }

        if (! $this->loadWarehouses()) {
            return self::FAILURE;
        }

        $beforeTotal = (int) Barang::withTrashed()->sum('stok');
        $historyBefore = DB::table('stok_transactions')->count();
        $audit = $this->audit();
        $this->displayAudit($audit, $beforeTotal);

        if (! $this->option('apply')) {
            $this->info('DRY-RUN: tidak ada data yang diubah. Gunakan --apply hanya setelah laporan ditinjau.');

            return self::SUCCESS;
        }

        try {
            $result = DB::transaction(function () use ($beforeTotal, $historyBefore): array {
                $applied = $this->applySafeMappings();
                $afterTotal = (int) Barang::withTrashed()->sum('stok');
                $warehouseTotal = (int) WarehouseStock::sum('stok');
                $historyAfter = DB::table('stok_transactions')->count();
                $mismatchedItems = $this->mismatchedItemCount();
                $negativeBalances = WarehouseStock::where('stok', '<', 0)->count();

                if (
                    $beforeTotal !== $afterTotal
                    || $afterTotal !== $warehouseTotal
                    || $historyBefore !== $historyAfter
                    || $mismatchedItems > 0
                    || $negativeBalances > 0
                ) {
                    throw new \RuntimeException('Verifikasi global gagal. Periksa konsistensi database sebelum melanjutkan.');
                }

                return compact('applied', 'afterTotal', 'historyAfter');
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());
            $this->warn('Seluruh perubahan apply dibatalkan.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("APPLY selesai: {$result['applied']['items']} jenis Barang / {$result['applied']['quantity']} unit direklasifikasi.");
        $this->line("Total sebelum/sesudah: {$beforeTotal} / {$result['afterTotal']}. Histori tetap: {$result['historyAfter']} transaksi.");

        return self::SUCCESS;
    }

    private function loadWarehouses(): bool
    {
        $warehouses = Warehouse::query()
            ->whereIn('kode_gudang', ['GDG-UTAMA', 'GDG-A', 'GDG-B', 'GDG-C'])
            ->get()
            ->keyBy('kode_gudang');

        foreach (['GDG-UTAMA', 'GDG-A', 'GDG-B', 'GDG-C'] as $code) {
            $warehouse = $warehouses->get($code);
            if (! $warehouse || ! $warehouse->is_active) {
                $this->error("Master {$code} tidak tersedia atau tidak aktif. Tidak ada saldo yang diubah.");

                return false;
            }
            $this->warehouses[$code] = $warehouse;
        }

        return true;
    }

    /** @return array{rows: array<int,array<string,mixed>>, totals: array<string,array{items:int,quantity:int}>, projection: array<string,array{items:int,quantity:int}>} */
    private function audit(): array
    {
        $locations = [];
        $totals = [];
        $projection = [];

        Barang::withTrashed()
            ->with('warehouseStocks.warehouse')
            ->orderBy('id')
            ->chunkById(200, function (Collection $items) use (&$locations, &$totals, &$projection): void {
                foreach ($items as $barang) {
                    $result = $this->classify($barang, $barang->warehouseStocks);
                    $key = (string) ($barang->lokasi ?? '');
                    $locations[$key] ??= [
                        'original' => $key === '' ? '(kosong)' : $key,
                        'normalized' => $result['normalized'] === '' ? '(kosong)' : $result['normalized'],
                        'target' => $result['target'],
                        'items' => 0,
                        'active_items' => 0,
                        'quantity' => 0,
                        'status' => $result['status'],
                    ];
                    $locations[$key]['items']++;
                    $locations[$key]['active_items'] += (int) $barang->stok > 0 ? 1 : 0;
                    $locations[$key]['quantity'] += (int) $barang->stok;
                    if ($this->severity($result['status']) > $this->severity($locations[$key]['status'])) {
                        $locations[$key]['status'] = $result['status'];
                    }

                    $totals[$result['status']] ??= ['items' => 0, 'quantity' => 0];
                    $totals[$result['status']]['items']++;
                    $totals[$result['status']]['quantity'] += (int) $barang->stok;

                    $balances = $barang->warehouseStocks
                        ->where('stok', '>', 0)
                        ->mapWithKeys(fn (WarehouseStock $stock): array => [
                            $stock->warehouse->kode_gudang => (int) $stock->stok,
                        ]);
                    if ($result['action'] === 'move') {
                        $quantity = (int) $balances->pull('GDG-UTAMA', 0);
                        $balances->put($result['target'], $quantity);
                    }
                    foreach ($balances as $code => $quantity) {
                        if ($quantity <= 0) {
                            continue;
                        }
                        $projection[$code] ??= ['items' => 0, 'quantity' => 0];
                        $projection[$code]['items']++;
                        $projection[$code]['quantity'] += $quantity;
                    }
                }
            });

        return ['rows' => array_values($locations), 'totals' => $totals, 'projection' => $projection];
    }

    /** @param array{rows: array<int,array<string,mixed>>, totals: array<string,array{items:int,quantity:int}>, projection: array<string,array{items:int,quantity:int}>} $audit */
    private function displayAudit(array $audit, int $total): void
    {
        $warehouseRows = Warehouse::withTrashed()
            ->withCount(['warehouseStocks as positive_items' => fn ($query) => $query->where('stok', '>', 0)])
            ->withSum('warehouseStocks as total_quantity', 'stok')
            ->orderBy('kode_gudang')
            ->get();
        $multiWarehouseItems = DB::query()->fromSub(
            WarehouseStock::query()
                ->select('barang_id')
                ->where('stok', '>', 0)
                ->groupBy('barang_id')
                ->havingRaw('COUNT(*) > 1'),
            'multi_warehouse_items',
        )->count();
        $mismatchedItems = $this->mismatchedItemCount();
        $transactionCount = DB::table('stok_transactions')->count();
        $linkedTransactionCount = DB::table('stok_transactions')->whereNotNull('warehouse_stock_id')->count();
        $negativeBalances = WarehouseStock::where('stok', '<', 0)->count();

        $this->table(
            ['Lokasi asli', 'Normalisasi', 'Target', 'Barang', 'Jenis bersaldo', 'Kuantitas', 'Status'],
            collect($audit['rows'])->sortBy('original')->map(fn (array $row): array => [
                $row['original'],
                $row['normalized'],
                $row['target'],
                $row['items'],
                $row['active_items'],
                $row['quantity'],
                strtoupper($row['status']),
            ])->values()->all(),
        );

        $this->table(
            ['Gudang', 'Jenis bersaldo', 'Kuantitas', 'Status master'],
            $warehouseRows->map(fn (Warehouse $warehouse): array => [
                $warehouse->kode_gudang,
                $warehouse->positive_items,
                (int) ($warehouse->total_quantity ?? 0),
                $warehouse->trashed() ? 'DIHAPUS' : ($warehouse->is_active ? 'AKTIF' : 'NONAKTIF'),
            ])->all(),
        );

        $this->table(
            ['Proyeksi setelah apply', 'Jenis bersaldo', 'Kuantitas'],
            collect(['GDG-A', 'GDG-B', 'GDG-C', 'GDG-UTAMA'])->map(fn (string $code): array => [
                $code,
                $audit['projection'][$code]['items'] ?? 0,
                $audit['projection'][$code]['quantity'] ?? 0,
            ])->all(),
        );

        $this->line("Total stok Barang: {$total}");
        foreach ($audit['totals'] as $status => $values) {
            $this->line(strtoupper($status).": {$values['items']} jenis / {$values['quantity']} unit");
        }
        $this->line("Barang bersaldo di lebih dari satu gudang: {$multiWarehouseItems}");
        $this->line("Barang dengan selisih saldo: {$mismatchedItems}");
        $this->line("Saldo gudang negatif: {$negativeBalances}");
        $this->line("Histori tertaut gudang: {$linkedTransactionCount} dari {$transactionCount} transaksi");
    }

    /** @return array{items:int,quantity:int} */
    private function applySafeMappings(): array
    {
        $applied = ['items' => 0, 'quantity' => 0];

        Barang::withTrashed()->select('id')->orderBy('id')->chunkById(200, function (Collection $items) use (&$applied): void {
            foreach ($items as $item) {
                $barang = Barang::withTrashed()->lockForUpdate()->findOrFail($item->id);
                $stocks = WarehouseStock::query()
                    ->with('warehouse')
                    ->where('barang_id', $barang->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $result = $this->classify($barang, $stocks);
                if ($result['action'] !== 'move') {
                    continue;
                }

                $main = $stocks->firstWhere('warehouse_id', $this->warehouses['GDG-UTAMA']->id);
                if (! $main) {
                    continue;
                }
                $targetWarehouse = $this->warehouses[$result['target']];
                $target = $stocks->firstWhere('warehouse_id', $targetWarehouse->id)
                    ?? WarehouseStock::create([
                        'barang_id' => $barang->id,
                        'warehouse_id' => $targetWarehouse->id,
                        'stok' => 0,
                        'stok_minimum' => 0,
                    ]);
                $quantity = (int) $main->stok;
                $main->update(['stok' => 0]);
                $target->update(['stok' => $quantity]);

                $total = (int) WarehouseStock::where('barang_id', $barang->id)->sum('stok');
                if ($total !== (int) $barang->stok) {
                    throw new \RuntimeException("Konsistensi stok gagal untuk Barang ID {$barang->id}.");
                }
                $applied['items']++;
                $applied['quantity'] += $quantity;
            }
        });

        return $applied;
    }

    /** @return array{normalized:string,target:string,status:string,action:string} */
    private function classify(Barang $barang, Collection $stocks): array
    {
        $normalized = $this->normalizeLocation($barang->lokasi);
        [$target, $recognized] = $this->targetFor($normalized);
        if ($stocks->contains(fn (WarehouseStock $stock): bool => $stock->stok < 0)) {
            return compact('normalized', 'target') + ['status' => 'konflik', 'action' => 'skip'];
        }
        $total = (int) $stocks->sum('stok');
        if ($total !== (int) $barang->stok) {
            return compact('normalized', 'target') + ['status' => 'selisih', 'action' => 'skip'];
        }

        $positive = $stocks->where('stok', '>', 0);
        if ($positive->count() > 1) {
            return compact('normalized', 'target') + ['status' => 'konflik', 'action' => 'skip'];
        }

        $current = $positive->first()?->warehouse?->kode_gudang;
        if (! $recognized) {
            $status = $current === null || $current === 'GDG-UTAMA' ? 'ambigu' : 'konflik';

            return compact('normalized', 'target', 'status') + ['action' => 'skip'];
        }

        if ($current === null || $current === $target) {
            return compact('normalized', 'target') + ['status' => 'aman', 'action' => 'none'];
        }
        if ($current === 'GDG-UTAMA' && $target !== 'GDG-UTAMA') {
            return compact('normalized', 'target') + ['status' => 'aman', 'action' => 'move'];
        }

        return compact('normalized', 'target') + ['status' => 'konflik', 'action' => 'skip'];
    }

    private function normalizeLocation(?string $location): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $location) ?? ''));
    }

    /** @return array{string,bool} */
    private function targetFor(string $normalized): array
    {
        if (isset(self::LOCATION_TARGETS[$normalized])) {
            return [self::LOCATION_TARGETS[$normalized], true];
        }

        return ['GDG-UTAMA', false];
    }

    private function severity(string $status): int
    {
        return ['aman' => 0, 'ambigu' => 1, 'konflik' => 2, 'selisih' => 3][$status] ?? 4;
    }

    private function mismatchedItemCount(): int
    {
        $stockTotals = WarehouseStock::query()
            ->selectRaw('barang_id, SUM(stok) AS total_stok')
            ->groupBy('barang_id');

        return DB::table('barang')
            ->leftJoinSub($stockTotals, 'warehouse_totals', 'warehouse_totals.barang_id', '=', 'barang.id')
            ->whereRaw('barang.stok <> COALESCE(warehouse_totals.total_stok, 0)')
            ->count();
    }
}
