@php
    $badges = [
        'lengkap' => ['badge-success', 'Lengkap'],
        'perlu_ditinjau' => ['badge-warning', 'Perlu Ditinjau'],
        'terindikasi_manipulasi' => ['badge-danger', 'Terindikasi Manipulasi'],
        'tidak_terbaca' => ['badge-secondary', 'Tidak Terbaca'],
    ];
    [$class, $label] = $badges[$status] ?? $badges['tidak_terbaca'];
@endphp
<span class="badge {{ $class }}">{{ $label }}</span>
