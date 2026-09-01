@php
    $badges = [
        'asli' => ['badge-success', 'Asli'],
        'lengkap' => ['badge-success', 'Lengkap'],
        'mencurigakan' => ['badge-warning', 'Mencurigakan'],
        'perlu_ditinjau' => ['badge-warning', 'Perlu Ditinjau'],
        'palsu' => ['badge-danger', 'Palsu'],
        'terindikasi_manipulasi' => ['badge-danger', 'Terindikasi Manipulasi'],
        'tidak_terbaca' => ['badge-dark', 'Tidak Terbaca'],
        'gagal_diproses' => ['badge-secondary', 'Gagal Diproses'],
        'menunggu' => ['badge-info', 'Menunggu Antrean'],
        'sedang_dianalisis' => ['badge-primary', 'Sedang Dianalisis'],
    ];
    [$class, $label] = $badges[$status] ?? ['badge-secondary', ucwords(str_replace('_', ' ', $status))];
@endphp
<span class="badge {{ $class }}">{{ $label }}</span>
