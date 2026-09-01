<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>{{ $title ?? 'LogistikKu' }}</title>
    <link rel="stylesheet" href="{{ asset('assets/vendors/feather/feather.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendors/ti-icons/css/themify-icons.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendors/css/vendor.bundle.base.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
    <link rel="stylesheet" href="{{ asset('css/skydash-logistics.css') }}">
</head>
<body>
<div class="container-scroller">
    <nav class="navbar col-lg-12 col-12 p-0 fixed-top d-flex flex-row" aria-label="Navigasi utama">
        <div class="navbar-brand-wrapper d-flex align-items-center justify-content-start">
            <a class="navbar-brand brand-logo logistics-brand" href="{{ route('barang.index') }}"><i class="ti-package" aria-hidden="true"></i><span>LogistikKu</span></a>
            <a class="navbar-brand brand-logo-mini logistics-brand-mini" href="{{ route('barang.index') }}" aria-label="LogistikKu"><i class="ti-package" aria-hidden="true"></i></a>
        </div>
        <div class="navbar-menu-wrapper d-flex align-items-center justify-content-end">
            <button class="navbar-toggler align-self-center" type="button" data-toggle="minimize" aria-label="Perkecil sidebar"><span class="ti-menu"></span></button>
            @php($totalUnreadNotifications = $unreadPredictions->count() + $unreadOcrCount)
            <div class="dropdown notification-menu">
                <button class="notification-trigger" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifikasi aplikasi"><i class="ti-bell"></i><span id="notification-count" class="notification-dot" @if($totalUnreadNotifications === 0) hidden @endif>{{ $totalUnreadNotifications }}</span></button>
                <div class="dropdown-menu dropdown-menu-end prediction-notifications app-notifications" id="app-notifications" data-endpoint="{{ route('ocr-notifications.index') }}" data-prediction-unread="{{ $unreadPredictions->count() }}">
                    <div class="dropdown-header d-flex justify-content-between align-items-center"><strong>Notifikasi OCR</strong>@if($unreadOcrCount > 0)<form method="POST" action="{{ route('ocr-notifications.read-all') }}">@csrf @method('PATCH')<button class="btn-link border-0 bg-transparent p-0" type="submit">Tandai semua dibaca</button></form>@endif</div>
                    <div id="ocr-notification-list">
                        @forelse($ocrNotifications as $notification)
                            <div class="ocr-notification-item {{ $notification->read_at ? 'is-read' : 'is-unread' }}">
                                <a href="{{ route('ocr-notifications.open', $notification->id) }}"><strong>{{ $notification->data['title'] ?? 'Status verifikasi dokumen' }}</strong><span>{{ $notification->data['filename'] ?? 'Dokumen' }}</span><small>{{ ucwords(str_replace('_', ' ', $notification->data['document_type'] ?? 'dokumen')) }} · {{ $notification->created_at->locale('id')->diffForHumans() }}</small></a>
                                @if(! $notification->read_at)<form method="POST" action="{{ route('ocr-notifications.read', $notification->id) }}">@csrf @method('PATCH')<button type="submit">Tandai sudah dibaca</button></form>@endif
                            </div>
                        @empty<div class="dropdown-item text-muted ocr-notification-empty">Belum ada notifikasi OCR.</div>@endforelse
                    </div>
                    @if($unreadPredictions->isNotEmpty())<div class="dropdown-header notification-subheading d-flex justify-content-between"><strong>Prediksi Stok</strong><form method="POST" action="{{ route('prediction-notifications.read-all') }}">@csrf @method('PATCH')<button class="btn-link border-0 bg-transparent p-0" type="submit">Tandai semua</button></form></div>@endif
                    @foreach($unreadPredictions as $notification)<form method="POST" action="{{ route('prediction-notifications.read', $notification) }}">@csrf @method('PATCH')<button class="dropdown-item" type="submit"><strong>{{ $notification->barang->nama_barang }}</strong><small>{{ $notification->status }} · tandai dibaca</small></button></form>@endforeach
                </div>
            </div>
            <div class="dropdown user-menu">
                <button class="user-menu-trigger" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Buka menu pengguna"><span class="user-avatar" aria-hidden="true">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span><span class="user-summary d-none d-sm-flex"><strong>{{ auth()->user()->name }}</strong><small>{{ ucfirst(auth()->user()->role) }}</small></span><i class="ti-angle-down" aria-hidden="true"></i></button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li class="dropdown-header"><strong>{{ auth()->user()->name }}</strong><span>{{ auth()->user()->email }}</span><x-ui.badge variant="primary" class="mt-2">{{ ucfirst(auth()->user()->role) }}</x-ui.badge></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><form action="/logout" method="POST">@csrf<button class="dropdown-item" type="submit"><i class="ti-power-off" aria-hidden="true"></i>Keluar</button></form></li>
                </ul>
            </div>
            <button class="navbar-toggler navbar-toggler-right d-lg-none align-self-center" type="button" data-toggle="offcanvas" aria-label="Buka sidebar"><span class="ti-menu"></span></button>
        </div>
    </nav>
    <div class="container-fluid page-body-wrapper">
        <nav class="sidebar sidebar-offcanvas" id="sidebar" aria-label="Menu aplikasi"><ul class="nav">
            <li class="nav-section-label" aria-hidden="true">Operasional</li>
            <li class="nav-item {{ request()->routeIs('barang.index') ? 'active' : '' }}"><a class="nav-link" href="{{ route('barang.index') }}"><i class="ti-view-grid menu-icon"></i><span class="menu-title">Daftar Barang</span></a></li>
            <li class="nav-item {{ request()->is('barang/low-stock') ? 'active' : '' }}"><a class="nav-link" href="/barang/low-stock"><i class="ti-alert menu-icon"></i><span class="menu-title">Stok Menipis</span></a></li>
            @can('manage-barang')<li class="nav-item"><a class="nav-link" href="{{ route('barang.index', ['import' => 1]) }}"><i class="ti-upload menu-icon"></i><span class="menu-title">Import Data</span></a></li>@endcan
            <li class="nav-section-label" aria-hidden="true">Analisis</li>
            <li class="nav-item {{ request()->is('prediksi-stok*') ? 'active' : '' }}"><a class="nav-link" href="{{ route('stock-predictions.index') }}"><i class="ti-stats-up menu-icon"></i><span class="menu-title">Prediksi Stok</span></a></li>
            <li class="nav-item {{ request()->is('verifications*') ? 'active' : '' }}"><a class="nav-link" href="{{ route('verifications.index') }}"><i class="ti-check-box menu-icon"></i><span class="menu-title">Verifikasi Dokumen</span></a></li>
            @can('manage-barang')
            <li class="nav-section-label" aria-hidden="true">Administrasi</li>
            <li class="nav-item {{ request()->is('users*') ? 'active' : '' }}"><a class="nav-link" href="/users"><i class="ti-user menu-icon"></i><span class="menu-title">Kelola Pengguna</span></a></li>
            <li class="nav-item {{ request()->routeIs('barang.trash.*') ? 'active' : '' }}"><a class="nav-link" href="{{ route('barang.trash.index') }}"><i class="ti-trash menu-icon"></i><span class="menu-title">Tong Sampah</span></a></li>
            @endcan
        </ul></nav>
        <div class="main-panel"><main class="content-wrapper" id="main-content">@yield('content')</main><footer class="footer"><span>LogistikKu · Manajemen Stok Barang</span></footer></div>
    </div>
</div>
<script src="{{ asset('assets/vendors/js/vendor.bundle.base.js') }}"></script><script src="{{ asset('assets/js/off-canvas.js') }}"></script><script src="{{ asset('assets/js/template.js') }}"></script>
<script>document.addEventListener('submit',function(event){const button=event.submitter||event.target.querySelector('button[type="submit"]');if(!button||!event.target.checkValidity())return;button.disabled=true;button.classList.add('is-loading');button.setAttribute('aria-busy','true');});</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const menu = document.getElementById('app-notifications');
    const count = document.getElementById('notification-count');
    const list = document.getElementById('ocr-notification-list');
    if (!menu || !count || !list) return;
    const predictionUnread = Number(menu.dataset.predictionUnread || 0);

    const render = function (payload) {
        const total = predictionUnread + Number(payload.unread_count || 0);
        count.textContent = total;
        count.hidden = total === 0;
        list.replaceChildren();
        if (!payload.notifications.length) {
            const empty = document.createElement('div');
            empty.className = 'dropdown-item text-muted ocr-notification-empty';
            empty.textContent = 'Belum ada notifikasi OCR.';
            list.appendChild(empty);
            return;
        }
        payload.notifications.forEach(function (item) {
            const wrapper = document.createElement('div');
            wrapper.className = 'ocr-notification-item ' + (item.read ? 'is-read' : 'is-unread');
            const link = document.createElement('a');
            link.href = item.open_url;
            const title = document.createElement('strong'); title.textContent = item.title;
            const filename = document.createElement('span'); filename.textContent = item.filename;
            const detail = document.createElement('small'); detail.textContent = item.document_type + ' · ' + item.time + (item.read ? ' · sudah dibaca' : ' · belum dibaca');
            link.append(title, filename, detail);
            wrapper.appendChild(link);
            list.appendChild(wrapper);
        });
    };
    const poll = function () {
        if (document.hidden) return;
        fetch(menu.dataset.endpoint, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
            .then(function (response) { if (!response.ok) throw new Error('Notifikasi tidak tersedia'); return response.json(); })
            .then(render)
            .catch(function () {});
    };
    window.setInterval(poll, 15000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
});
</script>
@stack('scripts')
</body>
</html>
