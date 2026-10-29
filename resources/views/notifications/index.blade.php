@extends('layouts.skydash')

@section('content')
<x-ui.page-header title="Notifikasi" description="Pembaruan OCR dan kondisi stok terbaru." />
@if(session('success'))<x-ui.alert type="success" dismissible>{{ session('success') }}</x-ui.alert>@endif

<x-ui.card>
    <div class="notification-page-toolbar">
        <form method="GET" action="{{ route('notifications.index') }}" class="notification-filter-grid">
            <div><label class="sr-only" for="status">Status</label><select class="form-select form-select-sm" id="status" name="status"><option value="all" @selected($readFilter === 'all')>Semua status</option><option value="unread" @selected($readFilter === 'unread')>Belum dibaca</option></select></div>
            <div><label class="sr-only" for="type">Jenis</label><select class="form-select form-select-sm" id="type" name="type"><option value="all" @selected($sourceFilter === 'all')>Semua jenis</option><option value="ocr" @selected($sourceFilter === 'ocr')>OCR</option><option value="prediction" @selected($sourceFilter === 'prediction')>Prediksi Stok</option></select></div>
            <div class="notification-filter-actions"><x-ui.button type="submit" size="sm" variant="outline-primary">Filter</x-ui.button>@if($readFilter !== 'all' || $sourceFilter !== 'all')<a class="notification-reset-link" href="{{ route('notifications.index') }}">Reset</a>@endif</div>
        </form>
        <div class="notification-page-summary"><span>{{ number_format($notifications->total(), 0, ',', '.') }} notifikasi</span><form method="POST" action="{{ route('notifications.read-all') }}">@csrf @method('PATCH')<button type="submit"><i class="ti-check" aria-hidden="true"></i> Baca semua</button></form></div>
    </div>

    <div class="notification-page-list">
        @forelse($notifications as $notification)
        <article class="notification-page-item {{ $notification['read'] ? 'is-read' : 'is-unread' }}">
            <a class="notification-page-link" href="{{ $notification['openUrl'] }}"><span class="notification-source-icon"><i class="{{ $notification['source'] === 'ocr' ? 'ti-file' : 'ti-stats-up' }}" aria-hidden="true"></i></span><span class="notification-copy"><span class="notification-title-row"><strong>{{ $notification['title'] }}</strong>@if(! $notification['read'])<span class="notification-unread-dot" aria-label="Belum dibaca"></span>@endif</span><span class="notification-message">{{ $notification['message'] }}</span><small>{{ $notification['type'] }} · {{ $notification['date_time'] }}</small></span></a>
            <div class="notification-page-actions">@if(! $notification['read'])<form method="POST" action="{{ $notification['readUrl'] }}">@csrf @method('PATCH')<button type="submit" title="Tandai dibaca" aria-label="Tandai {{ $notification['title'] }} dibaca"><i class="ti-check" aria-hidden="true"></i></button></form>@endif<a href="{{ $notification['openUrl'] }}" title="Buka detail" aria-label="Buka {{ $notification['title'] }}"><i class="ti-angle-right" aria-hidden="true"></i></a></div>
        </article>
        @empty
        <x-ui.empty-state icon="ti-bell" title="Tidak ada notifikasi" description="Belum ada notifikasi yang sesuai dengan filter." />
        @endforelse
    </div>
    @if($notifications->hasPages())<div class="pagination-wrap">{{ $notifications->onEachSide(1)->links() }}</div>@endif
</x-ui.card>
@endsection
