<?php

return [
    'units' => [
        'Pcs',
        'Unit',
        'Set',
        'Pack',
        'Box',
        'Kg',
        'Liter',
    ],

    'category_locations' => [
        'Elektronik' => ['Gudang A', 'Gudang C', 'Ruang IT'],
        'Jaringan' => ['Ruang IT'],
        'Peralatan' => ['Gudang B', 'Gudang C'],
        'ATK' => ['Rak A1', 'Rak A2'],
        'Bahan Baku' => ['Rak B2', 'Rak B3'],
        'Furniture' => ['Gudang B', 'Gudang C'],
    ],

    'products' => [
        ['name' => 'Laptop Lenovo ThinkPad', 'category' => 'Elektronik', 'unit' => 'Unit', 'image' => 'images/barang/laptop-lenovo-thinkpad.png'],
        ['name' => 'Monitor LED 24 Inch', 'category' => 'Elektronik', 'unit' => 'Unit', 'image' => 'images/barang/monitor-led-24-inch.png'],
        ['name' => 'Router Wi-Fi AC1200', 'category' => 'Jaringan', 'unit' => 'Unit', 'image' => 'images/barang/router-wifi-ac1200.png'],
        ['name' => 'Keyboard Wireless', 'category' => 'Elektronik', 'unit' => 'Unit', 'image' => 'images/barang/keyboard-wireless.png'],
        ['name' => 'Mouse Optik USB', 'category' => 'Elektronik', 'unit' => 'Unit', 'image' => 'images/barang/mouse-optik-usb.png'],
        ['name' => 'Kabel LAN Cat6', 'category' => 'Jaringan', 'unit' => 'Pcs', 'image' => 'images/barang/kabel-lan-cat6.png'],
        ['name' => 'Kertas HVS A4', 'category' => 'ATK', 'unit' => 'Pack', 'image' => 'images/barang/kertas-hvs-a4.png'],
        ['name' => 'Pulpen Gel Hitam', 'category' => 'ATK', 'unit' => 'Pcs', 'image' => 'images/barang/pulpen-gel-hitam.png'],
        ['name' => 'Stapler Besar', 'category' => 'ATK', 'unit' => 'Unit', 'image' => 'images/barang/stapler-besar.png'],
        ['name' => 'Kursi Kerja Ergonomis', 'category' => 'Furniture', 'unit' => 'Unit', 'image' => 'images/barang/kursi-kerja-ergonomis.png'],
        ['name' => 'Meja Kerja Kayu', 'category' => 'Furniture', 'unit' => 'Unit', 'image' => 'images/barang/meja-kerja-kayu.png'],
        ['name' => 'Proyektor Portable', 'category' => 'Elektronik', 'unit' => 'Unit', 'image' => 'images/barang/proyektor-portable.png'],
        ['name' => 'Toolkit Teknisi', 'category' => 'Peralatan', 'unit' => 'Set', 'image' => 'images/barang/toolkit-teknisi.png'],
        ['name' => 'Masker Sekali Pakai', 'category' => 'Peralatan', 'unit' => 'Box', 'image' => 'images/barang/masker-sekali-pakai.png'],
        ['name' => 'Beras', 'category' => 'Bahan Baku', 'unit' => 'Kg', 'image' => 'images/barang/beras.png'],
        ['name' => 'Minyak Pelumas', 'category' => 'Bahan Baku', 'unit' => 'Liter', 'image' => 'images/barang/minyak-pelumas.png'],
    ],
];
