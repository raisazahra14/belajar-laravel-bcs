@php
    $badges = [
        'menunggu' => ['badge-info', 'Menunggu'],
        'diproses' => ['badge-primary', 'Diproses'],
        'selesai' => ['badge-secondary', 'Selesai'],
        'gagal' => ['badge-dark', 'Gagal'],
    ];
    [$class, $label] = $badges[$processStatus] ?? ['badge-secondary', 'Tidak dikenal'];
@endphp
<span class="badge {{ $class }}">{{ $label }}</span>
