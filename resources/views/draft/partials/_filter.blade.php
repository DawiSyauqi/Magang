{{--
    Filter global (periode, shift, mesin, kategori) — dipakai semua halaman draft.
    Param: $route (nama route form), $routeParams (opsional, mis. kode mesin),
    $tanpaMesin (opsional: sembunyikan pilihan mesin, mis. di profil mesin),
    $info (teks kanan, opsional).
    Bila $filter punya kunci 'q' (halaman Data Downtime), kontrol tambahan
    pencarian / operator (admin) / sumber ikut ditampilkan, dan urutan + jumlah
    baris dipertahankan lewat input tersembunyi.
--}}
@php
    $isAdmin = request('peran') === 'admin';
    $warnaKat = config('produksi.warna_kategori');
    $tabel = array_key_exists('q', $filter);
    $routeParams = $routeParams ?? [];
    $tanpaMesin = $tanpaMesin ?? false;
    $adaFilter = $filter['shift'] || $filter['mesin'] || $filter['kategori'] || $filter['periode'] !== '30_hari'
        || ($tabel && ($filter['q'] !== '' || $filter['operator'] !== '' || $filter['sumber'] !== ''));
@endphp
<form method="GET" action="{{ route($route, $routeParams) }}" class="dx-filter">
    @if ($isAdmin)<input type="hidden" name="peran" value="admin">@endif
    @if ($tabel)
        <input type="hidden" name="sort" value="{{ $filter['sort'] }}">
        <input type="hidden" name="arah" value="{{ $filter['arah'] }}">
        <input type="hidden" name="per" value="{{ $filter['per'] }}">
    @endif

    <select name="periode" class="form-select" data-periode aria-label="Periode">
        @foreach ($opsiPeriode as $k => $label)
            <option value="{{ $k }}" @selected($filter['periode'] === $k)>{{ $label }}</option>
        @endforeach
    </select>
    <span data-kustom class="d-inline-flex gap-1 align-items-center {{ $filter['periode'] === 'kustom' ? '' : 'd-none' }}">
        <input type="date" name="dari" class="form-control" value="{{ $filter['dari'] }}" max="{{ now()->toDateString() }}">
        <span class="text-muted-x small">s/d</span>
        <input type="date" name="sampai" class="form-control" value="{{ $filter['sampai'] }}" max="{{ now()->toDateString() }}">
    </span>

    <div class="dx-chip-group" role="group" aria-label="Shift">
        @foreach (['1', '2', '3'] as $s)
            <input type="checkbox" name="shift[]" value="{{ $s }}" id="shift{{ $s }}" @checked(in_array($s, $filter['shift']))>
            <label for="shift{{ $s }}" title="{{ config("produksi.shift.$s.mulai") }}–{{ config("produksi.shift.$s.selesai") }}">Shift {{ $s }}</label>
        @endforeach
    </div>

    @unless ($tanpaMesin)
    <div class="dropdown dx-dropdown-check">
        <button class="btn dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside">
            <i class="bi bi-cpu me-1"></i> Mesin {{ $filter['mesin'] ? '('.count($filter['mesin']).')' : '' }}
        </button>
        <div class="dropdown-menu">
            @foreach ($opsiMesin as $kode => $nama)
                <label class="form-check">
                    <input class="form-check-input" type="checkbox" name="mesin[]" value="{{ $kode }}" @checked(in_array($kode, $filter['mesin']))>
                    <span class="fw-semibold">{{ $kode }}</span> <span class="text-muted-x">{{ $nama }}</span>
                </label>
            @endforeach
        </div>
    </div>
    @endunless

    <div class="dropdown dx-dropdown-check">
        <button class="btn dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside">
            <i class="bi bi-exclamation-triangle me-1"></i> Kategori {{ $filter['kategori'] ? '('.count($filter['kategori']).')' : '' }}
        </button>
        <div class="dropdown-menu">
            @foreach ($opsiKategori as $kode => $nama)
                <label class="form-check">
                    <input class="form-check-input" type="checkbox" name="kategori[]" value="{{ $kode }}" @checked(in_array($kode, $filter['kategori']))>
                    <span class="dx-cat" style="--c: {{ $warnaKat[$kode] ?? '#64748b' }}">{{ $kode }}</span> <span class="text-muted-x">{{ $nama }}</span>
                </label>
            @endforeach
        </div>
    </div>

    @if ($tabel)
        <select name="sumber" class="form-select" aria-label="Sumber input">
            <option value="">Semua sumber</option>
            <option value="manual" @selected($filter['sumber'] === 'manual')>Input manual</option>
            <option value="ai" @selected($filter['sumber'] === 'ai')>AI Baca Kertas</option>
        </select>
        @if ($isAdmin && ! empty($opsiOperator))
            <select name="operator" class="form-select" aria-label="Operator">
                <option value="">Semua operator</option>
                @foreach ($opsiOperator as $nik => $nama)
                    <option value="{{ $nik }}" @selected($filter['operator'] === (string) $nik)>{{ $nama }}</option>
                @endforeach
            </select>
        @endif
        <span class="dx-search">
            <i class="bi bi-search"></i>
            <input type="search" name="q" value="{{ $filter['q'] }}" class="form-control"
                   placeholder="{{ $isAdmin ? 'Cari No_Trs, mesin, masalah, item, operator…' : 'Cari No_Trs, mesin, masalah, item…' }}">
        </span>
    @endif

    <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Terapkan</button>
    @if ($adaFilter)
        <a href="{{ route($route, $routeParams + ($isAdmin ? ['peran' => 'admin'] : [])) }}" class="btn btn-link text-muted-x text-decoration-none">Reset</a>
    @endif
    @isset($info)<span class="ms-auto dx-active-filters num">{{ $info }}</span>@endisset
</form>

@once
    @push('scripts')
        <script>
            document.querySelectorAll('[data-periode]').forEach(function (sel) {
                sel.addEventListener('change', function () {
                    var k = sel.form.querySelector('[data-kustom]');
                    if (k) k.classList.toggle('d-none', sel.value !== 'kustom');
                    if (sel.value !== 'kustom') sel.form.submit();
                });
            });
        </script>
    @endpush
@endonce
