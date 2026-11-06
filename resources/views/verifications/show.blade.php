@extends('layouts.skydash')

@section('content')
@php
    $ela = data_get($verification->analysis_details, 'manipulation');
    $elaLevel = is_array($ela) ? ($ela['risk_level'] ?? 'not_available') : 'not_available';
    $reviewCount = collect($metadataFieldStates)->whereIn('status', ['review', 'absent'])->count();
    $completedCount = max(0, count($metadataFieldStates) - $reviewCount);
    $completion = count($metadataFieldStates) > 0 ? (int) round($completedCount / count($metadataFieldStates) * 100) : 0;
    $statusDescriptions = [
        'asli' => 'Tidak ditemukan indikasi kuat manipulasi. Tetap cocokkan isi dengan dokumen sumber.',
        'mencurigakan' => 'Ada indikator yang perlu diperiksa oleh pengguna sebelum dokumen digunakan.',
        'palsu' => 'Ditemukan indikator risiko tinggi. Jangan gunakan sebelum pemeriksaan lanjutan.',
    ];

    $docTotalAmount = data_get($mappedMetadata, 'total_amount', $verification->total_amount);
    $docNumber = $verification->document_number ?? data_get($mappedMetadata, 'invoice_number', data_get($mappedMetadata, 'document_number', '—'));
    $docParty = $verification->sender ?? data_get($mappedMetadata, 'vendor', data_get($mappedMetadata, 'sender', '—'));
    $docDate = $verification->document_date ? $verification->document_date->format('d/m/Y') : (data_get($mappedMetadata, 'invoice_date', data_get($mappedMetadata, 'document_date')) ?? '—');
    $isPdf = str_ends_with(strtolower($verification->original_filename), '.pdf') || str_ends_with(strtolower($verification->file_path), '.pdf');
@endphp

<style>
    /* Scoped Professional Console Styles for Document Verification */
    .doc-header-card {
        background-color: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.25rem;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    }
    .kpi-tile {
        background-color: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 1.15rem 1.25rem;
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        transition: transform 0.15s ease, box-shadow 0.15s ease;
        border-top: 4px solid #cbd5e1;
    }
    .kpi-tile:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.07);
    }
    .kpi-tile.tile-emerald { border-top-color: #10b981; }
    .kpi-tile.tile-sky { border-top-color: #0284c7; }
    .kpi-tile.tile-indigo { border-top-color: #6366f1; }
    .kpi-tile.tile-amber { border-top-color: #f59e0b; }
    .kpi-tile.tile-danger { border-top-color: #ef4444; }

    .kpi-icon-box {
        width: 38px;
        height: 38px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.15rem;
        flex-shrink: 0;
    }
    .kpi-icon-emerald { background-color: #ecfdf5; color: #059669; }
    .kpi-icon-sky { background-color: #f0f9ff; color: #0284c7; }
    .kpi-icon-indigo { background-color: #eef2ff; color: #4f46e5; }
    .kpi-icon-amber { background-color: #fffbeb; color: #d97706; }
    .kpi-icon-danger { background-color: #fef2f2; color: #dc2626; }

    .kpi-title {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #64748b;
        margin-bottom: 0.2rem;
    }
    .kpi-value-lg {
        font-size: 1.65rem;
        font-weight: 800;
        color: #1e293b;
        line-height: 1.2;
        margin: 0.35rem 0;
    }
    .kpi-submeta {
        font-size: 0.76rem;
        color: #64748b;
        border-top: 1px solid #f1f5f9;
        padding-top: 0.5rem;
        margin-top: 0.5rem;
    }

    /* Document Inspector Split View */
    .doc-inspector-card {
        background-color: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    }
    .doc-inspector-toolbar {
        background-color: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        padding: 0.75rem 1rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .doc-canvas-viewport {
        background-color: #1e293b;
        min-height: 520px;
        max-height: 640px;
        overflow: auto;
        padding: 1.5rem;
        display: flex;
        align-items: flex-start;
        justify-content: center;
    }
    .doc-canvas-viewport img {
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.45);
        border-radius: 4px;
        transition: transform 0.15s ease-out;
        transform-origin: top center;
        max-width: 100%;
        background-color: #ffffff;
    }

    /* Soft Badges for OCR Field Statuses */
    .ocr-field-heading .badge {
        font-size: 0.7rem !important;
        font-weight: 600 !important;
        padding: 0.22rem 0.55rem !important;
        border-radius: 9999px !important;
    }
    .ocr-field-heading .badge-warning {
        background-color: #fef3c7 !important;
        border: 1px solid #fde68a !important;
        color: #92400e !important;
    }
    .ocr-field-heading .badge-success {
        background-color: #d1fae5 !important;
        border: 1px solid #a7f3d0 !important;
        color: #065f46 !important;
    }
    .ocr-field-heading .badge-secondary {
        background-color: #f1f5f9 !important;
        border: 1px solid #e2e8f0 !important;
        color: #475569 !important;
    }
    .ocr-field-heading .badge-info {
        background-color: #e0f2fe !important;
        border: 1px solid #bae6fd !important;
        color: #0369a1 !important;
    }
    .ocr-field-message {
        color: #64748b !important;
        font-size: 0.74rem !important;
        margin-top: 0.25rem !important;
    }
</style>

{{-- =========================================================================
     EXECUTIVE HEADER CARD: CLEAN, MODERN & HARMONIOUS WITH SKYDASH
     ========================================================================= --}}
<div class="doc-header-card">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <x-ui.breadcrumb :items="[
                ['label' => 'Verifikasi Dokumen', 'url' => route('verifications.index')],
                ['label' => 'Hasil Verifikasi'],
            ]" class="p-0 mb-1 bg-transparent" />
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h1 class="h4 font-weight-bold text-dark mb-0">{{ $verification->original_filename }}</h1>
                <span class="badge bg-secondary text-white">{{ strtoupper(str_replace('_', ' ', $verification->document_type)) }}</span>
                @if($verification->authenticity_status === 'asli')
                    <span class="badge bg-success text-white"><i class="ti-check me-1"></i> Asli ({{ $verification->overall_score }}%)</span>
                @elseif($verification->authenticity_status === 'palsu')
                    <span class="badge bg-danger text-white"><i class="ti-close me-1"></i> Palsu ({{ $verification->overall_score }}%)</span>
                @else
                    <span class="badge bg-warning text-dark"><i class="ti-alert me-1"></i> Perlu Ditinjau ({{ $verification->overall_score }}%)</span>
                @endif
            </div>
            <div class="d-flex align-items-center gap-3 text-muted small mt-1 flex-wrap">
                <span><i class="ti-time me-1"></i> Waktu: {{ $verification->created_at->timezone(config('app.display_timezone'))->format('d M Y, H:i') }} WIB</span>
                <span><i class="ti-id-badge me-1"></i> Ref ID: #{{ $verification->id }}</span>
                <span><i class="ti-file me-1"></i> Format: {{ strtoupper(pathinfo($verification->original_filename, PATHINFO_EXTENSION) ?: 'DOKUMEN') }}</span>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2">
            <x-ui.button :href="route('verifications.download', $verification)" variant="outline-success" icon="ti-download">Unduh Dokumen</x-ui.button>
            <x-ui.button type="button" variant="outline-primary" icon="ti-reload" data-bs-toggle="modal" data-bs-target="#reprocessOcrModal">Proses Ulang</x-ui.button>
            <x-ui.button :href="route('verifications.index')" variant="outline-secondary" icon="ti-arrow-left">Kembali</x-ui.button>
        </div>
    </div>
</div>

@if(session('success'))<x-ui.alert type="success">{{ session('success') }}</x-ui.alert>@endif
@if($errors->any())<x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert>@endif
@if($verification->error_message)<x-ui.alert type="warning"><strong>Dokumen belum dapat diproses:</strong> {{ $verification->error_message }}</x-ui.alert>@endif

{{-- =========================================================================
     TOP DASHBOARD: 4 KPI SUMMARY CARDS IN A BALANCED GRID (BOOTSTRAP 5)
     ========================================================================= --}}
<div class="row g-3 mb-4" aria-label="Ringkasan Verifikasi Dokumen">
    <!-- Tile 1: Status Keaslian & Integritas -->
    <div class="col-xl-3 col-md-6 col-12">
        <div class="kpi-tile {{ $verification->authenticity_status === 'asli' ? 'tile-emerald' : ($verification->authenticity_status === 'palsu' ? 'tile-danger' : 'tile-amber') }}">
            <div>
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <div class="kpi-icon-box {{ $verification->authenticity_status === 'asli' ? 'kpi-icon-emerald' : 'kpi-icon-amber' }}">
                            <i class="ti-shield"></i>
                        </div>
                        <div>
                            <span class="kpi-title">Status Keaslian</span>
                            @include('verifications.partials.authenticity-status', ['authenticityStatus' => $verification->authenticity_status])
                        </div>
                    </div>
                </div>
                <div class="kpi-value-lg">
                    {{ $verification->overall_score }}<span class="fs-6 fw-normal text-muted">%</span>
                </div>
            </div>
            <div class="kpi-submeta">
                <div class="d-flex justify-content-between text-muted mb-1">
                    <span>Baca: <strong class="text-dark">{{ $verification->readability_score }}%</strong></span>
                    <span>Lengkap: <strong class="text-dark">{{ $verification->completeness_score }}%</strong></span>
                    <span>Asli: <strong class="text-dark">{{ $verification->authenticity_score }}%</strong></span>
                </div>
                <p class="mb-0 text-muted line-clamp-2" title="{{ $statusDescriptions[$verification->authenticity_status] ?? $verification->message }}">
                    {{ $statusDescriptions[$verification->authenticity_status] ?? $verification->message }}
                </p>
            </div>
        </div>
    </div>

    <!-- Tile 2: Finansial & Dokumen -->
    <div class="col-xl-3 col-md-6 col-12">
        <div class="kpi-tile tile-sky">
            <div>
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <div class="kpi-icon-box kpi-icon-sky">
                            <i class="ti-receipt"></i>
                        </div>
                        <div>
                            <span class="kpi-title">Finansial & Nilai</span>
                            <span class="badge bg-secondary text-white">{{ strtoupper(str_replace('_', ' ', $verification->document_type)) }}</span>
                        </div>
                    </div>
                </div>
                <div class="kpi-value-lg text-truncate" title="{{ $docTotalAmount ? 'Rp ' . number_format((float) $docTotalAmount, 0, ',', '.') : '' }}">
                    @if($verification->document_type === 'invoice' && $docTotalAmount)
                        Rp {{ number_format((float) $docTotalAmount, 0, ',', '.') }}
                    @elseif($verification->total_items)
                        {{ number_format((float) $verification->total_items, 0, ',', '.') }} <span class="fs-6 fw-normal text-muted">Item</span>
                    @else
                        <span class="text-dark fs-5">Dokumen Terbaca</span>
                    @endif
                </div>
            </div>
            <div class="kpi-submeta">
                <div class="text-truncate mb-1">
                    <span class="text-muted">No:</span> <strong class="text-dark">{{ $docNumber }}</strong>
                </div>
                <div class="text-truncate mb-1">
                    <span class="text-muted">Pihak:</span> <span class="text-dark">{{ $docParty }}</span>
                </div>
                <div class="text-muted">
                    Tanggal: <strong class="text-dark">{{ $docDate }}</strong>
                </div>
            </div>
        </div>
    </div>

    <!-- Tile 3: Ekosistem Pengadaan (Three-Way Matching PO) -->
    <div class="col-xl-3 col-md-6 col-12">
        <div class="kpi-tile tile-indigo">
            <div>
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <div class="kpi-icon-box kpi-icon-indigo">
                            <i class="ti-layers-alt"></i>
                        </div>
                        <div>
                            <span class="kpi-title">Three-Way Matching PO</span>
                            @if(isset($associatedPo) && $associatedPo)
                                <x-ui.badge :variant="$threeWayMatching['status_badge'] ?? 'secondary'">
                                    {{ $threeWayMatching['status_label'] ?? 'Terkoneksi' }}
                                </x-ui.badge>
                            @else
                                <span class="badge bg-light text-muted border">Mandiri</span>
                            @endif
                        </div>
                    </div>
                </div>
                @if(isset($associatedPo) && $associatedPo)
                    <div class="kpi-value-lg text-primary fs-5 text-truncate" title="{{ $associatedPo->nomor_po }}">
                        {{ $associatedPo->nomor_po }}
                    </div>
                @else
                    <div class="kpi-value-lg text-secondary fs-5">
                        Non-PO
                    </div>
                @endif
            </div>
            <div class="kpi-submeta">
                @if(isset($associatedPo) && $associatedPo)
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted text-truncate" style="max-width: 120px;">{{ $associatedPo->supplier?->nama_supplier ?? '—' }}</span>
                        <x-ui.badge :variant="$associatedPo->status->badgeVariant()">{{ $associatedPo->status->label() }}</x-ui.badge>
                    </div>
                    <a href="{{ route('purchase-orders.show', ['purchaseOrder' => $associatedPo, 'doc_id' => $verification->id]) }}" class="btn btn-outline-primary btn-sm w-100 py-1" style="font-size: 0.78rem;">
                        <i class="ti-layers-alt me-1"></i> Buka Matriks Three-Way Matching
                    </a>
                @else
                    <p class="text-muted small mb-0">Dokumen diproses secara mandiri tanpa tautan PO aktif.</p>
                @endif
            </div>
        </div>
    </div>

    <!-- Tile 4: Kesiapan Ekstraksi & Akurasi OCR -->
    <div class="col-xl-3 col-md-6 col-12">
        <div class="kpi-tile tile-amber">
            <div>
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <div class="kpi-icon-box kpi-icon-amber">
                            <i class="ti-pencil-alt"></i>
                        </div>
                        <div>
                            <span class="kpi-title">Kesiapan Ekstraksi</span>
                            <span class="badge {{ $reviewCount > 0 ? 'bg-warning text-dark' : 'bg-success text-white' }}">
                                {{ $reviewCount > 0 ? $reviewCount . ' Perlu Tinjauan' : '100% Siap' }}
                            </span>
                        </div>
                    </div>
                </div>
                <div class="kpi-value-lg text-primary">
                    {{ $completion }}<span class="fs-6 fw-normal text-muted">% Siap</span>
                </div>
            </div>
            <div class="kpi-submeta">
                <div class="progress mb-2" role="progressbar" aria-label="Kelengkapan data OCR" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $completion }}" style="height: 6px; background-color: #f1f5f9;">
                    <div class="progress-bar" style="width: {{ $completion }}%; background-color: #0284c7;"></div>
                </div>
                <a href="#ocr-data" class="btn btn-outline-primary btn-sm w-100 py-1" style="font-size: 0.78rem;">
                    <i class="ti-arrow-down me-1"></i> Tinjau Form Kerja di Bawah
                </a>
            </div>
        </div>
    </div>
</div>

{{-- Discrepancy Alert for Three-Way Matching if any --}}
@if(isset($associatedPo) && $associatedPo && !empty($threeWayMatching['discrepancies']))
    <div class="alert alert-warning border-warning shadow-sm mb-4" role="alert">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <strong class="d-flex align-items-center gap-2">
                    <i class="ti-alert text-warning fs-5" aria-hidden="true"></i>
                    Perhatian Three-Way Matching: Ditemukan {{ count($threeWayMatching['discrepancies']) }} selisih antara dokumen dan PO {{ $associatedPo->nomor_po }}
                </strong>
                <ul class="mb-0 mt-2 ps-3 small">
                    @foreach($threeWayMatching['discrepancies'] as $disc)
                        <li>{{ $disc }}</li>
                    @endforeach
                </ul>
            </div>
            <a href="{{ route('purchase-orders.show', ['purchaseOrder' => $associatedPo, 'doc_id' => $verification->id]) }}" class="btn btn-warning btn-sm fw-bold">
                <i class="ti-layers-alt me-1"></i> Tinjau di Matriks PO
            </a>
        </div>
    </div>
@endif

{{-- =========================================================================
     DUAL-PANE AI WORKSPACE: DOKUMEN ASLI (KIRI) VS FORM EKSTRAKSI (KANAN)
     ========================================================================= --}}
<div class="row g-4 mb-4">
    <!-- PANEL KIRI: INTERACTIVE DOCUMENT PREVIEW (STICKY) -->
    <div class="col-xl-5 col-lg-5 col-12">
        <div class="doc-inspector-card" style="position: sticky; top: 80px;">
            <div class="doc-inspector-toolbar">
                <div class="d-flex align-items-center gap-2">
                    <i class="ti-image text-primary" aria-hidden="true"></i>
                    <strong class="text-dark small">Pratinjau Dokumen Asli</strong>
                    <span class="badge bg-light text-dark border" style="font-size: 0.7rem;">
                        {{ $isPdf ? 'PDF' : 'IMAGE' }}
                    </span>
                </div>
                <div class="d-flex align-items-center gap-1">
                    @if(! $isPdf)
                        <button type="button" class="btn btn-light btn-sm px-2 py-0 border" onclick="adjustDocZoom(-15)" title="Perkecil (-)">
                            <i class="ti-minus" aria-hidden="true"></i>
                        </button>
                        <span id="previewDocZoomLevel" class="text-muted small px-1 font-monospace">100%</span>
                        <button type="button" class="btn btn-light btn-sm px-2 py-0 border" onclick="adjustDocZoom(15)" title="Perbesar (+)">
                            <i class="ti-plus" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="btn btn-light btn-sm px-2 py-0 border" onclick="resetDocZoom()" title="Reset Zoom">
                            <i class="ti-reload" aria-hidden="true"></i>
                        </button>
                    @endif
                    <a href="{{ route('verifications.preview', $verification) }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm px-2 py-0" title="Buka Dokumen di Tab Baru">
                        <i class="ti-new-window" aria-hidden="true"></i>
                    </a>
                </div>
            </div>

            <div class="doc-canvas-viewport">
                @if($isPdf)
                    <iframe src="{{ route('verifications.preview', $verification) }}" title="Pratinjau Dokumen PDF {{ $verification->original_filename }}" style="width: 100%; height: 560px; border: 0; background-color: #ffffff; border-radius: 4px;"></iframe>
                @else
                    <div style="width: 100%; text-align: center;">
                        <img id="previewDocImage" src="{{ route('verifications.preview', $verification) }}" alt="Dokumen {{ $verification->original_filename }}" class="img-fluid">
                    </div>
                @endif
            </div>

            <div class="p-2 px-3 bg-light border-top d-flex justify-content-between align-items-center text-muted" style="font-size: 0.75rem;">
                <span><i class="ti-info-alt me-1 text-primary" aria-hidden="true"></i> Cocokkan angka & stempel langsung dengan formulir di kanan.</span>
                <a href="{{ route('verifications.download', $verification) }}" class="text-primary fw-bold text-decoration-none">
                    <i class="ti-download me-1" aria-hidden="true"></i> Unduh
                </a>
            </div>
        </div>
    </div>

    <!-- PANEL KANAN: WORKING AREA FORM HASIL EKSTRAKSI OCR -->
    <div class="col-xl-7 col-lg-7 col-12">
        <section class="card shadow-sm border" id="ocr-data" aria-labelledby="ocr-data-title" style="border-radius: 12px;">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3 pb-3 border-bottom">
                    <div>
                        <span class="section-eyebrow text-primary">Working Area</span>
                        <h2 id="ocr-data-title" class="h4 font-weight-bold mb-1">Data Dokumen Hasil OCR</h2>
                        <p class="text-muted small mb-0">Kolom di bawah ini telah terisi otomatis oleh engine OCR. Periksa dan koreksi data sebelum disimpan ke database.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <x-ui.button type="button" variant="outline-primary" icon="ti-reload" data-bs-toggle="modal" data-bs-target="#reprocessOcrModal">
                            Proses Ulang OCR
                        </x-ui.button>
                    </div>
                </div>

                <div class="ocr-legend mb-3" aria-label="Keterangan status OCR">
                    <span><i class="ti-check text-success" aria-hidden="true"></i>Terbaca otomatis</span>
                    <span><i class="ti-alert text-warning" aria-hidden="true"></i>Perlu diperiksa</span>
                    <span><i class="ti-help-alt text-secondary" aria-hidden="true"></i>Input manual</span>
                    <span><i class="ti-pencil-alt text-info" aria-hidden="true"></i>Dikoreksi pengguna</span>
                </div>

                <form method="POST" action="{{ route('verifications.metadata.update', $verification) }}">
                    @csrf
                    @method('PATCH')

                    @include(match($verification->document_type) {
                        'invoice' => 'verifications.metadata.invoice',
                        'bukti_fisik' => 'verifications.metadata.bukti-fisik',
                        default => 'verifications.metadata.surat-jalan'
                    })

                    <div class="sticky-form-action d-flex justify-content-between align-items-center flex-wrap gap-2 mt-4" style="background: #ffffff; border-top: 1px solid #e2e8f0; padding: 1rem 0;">
                        <x-ui.button type="submit" icon="ti-save">Simpan Koreksi Data</x-ui.button>
                        @if($verification->ocr_corrected_at)
                            <small class="text-muted">Terakhir dikoreksi oleh {{ $verification->ocrCorrector?->name ?? 'pengguna' }} pada {{ $verification->ocr_corrected_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }} WIB.</small>
                        @endif
                    </div>
                </form>
            </div>
        </section>
    </div>
</div>

{{-- =========================================================================
     COLLAPSIBLE FORENSIC & TECHNICAL DETAILS (MINIMALIST & EXPANDABLE)
     ========================================================================= --}}
@if(is_array($ela) && ($ela['method'] ?? null) === 'ela')
<details class="card technical-details mb-4">
    <summary class="d-flex align-items-center justify-content-between p-3" style="cursor: pointer;">
        <span class="d-inline-flex align-items-center gap-2">
            <i class="ti-image text-primary" aria-hidden="true"></i>
            <strong id="ela-title">Pemeriksaan Manipulasi (ELA)</strong>
            <span class="badge {{ $elaLevel === 'high' ? 'badge-danger' : ($elaLevel === 'medium' ? 'badge-warning' : 'badge-success') }}">
                Risiko {{ ['low'=>'Rendah','medium'=>'Sedang','high'=>'Tinggi','not_available'=>'Tidak tersedia'][$elaLevel] ?? 'Tidak tersedia' }}
            </span>
        </span>
        <span class="text-muted small">
            Skor risiko: <strong>{{ number_format((float)($ela['risk_score'] ?? 0), 1, ',', '.') }}/100</strong>
            <i class="ti-angle-down ms-1" aria-hidden="true"></i>
        </span>
    </summary>
    <div class="card-body border-top">
        <div class="section-heading mb-2">
            <div>
                <span class="section-eyebrow">Keaslian visual</span>
                <p class="text-muted small mb-0">Membandingkan pola kompresi gambar pada area penting.</p>
            </div>
        </div>
        <p><strong>Skor risiko:</strong> {{ number_format((float)($ela['risk_score'] ?? 0), 1, ',', '.') }}/100</p>
        @foreach(($ela['findings'] ?? []) as $finding)
            <p class="mb-1">{{ $finding }}</p>
        @endforeach
        @if(!empty($ela['suspicious_regions']))
            <div class="table-responsive mt-3">
                <table class="table table-sm accessible-table">
                    <caption>Area dokumen yang memerlukan pemeriksaan visual</caption>
                    <thead>
                        <tr>
                            <th>Halaman</th>
                            <th>Area</th>
                            <th>Skor</th>
                            <th>Teks OCR</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($ela['suspicious_regions'] as $region)
                            <tr>
                                <td>{{ $region['page'] }}</td>
                                <td>{{ ucfirst($region['target']) }}</td>
                                <td>{{ number_format((float)$region['score'], 1, ',', '.') }}</td>
                                <td>{{ $region['text'] ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <small class="d-block text-muted mt-2">ELA adalah indikator risiko, bukan bukti pemalsuan. Periksa bersama isi, cap, tanda tangan, dan dokumen pembanding.</small>
    </div>
</details>
@endif

<details class="card technical-details mb-4 audit-trail-card">
    <summary class="d-flex align-items-center justify-content-between p-3" style="cursor: pointer;">
        <span class="d-inline-flex align-items-center gap-2">
            <i class="ti-time text-primary" aria-hidden="true"></i>
            <span>Riwayat Aktivitas & Audit Trail</span>
            <x-ui.badge variant="secondary">{{ $verification->audits->count() }} aktivitas</x-ui.badge>
        </span>
        <span class="text-muted small fw-normal">
            <i class="ti-angle-down me-1" aria-hidden="true"></i> Klik untuk melihat rincian audit
        </span>
    </summary>
    <div class="card-body border-top">
        <div class="section-heading mb-3">
            <div>
                <span class="section-eyebrow">Audit trail</span>
                <h2 id="audit-title" class="fs-6 fw-bold">Riwayat Aktivitas Dokumen</h2>
                <p class="text-muted small mb-0">Catatan perubahan tersimpan secara berurutan dan tidak dapat diedit dari halaman ini.</p>
            </div>
        </div>
        @php
            $eventLabels = [
                'document_uploaded' => 'mengunggah dokumen', 'ocr_queued' => 'memasukkan OCR ke antrean',
                'ocr_started' => 'memulai analisis OCR', 'ocr_completed' => 'menyelesaikan analisis OCR',
                'ocr_failed' => 'mencatat kegagalan analisis', 'ocr_retried' => 'mencoba kembali analisis',
                'ocr_reprocess_requested' => 'meminta proses ulang OCR', 'manual_correction_saved' => 'menyimpan koreksi manual',
                'manual_corrections_preserved' => 'mempertahankan koreksi pengguna', 'manual_corrections_replaced' => 'mengganti koreksi dengan hasil OCR baru',
                'final_status_changed' => 'memperbarui keputusan akhir dokumen', 'ocr_reprocess_failed' => 'mencatat proses ulang yang belum berhasil',
            ];
            $fieldLabels = ['process_status' => 'Status Proses OCR', 'authenticity_status' => 'Hasil Keaslian', 'document_number' => 'Nomor Dokumen', 'document_date' => 'Tanggal Dokumen', 'purchase_order_number' => 'Nomor PO', 'sender' => 'Pengirim/Vendor', 'recipient' => 'Penerima/Pelanggan', 'vehicle_number' => 'Nomor Kendaraan', 'total_items' => 'Jumlah Barang', 'total_amount' => 'Total Tagihan', 'subtotal' => 'Subtotal', 'dpp' => 'DPP', 'tax' => 'PPN/Pajak', 'currency' => 'Mata Uang'];
            $formatAuditValue = function ($value, $field) {
                if ($value === null || $value === '') return 'kosong';
                if (in_array($field, ['total_amount', 'subtotal', 'dpp', 'tax', 'discount', 'delivery_fee', 'down_payment'], true) && is_numeric($value)) return number_format((float) $value, 0, ',', '.');
                return is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            };
        @endphp
        <div class="audit-timeline">
            @forelse($verification->audits as $audit)
                <article class="audit-entry"><div class="audit-marker" aria-hidden="true"><i class="{{ $audit->source === 'user' ? 'ti-user' : ($audit->event === 'ocr_failed' ? 'ti-alert' : 'ti-settings') }}"></i></div><div class="audit-content">
                    <div class="audit-heading"><strong>{{ $audit->user?->name ?? 'Sistem' }} {{ $eventLabels[$audit->event] ?? str_replace('_', ' ', $audit->event) }}.</strong><time datetime="{{ $audit->created_at->toIso8601String() }}">{{ $audit->created_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }} WIB</time></div>
                    @foreach($audit->changed_fields ?? [] as $field)<p>{{ $audit->user?->name ?? 'Sistem' }} mengubah {{ $fieldLabels[$field] ?? ucwords(str_replace('_', ' ', $field)) }} dari <strong>{{ $formatAuditValue(data_get($audit->before_values, $field), $field) }}</strong> menjadi <strong>{{ $formatAuditValue(data_get($audit->after_values, $field), $field) }}</strong>.</p>@endforeach
                    @if($audit->technical_metadata || $audit->confidence || $audit->extraction_status)<details class="audit-technical"><summary>Detail teknis</summary><dl>@if($audit->technical_metadata)<dt>Metadata proses</dt><dd>{{ json_encode($audit->technical_metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</dd>@endif @if($audit->confidence)<dt>Confidence OCR</dt><dd>{{ json_encode($audit->confidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</dd>@endif @if($audit->extraction_status)<dt>Status ekstraksi</dt><dd>{{ json_encode($audit->extraction_status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</dd>@endif</dl></details>@endif
                </div></article>
            @empty<x-ui.empty-state class="compact" icon="ti-time" title="Belum ada aktivitas tercatat" description="Dokumen lama tetap dapat digunakan. Aktivitas baru akan muncul setelah dokumen diproses atau dikoreksi." />@endforelse
        </div>
    </div>
</details>

<details class="card technical-details mb-4">
    <summary class="d-flex align-items-center justify-content-between p-3" style="cursor: pointer;">
        <span class="d-inline-flex align-items-center gap-2">
            <i class="ti-settings text-primary" aria-hidden="true"></i>
            <span>Rincian Teknis & Raw Data OCR</span>
        </span>
        <span class="text-muted small fw-normal">
            <i class="ti-angle-down me-1" aria-hidden="true"></i> Klik untuk melihat rincian
        </span>
    </summary>
    <div class="card-body border-top">
        <dl class="technical-metrics">
            <div><dt>Jenis dokumen</dt><dd>{{ ucwords(str_replace('_', ' ', $verification->document_type)) }}</dd></div>
            <div><dt>Keterbacaan OCR</dt><dd>{{ $verification->readability_score }}%</dd></div>
            <div><dt>Kelengkapan OCR</dt><dd>{{ $verification->completeness_score }}%</dd></div>
            <div><dt>Skor indikasi keaslian</dt><dd>{{ $verification->authenticity_score }}%</dd></div>
            <div><dt>Waktu verifikasi</dt><dd>{{ $verification->created_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }} WIB</dd></div>
            <div><dt>Pesan sistem</dt><dd>{{ $verification->message }}</dd></div>
        </dl>
        <details class="mt-3"><summary>Data analisis JSON</summary><pre class="technical-json">{{ json_encode($verification->analysis_details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></details>
        @if($verification->ocr_raw_text)<details class="mt-3"><summary>Teks mentah OCR</summary><pre class="technical-json">{{ $verification->ocr_raw_text }}</pre></details>@endif
    </div>
</details>

<div class="modal fade" id="reprocessOcrModal" tabindex="-1" aria-labelledby="reprocessOcrModalLabel" aria-describedby="reprocessOcrModalDescription" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title" id="reprocessOcrModalLabel">Proses Ulang OCR</h2>
                    <p class="modal-subtitle" id="reprocessOcrModalDescription">Pilih cara menangani koreksi manual yang sudah tersimpan.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="reprocess-choice recommended">
                    <i class="ti-shield" aria-hidden="true"></i>
                    <div>
                        <strong>Pertahankan koreksi pengguna</strong>
                        <span>OCR diperbarui, tetapi nilai yang sudah Anda koreksi tetap aman.</span>
                    </div>
                </div>
                @if($verification->ocr_corrected_at)
                    <div class="reprocess-choice warning">
                        <i class="ti-alert" aria-hidden="true"></i>
                        <div>
                            <strong>Ganti dengan hasil OCR baru</strong>
                            <span>Koreksi manual akan diganti. Gunakan hanya jika Anda yakin ingin memulai ulang.</span>
                        </div>
                    </div>
                @endif
            </div>
            <div class="modal-footer reprocess-actions">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                <form method="POST" action="{{ route('verifications.reprocess', $verification) }}">
                    @csrf
                    <x-ui.button type="submit" icon="ti-shield">Proses & Pertahankan Koreksi</x-ui.button>
                </form>
                @if($verification->ocr_corrected_at)
                    <form method="POST" action="{{ route('verifications.reprocess', $verification) }}">
                        @csrf
                        <input type="hidden" name="replace_manual" value="1">
                        <x-ui.button type="submit" variant="outline-danger" icon="ti-reload">Ganti dengan OCR Baru</x-ui.button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const docImg = document.getElementById('previewDocImage');
        const zoomLevelEl = document.getElementById('previewDocZoomLevel');
        if (!docImg || !zoomLevelEl) return;

        let currentZoom = 100;
        window.adjustDocZoom = function (delta) {
            currentZoom = Math.max(50, Math.min(250, currentZoom + delta));
            docImg.style.transform = 'scale(' + (currentZoom / 100) + ')';
            zoomLevelEl.textContent = currentZoom + '%';
        };

        window.resetDocZoom = function () {
            currentZoom = 100;
            docImg.style.transform = 'scale(1)';
            zoomLevelEl.textContent = '100%';
        };
    });
</script>
@endsection
