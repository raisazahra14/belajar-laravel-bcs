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
            @php($unreadPredictions = \App\Models\StockPredictionNotification::with('barang')->whereNull('read_at')->latest()->limit(5)->get())
            <div class="dropdown notification-menu"><button class="notification-trigger" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifikasi prediksi stok"><i class="ti-bell"></i>@if($unreadPredictions->isNotEmpty())<span class="notification-dot">{{ $unreadPredictions->count() }}</span>@endif</button><div class="dropdown-menu dropdown-menu-end prediction-notifications"><div class="dropdown-header d-flex justify-content-between"><strong>Notifikasi Prediksi</strong>@if($unreadPredictions->isNotEmpty())<form method="POST" action="{{ route('prediction-notifications.read-all') }}">@csrf @method('PATCH')<button class="btn-link border-0 bg-transparent p-0" type="submit">Tandai semua</button></form>@endif</div>@forelse($unreadPredictions as $notification)<form method="POST" action="{{ route('prediction-notifications.read', $notification) }}">@csrf @method('PATCH')<button class="dropdown-item" type="submit"><strong>{{ $notification->barang->nama_barang }}</strong><small>{{ $notification->status }} · tandai dibaca</small></button></form>@empty<div class="dropdown-item text-muted">Tidak ada notifikasi baru.</div>@endforelse</div></div>
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
            <li class="nav-item {{ request()->routeIs('barang.index') ? 'active' : '' }}"><a class="nav-link" href="{{ route('barang.index') }}"><i class="ti-view-grid menu-icon"></i><span class="menu-title">Daftar Barang</span></a></li>
            <li class="nav-item {{ request()->is('barang/low-stock') ? 'active' : '' }}"><a class="nav-link" href="/barang/low-stock"><i class="ti-alert menu-icon"></i><span class="menu-title">Stok Menipis</span></a></li>
            <li class="nav-item {{ request()->is('prediksi-stok*') ? 'active' : '' }}"><a class="nav-link" href="{{ route('stock-predictions.index') }}"><i class="ti-stats-up menu-icon"></i><span class="menu-title">Prediksi Stok</span></a></li>
            <li class="nav-item {{ request()->is('verifications*') ? 'active' : '' }}"><a class="nav-link" href="{{ route('verifications.index') }}"><i class="ti-check-box menu-icon"></i><span class="menu-title">Verifikasi Dokumen</span></a></li>
            @can('manage-barang')<li class="nav-item {{ request()->is('users*') ? 'active' : '' }}"><a class="nav-link" href="/users"><i class="ti-user menu-icon"></i><span class="menu-title">Kelola User</span></a></li>@endcan
        </ul></nav>
        <div class="main-panel"><main class="content-wrapper" id="main-content">@yield('content')</main><footer class="footer"><span>LogistikKu · Manajemen Stok Barang</span></footer></div>
    </div>
</div>
<script src="{{ asset('assets/vendors/js/vendor.bundle.base.js') }}"></script><script src="{{ asset('assets/js/off-canvas.js') }}"></script><script src="{{ asset('assets/js/template.js') }}"></script>
<script>document.addEventListener('submit',function(event){const button=event.target.querySelector('button[type="submit"]');if(!button||!event.target.checkValidity())return;button.disabled=true;button.classList.add('is-loading');button.setAttribute('aria-busy','true');});</script>
@stack('scripts')
</body>
</html>
