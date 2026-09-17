@if(session('import_summary'))
<div class="import-result" aria-live="polite">
    <h2>Ringkasan Import</h2>
    <div>
        <span>Total diproses<strong>{{ session('import_summary.total', 0) }}</strong></span>
        <span>Berhasil<strong>{{ session('import_summary.created', 0) + session('import_summary.updated', 0) }}</strong></span>
        <span>Barang baru<strong>{{ session('import_summary.created', 0) }}</strong></span>
        <span>Diperbarui<strong>{{ session('import_summary.updated', 0) }}</strong></span>
        <span>Gagal disimpan<strong>{{ session('import_summary.failed', 0) }}</strong></span>
    </div>
    @if(session('import_summary.failed', 0) > 0)
        <p>{{ session('import_summary.invalid', 0) }} baris bermasalah. Seluruh batch dibatalkan; tidak ada data atau stok yang berubah. Perbaiki kesalahan berikut lalu unggah ulang.</p>
    @endif
</div>
@elseif($errors->has('spreadsheet'))
    <p role="status">Import gagal: 0 data berhasil disimpan. File ditolak sebelum seluruh baris dapat divalidasi.</p>
@endif
