@extends('layouts.skydash')

@section('content')
<div class="page-header"><div><h3 class="font-weight-bold">Hasil Verifikasi</h3><p>{{ $verification->original_filename }}</p></div><div class="page-actions"><form method="POST" action="{{ route('verifications.reprocess', $verification) }}" onsubmit="return confirm('Proses ulang akan mengganti skor, hasil OCR, dan koreksi metadata lama dengan hasil terbaru. Lanjutkan?')">@csrf<x-ui.button type="submit" variant="outline-primary" icon="ti-reload">Proses Ulang OCR</x-ui.button></form><x-ui.button :href="route('verifications.download', $verification)" variant="outline-success" icon="ti-download">Download Dokumen</x-ui.button><x-ui.button :href="route('verifications.index')" variant="outline-secondary" icon="ti-arrow-left">Kembali</x-ui.button></div></div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
@if($verification->error_message)<div class="alert alert-warning"><strong>Dokumen belum dapat diproses:</strong> {{ $verification->error_message }}</div>@endif
@php($ela = data_get($verification->analysis_details, 'manipulation'))
@if(is_array($ela) && ($ela['method'] ?? null) === 'ela')
<div class="card mb-4"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start"><div><h4 class="card-title mb-1">Indikasi Manipulasi Visual (ELA)</h4><p class="text-muted mb-2">Membandingkan pola kompresi gambar, terutama pada area tanggal dan nominal.</p></div>
    @php($elaLevel = $ela['risk_level'] ?? 'not_available')
    <span class="badge {{ $elaLevel === 'high' ? 'badge-danger' : ($elaLevel === 'medium' ? 'badge-warning' : 'badge-success') }}">Risiko {{ ['low'=>'Rendah','medium'=>'Sedang','high'=>'Tinggi','not_available'=>'Tidak tersedia'][$elaLevel] ?? 'Tidak tersedia' }}</span></div>
    <p class="mb-2"><strong>Skor risiko:</strong> {{ number_format((float)($ela['risk_score'] ?? 0), 1, ',', '.') }}/100</p>
    @foreach(($ela['findings'] ?? []) as $finding)<p class="mb-1">{{ $finding }}</p>@endforeach
    @if(!empty($ela['suspicious_regions']))<div class="table-responsive mt-3"><table class="table table-sm"><thead><tr><th>Halaman</th><th>Area</th><th>Skor</th><th>Teks OCR</th></tr></thead><tbody>@foreach($ela['suspicious_regions'] as $region)<tr><td>{{ $region['page'] }}</td><td>{{ ucfirst($region['target']) }}</td><td>{{ number_format((float)$region['score'], 1, ',', '.') }}</td><td>{{ $region['text'] ?? '-' }}</td></tr>@endforeach</tbody></table></div>@endif
    <small class="d-block text-muted mt-2">ELA merupakan indikator risiko dan tidak membuktikan pemalsuan secara mandiri. Hasil sedang/tinggi harus diperiksa bersama metadata, konsistensi isi, cap, dan tanda tangan.</small>
</div></div>
@endif
<div class="card"><div class="card-body"><div class="row"><div class="col-md-6"><dl class="row mb-0">
    <dt class="col-sm-5">Status</dt><dd class="col-sm-7">@include('verifications.partials.status', ['status' => $verification->status])</dd>
    <dt class="col-sm-5">Jenis dokumen</dt><dd class="col-sm-7">{{ ucwords(str_replace('_', ' ', $verification->document_type)) }}</dd>
    <dt class="col-sm-5">Skor keseluruhan</dt><dd class="col-sm-7">{{ $verification->overall_score }}%</dd>
    <dt class="col-sm-5">Keterbacaan</dt><dd class="col-sm-7">{{ $verification->readability_score }}%</dd>
    <dt class="col-sm-5">Kelengkapan</dt><dd class="col-sm-7">{{ $verification->completeness_score }}%</dd>
    <dt class="col-sm-5">Keaslian (netral)</dt><dd class="col-sm-7">{{ $verification->authenticity_score }}%</dd>
    <dt class="col-sm-5">Waktu</dt><dd class="col-sm-7">{{ $verification->created_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }} WIB</dd>
    <dt class="col-sm-5">Pesan</dt><dd class="col-sm-7">{{ $verification->message }}</dd>
</dl></div><div class="col-md-6"><h4 class="card-title">Indikator Analisis</h4><ul class="list-group list-group-flush">
@forelse($verification->analysis_details as $key => $value)@continue($key === 'manipulation' && is_array($ela) && ($ela['method'] ?? null) === 'ela')<li class="list-group-item px-0"><span class="d-block font-weight-bold mb-1">{{ ucwords(str_replace('_', ' ', $key)) }}</span><small class="text-muted text-break">{{ is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ($value ?? '-') }}</small></li>@empty<li class="list-group-item px-0 text-muted">Rincian tidak tersedia.</li>@endforelse
</ul></div></div></div></div>

<div class="card mt-4"><div class="card-body">
    <h4 class="card-title">Metadata Dokumen</h4>
    <p class="text-muted">Periksa dan koreksi hasil OCR. Perubahan ini tidak mengubah file asli.</p>
    <div class="d-flex flex-wrap mb-3" style="gap: .5rem">
        <span class="badge badge-success">Terbaca otomatis</span>
        <span class="badge badge-warning">Perlu diperiksa</span>
        <span class="badge badge-secondary">Tidak tercantum</span>
        <span class="badge badge-info">Dikoreksi pengguna</span>
    </div>
    <form method="POST" action="{{ route('verifications.metadata.update', $verification) }}">
        @csrf
        @method('PATCH')
        @include(match($verification->document_type) {
            'invoice' => 'verifications.metadata.invoice',
            'bukti_fisik' => 'verifications.metadata.bukti-fisik',
            default => 'verifications.metadata.surat-jalan',
        })
        <x-ui.button type="submit" icon="ti-save">Simpan Koreksi Metadata</x-ui.button>
        @if($verification->ocr_corrected_at)<small class="d-block text-muted mt-2">Terakhir dikoreksi oleh {{ $verification->ocrCorrector?->name ?? 'pengguna' }} pada {{ $verification->ocr_corrected_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }} WIB.</small>@endif
    </form>
    @if($verification->ocr_raw_text)<details class="mt-4"><summary class="font-weight-bold">Lihat teks mentah OCR</summary><pre class="bg-light p-3 mt-2 text-wrap">{{ $verification->ocr_raw_text }}</pre></details>@endif
</div></div>
@endsection
