<?php

namespace App\Console\Commands;

use App\Models\Barang;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SyncWarehouseBalances extends Command
{
    protected $signature = 'inventory:sync-warehouse-balances
        {--dry-run : Audit tanpa mengubah master atau saldo (mode default)}
        {--apply : Terapkan hanya bila seluruh data bebas konflik}';

    protected $description = 'Audit dan pindahkan saldo lama ke gudang yang disebut eksplisit pada lokasi Barang';

    public function handle(): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Gunakan salah satu opsi --dry-run atau --apply, bukan keduanya.');

            return self::INVALID;
        }

        $beforeTotal = (int) Barang::withTrashed()->sum('stok');
        $beforeWarehouseTotal = (int) WarehouseStock::sum('stok');
        $historyBefore = DB::table('stok_transactions')->count();
        $audit = $this->audit();
        $this->displayAudit($audit, $beforeTotal, $beforeWarehouseTotal);

        if (! $this->option('apply')) {
            $this->info('DRY-RUN: tidak ada data yang diubah. Gunakan --apply hanya setelah laporan ditinjau.');

            return self::SUCCESS;
        }

        if ($audit['conflicts'] > 0 || $beforeTotal !== $beforeWarehouseTotal) {
            $this->error('APPLY ditolak: audit masih memiliki konflik atau total stok global tidak sama.');

            return self::FAILURE;
        }

        try {
            $result = DB::transaction(function () use ($beforeTotal, $historyBefore): array {
                $applied = $this->applyLocked($beforeTotal);
                $afterTotal = (int) Barang::withTrashed()->sum('stok');
                $warehouseTotal = (int) WarehouseStock::sum('stok');
                $historyAfter = DB::table('stok_transactions')->count();

                if (
                    $beforeTotal !== $afterTotal
                    || $afterTotal !== $warehouseTotal
                    || $historyBefore !== $historyAfter
                    || $this->mismatchedItemCount() > 0
                    || WarehouseStock::where('stok', '<', 0)->exists()
                ) {
                    throw new RuntimeException('Verifikasi global gagal. Seluruh perubahan harus dibatalkan.');
                }

                return compact('applied', 'afterTotal', 'warehouseTotal', 'historyAfter');
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());
            $this->warn('Seluruh perubahan apply dibatalkan.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("APPLY selesai: {$result['applied']['items']} jenis Barang / {$result['applied']['quantity']} unit dipindahkan; {$result['applied']['warehouses']} master dibuat.");
        $this->line("Total stok sebelum/sesudah: {$beforeTotal} / {$result['afterTotal']}; total saldo gudang: {$result['warehouseTotal']}; histori tetap: {$result['historyAfter']} transaksi.");

        return self::SUCCESS;
    }

    /** @return array{rows:array<int,array<string,mixed>>,masters:array<string,array<string,mixed>>,before:array<string,array{items:int,quantity:int}>,after:array<string,array{items:int,quantity:int}>,statuses:array<string,array{items:int,quantity:int}>,conflicts:int} */
    private function audit(): array
    {
        $warehouses = Warehouse::withTrashed()->orderBy('id')->get();
        $masters = $this->masterPlans($warehouses);
        $rows = [];
        $before = $this->warehouseTotals($warehouses);
        $after = $before;
        $statuses = [];
        $conflicts = 0;

        Barang::withTrashed()->with('warehouseStocks.warehouse')->orderBy('id')
            ->chunkById(200, function (Collection $items) use (&$rows, &$masters, &$after, &$statuses, &$conflicts): void {
                foreach ($items as $barang) {
                    $result = $this->classify($barang, $barang->warehouseStocks, $masters);
                    $location = (string) ($barang->lokasi ?? '');
                    $rows[$location] ??= [
                        'original' => $location === '' ? '(kosong)' : $location,
                        'normalized' => $result['normalized'] === '' ? '(kosong)' : $result['normalized'],
                        'target' => $result['target'] ?? '—',
                        'items' => 0,
                        'positive_items' => 0,
                        'quantity' => 0,
                        'status' => $result['status'],
                    ];
                    $rows[$location]['items']++;
                    $rows[$location]['positive_items'] += (int) $barang->stok > 0 ? 1 : 0;
                    $rows[$location]['quantity'] += (int) $barang->stok;
                    if ($this->severity($result['status']) > $this->severity($rows[$location]['status'])) {
                        $rows[$location]['status'] = $result['status'];
                    }

                    $statuses[$result['status']] ??= ['items' => 0, 'quantity' => 0];
                    $statuses[$result['status']]['items']++;
                    $statuses[$result['status']]['quantity'] += (int) $barang->stok;
                    $conflicts += in_array($result['status'], ['konflik', 'selisih'], true) ? 1 : 0;

                    if ($result['action'] === 'move') {
                        $this->projectMove($after, $result['source'], $result['target'], (int) $barang->stok);
                    }
                }
            });

        foreach ($masters as $code => $master) {
            $after[$code] ??= ['items' => 0, 'quantity' => 0];
        }

        return [
            'rows' => array_values($rows),
            'masters' => $masters,
            'before' => $before,
            'after' => $after,
            'statuses' => $statuses,
            'conflicts' => $conflicts,
        ];
    }

    /** @param array<string,array<string,mixed>> $masters */
    private function classify(Barang $barang, Collection $stocks, array &$masters): array
    {
        $normalized = $this->normalizeLocation($barang->lokasi);
        $explicit = $this->explicitWarehouse($normalized);
        if ($explicit !== null) {
            $matchingPlan = collect($masters)->firstWhere('normalized_name', $explicit['normalized_name']);
            if ($matchingPlan) {
                $explicit['code'] = $matchingPlan['code'];
            }
        }
        $target = $explicit['code'] ?? null;
        if ($explicit !== null && ! isset($masters[$target])) {
            $masters[$target] = $explicit + ['warehouse' => null, 'state' => 'baru'];
        }

        if ($stocks->contains(fn (WarehouseStock $stock): bool => $stock->stok < 0)) {
            return compact('normalized', 'target') + ['source' => null, 'status' => 'konflik', 'action' => 'skip'];
        }
        if ((int) $stocks->sum('stok') !== (int) $barang->stok) {
            return compact('normalized', 'target') + ['source' => null, 'status' => 'selisih', 'action' => 'skip'];
        }

        $positive = $stocks->where('stok', '>', 0);
        if ($positive->count() > 1) {
            return compact('normalized', 'target') + ['source' => null, 'status' => 'konflik', 'action' => 'skip'];
        }

        $source = $positive->first()?->warehouse?->kode_gudang;
        if ($explicit === null) {
            return compact('normalized', 'target', 'source') + ['status' => 'ambigu', 'action' => 'skip'];
        }

        $master = $masters[$target];
        if ($master['state'] === 'konflik' || ($master['warehouse'] && ($master['warehouse']->trashed() || ! $master['warehouse']->is_active))) {
            return compact('normalized', 'target', 'source') + ['status' => 'konflik', 'action' => 'skip'];
        }
        if ($source === null || $source === $target) {
            return compact('normalized', 'target', 'source') + ['status' => 'aman', 'action' => 'none'];
        }
        if ($source === Warehouse::DEFAULT_CODE) {
            return compact('normalized', 'target', 'source') + ['status' => 'aman', 'action' => 'move'];
        }

        return compact('normalized', 'target', 'source') + ['status' => 'konflik', 'action' => 'skip'];
    }

    /** @return array{items:int,quantity:int,warehouses:int} */
    private function applyLocked(int $expectedTotal): array
    {
        $warehouses = Warehouse::withTrashed()->orderBy('id')->lockForUpdate()->get();
        $barang = Barang::withTrashed()->orderBy('id')->lockForUpdate()->get();
        $stocks = WarehouseStock::with('warehouse')->orderBy('id')->lockForUpdate()->get()->groupBy('barang_id');
        $masters = $this->masterPlans($warehouses);
        $moves = [];

        foreach ($barang as $item) {
            $result = $this->classify($item, $stocks->get($item->id, collect()), $masters);
            if (in_array($result['status'], ['konflik', 'selisih'], true)) {
                throw new RuntimeException("Konflik ditemukan saat lock pada Barang ID {$item->id}; apply dibatalkan.");
            }
            if ($result['action'] === 'move') {
                $moves[] = ['barang' => $item, 'result' => $result];
            }
        }

        if ((int) $barang->sum('stok') !== $expectedTotal || (int) $stocks->flatten(1)->sum('stok') !== $expectedTotal) {
            throw new RuntimeException('Total stok berubah sebelum lock selesai; apply dibatalkan.');
        }

        $created = 0;
        foreach ($masters as $code => &$master) {
            if ($master['state'] !== 'baru') {
                continue;
            }
            $master['warehouse'] = Warehouse::create([
                'kode_gudang' => $code,
                'nama_gudang' => $master['name'],
                'is_active' => true,
            ]);
            $master['state'] = 'tersedia';
            $created++;
        }
        unset($master);

        $applied = ['items' => 0, 'quantity' => 0, 'warehouses' => $created];
        foreach ($moves as $move) {
            /** @var Barang $item */
            $item = $move['barang'];
            $result = $move['result'];
            $itemStocks = $stocks->get($item->id, collect());
            $source = $itemStocks->first(fn (WarehouseStock $stock): bool => $stock->warehouse?->kode_gudang === $result['source']);
            $targetWarehouse = $masters[$result['target']]['warehouse'];
            if (! $source || ! $targetWarehouse) {
                throw new RuntimeException("Saldo sumber atau master target Barang ID {$item->id} tidak tersedia.");
            }
            $target = $itemStocks->firstWhere('warehouse_id', $targetWarehouse->id)
                ?? WarehouseStock::create([
                    'barang_id' => $item->id,
                    'warehouse_id' => $targetWarehouse->id,
                    'stok' => 0,
                    'stok_minimum' => 0,
                ]);
            $quantity = (int) $source->stok;
            $source->update(['stok' => 0]);
            $target->update(['stok' => $quantity]);

            if ((int) WarehouseStock::where('barang_id', $item->id)->sum('stok') !== (int) $item->stok) {
                throw new RuntimeException("Konsistensi stok gagal untuk Barang ID {$item->id}.");
            }
            $applied['items']++;
            $applied['quantity'] += $quantity;
        }

        return $applied;
    }

    /** @return array<string,array{code:string,name:string,normalized_name:string,warehouse:?Warehouse,state:string}> */
    private function masterPlans(Collection $warehouses): array
    {
        $plans = [];
        foreach ($warehouses as $warehouse) {
            $plans[$warehouse->kode_gudang] = [
                'code' => $warehouse->kode_gudang,
                'name' => $warehouse->nama_gudang,
                'normalized_name' => $this->normalizeLocation($warehouse->nama_gudang),
                'warehouse' => $warehouse,
                'state' => 'tersedia',
            ];
        }

        Barang::withTrashed()->pluck('lokasi')->each(function ($location) use (&$plans, $warehouses): void {
            $explicit = $this->explicitWarehouse($this->normalizeLocation($location));
            if ($explicit === null) {
                return;
            }
            $sameName = $warehouses->first(fn (Warehouse $warehouse): bool => $this->normalizeLocation($warehouse->nama_gudang) === $explicit['normalized_name']);
            if ($sameName) {
                $explicit['code'] = $sameName->kode_gudang;
            }
            $codeOwner = $warehouses->firstWhere('kode_gudang', $explicit['code']);
            $state = $codeOwner && $this->normalizeLocation($codeOwner->nama_gudang) !== $explicit['normalized_name'] ? 'konflik' : ($codeOwner ? 'tersedia' : 'baru');
            $plans[$explicit['code']] = $explicit + ['warehouse' => $codeOwner, 'state' => $state];
        });

        return $plans;
    }

    /** @return array{code:string,name:string,normalized_name:string}|null */
    private function explicitWarehouse(string $normalized): ?array
    {
        if (! preg_match('/^gudang\s+([\pL\pN]+)(?:\s*[,;\/-]\s*.+)?$/u', $normalized, $matches)) {
            return null;
        }
        $suffix = Str::upper(Str::slug($matches[1], '-'));
        if ($suffix === '') {
            return null;
        }
        $name = 'Gudang '.mb_convert_case($matches[1], MB_CASE_TITLE, 'UTF-8');
        $code = $suffix === 'UTAMA' ? Warehouse::DEFAULT_CODE : 'GDG-'.$suffix;

        return ['code' => $code, 'name' => $name, 'normalized_name' => $this->normalizeLocation($name)];
    }

    private function normalizeLocation(?string $location): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $location) ?? ''));
    }

    /** @return array<string,array{items:int,quantity:int}> */
    private function warehouseTotals(Collection $warehouses): array
    {
        return $warehouses->mapWithKeys(fn (Warehouse $warehouse): array => [
            $warehouse->kode_gudang => [
                'items' => $warehouse->warehouseStocks()->where('stok', '>', 0)->count(),
                'quantity' => (int) $warehouse->warehouseStocks()->sum('stok'),
            ],
        ])->all();
    }

    /** @param array<string,array{items:int,quantity:int}> $totals */
    private function projectMove(array &$totals, ?string $source, string $target, int $quantity): void
    {
        if ($source === null || $source === $target || $quantity <= 0) {
            return;
        }
        $totals[$source] ??= ['items' => 0, 'quantity' => 0];
        $totals[$target] ??= ['items' => 0, 'quantity' => 0];
        $totals[$source]['items']--;
        $totals[$source]['quantity'] -= $quantity;
        $totals[$target]['items']++;
        $totals[$target]['quantity'] += $quantity;
    }

    /** @param array{rows:array<int,array<string,mixed>>,masters:array<string,array<string,mixed>>,before:array<string,array{items:int,quantity:int}>,after:array<string,array{items:int,quantity:int}>,statuses:array<string,array{items:int,quantity:int}>,conflicts:int} $audit */
    private function displayAudit(array $audit, int $barangTotal, int $warehouseTotal): void
    {
        $this->table(
            ['Lokasi asli', 'Normalisasi', 'Target', 'Barang', 'Jenis bersaldo', 'Kuantitas', 'Status'],
            collect($audit['rows'])->sortBy('original')->map(fn (array $row): array => [
                $row['original'], $row['normalized'], $row['target'], $row['items'],
                $row['positive_items'], $row['quantity'], Str::upper($row['status']),
            ])->values()->all(),
        );
        $this->table(
            ['Kode', 'Nama Gudang', 'Status master'],
            collect($audit['masters'])->sortKeys()->map(fn (array $master): array => [
                $master['code'], $master['name'], Str::upper($master['state']),
            ])->values()->all(),
        );
        $codes = collect(array_keys($audit['before']))->merge(array_keys($audit['after']))->unique()->sort();
        $this->table(
            ['Gudang', 'Jenis sebelum', 'Unit sebelum', 'Jenis sesudah', 'Unit sesudah', 'Delta'],
            $codes->map(fn (string $code): array => [
                $code,
                $audit['before'][$code]['items'] ?? 0,
                $audit['before'][$code]['quantity'] ?? 0,
                $audit['after'][$code]['items'] ?? 0,
                $audit['after'][$code]['quantity'] ?? 0,
                ($audit['after'][$code]['quantity'] ?? 0) - ($audit['before'][$code]['quantity'] ?? 0),
            ])->all(),
        );

        $this->line("Total stok Barang / saldo Gudang: {$barangTotal} / {$warehouseTotal}");
        foreach ($audit['statuses'] as $status => $values) {
            $this->line(Str::upper($status).": {$values['items']} jenis / {$values['quantity']} unit");
        }
        $this->line("Konflik yang memblokir apply: {$audit['conflicts']}");
        $this->line('Histori stok: '.DB::table('stok_transactions')->count().' transaksi (tidak akan diubah).');
    }

    private function severity(string $status): int
    {
        return ['aman' => 0, 'ambigu' => 1, 'konflik' => 2, 'selisih' => 3][$status] ?? 4;
    }

    private function mismatchedItemCount(): int
    {
        $stockTotals = WarehouseStock::query()->selectRaw('barang_id, SUM(stok) AS total_stok')->groupBy('barang_id');

        return DB::table('barang')
            ->leftJoinSub($stockTotals, 'warehouse_totals', 'warehouse_totals.barang_id', '=', 'barang.id')
            ->whereRaw('barang.stok <> COALESCE(warehouse_totals.total_stok, 0)')
            ->count();
    }
}
