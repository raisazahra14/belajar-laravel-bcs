@extends('layouts.skydash')
@section('content')
<header class="page-header"><div><h1>Prediksi Stok</h1><p>Perkiraan kebutuhan berdasarkan riwayat barang keluar.</p></div>
@can('run-stock-prediction')<form method="POST" action="{{ route('stock-predictions.analyze-all') }}">@csrf<x-ui.button type="submit" icon="ti-reload">Analisis Semua Barang</x-ui.button></form>@endcan</header>
<x-ui.alert type="info" class="mb-4" role="note">
    <strong>Petunjuk membaca prediksi:</strong>
    <div class="mt-2">
        <strong>Kebutuhan 30 hari</strong> adalah perkiraan jumlah barang yang akan digunakan berdasarkan transaksi barang keluar.
        <strong>Perkiraan minimum/habis</strong> menunjukkan kapan stok mencapai batas aman dan habis.
    </div>
    <div class="mt-1">
        <strong>Cold Start</strong> memakai estimasi manual, <strong>Rata-rata</strong> dipakai saat histori masih sedikit, dan <strong>Machine Learning</strong> aktif setelah histori mencukupi.
    </div>
    <div class="mt-1">
        Jika input Cold Start belum lengkap, sistem menampilkan data yang perlu dilengkapi tanpa membuat angka prediksi.
    </div>
</x-ui.alert>
@can('run-stock-prediction')
<section id="active-prediction-processes" data-endpoint="{{ route('stock-predictions.processes') }}" data-fingerprint='@json($activeProcesses->map(fn($process) => [$process->id, $process->status, $process->updated_at?->toIso8601String()])->values())'>
@if($activeProcesses->isNotEmpty())
<x-ui.card class="mb-4"><div class="table-heading"><div><h2>Proses Prediksi Aktif</h2><p>Status analisis yang masih menunggu, sedang diproses, atau memerlukan penjadwalan ulang.</p></div></div><div class="table-responsive"><table class="table responsive-data-table"><caption>Daftar proses prediksi stok aktif</caption><thead><tr><th>Barang</th><th>Status</th><th>Dijadwalkan/Diperbarui</th></tr></thead><tbody>
@foreach($activeProcesses as $activeProcess)
@php($activeLabel = ['waiting' => 'Menunggu', 'processing' => 'Diproses', 'failed' => 'Gagal'][$activeProcess->status])
<tr><td data-label="Barang"><strong>{{ $activeProcess->barang->nama_barang }}</strong><small class="d-block text-muted">{{ $activeProcess->barang->kode_barang }}</small></td><td data-label="Status"><x-ui.badge :variant="$activeProcess->status === 'failed' ? 'danger' : 'info'">{{ $activeLabel }}</x-ui.badge>@if($activeProcess->status === 'failed')<small class="d-block text-danger mt-1">{{ $activeProcess->error_message ?: 'Analisis belum berhasil. Silakan jadwalkan ulang.' }}</small>@endif</td><td data-label="Dijadwalkan/Diperbarui">{{ $activeProcess->updated_at?->format('d/m/Y H:i') ?? '-' }}</td></tr>
@endforeach
</tbody></table></div></x-ui.card>
@endif
</section>
@endcan
<x-ui.card class="mb-4"><form method="GET" class="filter-grid prediction-filter"><div><label class="form-label" for="status">Status</label><select class="form-select" name="status" id="status"><option value="">Semua status</option>@foreach(['Aman','Waspada','Perlu Restock','Mendesak','Perlu Ditinjau'] as $status)<option @selected(request('status')===$status)>{{ $status }}</option>@endforeach</select></div><div class="filter-actions"><x-ui.button type="submit">Terapkan</x-ui.button><x-ui.button :href="route('stock-predictions.index')" variant="outline-secondary">Reset</x-ui.button></div></form></x-ui.card>
<x-ui.card><div class="table-responsive"><table class="table prediction-table responsive-data-table"><caption>Daftar perkiraan kebutuhan dan rekomendasi stok barang</caption><thead><tr><th>Barang</th><th>Stok Sekarang</th><th>Kebutuhan 30 Hari</th><th>Perkiraan Habis</th><th>Saran Restock</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
@forelse($predictions as $prediction)
@php($summary = $prediction->input_summary ?? [])
@php($available = (bool)($summary['prediction_available'] ?? $prediction->predicted_30_day_need !== null))
@php($methodLabel = ['cold_start' => 'Cold Start', 'simple_average' => 'Rata-rata', 'machine_learning' => 'Machine Learning'][$prediction->method] ?? ucfirst(str_replace('_', ' ', $prediction->method)))
@php($isDemo = str_starts_with($prediction->barang->kode_barang, 'BRG-900') && str_starts_with($prediction->barang->nama_barang, '[TEST]'))
@php($process = $processes->get($prediction->barang_id))
@php($processLabel = ['waiting' => 'Menunggu', 'processing' => 'Diproses', 'completed' => 'Selesai', 'failed' => 'Gagal'][$process?->status] ?? null)
<tr><td data-label="Barang"><strong>{{ $prediction->barang->nama_barang }}</strong><small class="d-block text-muted">{{ $prediction->barang->kode_barang }}</small><div class="prediction-labels"><x-ui.badge variant="info">{{ $methodLabel }}</x-ui.badge>@if($isDemo)<x-ui.badge variant="secondary">Data Demo</x-ui.badge>@endif</div><details class="prediction-details"><summary>Rincian analisis</summary><dl><dt>Metode</dt><dd>{{ $methodLabel }}</dd><dt>Batas aman</dt><dd>{{ $prediction->safety_stock ?? 'Belum dapat dihitung' }}</dd><dt>Jumlah histori</dt><dd>{{ $summary['out_transaction_count'] ?? 0 }} transaksi OUT pada {{ $summary['out_transaction_days'] ?? 0 }} hari</dd><dt>Rentang data</dt><dd>{{ $summary['history_days'] ?? 0 }} dari minimal {{ $summary['minimum_history_days'] ?? 30 }} hari</dd><dt>Confidence</dt><dd>{{ isset($summary['confidence']) ? number_format($summary['confidence'] * 100, 0).'%' : 'Belum tersedia' }}</dd><dt>Waktu analisis</dt><dd>{{ $prediction->analyzed_at->format('d/m/Y H:i') }}</dd></dl><p><strong>Alasan:</strong> {{ $summary['reason'] ?? 'Prediksi dihitung dari histori transaksi OUT.' }}</p>@if($summary['fallback_used'] ?? false)<p class="text-warning">Engine fallback lokal digunakan.</p>@endif @if($summary['anomaly_reason'] ?? false)<p class="text-danger">{{ $summary['anomaly_reason'] }}</p>@endif</details></td>
<td data-label="Stok Sekarang">{{ $prediction->current_stock }} {{ $prediction->barang->satuan }}</td>
<td data-label="Kebutuhan 30 Hari">{{ $available ? number_format((float)$prediction->predicted_30_day_need,2,',','.') . ' ' . $prediction->barang->satuan : 'Belum dapat dihitung' }}</td>
<td data-label="Perkiraan Habis">{{ $available ? ($prediction->predicted_depletion_date?->format('d/m/Y') ?? 'Belum dapat dipastikan') : 'Belum cukup data' }}</td>
<td data-label="Saran Restock"><strong>{{ $available ? $prediction->recommended_restock.' '.$prediction->barang->satuan : 'Lengkapi data Cold Start' }}</strong></td><td data-label="Status"><span class="prediction-badge status-{{ str($prediction->status)->slug() }}">{{ $prediction->status }}</span></td>
<td data-label="Aksi">@if($processLabel)<x-ui.badge :variant="$process?->status === 'failed' ? 'danger' : ($process?->status === 'completed' ? 'success' : 'info')">{{ $processLabel }}</x-ui.badge>@endif<div class="table-actions">@can('run-stock-prediction')<form method="POST" action="{{ route('stock-predictions.analyze',$prediction->barang) }}">@csrf<x-ui.button type="submit" size="sm" variant="outline-primary" :disabled="in_array($process?->status, ['waiting','processing'], true)">Analisis Ulang</x-ui.button></form>@endcan @can('approve-restock')@if($prediction->recommended_restock>0)<form method="POST" action="{{ route('stock-predictions.approve',$prediction) }}">@csrf<x-ui.button type="submit" size="sm">Setujui</x-ui.button></form>@endif @endcan</div></td></tr>
@empty<tr><td colspan="7"><x-ui.empty-state icon="ti-stats-up" title="Belum ada hasil prediksi" description="Admin atau Manager dapat menjalankan analisis untuk mulai melihat rekomendasi." /></td></tr>@endforelse</tbody></table></div>@if($predictions->hasPages())<div class="pagination-wrap">{{ $predictions->links() }}</div>@endif</x-ui.card>
@endsection
@push('scripts')
@can('run-stock-prediction')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const section = document.getElementById('active-prediction-processes');
    if (!section) return;
    const initial = section.dataset.fingerprint;
    document.addEventListener('app:poll', function () {
        fetch(section.dataset.endpoint, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
            .then(function (response) { if (!response.ok) throw new Error('Status prediksi tidak tersedia'); return response.json(); })
            .then(function (payload) {
                const current = JSON.stringify(payload.processes.map(function (item) { return [item.id, item.status, item.updated_at]; }));
                if (current !== initial) window.location.reload();
            })
            .catch(function () {});
    });
});
</script>
@endcan
@endpush
