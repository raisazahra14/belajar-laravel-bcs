@php
    $badges = [
        'valid' => ['badge-success', 'Valid/Asli'],
        'review' => ['badge-warning', 'Perlu Ditinjau'],
        'suspicious' => ['badge-danger', 'Terindikasi Palsu/Manipulasi'],
        'failed' => ['badge-secondary', 'Verifikasi Gagal'],
    ];
    [$class, $label] = $badges[$status] ?? $badges['failed'];
@endphp
<span class="badge {{ $class }}">{{ $label }}</span>
