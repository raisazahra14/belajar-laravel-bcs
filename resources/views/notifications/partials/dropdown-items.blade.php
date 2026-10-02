@forelse($notifications as $notification)
<div class="app-notification-item is-unread">
    <a class="app-notification-link" href="{{ $notification['openUrl'] }}">
        <span class="notification-source-icon"><i class="{{ $notification['source'] === 'ocr' ? 'ti-file' : 'ti-stats-up' }}" aria-hidden="true"></i></span>
        <span class="notification-copy"><span class="notification-title-row"><strong>{{ $notification['title'] }}</strong><span class="notification-unread-dot" aria-label="Belum dibaca"></span></span><span class="notification-message">{{ $notification['message'] }}</span><small>{{ $notification['type'] }} · {{ $notification['time'] }}</small></span>
    </a>
    <form class="notification-quick-read" method="POST" action="{{ $notification['readUrl'] }}">@csrf @method('PATCH')<button type="submit" title="Tandai dibaca" aria-label="Tandai {{ $notification['title'] }} dibaca"><i class="ti-check" aria-hidden="true"></i></button></form>
</div>
@empty
<div class="app-notification-empty"><i class="ti-check-box" aria-hidden="true"></i><strong>Semua beres</strong><span>Tidak ada notifikasi baru.</span></div>
@endforelse
