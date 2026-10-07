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
            @php($totalUnreadNotifications = $notificationDropdown['unread_count'])
            <div class="dropdown notification-menu">
                <button class="notification-trigger" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifikasi aplikasi"><i class="ti-bell"></i><span id="notification-count" class="notification-dot" @if($totalUnreadNotifications === 0) hidden @endif>{{ $totalUnreadNotifications }}</span></button>
                <div class="dropdown-menu dropdown-menu-end prediction-notifications app-notifications" id="app-notifications" data-endpoint="{{ route('notifications.feed') }}">
                    <div class="dropdown-header notification-dropdown-heading"><div><strong>Notifikasi</strong><small id="notification-summary">{{ number_format($totalUnreadNotifications, 0, ',', '.') }} belum dibaca · {{ $notificationDropdown['notifications']->count() }} terbaru</small></div><form id="notification-read-all" method="POST" action="{{ route('notifications.read-all') }}" @if($totalUnreadNotifications === 0) hidden @endif>@csrf @method('PATCH')<button class="btn-link border-0 bg-transparent p-0" type="submit"><i class="ti-check" aria-hidden="true"></i> Baca semua</button></form></div>
                    <div id="notification-list">@include('notifications.partials.dropdown-items', ['notifications' => $notificationDropdown['notifications']])</div>
                    <div class="notification-dropdown-footer"><a href="{{ route('notifications.index') }}">Lihat semua notifikasi <i class="ti-angle-right" aria-hidden="true"></i></a></div>
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
            <li class="nav-item {{ request()->routeIs('barang.scanner*') ? 'active' : '' }}"><a class="nav-link" href="{{ route('barang.scanner') }}"><i class="ti-barcode menu-icon"></i><span class="menu-title">Scan Barang</span></a></li>
            <li class="nav-item {{ request()->routeIs('suppliers.*') ? 'active' : '' }}"><a class="nav-link" href="{{ route('suppliers.index') }}"><i class="ti-truck menu-icon"></i><span class="menu-title">Supplier</span></a></li>
            <li class="nav-item {{ request()->routeIs('warehouses.*') ? 'active' : '' }}"><a class="nav-link" href="{{ route('warehouses.index') }}"><i class="ti-home menu-icon"></i><span class="menu-title">Gudang</span></a></li>
            <li class="nav-item {{ request()->is('barang/low-stock') ? 'active' : '' }}"><a class="nav-link" href="/barang/low-stock"><i class="ti-alert menu-icon"></i><span class="menu-title">Stok Menipis</span></a></li>
            @can('manage-barang')<li class="nav-item"><a class="nav-link" href="{{ route('barang.index', ['import' => 1]) }}"><i class="ti-upload menu-icon"></i><span class="menu-title">Import Data</span></a></li>@endcan
            <li class="nav-section-label" aria-hidden="true">Analisis</li>
            @can('manage-barang')<li class="nav-item {{ request()->routeIs('analytics.*') ? 'active' : '' }}"><a class="nav-link" href="{{ route('analytics.index') }}"><i class="ti-bar-chart menu-icon"></i><span class="menu-title">Analitik Bisnis</span></a></li>@endcan
            @can('view-stock-reports')<li class="nav-item {{ request()->routeIs('stock-mutations.*') ? 'active' : '' }}"><a class="nav-link" href="{{ route('stock-mutations.index') }}"><i class="ti-exchange-vertical menu-icon"></i><span class="menu-title">Mutasi Stok</span></a></li>@endcan
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
    const list = document.getElementById('notification-list');
    const summary = document.getElementById('notification-summary');
    const readAll = document.getElementById('notification-read-all');
    if (!menu || !count || !list || !summary || !readAll) return;

    const render = function (payload) {
        const total = Number(payload.unread_count || 0);
        count.textContent = total;
        count.hidden = total === 0;
        readAll.hidden = total === 0;
        summary.textContent = total.toLocaleString('id-ID') + ' belum dibaca · ' + Number(payload.displayed_count || 0).toLocaleString('id-ID') + ' terbaru';
        list.innerHTML = payload.html;
    };
    const poll = function () {
        if (document.hidden) return;
        fetch(menu.dataset.endpoint, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
            .then(function (response) { if (!response.ok) throw new Error('Notifikasi tidak tersedia'); return response.json(); })
            .then(render)
            .catch(function () {})
            .finally(function () { document.dispatchEvent(new CustomEvent('app:poll')); });
    };
    window.setInterval(poll, 15000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
});
</script>
@stack('scripts')
</body>
</html>
