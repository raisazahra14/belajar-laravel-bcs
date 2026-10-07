<?php

namespace App\Services;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;

class BarangQrCodeService
{
    public function svg(string $kodeBarang): string
    {
        return Builder::create()
            ->writer(new SvgWriter)
            ->writerOptions([
                SvgWriter::WRITER_OPTION_COMPACT => true,
                SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true,
            ])
            // Isi QR sengaja hanya kode barang agar persis sama dengan input scanner.
            ->data($kodeBarang)
            ->errorCorrectionLevel(ErrorCorrectionLevel::Medium)
            ->size(320)
            ->margin(16)
            ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
            ->build()
            ->getString();
    }
}
