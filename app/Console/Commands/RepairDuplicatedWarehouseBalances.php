<?php

namespace App\Console\Commands;

use App\Models\Barang;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class RepairDuplicatedWarehouseBalances extends Command
{
    protected $signature = 'inventory:repair-duplicated-warehouse-balances
        {--apply : Terapkan perbaikan (default: dry-run)}
        {--mapping= : CSV pemetaan: kode_barang,kode_gudang[,rak]}
        {--map-rack-letters : Petakan "Rak A*/B*/C*" ke GDG-A/B/C (hanya bila huruf rak memang = gudang)}
        {--fallback= : Kode gudang untuk barang yang tidak dapat dipetakan (mis. GDG-UTAMA)}';

    protected $description = 'Perbaiki saldo gudang terduplikasi: stok master hanya ditempatkan di satu gudang target';

    public function handle(): int
    {
        try {
            $mapping = $this->loadMapping((string) $this->option('mapping'));
            $fallback = $this->option('fallback') ? $this->warehouseByCode((string) $this->option('fallback')) : null;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $plan = $this->plan($mapping, $fallback);
        $this->display($plan);

        if (! $this->option('apply')) {
            $this->info('DRY-RUN: tidak ada data yang diubah.');

            return self::SUCCESS;
        }
        if ($plan['unresolved'] > 0) {
            $this->error("APPLY ditolak: {$plan['unresolved']} barang belum punya gudang target (gunakan --mapping/--fallback).");

            return self::FAILURE;
        }

        $totalBefore = (int) Barang::withTrashed()->sum('stok');
        $historyBefore = DB::table('stok_transactions')->count();

        try {
            $fixed = DB::transaction(function () use ($mapping, $fallback, $totalBefore, $historyBefore): int {
                Barang::withTrashed()->orderBy('id')->lockForUpdate()->get();
                $locked = $this->plan($mapping, $fallback);
                if ($locked['unresolved'] > 0) {
                    throw new RuntimeException('Data berubah saat lock; apply dibatalkan.');
                }
                $count = 0;
                foreach ($locked['items'] as $item) {
                    $this->repair($item['barang'], $item['warehouse'], $item['rack']);
                    $count++;
                }

                $mismatch = DB::table('barang')
                    ->leftJoinSub(
                        WarehouseStock::query()->selectRaw('barang_id, SUM(stok) AS total')->groupBy('barang_id'),
                        'w', 'w.barang_id', '=', 'barang.id'
                    )
                    ->whereRaw('barang.stok <> COALESCE(w.total, 0)')->count();

                if (
                    $mismatch > 0
                    || WarehouseStock::where('stok', '<', 0)->exists()
                    || (int) Barang::withTrashed()->sum('stok') !== $totalBefore
                    || (int) WarehouseStock::sum('stok') !== $totalBefore
                    || DB::table('stok_transactions')->count() !== $historyBefore
                ) {
                    throw new RuntimeException('Validasi akhir gagal; seluruh perubahan dibatalkan.');
                }

                return $count;
            });
        } catch (Throwable $e) {
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("APPLY selesai: {$fixed} barang diperbaiki. Total stok {$totalBefore} = total saldo gudang.");

        return self::SUCCESS;
    }

    /** @return array{items:array<int,array<string,mixed>>,unresolved:int,rows:array<int,array<int,mixed>>,before:int,after:int} */
    private function plan(array $mapping, ?Warehouse $fallback): array
    {
        $warehouses = Warehouse::where('is_active', true)->get();
        $items = [];
        $rows = [];
        $unresolved = 0;
        $before = 0;
        $after = 0;

        Barang::withTrashed()->with('warehouseStocks')->orderBy('id')->chunkById(200, function (Collection $chunk) use (&$items, &$rows, &$unresolved, &$before, &$after, $mapping, $fallback, $warehouses): void {
            foreach ($chunk as $barang) {
                $sum = (int) $barang->warehouseStocks->sum('stok');
                if ($sum === (int) $barang->stok && ! $barang->warehouseStocks->contains(fn ($s) => $s->stok < 0)) {
                    continue;
                }
                $before += $sum;
                $after += (int) $barang->stok;
                [$warehouse, $rack, $source] = $this->resolve($barang, $mapping, $fallback, $warehouses);
                if (! $warehouse) {
                    $unresolved++;
                } else {
                    $items[] = compact('barang', 'warehouse', 'rack');
                }
                $rows[] = [$barang->kode_barang, $barang->lokasi ?: '(kosong)', $barang->stok, $sum, $warehouse?->kode_gudang ?? 'BELUM DIPETAKAN', $source];
            }
        });

        return compact('items', 'unresolved', 'rows', 'before', 'after');
    }

    /** @return array{0:?Warehouse,1:?string,2:string} */
    private function resolve(Barang $barang, array $mapping, ?Warehouse $fallback, Collection $warehouses): array
    {
        $location = trim(preg_replace('/\s+/u', ' ', (string) $barang->lokasi) ?? '');

        if (isset($mapping[$barang->kode_barang])) {
            [$code, $rack] = $mapping[$barang->kode_barang];
            $warehouse = $warehouses->firstWhere('kode_gudang', $code);
            if (! $warehouse) {
                throw new RuntimeException("Gudang '{$code}' pada mapping tidak ditemukan/aktif.");
            }

            return [$warehouse, $rack ?: $location ?: null, 'mapping'];
        }

        if (preg_match('/^gudang\s+([\pL\pN]+)(?:\s*[,;\/-]\s*(.+))?$/iu', $location, $m)) {
            $name = mb_strtolower('Gudang '.$m[1]);
            $warehouse = $warehouses->first(fn (Warehouse $w): bool => mb_strtolower(trim($w->nama_gudang)) === $name);
            if ($warehouse) {
                return [$warehouse, ($m[2] ?? null) ?: null, 'lokasi eksplisit'];
            }
        }

        if ($this->option('map-rack-letters') && preg_match('/^rak\s+([a-z])\s*-?\s*\d*/iu', $location, $m)) {
            $warehouse = $warehouses->firstWhere('kode_gudang', 'GDG-'.mb_strtoupper($m[1]));
            if ($warehouse) {
                return [$warehouse, $location, 'huruf rak'];
            }
        }

        return $fallback ? [$fallback, $location ?: null, 'fallback'] : [null, null, 'ambigu'];
    }

    private function repair(Barang $barang, Warehouse $target, ?string $rack): void
    {
        WarehouseStock::where('barang_id', $barang->id)
            ->where('warehouse_id', '!=', $target->id)
            ->get()
            ->each(fn (WarehouseStock $s) => $s->update(['stok' => 0]));

        $stock = WarehouseStock::firstOrNew(['barang_id' => $barang->id, 'warehouse_id' => $target->id]);
        $stock->stok = max(0, (int) $barang->stok);
        if ($rack && ! $stock->rack_location) {
            $stock->rack_location = mb_substr($rack, 0, 100);
        }
        $stock->save();
    }

    /** @return array<string,array{0:string,1:?string}> */
    private function loadMapping(string $path): array
    {
        if ($path === '') {
            return [];
        }
        if (! is_file($path) || ($handle = fopen($path, 'r')) === false) {
            throw new RuntimeException("File mapping '{$path}' tidak dapat dibaca.");
        }
        $mapping = [];
        while (($row = fgetcsv($handle)) !== false) {
            $row = array_map('trim', $row);
            if (count($row) < 2 || $row[0] === '' || strtolower($row[0]) === 'kode_barang') {
                continue;
            }
            $mapping[$row[0]] = [$row[1], $row[2] ?? null];
        }
        fclose($handle);

        return $mapping;
    }

    private function warehouseByCode(string $code): Warehouse
    {
        return Warehouse::where('kode_gudang', $code)->where('is_active', true)->first()
            ?? throw new RuntimeException("Gudang fallback '{$code}' tidak ditemukan/aktif.");
    }

    private function display(array $plan): void
    {
        $this->table(['Kode', 'Lokasi lama', 'Stok master', 'Saldo gudang', 'Target', 'Sumber'], $plan['rows']);
        $this->line('Barang tidak konsisten: '.count($plan['rows']).'; belum dipetakan: '.$plan['unresolved']);
        $this->line("Total saldo gudang terdampak sebelum/sesudah: {$plan['before']} / {$plan['after']}");
    }
}
