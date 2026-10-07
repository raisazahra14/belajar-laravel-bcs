<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

for ($index = 1; $index <= 16; $index++) {
    App\Models\Barang::create([
        'kode_barang' => sprintf('BRG-8%05d', $index),
        'nama_barang' => sprintf('Audit Browser %02d', $index),
        'kategori' => 'ATK',
        'stok' => 10,
        'daily_usage_estimate' => 1,
        'lead_time_days' => 3,
        'satuan' => 'Unit',
        'lokasi' => 'Rak Audit',
    ]);
}

$image = imagecreatetruecolor(24, 24);
$white = imagecolorallocate($image, 255, 255, 255);
imagefill($image, 0, 0, $white);
imagepng($image, __DIR__.'/browser-audit.png');
imagedestroy($image);

echo App\Models\Barang::count(), PHP_EOL;
