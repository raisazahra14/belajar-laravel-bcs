@extends('layouts.skydash')

@section('content')
<x-ui.page-header title="Verifikasi Dokumen" description="Periksa dokumen operasional dan lihat riwayat hasil analisis." />
@if($errors->any())<x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert>@endif
<x-ui.card class="mb-4">
    <h4 class="card-title">Unggah Dokumen</h4>
    <form method="POST" action="{{ route('verifications.store') }}" enctype="multipart/form-data" class="row g-3 align-items-end">
        @csrf
        <div class="col-md-4"><label class="form-label" for="document_type">Jenis dokumen</label><select class="form-select" id="document_type" name="document_type" required><option value="surat_jalan">Surat Jalan</option><option value="invoice">Invoice</option><option value="bukti_fisik">Bukti Fisik</option></select></div>
        <div class="col-md-5"><label class="form-label" for="document">PDF atau gambar</label><input class="form-control" id="document" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png" aria-describedby="documentHelp" required><div class="form-text" id="documentHelp">PDF, JPG, JPEG, atau PNG, maksimal 10 MB.</div></div>
        <div class="col-md-3"><x-ui.button class="w-100" type="submit" icon="ti-check">Verifikasi Dokumen</x-ui.button></div>
    </form>
</x-ui.card>
<x-ui.card><h4 class="card-title">Riwayat Verifikasi</h4><div class="table-responsive"><table class="table table-hover"><thead><tr><th>Waktu</th><th>Nama file</th><th>Jenis</th><th>Proses OCR</th><th>Hasil Keaslian</th><th>Skor</th><th></th></tr></thead><tbody>
@forelse($verifications as $item)@php($isCompleted = $item->process_status === 'selesai')<tr><td>{{ $item->created_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }}</td><td>{{ $item->original_filename }}</td><td>{{ ucwords(str_replace('_', ' ', $item->document_type)) }}</td><td>@include('verifications.partials.process-status', ['processStatus' => $item->process_status])</td><td>@include('verifications.partials.authenticity-status', ['authenticityStatus' => $item->authenticity_status])</td><td>{{ $isCompleted ? $item->overall_score.'%' : '—' }}</td><td><a class="btn btn-sm btn-inverse-primary" href="{{ $isCompleted ? route('verifications.show', $item) : route('verifications.processing', $item) }}">{{ $isCompleted ? 'Detail' : 'Lihat Status' }}</a></td></tr>@empty<tr><td colspan="7" class="text-center text-muted">Belum ada riwayat verifikasi.</td></tr>@endforelse
</tbody></table></div>{{ $verifications->links() }}</x-ui.card>
@endsection
