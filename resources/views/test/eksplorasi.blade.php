@extends('layouts.app')

@section('title', 'Uji Eksplorasi 4–6')

@section('content')
@push('styles')
<style>
    .ir-btn-primary {
        background-color: var(--text); border-color: var(--text);
        border-radius: 999px; color: var(--bg); font-weight: 600;
    }
    .ir-btn-primary:hover { background-color: var(--n-800); border-color: var(--n-800); color: var(--bg); }
    :root[data-theme="dark"] .ir-btn-primary { background-color: var(--n-0); border-color: var(--n-0); color: var(--n-900); }
    .ir-badge { border-radius: 999px; padding: .3rem .7rem; font-size: .75rem; font-weight: 600; }
    .ir-badge-review { background-color: rgba(245, 158, 11, 0.12); color: var(--warning); }
    .ir-badge-ok { background-color: rgba(34, 197, 94, 0.12); color: var(--success); }
    .ir-badge-bad { background-color: rgba(239, 68, 68, 0.12); color: var(--danger, #dc3545); }
    .versi-card { cursor: pointer; border: 1.5px solid var(--border); border-radius: var(--r-lg, 12px); }
    .versi-card.active { border-color: var(--text); background: var(--surface-alt); }
    .grid-lt td, .grid-lt th { text-align: center; vertical-align: middle; }
    .grid-lt td.kotak { min-width: 64px; }
    .grid-lt td.ragu { background-color: rgba(245, 158, 11, 0.18) !important; }
    .grid-lt img { display: block; margin: 0 auto 2px; width: 48px; height: 48px; object-fit: contain; background: #fff; }
    .grid-lt img.blok { width: 260px; height: auto; }
    .field-img { max-height: 48px; max-width: 260px; background: #fff; }
    pre.raw { max-height: 400px; overflow: auto; white-space: pre-wrap; font-size: .75rem; }
</style>
@endpush

<div class="d-flex align-items-center justify-content-between px-3 px-md-4 py-2 border-bottom" style="background: var(--surface);">
    <span class="fw-semibold small" style="color: var(--text);">MODE UJI — Eksplorasi 4–6 (TrOCR, tanpa Ollama, tanpa login &amp; database)</span>
    @include('layouts.partials._theme-toggle')
</div>

<div class="container py-3" style="max-width: 1100px;">
    <h4 class="mb-2">Uji Eksplorasi 4–6</h4>
    <div class="alert alert-warning small mb-3">
        Halaman uji saja: foto dibaca oleh skrip di <code>scripts/eksplorasi4–6</code> (TrOCR di CPU), hasil hanya ditampilkan
        sebagai teks + potongan gambar, tidak disimpan dan tidak dicocokkan ke database.
        Proses pertama bisa lebih lama karena model dimuat.
    </div>

    <div class="row g-2 mb-3" id="versiPicker">
        <div class="col-md-4">
            <div class="versi-card p-3 h-100" data-versi="e4">
                <div class="fw-semibold">Eksplorasi 4</div>
                <div class="small text-muted">Foto close-up grid · TrOCR per kotak (pilih kode sah) · 1 shift per proses</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="versi-card p-3 h-100" data-versi="e5">
                <div class="fw-semibold">Eksplorasi 5</div>
                <div class="small text-muted">Foto close-up grid · TrOCR per blok jam (6 kotak sekaligus) · 1 shift per proses</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="versi-card p-3 h-100 active" data-versi="e6">
                <div class="fw-semibold">Eksplorasi 6</div>
                <div class="small text-muted">Foto SELURUH halaman · header, Size/Speed, operator + lost time sekaligus · tanpa klik titik</div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form id="uploadForm" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label" id="photoLabel">Foto seluruh halaman</label>
                    <input type="file" name="photo" id="photoInput" accept="image/*" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Shift</label>
                    <select name="shift" id="shiftSelect" class="form-select">
                        <option value="" id="shiftAuto">Otomatis (dari header)</option>
                        <option value="1">Shift 1</option>
                        <option value="2">Shift 2</option>
                        <option value="3">Shift 3</option>
                    </select>
                </div>
                <div class="col-md-2" id="barisWrap">
                    <label class="form-label">Baris dibaca</label>
                    <select name="baris" class="form-select">
                        <option value="bertinta">Semua yg bertinta</option>
                        <option value="shift">Hanya shift</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" id="submitBtn" class="btn ir-btn-primary w-100">Analisa</button>
                </div>
            </form>
            <img id="photoPreview" class="mt-3 d-none" style="max-height: 220px; max-width: 100%; border: 1px solid var(--border);" alt="Preview foto">
            <div id="loading" class="d-none mt-3">
                <div class="spinner-border spinner-border-sm"></div>
                Memproses… <span id="loadingTimer">0</span> dtk
            </div>
        </div>
    </div>

    <div id="result"></div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const URL_ANALYZE = @json(route('test.eksplorasi.analyze', ['versi' => '__V__']));
    const el = (id) => document.getElementById(id);
    const esc = (v) => String(v ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    let versi = 'e6';

    // ---------------- pilihan versi ----------------
    document.querySelectorAll('.versi-card').forEach(card => card.addEventListener('click', () => {
        document.querySelectorAll('.versi-card').forEach(c => c.classList.remove('active'));
        card.classList.add('active');
        versi = card.dataset.versi;
        applyVersiUI();
    }));

    function applyVersiUI() {
        const isE6 = versi === 'e6';
        el('photoLabel').textContent = isE6 ? 'Foto seluruh halaman' : 'Foto close-up grid Lost time';
        el('shiftAuto').hidden = !isE6;
        el('shiftAuto').disabled = !isE6;
        el('shiftSelect').required = !isE6;
        if (!isE6 && !el('shiftSelect').value) el('shiftSelect').value = '1';
        el('barisWrap').classList.toggle('d-none', !isE6);
    }
    applyVersiUI();

    el('photoInput').addEventListener('change', () => {
        const f = el('photoInput').files[0];
        el('photoPreview').classList.toggle('d-none', !f);
        if (f) el('photoPreview').src = URL.createObjectURL(f);
    });

    // ---------------- submit ----------------
    el('uploadForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        if (versi !== 'e6') fd.delete('baris');
        if (!fd.get('shift')) fd.delete('shift');

        el('result').innerHTML = '';
        el('submitBtn').disabled = true;
        el('loading').classList.remove('d-none');
        const t0 = Date.now();
        const timer = setInterval(() => { el('loadingTimer').textContent = Math.round((Date.now() - t0) / 1000); }, 500);

        try {
            const res = await fetch(URL_ANALYZE.replace('__V__', versi), {
                method: 'POST', headers: { 'Accept': 'application/json' }, body: fd,
            });
            const data = await res.json();
            render(data, (Date.now() - t0) / 1000);
        } catch (err) {
            el('result').innerHTML = `<div class="alert alert-danger">Tidak bisa menghubungi server: ${esc(err.message)}</div>`;
        } finally {
            clearInterval(timer);
            el('loading').classList.add('d-none');
            el('submitBtn').disabled = false;
        }
    });

    // ---------------- render ----------------
    function statusBadge(status) {
        const cls = status === 'success' ? 'ir-badge-ok' : (status === 'error' ? 'ir-badge-bad' : 'ir-badge-review');
        return `<span class="ir-badge ${cls}">${esc(status)}</span>`;
    }

    function render(data, browserSeconds) {
        const crops = data.crops || {};
        let html = '';

        // Ringkasan & waktu
        const meta = data.meta || {};
        const waktu = [
            ['Total di browser', browserSeconds.toFixed(1)],
            ['Total proses Python (server)', data.waktu_total_server_detik],
            ['Geometri', meta.geometry_seconds],
            ['Muat model', meta.model_load_seconds],
            ['Header & Size/Speed', meta.field_seconds],
            ['Baca kotak/blok Lost time', meta.model_seconds],
            ['Total menurut skrip', meta.total_seconds ?? meta.elapsed_seconds],
        ].filter(([, v]) => v !== undefined && v !== null);
        html += `
            <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center">
                <span>Hasil ${esc((data.versi || versi).toUpperCase())}</span>${statusBadge(data.status)}
            </div><div class="card-body">
                ${data.message || data.reason || data.error ? `<div class="alert alert-danger small">${esc(data.message || data.reason || data.error)}</div>` : ''}
                <table class="table table-sm mb-0" style="max-width: 520px;"><tbody>
                    ${waktu.map(([k, v]) => `<tr><td>${esc(k)}</td><td class="text-end"><b>${esc(v)}</b> dtk</td></tr>`).join('')}
                    ${meta.n_model_calls !== undefined ? `<tr><td>Jumlah pemanggilan model (kotak/blok)</td><td class="text-end"><b>${esc(meta.n_model_calls)}</b></td></tr>` : ''}
                    ${data.data?.n_kotak_ragu !== undefined ? `<tr><td>Kotak ditandai ragu (keyakinan &lt; 0,85)</td><td class="text-end"><b>${esc(data.data.n_kotak_ragu)}</b></td></tr>` : ''}
                </tbody></table>
            </div></div>`;

        if (data.data?.header) html += renderHeader(data.data, crops);

        for (const [shift, entry] of Object.entries(lostTimeOf(data))) {
            html += renderShift(shift, entry, crops, data.versi || versi);
        }

        if (crops.kanvas) {
            html += `<details class="mb-3"><summary>Kanvas formulir yang sudah diluruskan (E6)</summary>
                <img src="${crops.kanvas}" class="mt-2" style="max-width: 100%; border: 1px solid var(--border);"></details>`;
        }

        const rawCopy = Object.assign({}, data, { crops: `(${Object.keys(crops).length} gambar, disembunyikan)` });
        html += `<details class="mb-3"><summary>Respons mentah</summary><pre class="raw">${esc(JSON.stringify(rawCopy, null, 2))}</pre></details>`;
        el('result').innerHTML = html;
    }

    // Satukan bentuk Lost time E4/E5 (1 shift) & E6 (per shift) -> {shift: {status, blocks}}
    function lostTimeOf(data) {
        if (!data.data) return {};
        if (data.data.lost_time) return data.data.lost_time;
        if (data.data.blocks) return { [data.data.shift]: { status: data.status, blocks: data.data.blocks } };
        return {};
    }

    function renderHeader(d, crops) {
        const label = { tanggal: 'Tanggal', mesin: 'Mesin', shift: 'Shift', operator: 'Operator', size: 'Size', speed: 'Speed' };
        const rows = Object.entries(d.header).map(([name, f]) => {
            const info = [];
            if (f.kosong) info.push('kosong (tidak ada tulisan)');
            if (f.raw !== undefined && f.raw !== f.nilai) info.push(`mentah: "${f.raw}"`);
            if (f.keyakinan !== undefined) info.push(`keyakinan ${f.keyakinan}`);
            if (f.keyakinan_min !== undefined) info.push(`keyakinan digit terendah ${f.keyakinan_min}`);
            if (f.valid === false) info.push('⚠ tanggal tidak valid');
            if (f.hari_cocok_kalender === false) info.push('⚠ nama hari tidak cocok kalender');
            if (f.n_coretan_dibuang) info.push(`${f.n_coretan_dibuang} coretan dibuang`);
            const warn = f.valid === false || f.hari_cocok_kalender === false;
            return `<tr class="${warn ? 'table-warning' : ''}">
                <td>${esc(label[name] || name)}</td>
                <td><b>${f.nilai === null || f.nilai === undefined ? '<span class="text-muted">—</span>' : esc(f.nilai)}</b></td>
                <td>${crops['field_' + name] ? `<img class="field-img" src="${crops['field_' + name]}">` : ''}</td>
                <td class="small text-muted">${esc(info.join(' · '))}</td>
                <td class="text-end small">${f.elapsed_sec ?? 0} dtk</td>
            </tr>`;
        }).join('');
        return `<div class="card mb-3"><div class="card-header">Header &amp; Size/Speed
                <span class="small text-muted ms-2">shift di header: ${esc(d.shift_header ?? '-')} · shift dipakai: ${esc(d.shift_dipakai ?? '-')}</span></div>
            <div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0">
                <thead><tr><th>Field</th><th>Hasil</th><th>Potongan yg dibaca</th><th>Info</th><th class="text-end">Waktu</th></tr></thead>
                <tbody>${rows}</tbody></table></div></div></div>`;
    }

    function renderShift(shift, entry, crops, v) {
        const blocks = entry.blocks || [];
        let nTerisi = 0, nRagu = 0;
        const body = blocks.map(b => {
            const calls = Object.fromEntries((b.calls || []).map(c => [c.kolom, c]));
            const jam = b.jam || b.expected_jam_label || `Blok ${b.block_idx}`;
            const cells = (b.kotak || Array(6).fill(null)).map((val, i) => {
                const k = i + 1;
                const c = calls[k];
                if (val !== null) nTerisi++;
                if (c?.ragu) nRagu++;
                const cropKey = v === 'e6' ? `s${shift}_b${b.block_idx}_k${k}` : `b${b.block_idx}_k${k}`;
                const img = crops[cropKey] ? `<img src="${crops[cropKey]}">` : '';
                const top = c?.top5 ? c.top5.map(([kode, skor]) => `${kode} (${skor})`).join(', ') : '';
                const extra = c?.ragu ? `<div class="small">ragu: ${esc((c.kandidat_3 || []).join(' / '))}</div>`
                    : (c?.keyakinan !== undefined ? `<div class="small text-muted">${c.keyakinan}</div>` : '');
                return `<td class="kotak ${c?.ragu ? 'ragu' : ''}" title="${esc(top)}">${img}
                    ${val === null ? '<span class="text-muted">·</span>' : `<code>${esc(val)}</code>`}${extra}</td>`;
            }).join('');
            const blokImg = v === 'e5' && crops[`b${b.block_idx}`]
                ? `<tr><td></td><td colspan="6" class="text-start"><img class="blok" src="${crops['b' + b.block_idx]}">
                    <span class="small text-muted">teks mentah: "${esc(b.raw_text ?? '')}"</span></td></tr>` : '';
            return `<tr><th class="text-nowrap small">${esc(jam)}</th>${cells}</tr>${blokImg}`;
        }).join('');

        return `<div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center">
                <span>Lost time — Shift ${esc(shift)} <span class="small text-muted ms-2">${nTerisi} kotak terisi${nRagu ? ` · ${nRagu} ragu` : ''}</span></span>
                ${statusBadge(entry.status)}</div>
            <div class="card-body p-0"><div class="table-responsive">
                ${blocks.length ? `<table class="table table-sm table-bordered mb-0 grid-lt">
                    <thead><tr><th>Jam</th><th>1</th><th>2</th><th>3</th><th>4</th><th>5</th><th>6</th></tr></thead>
                    <tbody>${body}</tbody></table>` : `<p class="p-3 mb-0 text-muted">${esc(entry.reason || 'Baris tidak terbaca.')}</p>`}
            </div>
            <p class="small text-muted px-3 py-2 mb-0">Arahkan kursor ke kotak untuk melihat 5 kandidat teratas beserta skornya. Kotak kuning = keyakinan rendah (perlu dikonfirmasi).</p>
            </div></div>`;
    }
})();
</script>
@endpush
