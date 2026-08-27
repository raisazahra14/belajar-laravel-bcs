@extends('layouts.skydash')

@section('content')
<div class="page-header"><div><h3 class="font-weight-bold">Hasil Verifikasi</h3><p>{{ $verification->original_filename }}</p></div><a class="btn btn-outline-primary" href="{{ route('verifications.index') }}">Kembali</a></div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<div class="card"><div class="card-body"><div class="row"><div class="col-md-6"><dl class="row mb-0">
    <dt class="col-sm-5">Status</dt><dd class="col-sm-7">@include('verifications.partials.status', ['status' => $verification->status])</dd>
    <dt class="col-sm-5">Jenis dokumen</dt><dd class="col-sm-7">{{ ucwords(str_replace('_', ' ', $verification->document_type)) }}</dd>
    <dt class="col-sm-5">Skor analisis</dt><dd class="col-sm-7">{{ $verification->score }}%</dd>
    <dt class="col-sm-5">Waktu</dt><dd class="col-sm-7">{{ $verification->created_at->format('d/m/Y H:i') }}</dd>
    <dt class="col-sm-5">Pesan</dt><dd class="col-sm-7">{{ $verification->message }}</dd>
</dl></div><div class="col-md-6"><h4 class="card-title">Indikator Analisis</h4><ul class="list-group list-group-flush">
@forelse($verification->analysis_details as $key => $value)<li class="list-group-item d-flex justify-content-between px-0"><span>{{ ucwords(str_replace('_', ' ', $key)) }}</span><strong>{{ is_bool($value) ? ($value ? 'Ya' : 'Tidak') : (is_array($value) ? implode(', ', $value) : ($value ?? '-')) }}</strong></li>@empty<li class="list-group-item px-0 text-muted">Rincian tidak tersedia.</li>@endforelse
</ul></div></div></div></div>
@endsection
