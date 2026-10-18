<!DOCTYPE html>
<html lang="id" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk · Downtime Mesin</title>
    <link rel="icon" type="image/png" href="{{ asset('icons/favicon-32.png') }}">
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#ea580c">
    <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
    @vite(['resources/js/theme.js', 'resources/css/draft.scss', 'resources/js/app.js'])
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
<div class="dx-auth">
    <section class="dx-auth-panel d-none d-lg-flex">
        <div class="dx-brand-logo"><i class="bi bi-gear-wide-connected"></i></div>
        <h1>Downtime Mesin</h1>
        <p>Catat mesin berhenti, lihat penyebab terbesar, dan siapkan laporan, langsung dari lantai produksi.</p>
        <ul>
            <li><i class="bi bi-graph-up-arrow"></i> Ringkasan & analisis per mesin, masalah, dan shift</li>
            <li><i class="bi bi-clipboard-check"></i> Pemeriksaan data otomatis</li>
            <li><i class="bi bi-file-earmark-bar-graph"></i> Laporan harian & bulanan siap cetak</li>
        </ul>
        <div class="dx-auth-foot">OptiGear · Downtime Monitoring</div>
    </section>

    <section class="dx-auth-form">
        <div class="position-absolute top-0 end-0 p-3">@include('layouts.partials._theme-toggle')</div>

        <div class="w-100" style="max-width: 400px">
            <div class="d-lg-none text-center mb-4">
                <div class="dx-brand-logo mx-auto mb-3"><i class="bi bi-gear-wide-connected"></i></div>
                <h1 class="fs-4 fw-bold mb-1">Downtime Mesin</h1>
            </div>

            <div class="dx-card p-4" style="height: auto">
                <h2 class="fs-5 fw-bold mb-1">Masuk</h2>
                <p class="text-muted-x small mb-4">Gunakan akun perusahaan Anda.</p>

                @if ($errors->any())
                    <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-circle me-1"></i>{{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ url('/login') }}">
                    @csrf
                    <div class="mb-3">
                        <label for="username" class="form-label small fw-semibold">Username</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                            <input type="text" class="form-control @error('username') is-invalid @enderror" id="username" name="username"
                                   value="{{ old('username') }}" maxlength="10" autocomplete="username" required autofocus>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label small fw-semibold">Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            {{-- Sengaja tanpa "required": akun yang belum pernah membuat password
                                 (PassWeb masih kosong) boleh masuk tanpa password (LoginController). --}}
                            <input type="password" class="form-control" id="password" name="password" autocomplete="current-password">
                            <button class="btn btn-outline-secondary" type="button" id="lihatPassword" aria-label="Tampilkan password"><i class="bi bi-eye"></i></button>
                        </div>
                        <div class="form-text">Belum pernah membuat password? Kosongkan saja, lalu buat lewat menu akun.</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-semibold">Masuk</button>
                </form>
            </div>
        </div>
    </section>
</div>
<script>
    document.getElementById('lihatPassword').addEventListener('click', function () {
        var pw = document.getElementById('password');
        pw.type = pw.type === 'password' ? 'text' : 'password';
        this.querySelector('i').className = pw.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
    });
</script>
</body>
</html>
