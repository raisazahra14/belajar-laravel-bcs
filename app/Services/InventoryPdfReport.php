<?php

namespace App\Services;

use Illuminate\Support\Collection;

class InventoryPdfReport
{
    public function make(Collection $items): string
    {
        $pages = $items->chunk(32);
        if ($pages->isEmpty()) {
            $pages = collect([collect()]);
        }

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
        ];
        $pageIds = [];
        $nextId = 5;

        foreach ($pages as $pageNumber => $pageItems) {
            $pageId = $nextId++;
            $contentId = $nextId++;
            $pageIds[] = $pageId;
            $stream = $this->page($pageItems, $pageNumber + 1, $pages->count());
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', array_map(fn (int $id) => "{$id} 0 R", $pageIds)).'] /Count '.count($pageIds).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(max(array_keys($objects)) + 1)."\n0000000000 65535 f \n";
        for ($id = 1; $id <= max(array_keys($objects)); $id++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$id])."\n";
        }

        return $pdf."trailer\n<< /Size ".(max(array_keys($objects)) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    private function page(Collection $items, int $page, int $totalPages): string
    {
        $commands = [
            'BT /F2 18 Tf 40 555 Td (Laporan Persediaan Barang) Tj ET',
            'BT /F1 9 Tf 40 537 Td (Dicetak: '.$this->escape(now()->format('d/m/Y H:i')).') Tj ET',
            'BT /F2 9 Tf 40 510 Td (No.) Tj 35 0 Td (Kode Barang) Tj 105 0 Td (Nama Barang) Tj 190 0 Td (Kategori) Tj 105 0 Td (Stok) Tj 70 0 Td (Satuan) Tj 65 0 Td (Lokasi) Tj ET',
            '40 502 m 802 502 l S',
        ];
        $y = 485;
        foreach ($items->values() as $index => $item) {
            $values = [
                (string) (($page - 1) * 32 + $index + 1),
                (string) $item->kode_barang,
                (string) $item->nama_barang,
                (string) $item->kategori,
                (string) $item->stok,
                (string) $item->satuan,
                (string) $item->lokasi,
            ];
            $xPositions = [40, 75, 180, 370, 475, 545, 610];
            foreach ($values as $column => $value) {
                $commands[] = "BT /F1 8 Tf {$xPositions[$column]} {$y} Td (".$this->escape(mb_strimwidth($value, 0, $column === 2 ? 34 : 20, '...')).') Tj ET';
            }
            $commands[] = '40 '.($y - 6).' m 802 '.($y - 6).' l S';
            $y -= 14;
        }
        $commands[] = "BT /F1 8 Tf 735 25 Td (Halaman {$page}/{$totalPages}) Tj ET";

        return implode("\n", $commands);
    }

    private function escape(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii);
    }
}
