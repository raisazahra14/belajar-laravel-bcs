<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>{{ $title ?? 'LogistikKu' }}</title>
    <link rel="stylesheet" href="{{ asset('assets/vendors/feather/feather.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendors/ti-icons/css/themify-icons.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendors/css/vendor.bundle.base.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendors/font-awesome/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendors/mdi/css/materialdesignicons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
    <link rel="stylesheet" href="{{ asset('css/skydash-logistics.css') }}">
</head>
<body class="role-{{ auth()->check() ? str(auth()->user()->getRoleNames()->first() ?? 'guest')->slug() : 'guest' }}">
<div class="container-scroller">
    <nav class="navbar col-lg-12 col-12 p-0 fixed-top d-flex flex-row">
        <div class="text-center navbar-brand-wrapper d-flex align-items-center justify-content-start">
            <a class="navbar-brand brand-logo me-5 logistics-brand" href="/barang">🏬 <span>LogistikKu</span></a>
            <a class="navbar-brand brand-logo-mini logistics-brand-mini" href="/barang">🏬</a>
        </div>
        <div class="navbar-menu-wrapper d-flex align-items-center justify-content-end">
            <button class="navbar-toggler align-self-center" type="button" data-toggle="minimize"><span class="icon-menu"></span></button>
            <div class="navbar-text d-none d-md-block">{{ auth()->user()->name }} · {{ auth()->user()->getRoleNames()->first() ?? 'Tanpa Role' }}</div>
            <form action="/logout" method="POST" class="d-none d-md-block me-3">@csrf<button class="btn btn-sm btn-outline-secondary" type="submit">Keluar</button></form>
            <button class="navbar-toggler navbar-toggler-right d-lg-none align-self-center" type="button" data-toggle="offcanvas"><span class="icon-menu"></span></button>
        </div>
    </nav>
    <div class="container-fluid page-body-wrapper">
        <nav class="sidebar sidebar-offcanvas" id="sidebar">
            <ul class="nav">
                <li class="nav-item {{ request()->is('barang') ? 'active' : '' }}">
                    <a class="nav-link" href="/barang"><i class="icon-grid menu-icon"></i><span class="menu-title">Daftar Barang</span></a>
                </li>
                <li class="nav-item {{ request()->is('barang/create') ? 'active' : '' }}">
                    <a class="nav-link" href="/barang/create"><i class="icon-circle-plus menu-icon"></i><span class="menu-title">Tambah Barang</span></a>
                </li>
                <li class="nav-item {{ request()->is('barang/low-stock') ? 'active' : '' }}">
                    <a class="nav-link" href="/barang/low-stock"><i class="icon-alert menu-icon"></i><span class="menu-title">Stok Menipis</span></a>
                </li>
                @role('Admin')
                    <li class="nav-item {{ request()->is('users*') ? 'active' : '' }}">
                        <a class="nav-link" href="/users"><i class="icon-head menu-icon"></i><span class="menu-title">Kelola User</span></a>
                    </li>
                @endrole
            </ul>
        </nav>
        <div class="main-panel">
            <div class="content-wrapper">
                @yield('content')
            </div>
            <footer class="footer"><div class="d-sm-flex justify-content-center justify-content-sm-between"><span class="text-muted text-center text-sm-left d-block d-sm-inline-block">LogistikKu · Manajemen Stok Barang</span></div></footer>
        </div>
    </div>
</div>
<script src="{{ asset('assets/vendors/js/vendor.bundle.base.js') }}"></script>
<script src="{{ asset('assets/js/off-canvas.js') }}"></script>
<script src="{{ asset('assets/js/template.js') }}"></script>
@stack('scripts')
</body>
</html>
