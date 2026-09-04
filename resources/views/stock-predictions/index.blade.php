@extends('layouts.skydash')
@section('content')
<header class="page-header prediction-page-header">
    <div><span class="section-eyebrow">Perencanaan persediaan</span><h1>Prediksi Stok</h1><p>Ringkasan risiko dan rekomendasi persediaan terbaru.</p></div>
    @can('run-stock-prediction')<form method="POST" action="{{ route('stock-predictions.analyze-all') }}">@csrf<x-ui.button type="submit" icon="ti-reload">Analisis Semua Barang</x-ui.button></form>@endcan
</header>
<x-ui.alert type="info" class="prediction-info-alert" role="note">Prediksi membantu perencanaan dan tidak mengubah stok. Confidence menunjukkan kualitas data, bukan jaminan kejadian.</x-ui.alert>

@can('run-stock-prediction')
<section id="active-prediction-processes" class="prediction-process-panel" data-endpoint="{{ route('stock-predictions.processes') }}">
    <div class="prediction-process-summary">
        <div><h2>Proses Prediksi Aktif</h2><p id="prediction-process-counters" aria-live="polite">@if($activeProcesses->isEmpty()) Tidak ada proses aktif @else <strong>{{ $activeProcessCounts->get('waiting', 0) }}</strong> Menunggu <span>·</span> <strong>{{ $activeProcessCounts->get('processing', 0) }}</strong> Diproses <span>·</span> <strong>{{ $activeProcessCounts->get('failed', 0) }}</strong> Gagal @endif</p></div>
        <button id="prediction-process-toggle" class="btn btn-sm btn-outline-primary" type="button" aria-expanded="false" aria-controls="prediction-process-list" @if($activeProcesses->isEmpty()) hidden @endif><span class="show-label">Lihat proses</span><span class="hide-label">Sembunyikan</span></button>
    </div>
    <div id="prediction-process-list" class="prediction-process-list" hidden>
        <div class="table-responsive"><table class="table prediction-process-table"><caption>Lima proses aktif dengan prioritas tertinggi</caption><thead><tr><th>Barang</th><th>Status proses</th><th>Diperbarui</th></tr></thead><tbody id="prediction-process-rows">
        @foreach($visibleActiveProcesses as $activeProcess)
        @php($activeLabel = ['waiting' => 'Menunggu', 'processing' => 'Diproses', 'failed' => 'Gagal'][$activeProcess->status])
        <tr><td><strong>{{ $activeProcess->barang->nama_barang }}</strong><small>{{ $activeProcess->barang->kode_barang }}</small></td><td><x-ui.badge :variant="$activeProcess->status === 'failed' ? 'danger' : 'info'">{{ $activeLabel }}</x-ui.badge>@if($activeProcess->status === 'failed')<small class="process-safe-error">{{ $activeProcess->safe_error_message }}</small>@endif</td><td>{{ $activeProcess->updated_at?->timezone(config('app.display_timezone'))->format('d/m/Y H:i') ?? '—' }} WIB</td></tr>
        @endforeach
        </tbody></table></div>
        <button id="prediction-process-all-trigger" class="btn btn-sm btn-link" type="button" @if($activeProcesses->count() <= 5) hidden @endif>Lihat semua (<span>{{ $activeProcesses->count() }}</span>)</button>
    </div>
    <dialog id="prediction-process-dialog" class="prediction-process-dialog" aria-labelledby="prediction-process-dialog-title">
        <div class="dialog-heading"><div><h2 id="prediction-process-dialog-title">Semua Proses Aktif</h2><p>Diurutkan dari Gagal, Diproses, lalu Menunggu.</p></div><button class="dialog-close" type="button" aria-label="Tutup daftar proses"><i class="ti-close"></i></button></div>
        <div class="table-responsive"><table class="table"><caption>Seluruh proses prediksi aktif</caption><thead><tr><th>Barang</th><th>Status proses</th><th>Diperbarui</th></tr></thead><tbody id="prediction-process-all-rows">
        @foreach($activeProcesses as $activeProcess)
        @php($activeLabel = ['waiting' => 'Menunggu', 'processing' => 'Diproses', 'failed' => 'Gagal'][$activeProcess->status])
        <tr><td><strong>{{ $activeProcess->barang->nama_barang }}</strong><small>{{ $activeProcess->barang->kode_barang }}</small></td><td><x-ui.badge :variant="$activeProcess->status === 'failed' ? 'danger' : 'info'">{{ $activeLabel }}</x-ui.badge>@if($activeProcess->status === 'failed')<small class="process-safe-error">{{ $activeProcess->safe_error_message }}</small>@endif</td><td>{{ $activeProcess->updated_at?->timezone(config('app.display_timezone'))->format('d/m/Y H:i') ?? '—' }} WIB</td></tr>
        @endforeach
        </tbody></table></div>
    </dialog>
</section>
@endcan

<x-ui.card class="prediction-results-card">
    <div class="prediction-results-heading"><div><h2>Hasil Prediksi</h2><p>{{ $predictions->total() }} barang ditemukan</p></div><form method="GET" class="prediction-inline-filter"><label class="sr-only" for="status">Risiko stok</label><select class="form-select" name="status" id="status"><option value="">Semua risiko</option>@foreach(['Aman','Waspada','Perlu Restock','Mendesak','Perlu Ditinjau'] as $status)<option @selected(request('status')===$status)>{{ $status }}</option>@endforeach</select><x-ui.button type="submit" size="sm">Terapkan</x-ui.button><x-ui.button :href="route('stock-predictions.index')" size="sm" variant="outline-secondary">Reset</x-ui.button></form></div>
    <div class="table-responsive prediction-results-scroll"><table class="table prediction-compact-table"><caption>Ringkasan hasil prediksi stok; gunakan tombol Detail untuk membuka informasi analisis lengkap</caption><thead><tr><th>Barang</th><th>Risiko</th><th class="text-right">Stok</th><th>Perkiraan habis</th><th class="text-right">Saran restock</th><th class="text-right">Confidence</th><th>Proses</th><th>Aksi</th></tr></thead><tbody>
    @forelse($predictions as $prediction)
    @php($ui = $presentations->get($prediction->id))
    @php($summary = $ui['summary'])
    @php($process = $processes->get($prediction->barang_id))
    <tr class="prediction-summary-row">
        <td><strong>{{ $prediction->barang->nama_barang }}</strong><small>{{ $prediction->barang->kode_barang }}</small>@if(!$ui['available'])<span class="prediction-data-missing">Data belum lengkap</span>@endif</td>
        <td><x-ui.badge variant="{{ $ui['risk_variant'] }}">{{ $prediction->status }}</x-ui.badge><span class="sr-only">Risiko stok {{ $prediction->status }}</span></td>
        <td class="text-right text-nowrap">{{ $prediction->current_stock }} {{ $prediction->barang->satuan }}</td>
        <td class="text-nowrap">{{ $ui['available'] ? ($ui['depletion_date']?->format('d/m/Y') ?? 'Belum tersedia') : '—' }}</td>
        <td class="text-right text-nowrap">{{ $ui['available'] ? $prediction->recommended_restock.' '.$prediction->barang->satuan : '—' }}</td>
        <td class="text-right">{{ $ui['confidence'] === null ? '—' : $ui['confidence_label'] }}</td>
        <td>@if($ui['process_label'])<x-ui.badge variant="{{ $ui['process_variant'] }}">{{ $ui['process_label'] }}</x-ui.badge>@else<span class="text-muted">—</span>@endif</td>
        <td><div class="prediction-row-actions"><button class="btn btn-sm btn-outline-secondary prediction-detail-toggle" type="button" aria-expanded="false" aria-controls="prediction-detail-{{ $prediction->id }}">Detail</button>@can('run-stock-prediction')<form method="POST" action="{{ route('stock-predictions.analyze',$prediction->barang) }}">@csrf<x-ui.button type="submit" size="sm" variant="outline-primary" :disabled="in_array($process?->status, ['waiting','processing'], true)">Analisis Ulang</x-ui.button></form>@endcan</div></td>
    </tr>
    <tr id="prediction-detail-{{ $prediction->id }}" class="prediction-detail-row" hidden><td colspan="8"><section aria-label="Detail prediksi {{ $prediction->barang->nama_barang }}">
        <div class="prediction-detail-heading"><div><span class="section-eyebrow">Dasar analisis</span><h3>{{ $prediction->barang->nama_barang }}</h3></div><button class="prediction-detail-close" type="button" aria-label="Tutup detail {{ $prediction->barang->nama_barang }}"><i class="ti-close"></i></button></div>
        <div class="prediction-detail-metrics"><div><span>Metode</span><strong>{{ $ui['method_label'] }}</strong><small>Teknis: <code>{{ $ui['technical_method'] }}</code></small></div><div><span>Kebutuhan 30 hari</span><strong>{{ $ui['available'] && $prediction->predicted_30_day_need !== null ? number_format((float)$prediction->predicted_30_day_need, 2, ',', '.').' '.$prediction->barang->satuan : 'Belum tersedia' }}</strong></div><div><span>Jumlah histori</span><strong>{{ $summary['out_transaction_count'] ?? 0 }} transaksi OUT</strong><small>{{ $summary['out_transaction_days'] ?? 0 }} hari transaksi; rentang {{ $summary['history_days'] ?? 0 }} hari</small></div><div><span>Batas aman</span><strong>{{ $prediction->safety_stock === null ? 'Belum tersedia' : $prediction->safety_stock.' '.$prediction->barang->satuan }}</strong></div><div><span>Waktu analisis</span><strong>{{ $ui['analyzed_at'] }} WIB</strong></div></div>
        <div class="prediction-confidence"><div><span>Tingkat keyakinan data</span><strong>{{ $ui['confidence_label'] }}</strong></div><div class="progress" role="progressbar" aria-label="Tingkat keyakinan data" aria-valuemin="0" aria-valuemax="100" @if($ui['confidence'] !== null) aria-valuenow="{{ $ui['confidence'] }}" @endif><div class="progress-bar" style="width: {{ $ui['confidence'] ?? 0 }}%"></div></div><small>Kualitas data yang digunakan dalam analisis, bukan probabilitas prediksi akan terjadi.</small></div>
        @if($ui['available'] && $ui['depletion_date'])
        <div class="prediction-date-flow" aria-label="Linimasa prediksi stok"><div><span class="prediction-date-dot"></span><small>Hari ini</small><strong>{{ today(config('app.display_timezone'))->format('d/m/Y') }}</strong></div><div class="prediction-date-line"></div><div><span class="prediction-date-dot warning"></span><small>Disarankan restock</small><strong>{{ $ui['restock_date']?->format('d/m/Y') ?? 'Belum tersedia' }}</strong></div><div class="prediction-date-line"></div><div><span class="prediction-date-dot danger"></span><small>Perkiraan habis</small><strong>{{ $ui['depletion_date']->format('d/m/Y') }}</strong></div></div>
        @if($ui['depletion_is_past'])<p class="prediction-urgent-note"><i class="ti-alert"></i> Tanggal perkiraan habis telah terlewati. Tinjau stok dan lakukan analisis ulang.</p>@endif
        @elseif(!$ui['available'])
        <div class="prediction-cold-start"><strong>Prediksi belum tersedia.</strong><p>Lengkapi {{ collect($ui['missing_inputs'])->map(fn($item) => str($item)->lower())->join(' dan ') ?: 'estimasi pemakaian harian dan lead time pemasok' }}.</p>@can('manage-barang')<a class="btn btn-sm btn-outline-primary" href="/barang/{{ $prediction->barang_id }}/edit">Lengkapi data</a>@endcan</div>
        @else<p class="prediction-cold-start"><strong>Tanggal habis belum dapat dipastikan.</strong> Data jumlah tersedia, tetapi belum cukup untuk menentukan tanggal.</p>@endif
        <div class="prediction-analysis-note"><strong>Alasan dan rekomendasi</strong><p>{{ $summary['reason'] ?? 'Analisis menggunakan histori transaksi barang keluar yang tersedia.' }}</p>@if($summary['fallback_used'] ?? false)<p class="text-warning">Perhitungan cadangan digunakan karena engine utama tidak dapat menyelesaikan analisis.</p>@endif @if($summary['anomaly_reason'] ?? false)<p class="text-danger">{{ $summary['anomaly_reason'] }}</p>@endif</div>
        <details class="prediction-technical-audit"><summary>Rincian teknis dan audit</summary><dl><dt>Metode teknis</dt><dd><code>{{ $ui['technical_method'] }}</code></dd><dt>Minimal histori</dt><dd>{{ $summary['minimum_history_days'] ?? 30 }} hari</dd><dt>Status analisis</dt><dd>{{ $summary['analysis_status'] ?? $prediction->analysis_status ?? 'Tidak tersedia' }}</dd><dt>Fallback</dt><dd>{{ ($summary['fallback_used'] ?? false) ? 'Digunakan' : 'Tidak digunakan' }}</dd></dl></details>
        <div class="prediction-detail-actions">@can('approve-restock')@if($prediction->recommended_restock > 0)<form method="POST" action="{{ route('stock-predictions.approve',$prediction) }}">@csrf<x-ui.button type="submit" size="sm">Setujui Restock</x-ui.button></form>@endif @endcan</div>
    </section></td></tr>
    @empty<tr><td colspan="8"><x-ui.empty-state icon="ti-stats-up" title="Belum ada hasil prediksi" description="Admin atau Manager dapat menjalankan analisis untuk mulai melihat rekomendasi." /></td></tr>@endforelse
    </tbody></table></div>
    @if($predictions->hasPages())<div class="pagination-wrap">{{ $predictions->links() }}</div>@endif
</x-ui.card>
@endsection
@push('scripts')
<script src="{{ asset('assets/js/prediction-view.js') }}"></script>
@endpush
