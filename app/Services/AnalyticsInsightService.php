<?php

namespace App\Services;

use Illuminate\Support\Collection;

class AnalyticsInsightService
{
    /** @return array{notifications:Collection<int,array<string,mixed>>,summary:array<string,mixed>} */
    public function build(array $mutation, array $movement, array $attention, ?string $warehouseName = null): array
    {
        $deadCount = (int) ($movement['composition']['item_counts'][2] ?? 0);
        $deadValuation = $movement['dead_stock_valuation'];
        $scope = $warehouseName === null ? 'seluruh gudang' : 'Gudang '.$warehouseName;
        $notifications = collect();

        if ($attention['low_stock_count'] > 0) {
            $top = $attention['top_low_stock'];
            $notifications->push([
                'category' => 'Stok menipis',
                'priority' => 'Tinggi',
                'priority_key' => 'high',
                'affected_count' => $attention['low_stock_count'],
                'reason' => "Saldo saat ini memenuhi batas minimum pada {$scope}. Aturan: {$attention['minimum_stock_rule']}.",
                'top_item' => $top,
                'action_url' => url('barang/'.$top['barang_id']),
                'action_label' => 'Lihat barang prioritas',
            ]);
        }

        if ($deadCount > 0) {
            $top = $movement['top_dead_stock'];
            $notifications->push([
                'category' => 'Dead stock',
                'priority' => 'Sedang',
                'priority_key' => 'medium',
                'affected_count' => $deadCount,
                'reason' => sprintf(
                    'Stok positif tanpa transaksi OUT selama 60 hari. Nilai yang dapat dihitung %s; %s barang belum memiliki harga.',
                    $this->rupiah($deadValuation['calculated_value']),
                    number_format((int) $deadValuation['unpriced_item_count'], 0, ',', '.'),
                ),
                'top_item' => $top,
                'action_url' => url('barang/'.$top['barang_id']),
                'action_label' => 'Lihat nilai terbesar',
            ]);
        }

        if ($attention['unpriced_item_count'] > 0) {
            $top = $attention['top_unpriced'];
            $notifications->push([
                'category' => 'Harga beli belum diisi',
                'priority' => 'Rendah',
                'priority_key' => 'low',
                'affected_count' => $attention['unpriced_item_count'],
                'reason' => number_format($attention['unpriced_stock_units'], 0, ',', '.')
                    .' unit stok saat ini terkait barang dengan harga NULL. Harga 0 tetap dianggap sudah diisi.',
                'top_item' => $top,
                'action_url' => url('barang/'.$top['barang_id']),
                'action_label' => 'Lihat barang',
            ]);
        }

        return [
            'notifications' => $notifications,
            'summary' => $this->summary($mutation, $movement, $attention, $scope),
        ];
    }

    /** @return array{statements:array<int,string>,recommendations:array<int,string>,limitations:array<int,string>} */
    private function summary(array $mutation, array $movement, array $attention, string $scope): array
    {
        $totals = $mutation['totals'];
        $period = $mutation['period'];
        $topOutgoing = $mutation['top_outgoing'] ?? null;
        $dead = $movement['dead_stock_valuation'];
        $deadCount = (int) ($movement['composition']['item_counts'][2] ?? 0);
        $statements = [
            sprintf(
                'Pada periode %s sampai %s, %s jenis barang masuk sebanyak %s unit dan %s jenis barang keluar sebanyak %s unit.',
                $period['start_date'],
                $period['end_date'],
                number_format((int) ($totals['barang_masuk'] ?? 0), 0, ',', '.'),
                number_format((int) $totals['total_masuk'], 0, ',', '.'),
                number_format((int) ($totals['barang_keluar'] ?? 0), 0, ',', '.'),
                number_format((int) $totals['total_keluar'], 0, ',', '.'),
            ),
            $topOutgoing === null
                ? 'Tidak ada barang keluar pada periode pilihan, sehingga barang dengan OUT terbanyak tidak tersedia.'
                : sprintf(
                    'Barang paling banyak keluar pada periode pilihan adalah %s (%s) sebanyak %s unit.',
                    $topOutgoing['nama_barang'],
                    $topOutgoing['kode_barang'],
                    number_format($topOutgoing['total_unit_keluar'], 0, ',', '.'),
                ),
            sprintf(
                'Dalam jendela tetap 60 hari (%s sampai %s), terdapat %s dead stock dengan nilai yang dapat dihitung %s; %s barang di antaranya belum memiliki harga.',
                $movement['periods']['start_60'],
                $movement['periods']['end'],
                number_format($deadCount, 0, ',', '.'),
                $this->rupiah($dead['calculated_value']),
                number_format((int) $dead['unpriced_item_count'], 0, ',', '.'),
            ),
            sprintf(
                'Berdasarkan stok saat ini pada %s, %s barang menipis dan %s barang belum memiliki harga beli.',
                $scope,
                number_format($attention['low_stock_count'], 0, ',', '.'),
                number_format($attention['unpriced_item_count'], 0, ',', '.'),
            ),
        ];

        $recommendations = [];
        if ($attention['low_stock_count'] > 0) {
            $recommendations[] = 'Prioritaskan pemeriksaan kebutuhan pengadaan untuk barang dengan stok menipis.';
        }
        if ($deadCount > 0) {
            $recommendations[] = 'Evaluasi dead stock mulai dari nilai stok yang dapat dihitung paling besar.';
        }
        if ($attention['unpriced_item_count'] > 0) {
            $recommendations[] = 'Lengkapi harga beli yang masih NULL agar valuasi lebih utuh.';
        }
        if ($recommendations === []) {
            $recommendations[] = 'Tidak ada tindak lanjut prioritas dari tiga aturan pemantauan saat ini.';
        }

        $limitations = [];
        if (! $totals['history_available']) {
            $limitations[] = 'Saldo historis sebagian barang tidak dapat dibuktikan; ringkasan hanya menyatakan mutasi aktual dan tidak menyimpulkan tren.';
        }
        if (! $movement['warehouse_history']['complete_60']) {
            $limitations[] = $movement['warehouse_history']['excluded_barang_60'].' barang dikecualikan dari analisis 60 hari karena relasi gudangnya tidak dapat dibuktikan.';
        }
        if ($dead['unpriced_item_count'] > 0 || $attention['unpriced_item_count'] > 0) {
            $limitations[] = 'Harga NULL tidak dianggap nol; nilai yang ditampilkan hanya mencakup barang dengan harga beli yang tersedia.';
        }
        if ((int) $totals['total_masuk'] === 0 && (int) $totals['total_keluar'] === 0) {
            $limitations[] = 'Tidak ada mutasi pada periode pilihan; tidak ada kesimpulan kenaikan atau penurunan yang dibuat.';
        }

        return compact('statements', 'recommendations', 'limitations');
    }

    private function rupiah(string $value): string
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '00');

        return 'Rp'.number_format((int) $whole, 0, ',', '.').','.str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
