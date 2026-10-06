<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Masuk &middot; LogistikKu</title>
    <link rel="stylesheet" href="{{ asset('assets/vendors/ti-icons/css/themify-icons.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendors/css/vendor.bundle.base.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
    <link rel="stylesheet" href="{{ asset('css/skydash-logistics.css') }}">
</head>
<body class="login-page">
    <main class="login-shell">
        <section class="login-intro" aria-labelledby="login-brand">
            <div>
                <a class="login-brand" href="{{ route('login') }}" id="login-brand" aria-label="LogistikKu">
                    <span class="login-brand-icon"><i class="ti-package" aria-hidden="true"></i></span>
                    <span>LogistikKu</span>
                </a>
                <p class="login-eyebrow">Sistem inventaris terpadu</p>
                <h1>Stok lebih tertata, keputusan lebih cepat.</h1>
                <p class="login-intro-copy">Kelola barang, gudang, supplier, pergerakan stok, dan laporan operasional dari satu tempat.</p>
            </div>
            <ul class="login-benefits" aria-label="Fitur utama">
                <li><i class="ti-check" aria-hidden="true"></i> Pantau saldo setiap gudang</li>
                <li><i class="ti-check" aria-hidden="true"></i> Telusuri mutasi dan riwayat stok</li>
                <li><i class="ti-check" aria-hidden="true"></i> Dapatkan peringatan serta prediksi restock</li>
            </ul>
        </section>

        <section class="login-form-panel" aria-labelledby="login-title">
            <div class="login-form-card">
                <div class="login-mobile-brand" aria-hidden="true"><i class="ti-package"></i> LogistikKu</div>
                <p class="login-eyebrow">Selamat datang kembali</p>
                <h2 id="login-title">Masuk ke akun Anda</h2>
                <p class="login-form-help">Gunakan email dan password yang telah terdaftar.</p>

                @if ($errors->any())
                    <x-ui.alert type="danger" class="login-alert">
                        <i class="ti-alert" aria-hidden="true"></i>
                        <span>{{ $errors->first() }}</span>
                    </x-ui.alert>
                @endif

                <form action="{{ route('login') }}" method="POST" class="login-form">
                    @csrf
                    <div class="form-group">
                        <label for="email">Email</label>
                        <div class="login-input-wrap">
                            <i class="ti-email" aria-hidden="true"></i>
                            <input id="email" type="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email') }}" placeholder="nama@perusahaan.com" autocomplete="username" inputmode="email" required autofocus aria-describedby="email-help">
                        </div>
                        <small id="email-help">Email tidak membedakan huruf besar dan kecil.</small>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="login-input-wrap login-password-wrap">
                            <i class="ti-lock" aria-hidden="true"></i>
                            <input id="password" type="password" name="password" class="form-control @error('password') is-invalid @enderror" placeholder="Masukkan password" autocomplete="current-password" required>
                            <button id="toggle-password" type="button" class="login-password-toggle" aria-controls="password" aria-pressed="false">
                                <i class="ti-eye" aria-hidden="true"></i><span>Tampilkan</span>
                            </button>
                        </div>
                    </div>

                    <label class="login-remember" for="remember">
                        <input id="remember" type="checkbox" name="remember" value="1" @checked(old('remember'))>
                        <span>Ingat saya di perangkat ini</span>
                    </label>

                    <button class="btn btn-primary login-submit" type="submit">
                        <span>Masuk</span><i class="ti-arrow-right" aria-hidden="true"></i>
                    </button>
                </form>

            </div>
            <p class="login-footer">&copy; {{ now()->year }} LogistikKu &middot; Manajemen inventaris dan logistik</p>
        </section>
    </main>

    <script>
        document.getElementById('toggle-password')?.addEventListener('click', function () {
            const input = document.getElementById('password');
            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            this.setAttribute('aria-pressed', String(!showing));
            this.querySelector('span').textContent = showing ? 'Tampilkan' : 'Sembunyikan';
            this.querySelector('i').className = showing ? 'ti-eye' : 'ti-close';
            input.focus();
        });

        document.querySelector('.login-form')?.addEventListener('submit', function (event) {
            if (!this.checkValidity()) return;
            const button = event.submitter;
            if (!button) return;
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.querySelector('span').textContent = 'Memproses...';
        });
    </script>
</body>
</html>
