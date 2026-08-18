<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk &middot; LogistikKu</title>
    <link rel="stylesheet" href="{{ asset('assets/vendors/css/vendor.bundle.base.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
    <link rel="stylesheet" href="{{ asset('css/skydash-logistics.css') }}">
</head>
<body>
    <div class="container-scroller">
        <div class="container-fluid page-body-wrapper full-page-wrapper">
            <div class="content-wrapper d-flex align-items-center auth px-0">
                <div class="row w-100 mx-0">
                    <div class="col-lg-4 mx-auto">
                        <div class="auth-form-light text-left py-5 px-4 px-sm-5">
                            <h3 class="logistics-brand mb-2">&#127970; LogistikKu</h3>
                            <h5 class="font-weight-light mb-4">Masuk untuk mengelola stok gudang.</h5>
                            @if($errors->any())
                                <div class="alert alert-danger">{{ $errors->first() }}</div>
                            @endif
                            <form class="pt-3" action="/login" method="POST">
                                @csrf
                                <div class="form-group"><input type="email" name="email" class="form-control form-control-lg" placeholder="Email" value="{{ old('email') }}" required autofocus></div>
                                <div class="form-group"><input type="password" name="password" class="form-control form-control-lg" placeholder="Password" required></div>
                                <div class="form-check mb-3"><label class="form-check-label text-muted"><input type="checkbox" name="remember" class="form-check-input"> Ingat saya</label></div>
                                <button class="btn btn-primary btn-lg font-weight-medium auth-form-btn w-100" type="submit">MASUK</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
