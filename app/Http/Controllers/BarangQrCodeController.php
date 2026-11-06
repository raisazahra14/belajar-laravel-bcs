<?php

namespace App\Http\Controllers;

use App\Models\Barang;
use App\Services\BarangQrCodeService;
use App\Services\BarcodeLabelPdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BarangQrCodeController extends Controller
{
    public function show(Barang $barang, BarangQrCodeService $qrCode): Response
    {
        return $this->response($barang, $qrCode, false);
    }

    public function download(Barang $barang, BarangQrCodeService $qrCode): Response
    {
        return $this->response($barang, $qrCode, true);
    }

    public function labelPdf(Request $request, Barang $barang, BarcodeLabelPdfService $labelPdf): Response
    {
        $copies = max(1, min(200, (int) $request->input('copies', 10)));
        $startSlot = max(1, min(10, (int) $request->input('start_slot', 1)));
        $safeCode = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $barang->kode_barang), '-');
        $filename = 'label-qr-'.($safeCode !== '' ? $safeCode : (string) $barang->getKey()).'.pdf';

        return response($labelPdf->makeForBarang($barang, $copies, $startSlot), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function response(Barang $barang, BarangQrCodeService $qrCode, bool $download): Response
    {
        $headers = [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($download) {
            $safeCode = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $barang->kode_barang), '-');
            $filename = 'qr-'.($safeCode !== '' ? $safeCode : (string) $barang->getKey()).'.svg';
            $headers['Content-Disposition'] = 'attachment; filename="'.$filename.'"';
        }

        return response($qrCode->svg($barang->kode_barang), 200, $headers);
    }
}
