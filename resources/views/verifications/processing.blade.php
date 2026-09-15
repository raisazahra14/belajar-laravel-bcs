@extends('layouts.skydash')

@section('content')
@php
    $labels = [
        'menunggu' => 'Dokumen menunggu antrean',
        'diproses' => 'Status akan diperbarui otomatis.',
        'selesai' => 'Analisis dokumen selesai',
        'gagal' => 'Dokumen belum berhasil dianalisis',
    ];
@endphp
<x-ui.breadcrumb :items="[['label' => 'Verifikasi Dokumen', 'url' => route('verifications.index')], ['label' => 'Status Proses']]" />
<header class="page-header"><div><h1>Status Verifikasi Dokumen</h1><p>{{ $verification->original_filename }}</p></div><x-ui.button :href="route('verifications.index')" variant="outline-secondary" icon="ti-arrow-left">Kembali ke Riwayat</x-ui.button></header>
@if(session('success'))<x-ui.alert type="success">{{ session('success') }}</x-ui.alert>@endif

<section class="ocr-processing-card card" data-process-status="{{ $verification->process_status }}" id="ocr-process" data-status-url="{{ route('verifications.status', $verification) }}">
    <div class="card-body">
        <div class="document-scanner" aria-hidden="true">
            <i class="ti-file scanner-document-icon"></i>
            <span class="scanner-line"></span>
            <span class="processing-spinner"></span>
            <i class="ti-check processing-result-icon result-success"></i>
            <i class="ti-alert processing-result-icon result-failed"></i>
        </div>
        <div class="ocr-status-copy" aria-live="polite" aria-atomic="true">
            <span class="section-eyebrow">Proses OCR</span>
            <h2 id="ocr-status-label">{{ $labels[$verification->process_status] ?? 'Status proses tidak dikenal' }}</h2>
            <p id="ocr-status-message">{{ $verification->process_status === 'gagal' ? $verification->error_message : 'Status akan diperbarui otomatis.' }}</p>
        </div>
        <div class="ocr-indeterminate" role="progressbar" aria-label="Proses analisis sedang berlangsung"><span></span></div>
        <p class="ocr-wait-note">Anda boleh meninggalkan halaman ini. Hasil tetap tersimpan di riwayat verifikasi.</p>
        <div class="ocr-process-actions">
            <a class="btn btn-primary" id="ocr-result-link" href="{{ route('verifications.show', $verification) }}" @if($verification->process_status !== 'selesai') hidden @endif><i class="ti-eye" aria-hidden="true"></i><span>Lihat Hasil</span></a>
            <form id="ocr-retry-form" method="POST" action="{{ route('verifications.retry', $verification) }}" @if($verification->process_status !== 'gagal') hidden @endif>@csrf<button class="btn btn-outline-primary" type="submit"><i class="ti-reload" aria-hidden="true"></i><span>Coba Lagi</span></button></form>
        </div>
        <noscript><p class="alert alert-info mt-3">Pembaruan otomatis tidak tersedia. Muat ulang halaman untuk melihat status terbaru.</p></noscript>
    </div>
</section>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const processCard = document.getElementById('ocr-process');
    if (!processCard || ['selesai', 'gagal'].includes(processCard.dataset.processStatus)) return;
    const label = document.getElementById('ocr-status-label');
    const message = document.getElementById('ocr-status-message');
    const resultLink = document.getElementById('ocr-result-link');
    const retryForm = document.getElementById('ocr-retry-form');
    let timer;

    const update = function (payload) {
        processCard.dataset.processStatus = payload.process_status;
        label.textContent = payload.label;
        message.textContent = payload.message || 'Status proses telah diperbarui.';
        resultLink.hidden = payload.process_status !== 'selesai';
        retryForm.hidden = payload.process_status !== 'gagal';
        if (payload.result_url) resultLink.href = payload.result_url;
        if (payload.retry_url) retryForm.action = payload.retry_url;
        if (['selesai', 'gagal'].includes(payload.process_status)) window.clearInterval(timer);
    };

    const poll = function () {
        fetch(processCard.dataset.statusUrl, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
            .then(function (response) { if (!response.ok) throw new Error('Status tidak tersedia'); return response.json(); })
            .then(update)
            .catch(function () { window.clearInterval(timer); });
    };
    timer = window.setInterval(poll, 2000);
    poll();
});
</script>
@endpush
