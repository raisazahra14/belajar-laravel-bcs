@php
    $badges = [
        'asli' => ['badge-success', 'Asli'],
        'mencurigakan' => ['badge-warning', 'Mencurigakan'],
        'palsu' => ['badge-danger', 'Palsu'],
    ];
    [$class, $label] = $badges[$authenticityStatus] ?? ['badge-secondary', 'Belum tersedia'];
@endphp
<span class="badge {{ $class }}">{{ $label }}</span>
