@extends('layouts.skydash')

@section('content')
<div class="page-header"><div><h3 class="font-weight-bold">Verifikasi Dokumen</h3><p>Periksa dokumen operasional dan lihat riwayat hasil analisis.</p></div></div>
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<div class="card mb-4"><div class="card-body">
    <h4 class="card-title">Unggah Dokumen</h4>
    <form method="POST" action="{{ route('verifications.store') }}" enctype="multipart/form-data" class="row g-3 align-items-end">
        @csrf
        <div class="col-md-4"><label class="form-label" for="document_type">Jenis dokumen</label><select class="form-select" id="document_type" name="document_type" required><option value="surat_jalan">Surat Jalan</option><option value="invoice">Invoice</option><option value="bukti_fisik">Bukti Fisik</option></select></div>
        <div class="col-md-5"><label class="form-label" for="document">PDF atau gambar</label><input class="form-control" id="document" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png" required></div>
        <div class="col-md-3"><button class="btn btn-primary w-100" type="submit"><i class="ti-check" aria-hidden="true"></i>Verifikasi Dokumen</button></div>
    </form>
</div></div>
<div class="card"><div class="card-body"><h4 class="card-title">Riwayat Verifikasi</h4><div class="table-responsive"><table class="table table-hover"><thead><tr><th>Waktu</th><th>Nama file</th><th>Jenis</th><th>Status</th><th>Skor</th><th></th></tr></thead><tbody>
@forelse($verifications as $item)<tr><td>{{ $item->created_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }}</td><td>{{ $item->original_filename }}</td><td>{{ ucwords(str_replace('_', ' ', $item->document_type)) }}</td><td>@include('verifications.partials.status', ['status' => $item->status])</td><td>{{ $item->overall_score }}%</td><td><a class="btn btn-sm btn-inverse-primary" href="{{ route('verifications.show', $item) }}">Detail</a></td></tr>@empty<tr><td colspan="6" class="text-center text-muted">Belum ada riwayat verifikasi.</td></tr>@endforelse
</tbody></table></div>{{ $verifications->links() }}</div></div>
@endsection
