@extends('layouts.skydash')

@section('content')
@php
    $overall = $diagnostics['overall'];
    $env = $diagnostics['environment'];
    $components = $diagnostics['components'];

    $statusBadgeMap = [
        'healthy' => ['variant' => 'success', 'label' => 'Sehat (Optimal)', 'icon' => 'ti-check-box'],
        'warning' => ['variant' => 'warning', 'label' => 'Perhatian (Warning)', 'icon' => 'ti-alert'],
        'critical' => ['variant' => 'danger', 'label' => 'Gangguan Kritis', 'icon' => 'ti-close'],
    ];
    $overallBadge = $statusBadgeMap[$overall['status']] ?? $statusBadgeMap['healthy'];
@endphp

<x-ui.page-header
    title="Kesehatan Sistem & Subsistem AI"
    description="Pemantauan real-time konektivitas database, antrean worker (OCR & Prediksi Stok), environment Python .venv, mesin Tesseract OCR, serta kesiapan kapasitas disk storage."
>
    <div class="page-actions">
        <x-ui.button :href="route('system-health.index', ['deep' => 1])" variant="primary" icon="ti-reload">Periksa Ulang Sekarang</x-ui.button>
        <x-ui.button :href="route('system-health.json')" variant="outline-secondary" icon="ti-shortcode">JSON Endpoint</x-ui.button>
    </div>
</x-ui.page-header>

<div class="stats-grid mb-4">
    <x-ui.card class="stat-card {{ $overall['status'] === 'healthy' ? 'stat-success' : ($overall['status'] === 'warning' ? 'stat-warning' : 'stat-danger') }}">
        <div class="stat-icon"><i class="ti-pulse"></i></div>
        <p>Skor Kesiapan Infrastruktur</p>
        <strong>{{ $overall['score'] }}%</strong>
        <small>{{ $overall['label'] }}</small>
    </x-ui.card>
    <x-ui.card class="stat-card stat-info">
        <div class="stat-icon"><i class="ti-check-box"></i></div>
        <p>Subsistem Optimal</p>
        <strong>{{ $overall['healthy_count'] }} / {{ $overall['total_components'] }}</strong>
        <small>Komponen lulus verifikasi kesiapan</small>
    </x-ui.card>
    <x-ui.card class="stat-card">
        <div class="stat-icon"><i class="ti-server"></i></div>
        <p>Latensi Database</p>
        <strong>{{ ($components['database']['metrics']['latency_ms'] ?? 0) }} ms</strong>
        <small>Driver: {{ $components['database']['metrics']['driver'] ?? 'DB' }} · {{ $components['database']['metrics']['migrations_applied'] ?? 0 }} migrasi</small>
    </x-ui.card>
    <x-ui.card class="stat-card">
        <div class="stat-icon"><i class="ti-settings"></i></div>
        <p>Runtime Platform</p>
        <strong>PHP {{ $env['php_version'] }}</strong>
        <small>Laravel {{ $env['laravel_version'] }} · {{ strtoupper($env['app_env']) }} ({{ $env['os_family'] }})</small>
    </x-ui.card>
</div>

<x-ui.card class="mb-4">
    <div class="table-heading">
        <div>
            <h2>Status Komponen Subsistem Inti</h2>
            <p>Terakhir diperiksa pada {{ $diagnostics['checked_at'] }} WIB</p>
        </div>
        <x-ui.badge :variant="$overallBadge['variant']">{{ $overallBadge['label'] }}</x-ui.badge>
    </div>

    <div class="row">
        @foreach($components as $key => $comp)
            @php
                $badge = $statusBadgeMap[$comp['status']] ?? $statusBadgeMap['healthy'];
            @endphp
            <div class="col-lg-6 mb-3">
                <div class="border rounded p-3 h-100 bg-white">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h3 class="h6 font-weight-bold mb-1">{{ $comp['name'] }}</h3>
                            <div class="text-muted small">{{ $comp['summary'] }}</div>
                        </div>
                        <x-ui.badge :variant="$badge['variant']">{{ $badge['label'] }}</x-ui.badge>
                    </div>

                    <hr class="my-2">

                    <div class="small mb-2">
                        @if($key === 'database')
                            <div class="d-flex justify-content-between py-1 border-bottom">
                                <span class="text-muted">Driver Koneksi</span>
                                <strong>{{ $comp['metrics']['driver'] }}</strong>
                            </div>
                            <div class="d-flex justify-content-between py-1 border-bottom">
                                <span class="text-muted">Latensi Kueri (SELECT 1)</span>
                                <strong>{{ $comp['metrics']['latency_ms'] !== null ? $comp['metrics']['latency_ms'] . ' ms' : 'N/A' }}</strong>
                            </div>
                            <div class="d-flex justify-content-between py-1">
                                <span class="text-muted">Skema Tabel Inti &amp; Migrasi</span>
                                <strong>{{ $comp['metrics']['core_tables_ready'] ? 'Lengkap' : 'Belum Lengkap' }} ({{ $comp['metrics']['migrations_applied'] }} migrasi)</strong>
                            </div>
                        @elseif($key === 'queue')
                            <div class="d-flex justify-content-between py-1 border-bottom">
                                <span class="text-muted">Queue Driver</span>
                                <strong>{{ strtoupper($comp['metrics']['driver']) }}</strong>
                            </div>
                            <div class="d-flex justify-content-between py-1 border-bottom">
                                <span class="text-muted">Antrean default (OCR) / stock-predictions</span>
                                <strong>{{ $comp['metrics']['pending_default'] }} job / {{ $comp['metrics']['pending_stock_predictions'] }} job</strong>
                            </div>
                            <div class="d-flex justify-content-between py-1">
                                <span class="text-muted">Job Gagal (failed_jobs) &amp; Self-Healing OCR</span>
                                <strong>{{ $comp['metrics']['failed_jobs'] }} gagal · Self-Healing Aktif</strong>
                            </div>
                        @elseif($key === 'python')
                            <div class="d-flex justify-content-between py-1 border-bottom">
                                <span class="text-muted">Versi Python</span>
                                <strong>{{ $comp['metrics']['python_version'] }}</strong>
                            </div>
                            <div class="d-flex justify-content-between py-1 border-bottom">
                                <span class="text-muted">Virtualenv (python/.venv)</span>
                                <strong>{{ $comp['metrics']['venv_exists'] ? 'Tersedia' : 'Tidak Ditemukan' }}</strong>
                            </div>
                            <div class="py-1">
                                <span class="text-muted d-block mb-1">Modul Pustaka AI/OCR:</span>
                                <div class="d-flex flex-wrap" style="gap: 0.35rem;">
                                    @foreach($comp['metrics']['packages'] as $pkg => $installed)
                                        <x-ui.badge :variant="$installed ? 'success' : 'warning'">
                                            {{ $pkg }}: {{ $installed ? 'OK' : 'Missing' }}
                                        </x-ui.badge>
                                    @endforeach
                                </div>
                            </div>
                        @elseif($key === 'tesseract')
                            <div class="d-flex justify-content-between py-1 border-bottom">
                                <span class="text-muted">Status Binary OCR</span>
                                <strong>{{ $comp['metrics']['available'] ? 'Terdeteksi' : 'Belum Terdeteksi' }}</strong>
                            </div>
                            <div class="d-flex justify-content-between py-1 border-bottom">
                                <span class="text-muted">Versi Mesin</span>
                                <strong>{{ $comp['metrics']['version'] }}</strong>
                            </div>
                            <div class="d-flex justify-content-between py-1">
                                <span class="text-muted">Model Bahasa (tessdata)</span>
                                <span>
                                    @foreach($comp['metrics']['languages'] as $lang => $ready)
                                        <x-ui.badge :variant="$ready ? 'success' : 'secondary'">{{ strtoupper($lang) }}: {{ $ready ? 'Siap' : 'N/A' }}</x-ui.badge>
                                    @endforeach
                                </span>
                            </div>
                        @elseif($key === 'storage')
                            <div class="d-flex justify-content-between py-1 border-bottom">
                                <span class="text-muted">Izin Tulis Disk (local / public)</span>
                                <strong>{{ $comp['metrics']['local_disk_writable'] && $comp['metrics']['public_disk_writable'] ? 'Writable (OK)' : 'Read-Only / Bermasalah' }}</strong>
                            </div>
                            <div class="d-flex justify-content-between py-1 border-bottom">
                                <span class="text-muted">Public Storage Symlink (public/storage)</span>
                                <strong>{{ $comp['metrics']['storage_symlink_ready'] ? 'Terhubung' : 'Belum Dibuat' }}</strong>
                            </div>
                            <div class="d-flex justify-content-between py-1">
                                <span class="text-muted">Kapasitas Disk Tersedia</span>
                                <strong>
                                    @if($comp['metrics']['free_space_gb'] !== null)
                                        {{ number_format($comp['metrics']['free_space_gb'], 2, ',', '.') }} GB bebas ({{ $comp['metrics']['used_percent'] }}% terpakai)
                                    @else
                                        N/A
                                    @endif
                                </strong>
                            </div>
                        @endif
                    </div>

                    <div class="p-2 rounded bg-light small text-muted mt-2">
                        <i class="ti-info-alt mr-1" aria-hidden="true"></i>{{ $comp['recommendation'] }}
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</x-ui.card>
@endsection
