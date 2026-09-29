<?php

namespace App\Services;

use Carbon\CarbonInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsCsv
{
    private const BOM = "\xEF\xBB\xBF";

    /** @param array<string,mixed> $data */
    public function download(array $data): StreamedResponse
    {
        return response()->streamDownload(function () use ($data): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new RuntimeException('Tidak dapat membuka stream CSV analitik.');
            }

            try {
                fwrite($stream, self::BOM);
                foreach ($this->rows($data) as $row) {
                    fputcsv($stream, array_map($this->safeCell(...), $row), ',', '"', '', "\r\n");
                }
            } finally {
                fclose($stream);
            }
        }, 'analitik-bisnis-'.now(config('app.display_timezone'))->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @param array<string,mixed> $data */
    private function rows(array $data): iterable
    {
        $mutation = $data['mutation'];
        $movement = $data['movement'];
        $valuation = $data['valuation'];
        $warehouse = $data['selectedWarehouse']?->nama_gudang ?? 'Semua gudang';
        $supplier = $data['selectedSupplier']?->nama_supplier ?? 'Semua supplier master';

        yield ['ANALITIK BISNIS LOGISTIKKU'];
        yield ['Waktu dibuat', now(config('app.display_timezone'))->format('Y-m-d H:i:s T')];
        yield ['Filter gudang', $warehouse];
        yield ['Filter supplier', $supplier];
        yield ['Periode mutasi', $mutation['period']['start_date'].' s.d. '.$mutation['period']['end_date']];
        yield ['Definisi rasio perputaran', $mutation['turnover']['definition']];
        yield ['Definisi Fast-Moving', 'Top 5 total unit OUT dalam 30 hari terakhir'];
        yield ['Definisi Slow-Moving', 'Stok positif dan total OUT 1–2 unit dalam 60 hari terakhir'];
        yield ['Definisi Dead Stock', 'Stok positif dan tanpa OUT dalam 60 hari terakhir'];
        yield ['Definisi valuasi', 'Stok saat ini × harga beli master; harga kosong tidak dianggap nol'];
        yield [];

        yield ['RINGKASAN'];
        yield ['Total mutasi masuk', $mutation['totals']['total_masuk']];
        yield ['Total mutasi keluar', $mutation['totals']['total_keluar']];
        yield [$valuation['total']['label'], $valuation['total']['calculated_value']];
        yield ['Rasio perputaran', $mutation['turnover']['formatted'] ?? 'Tidak tersedia'];
        yield ['Status rasio', $mutation['turnover']['reason'] ?? 'Tersedia'];
        yield ['Barang tanpa harga', $valuation['total']['unpriced_item_count']];
        yield ['Unit tanpa harga', $valuation['total']['unpriced_stock_units']];
        yield ['Cakupan harga unit', $valuation['total']['coverage_percentage'].'%'];
        yield [];

        yield ['REKAP MUTASI'];
        yield ['Kode', 'Barang', 'Kategori', 'Supplier master', 'Saldo awal', 'Masuk', 'Keluar', 'Saldo akhir', 'Status'];
        foreach ($mutation['rows'] as $row) {
            yield [
                $row['barang']->kode_barang,
                $row['barang']->nama_barang,
                $row['barang']->kategori,
                $row['barang']->supplier?->nama_supplier ?? 'Tanpa Supplier',
                $row['history_available'] ? $row['saldo_awal'] : 'Tidak tersedia',
                $row['total_masuk'],
                $row['total_keluar'],
                $row['history_available'] ? $row['saldo_akhir'] : 'Tidak tersedia',
                $row['history_available'] ? 'Tersedia' : $row['unavailable_reason'],
            ];
        }
        yield [];

        yield from $this->movementSection('FAST-MOVING — 30 HARI', $movement['periods']['start_30'].' s.d. '.$movement['periods']['end'], $movement['fast_moving']);
        yield from $this->movementSection('SLOW-MOVING — 60 HARI', $movement['periods']['start_60'].' s.d. '.$movement['periods']['end'], $movement['slow_moving']);
        yield from $this->movementSection('DEAD STOCK — 60 HARI', $movement['periods']['start_60'].' s.d. '.$movement['periods']['end'], $movement['dead_stock']);

        yield ['VALUASI PER KATEGORI', 'Acuan '.$this->dateTime($valuation['scope']['as_of'])];
        yield ['Kategori', 'Nilai terhitung', 'Barang dinilai', 'Unit dinilai', 'Barang tanpa harga', 'Unit tanpa harga', 'Status'];
        foreach ($valuation['categories'] as $row) {
            yield [$row['kategori'], $row['calculated_value'], $row['priced_item_count'], $row['priced_stock_units'], $row['unpriced_item_count'], $row['unpriced_stock_units'], $row['label']];
        }
        yield [];

        yield ['VALUASI PER GUDANG', 'Acuan '.$this->dateTime($valuation['scope']['as_of'])];
        yield ['Kode gudang', 'Gudang', 'Nilai terhitung', 'Barang tanpa harga', 'Unit tanpa harga', 'Status'];
        foreach ($valuation['warehouses'] as $row) {
            yield [$row['kode_gudang'], $row['nama_gudang'], $row['calculated_value'], $row['unpriced_item_count'], $row['unpriced_stock_units'], $row['label']];
        }
        yield [];

        yield ['AUDIT SALDO'];
        yield ['Status', $valuation['stock_consistency']['status']];
        yield ['Cakupan audit', $valuation['stock_consistency']['scope']];
        yield ['Saldo barang', $valuation['stock_consistency']['barang_stock_units']];
        yield ['Saldo seluruh gudang', $valuation['stock_consistency']['warehouse_stock_units']];
        yield ['Selisih', $valuation['stock_consistency']['difference_units']];
        yield ['Barang berselisih', $valuation['stock_consistency']['mismatched_item_count']];
        yield ['Kelengkapan histori gudang 30 hari', $movement['warehouse_history']['complete_30'] ? 'Lengkap' : 'Tidak lengkap'];
        yield ['Kelengkapan histori gudang 60 hari', $movement['warehouse_history']['complete_60'] ? 'Lengkap' : 'Tidak lengkap'];
    }

    private function movementSection(string $title, string $period, iterable $rows): iterable
    {
        yield [$title];
        yield ['Periode', $period];
        yield ['Kode', 'Barang', 'Total OUT', 'Jumlah transaksi', 'OUT terakhir', 'Stok saat ini'];
        foreach ($rows as $row) {
            yield [$row['kode_barang'], $row['nama_barang'], $row['total_unit_keluar'], $row['jumlah_transaksi'], $this->dateTime($row['out_terakhir']), $row['stok_saat_ini']];
        }
        yield [];
    }

    private function dateTime(?CarbonInterface $date): string
    {
        return $date?->format('Y-m-d H:i:s T') ?? 'Tidak tersedia';
    }

    private function safeCell(mixed $value): string
    {
        $value = (string) $value;
        if (preg_match('/\A[\s\p{Z}\x{FEFF}]*[=+@\-\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]/u', $value) === 1
            || preg_match('/\A[\t\r\n]/', $value) === 1) {
            return "\t".$value;
        }

        return $value;
    }
}
