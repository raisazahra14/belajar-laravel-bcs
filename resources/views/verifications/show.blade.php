@extends('layouts.skydash')

@section('content')
@php
    $ela = data_get($verification->analysis_details, 'manipulation');
    $reviewCount = collect($metadataFieldStates)->whereIn('status', ['review', 'absent'])->count();
    $completedCount = max(0, count($metadataFieldStates) - $reviewCount);
    $completion = count($metadataFieldStates) > 0 ? (int) round($completedCount / count($metadataFieldStates) * 100) : 0;
    $statusDescriptions = [
        'ASLI' => 'Tidak ditemukan indikasi kuat manipulasi. Tetap cocokkan isi dengan dokumen sumber.',
        'MENCURIGAKAN' => 'Ada indikator yang perlu diperiksa oleh pengguna sebelum dokumen digunakan.',
        'PALSU' => 'Ditemukan indikator risiko tinggi. Jangan gunakan sebelum pemeriksaan lanjutan.',
    ];
@endphp

<x-ui.breadcrumb :items="[
    ['label' => 'Verifikasi Dokumen', 'url' => route('verifications.index')],
    ['label' => 'Hasil Verifikasi'],
]" />
<div class="page-header">
    <div><h1 class="font-weight-bold">Hasil Verifikasi Dokumen</h1><p>{{ $verification->original_filename }}</p></div>
    <div class="page-actions">
        <x-ui.button :href="route('verifications.download', $verification)" variant="outline-success" icon="ti-download">Unduh Dokumen</x-ui.button>
        <x-ui.button :href="route('verifications.index')" variant="outline-secondary" icon="ti-arrow-left">Kembali</x-ui.button>
    </div>
</div>

@if(session('success'))<x-ui.alert type="success">{{ session('success') }}</x-ui.alert>@endif
@if($errors->any())<x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert>@endif
@if($verification->error_message)<x-ui.alert type="warning"><strong>Dokumen belum dapat diproses:</strong> {{ $verification->error_message }}</x-ui.alert>@endif

<section class="verification-summary card mb-4" aria-labelledby="verification-summary-title">
    <div class="card-body">
        <div class="verification-summary-main">
            <div class="verification-status-icon" aria-hidden="true"><i class="{{ $verification->status === 'ASLI' ? 'ti-check' : ($verification->status === 'PALSU' ? 'ti-close' : 'ti-alert') }}"></i></div>
            <div>
                <span class="section-eyebrow">Ringkasan</span>
                <h2 id="verification-summary-title">@include('verifications.partials.status', ['status' => $verification->status])</h2>
                <p>{{ $statusDescriptions[$verification->status] ?? $verification->message }}</p>
            </div>
        </div>
        <div class="verification-metrics">
            <div><span>Skor verifikasi</span><strong>{{ $verification->overall_score }}%</strong></div>
            <div><span>Kolom perlu ditinjau</span><strong>{{ $reviewCount }}</strong></div>
            <div><span>Data OCR siap</span><strong>{{ $completion }}%</strong></div>
        </div>
        <div class="progress verification-progress" role="progressbar" aria-label="Kelengkapan data OCR" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $completion }}"><div class="progress-bar" style="width: {{ $completion }}%"></div></div>
    </div>
</section>

<section class="card mb-4" aria-labelledby="next-action-title"><div class="card-body action-guidance">
    <div><span class="section-eyebrow">Langkah berikutnya</span><h2 id="next-action-title">Periksa data yang ditandai</h2><p>{{ $reviewCount > 0 ? $reviewCount.' kolom masih memerlukan pemeriksaan atau input manual.' : 'Semua kolom yang tersedia sudah terisi. Pastikan nilainya sesuai dokumen sumber.' }}</p></div>
    <div class="page-actions"><x-ui.button href="#ocr-data" icon="ti-pencil-alt">Periksa Data OCR</x-ui.button><x-ui.button type="button" variant="outline-primary" icon="ti-reload" data-bs-toggle="modal" data-bs-target="#reprocessOcrModal">Proses Ulang OCR</x-ui.button></div>
</div></section>

<section class="card mb-4" id="ocr-data" aria-labelledby="ocr-data-title"><div class="card-body">
    <div class="section-heading"><div><span class="section-eyebrow">Isi dokumen</span><h2 id="ocr-data-title">Data Dokumen Hasil OCR</h2><p>Periksa lalu koreksi bila perlu. Perubahan tidak mengubah file asli.</p></div></div>
    <div class="ocr-legend" aria-label="Keterangan status OCR"><span><i class="ti-check text-success"></i>Terbaca otomatis</span><span><i class="ti-alert text-warning"></i>Perlu diperiksa</span><span><i class="ti-help-alt text-secondary"></i>Input manual</span><span><i class="ti-pencil-alt text-info"></i>Dikoreksi pengguna</span></div>
    <form method="POST" action="{{ route('verifications.metadata.update', $verification) }}">@csrf @method('PATCH')
        @include(match($verification->document_type) {'invoice' => 'verifications.metadata.invoice', 'bukti_fisik' => 'verifications.metadata.bukti-fisik', default => 'verifications.metadata.surat-jalan'})
        <div class="sticky-form-action"><x-ui.button type="submit" icon="ti-save">Simpan Koreksi Data</x-ui.button></div>
        @if($verification->ocr_corrected_at)<small class="d-block text-muted mt-2">Terakhir dikoreksi oleh {{ $verification->ocrCorrector?->name ?? 'pengguna' }} pada {{ $verification->ocr_corrected_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }} WIB.</small>@endif
    </form>
</div></section>

@if(is_array($ela) && ($ela['method'] ?? null) === 'ela')
<section class="card mb-4" aria-labelledby="ela-title"><div class="card-body">
    @php
        $elaLevel = $ela['risk_level'] ?? 'not_available';
    @endphp
    <div class="section-heading"><div><span class="section-eyebrow">Keaslian visual</span><h2 id="ela-title">Pemeriksaan Manipulasi (ELA)</h2><p>Membandingkan pola kompresi gambar pada area penting.</p></div><span class="badge {{ $elaLevel === 'high' ? 'badge-danger' : ($elaLevel === 'medium' ? 'badge-warning' : 'badge-success') }}">Risiko {{ ['low'=>'Rendah','medium'=>'Sedang','high'=>'Tinggi','not_available'=>'Tidak tersedia'][$elaLevel] ?? 'Tidak tersedia' }}</span></div>
    <p><strong>Skor risiko:</strong> {{ number_format((float)($ela['risk_score'] ?? 0), 1, ',', '.') }}/100</p>
    @foreach(($ela['findings'] ?? []) as $finding)<p class="mb-1">{{ $finding }}</p>@endforeach
    @if(!empty($ela['suspicious_regions']))<div class="table-responsive mt-3"><table class="table table-sm accessible-table"><caption>Area dokumen yang memerlukan pemeriksaan visual</caption><thead><tr><th>Halaman</th><th>Area</th><th>Skor</th><th>Teks OCR</th></tr></thead><tbody>@foreach($ela['suspicious_regions'] as $region)<tr><td>{{ $region['page'] }}</td><td>{{ ucfirst($region['target']) }}</td><td>{{ number_format((float)$region['score'], 1, ',', '.') }}</td><td>{{ $region['text'] ?? '-' }}</td></tr>@endforeach</tbody></table></div>@endif
    <small class="d-block text-muted mt-2">ELA adalah indikator risiko, bukan bukti pemalsuan. Periksa bersama isi, cap, tanda tangan, dan dokumen pembanding.</small>
</div></section>
@endif

<section class="card mb-4" aria-labelledby="audit-title"><div class="card-body">
    <div class="section-heading"><div><span class="section-eyebrow">Audit trail</span><h2 id="audit-title">Riwayat Aktivitas Dokumen</h2><p>Catatan perubahan tersimpan secara berurutan dan tidak dapat diedit dari halaman ini.</p></div></div>
    @php
        $eventLabels = [
            'document_uploaded' => 'mengunggah dokumen', 'ocr_queued' => 'memasukkan OCR ke antrean',
            'ocr_started' => 'memulai analisis OCR', 'ocr_completed' => 'menyelesaikan analisis OCR',
            'ocr_failed' => 'mencatat kegagalan analisis', 'ocr_retried' => 'mencoba kembali analisis',
            'ocr_reprocess_requested' => 'meminta proses ulang OCR', 'manual_correction_saved' => 'menyimpan koreksi manual',
            'manual_corrections_preserved' => 'mempertahankan koreksi pengguna', 'manual_corrections_replaced' => 'mengganti koreksi dengan hasil OCR baru',
            'final_status_changed' => 'memperbarui keputusan akhir dokumen', 'ocr_reprocess_failed' => 'mencatat proses ulang yang belum berhasil',
        ];
        $fieldLabels = ['status' => 'Status', 'document_number' => 'Nomor Dokumen', 'document_date' => 'Tanggal Dokumen', 'purchase_order_number' => 'Nomor PO', 'sender' => 'Pengirim/Vendor', 'recipient' => 'Penerima/Pelanggan', 'vehicle_number' => 'Nomor Kendaraan', 'total_items' => 'Jumlah Barang', 'total_amount' => 'Total Tagihan', 'subtotal' => 'Subtotal', 'dpp' => 'DPP', 'tax' => 'PPN/Pajak', 'currency' => 'Mata Uang'];
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
        @empty<div class="empty-state compact"><i class="ti-time"></i><h3>Belum ada aktivitas tercatat</h3><p>Dokumen lama tetap dapat digunakan. Aktivitas baru akan muncul setelah dokumen diproses atau dikoreksi.</p></div>@endforelse
    </div>
</div></section>

<details class="card technical-details mb-4"><summary>Rincian teknis verifikasi</summary><div class="card-body">
    <dl class="technical-metrics"><div><dt>Jenis dokumen</dt><dd>{{ ucwords(str_replace('_', ' ', $verification->document_type)) }}</dd></div><div><dt>Keterbacaan OCR</dt><dd>{{ $verification->readability_score }}%</dd></div><div><dt>Kelengkapan OCR</dt><dd>{{ $verification->completeness_score }}%</dd></div><div><dt>Skor indikasi keaslian</dt><dd>{{ $verification->authenticity_score }}%</dd></div><div><dt>Waktu verifikasi</dt><dd>{{ $verification->created_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }} WIB</dd></div><div><dt>Pesan sistem</dt><dd>{{ $verification->message }}</dd></div></dl>
    <details class="mt-3"><summary>Data analisis JSON</summary><pre class="technical-json">{{ json_encode($verification->analysis_details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></details>
    @if($verification->ocr_raw_text)<details class="mt-3"><summary>Teks mentah OCR</summary><pre class="technical-json">{{ $verification->ocr_raw_text }}</pre></details>@endif
</div></details>

<div class="modal fade" id="reprocessOcrModal" tabindex="-1" aria-labelledby="reprocessOcrModalLabel" aria-describedby="reprocessOcrModalDescription" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><div><h2 class="modal-title" id="reprocessOcrModalLabel">Proses Ulang OCR</h2><p class="modal-subtitle" id="reprocessOcrModalDescription">Pilih cara menangani koreksi manual yang sudah tersimpan.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
    <div class="modal-body"><div class="reprocess-choice recommended"><i class="ti-shield"></i><div><strong>Pertahankan koreksi pengguna</strong><span>OCR diperbarui, tetapi nilai yang sudah Anda koreksi tetap aman.</span></div></div>@if($verification->ocr_corrected_at)<div class="reprocess-choice warning"><i class="ti-alert"></i><div><strong>Ganti dengan hasil OCR baru</strong><span>Koreksi manual akan diganti. Gunakan hanya jika Anda yakin ingin memulai ulang.</span></div></div>@endif</div>
    <div class="modal-footer reprocess-actions"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button><form method="POST" action="{{ route('verifications.reprocess', $verification) }}">@csrf<x-ui.button type="submit" icon="ti-shield">Proses & Pertahankan Koreksi</x-ui.button></form>@if($verification->ocr_corrected_at)<form method="POST" action="{{ route('verifications.reprocess', $verification) }}">@csrf<input type="hidden" name="replace_manual" value="1"><x-ui.button type="submit" variant="outline-danger" icon="ti-reload">Ganti dengan OCR Baru</x-ui.button></form>@endif</div>
</div></div></div>
@endsection
