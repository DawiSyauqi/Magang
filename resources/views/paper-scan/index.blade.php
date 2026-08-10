@extends('layouts.app') {{-- sesuaikan nama layout Blade utama Anda --}}

@section('content')
<div class="container py-3" style="max-width: 900px;">
    <h4 class="mb-3">Ambil Foto Kertas — Laporan Proses Drawing Harian</h4>

    {{-- ===================== STATE 1: IDLE (form upload) ===================== --}}
    <section id="state-idle">
        <div class="card">
            <div class="card-body text-center py-5">
                <input type="file" id="photoInput" accept="image/*" capture="environment" class="form-control mb-3">
                <button id="analyzeBtn" class="btn btn-primary btn-lg" disabled>Analisa Foto</button>
            </div>
        </div>
    </section>

    {{-- ===================== STATE 2: LOADING ===================== --}}
    <section id="state-loading" class="d-none text-center py-5">
        <div class="spinner-border text-primary mb-3" role="status"></div>
        <p>Sedang membaca kertas... (bisa sampai beberapa menit, mohon tunggu)</p>
    </section>

    {{-- ===================== STATE 3: RETAKE ===================== --}}
    <section id="state-retake" class="d-none">
        <div class="alert alert-warning">
            <p id="retakeMessage"></p>
            <button id="retakeBtn" class="btn btn-warning">Foto Ulang</button>
        </div>
    </section>

    {{-- ===================== STATE 4: PILIH SHIFT ===================== --}}
    <section id="state-shift" class="d-none">
        <div class="alert alert-info">
            <p>Shift tidak terbaca jelas dari foto. Pilih shift yang benar:</p>
            <select id="shiftSelect" class="form-select mb-2" style="max-width:200px;">
                <option value="1">Shift 1</option>
                <option value="2">Shift 2</option>
                <option value="3">Shift 3</option>
            </select>
            <button id="confirmShiftBtn" class="btn btn-primary">Lanjutkan</button>
        </div>
    </section>

    {{-- ===================== STATE 5: ERROR ===================== --}}
    <section id="state-error" class="d-none">
        <div class="alert alert-danger">
            <p id="errorMessage"></p>
            <button id="retryBtn" class="btn btn-secondary">Coba Lagi</button>
        </div>
    </section>

    {{-- ===================== STATE 6: REVIEW ===================== --}}
    <section id="state-review" class="d-none">
    <div class="card mb-3 border-primary">
        <div class="card-header bg-primary text-white">1. Konfirmasi Mesin</div>
        <div class="card-body">
            <p class="mb-1">Teks di kertas: <strong id="mesinRawText"></strong></p>
            <p class="text-muted small" id="mesinAlasan"></p>
            <div class="input-group">
                <select id="mesinSelect" class="form-select"></select>
                <button id="confirmMesinBtn" class="btn btn-success">Setuju / Simpan Pilihan</button>
            </div>
            <p id="mesinStatus" class="small mt-2"></p>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">2. Info Umum</div>
        <div class="card-body row g-3">
            <div class="col-md-3">
                <label class="form-label">Tanggal</label>
                <input type="date" id="tanggalInput" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label">Shift</label>
                <input type="text" id="shiftDisplay" class="form-control" readonly>
            </div>
            <div class="col-md-2">
                <label class="form-label">Speed (m/mnt)</label>
                <input type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" id="speedInput" class="form-control" placeholder="mis. 3.6">
            </div>
            <div class="col-md-2">
                <label class="form-label">Operator</label>
                <input type="text" id="operatorSearch" class="form-control" list="operatorList" placeholder="Cari NIK/nama...">
                <datalist id="operatorList"></datalist>
                <input type="hidden" id="operatorSelect">
                <p id="operatorStatus" class="small mt-1"></p>
            </div>
            <div class="col-md-3">
                <label class="form-label">Nomor Item / Produk</label>
                <input type="text" id="itemSearch" class="form-control" list="itemList" placeholder="-- konfirmasi Mesin dulu --" disabled>
                <datalist id="itemList"></datalist>
                <input type="hidden" id="itemSelect">
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>3. Daftar Downtime Terbaca (<span id="rowCount">0</span> baris)</span>
            <button id="addRowBtn" class="btn btn-sm btn-outline-primary" type="button">+ Tambah Baris</button>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>No</th><th>Jam Mulai</th><th>Jam Selesai</th><th>Menit</th>
                        <th>Kategori</th><th>Detail Masalah</th><th>Status</th><th></th><th></th>
                    </tr>
                </thead>
                <tbody id="rowsTableBody"></tbody>
            </table>
        </div>
    </div>

    <div id="saveResultBox" class="alert d-none mb-3"></div>

    <button id="saveAllBtn" class="btn btn-success btn-lg" disabled>Simpan Semua</button>
</section>

</div>
@endsection

@push('scripts')
<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    let currentToken = null;
    let currentData = null; // {header, rows} hasil analyze sukses
    let mesinOptionsCache = null;
    let operatorOptionsCache = null;

    const el = (id) => document.getElementById(id);
    const states = ['idle', 'loading', 'retake', 'shift', 'error', 'review'];
    function showState(name) {
        states.forEach((s) => el(`state-${s}`).classList.toggle('d-none', s !== name));
    }

    async function apiPost(url, body, isJson = true) {
        const headers = { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' };
        if (isJson) headers['Content-Type'] = 'application/json';
        const res = await fetch(url, { method: 'POST', headers, body: isJson ? JSON.stringify(body) : body });
        return res.json();
    }

    async function apiGet(url) {
        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
        return res.json();
    }

    function handlePipelineResult(data) {
        if (data.status === 'needs_retake') {
            el('retakeMessage').textContent = data.message;
            showState('retake');
        } else if (data.status === 'needs_shift_confirmation') {
            currentToken = data.token;
            showState('shift');
        } else if (data.status === 'error') {
            el('errorMessage').textContent = data.message;
            showState('error');
        } else if (data.status === 'success') {
            currentData = data;
            renderReview(data);
            showState('review');
        }
    }

    el('addRowBtn').addEventListener('click', () => {
        const tanggal = currentData.header.tanggal_parsed || el('tanggalInput').value || null;
        const shift = currentData.header.shift || null;

        currentData.rows.push({
            Tgl_Trs: tanggal,
            ShiftCode: shift,
            Time_Start: null,
            Time_End: null,
            Time_Total: null,
            MesinCode: currentData.header.mesin_code_resolved || null,
            NIK: el('operatorSelect').value || null,
            Speed_Mesin: el('speedInput').value || null,
            ProblemCode: null,
            Problem_Desc: null,
            ITEMNO: el('itemSelect').value || null,
            _raw_code: '(manual)',
            _review: {
                perlu_review: true,
                alasan: ['Baris ditambahkan manual oleh petugas -- belum diisi.'],
                problem_code_debug: null,
            },
        });

        renderRows(currentData.rows);
        updateSaveButtonState();
    });

    // ---------- STATE 1: upload ----------
    el('photoInput').addEventListener('change', () => {
        el('analyzeBtn').disabled = !el('photoInput').files.length;
    });

    el('analyzeBtn').addEventListener('click', async () => {
        const fd = new FormData();
        fd.append('photo', el('photoInput').files[0]);
        showState('loading');
        try {
            const data = await apiPost('/paper-scan/analyze', fd, false);
            handlePipelineResult(data);
        } catch (e) {
            el('errorMessage').textContent = 'Tidak bisa menghubungi server. Cek koneksi internet.';
            showState('error');
        }
    });

    // ---------- STATE 3: retake ----------
    el('retakeBtn').addEventListener('click', () => {
        el('photoInput').value = '';
        el('analyzeBtn').disabled = true;
        showState('idle');
    });

    // ---------- STATE 4: shift ----------
    el('confirmShiftBtn').addEventListener('click', async () => {
        showState('loading');
        const data = await apiPost('/paper-scan/analyze/confirm-shift', {
            token: currentToken, shift: el('shiftSelect').value,
        });
        handlePipelineResult(data);
    });

    // ---------- STATE 5: error ----------
    el('retryBtn').addEventListener('click', () => {
        el('photoInput').value = '';
        el('analyzeBtn').disabled = true;
        showState('idle');
    });

    // ---------- STATE 6: review ----------
    // ---------- STATE 6: review (GANTI seluruh blok lama dgn ini) ----------
    async function renderReview(data) {
        const h = data.header;

        el('mesinRawText').textContent = h.mesin_raw ?? '(tidak terbaca)';
        el('mesinAlasan').textContent = h.mesin_resolution.alasan ?? '';

        if (!mesinOptionsCache) mesinOptionsCache = await apiGet('/referensi/mesin');
        const mesinSelect = el('mesinSelect');
        mesinSelect.innerHTML = '<option value="">-- pilih mesin --</option>' +
            mesinOptionsCache.map((m) => `<option value="${m.kode}">${m.kode} — ${m.nama}</option>`).join('');
        if (h.mesin_resolution.resrceno) mesinSelect.value = h.mesin_resolution.resrceno;

        if (h.mesin_resolution.dikonfirmasi) {
            setMesinConfirmedUI('✓ Sudah dikonfirmasi sebelumnya (dari alias).', true);
            applyMesinToAllRows(h.mesin_resolution.resrceno);
            await loadItemOptions(h.mesin_resolution.resrceno);
        } else {
            el('mesinStatus').textContent = h.mesin_resolution.sumber === 'ai'
                ? '⚠ Ini tebakan AI, wajib diperiksa & disetujui.'
                : '⚠ Tidak ada tebakan otomatis, wajib dipilih manual.';
            el('mesinStatus').className = 'small mt-2 text-warning';
        }

        el('tanggalInput').value = h.tanggal_parsed ?? '';
        el('shiftDisplay').value = 'Shift ' + (h.shift ?? '-');
        el('speedInput').value = h.speed ?? '';

        // GANTI bagian operator di renderReview():
        if (!operatorOptionsCache) operatorOptionsCache = await apiGet('/referensi/operator');
        const operatorList = el('operatorList');
        operatorList.innerHTML = operatorOptionsCache.map((o) =>
            `<option data-nik="${o.nik}" value="${o.nama} (${o.nik})"></option>`
        ).join('');
        if (h.operator_match.nik) {
            const matched = operatorOptionsCache.find(o => o.nik === h.operator_match.nik);
            if (matched) {
                el('operatorSearch').value = `${matched.nama} (${matched.nik})`;
                el('operatorSelect').value = matched.nik;
            }
        }

        renderRows(data.rows);
        updateSaveButtonState();
    }

    function setMesinConfirmedUI(msg, ok) {
        el('mesinStatus').textContent = msg;
        el('mesinStatus').className = 'small mt-2 ' + (ok ? 'text-success' : 'text-warning');
    }

    function applyMesinToAllRows(resrceno) {
        currentData.rows.forEach((r) => { r.MesinCode = resrceno; });
        currentData.header.mesin_code_resolved = resrceno;
    }

    function applyItemToAllRows(itemno) {
        currentData.rows.forEach((r) => { r.ITEMNO = itemno; });
    }

    // GANTI loadItemOptions():
    async function loadItemOptions(mesinCode) {
        const itemSearch = el('itemSearch');
        const itemList = el('itemList');
        if (!mesinCode) {
            itemSearch.placeholder = '-- konfirmasi Mesin dulu --';
            itemSearch.disabled = true;
            itemSearch.value = '';
            el('itemSelect').value = '';
            return;
        }
        const items = await apiGet(`/referensi/item?mesin=${encodeURIComponent(mesinCode)}`);
        itemList.innerHTML = items.map((i) =>
            `<option data-kode="${i.kode}" value="${i.kode} — ${i.nama}"></option>`
        ).join('');
        itemSearch.disabled = false;
        itemSearch.placeholder = 'Cari kode/nama item...';
    }

    el('confirmMesinBtn').addEventListener('click', async () => {
        const resrceno = el('mesinSelect').value;
        if (!resrceno) { alert('Pilih mesin dulu.'); return; }

        await apiPost('/paper-scan/confirm-mesin', {
            raw_text: currentData.header.mesin_raw,
            resrceno_terpilih: resrceno,
        });

        setMesinConfirmedUI('✓ Tersimpan sebagai alias untuk lain kali.', true);
        applyMesinToAllRows(resrceno);
        await loadItemOptions(resrceno);
        updateSaveButtonState();
    });

    
    el('tanggalInput').addEventListener('change', updateSaveButtonState);
    el('speedInput').addEventListener('change', updateSaveButtonState);
    el('operatorSearch').addEventListener('input', () => {
        const match = operatorOptionsCache?.find(o => `${o.nama} (${o.nik})` === el('operatorSearch').value);
        el('operatorSelect').value = match ? match.nik : '';
        updateSaveButtonState();
    });

    el('itemSearch').addEventListener('input', async () => {
        const opts = el('itemList').querySelectorAll('option');
        const match = Array.from(opts).find(o => o.value === el('itemSearch').value);
        const kode = match ? match.dataset.kode : '';
        el('itemSelect').value = kode;
        if (kode) {
            applyItemToAllRows(kode);
        }
        updateSaveButtonState();
    });


    function updateSaveButtonState() {
        const rowsReady = currentData.rows.every(r =>
            r.ProblemCode && r.Problem_Desc && r.Time_Start && r.Time_End
        );
        const ready = currentData.rows.length > 0 && rowsReady
            && el('mesinSelect').value && el('itemSelect').value
            && el('tanggalInput').value && el('operatorSelect').value;
        el('saveAllBtn').disabled = !ready;
    }
    let kategoriOptionsCache = null;

    async function renderRows(rows) {
        el('rowCount').textContent = rows.length;
        if (!kategoriOptionsCache) kategoriOptionsCache = await apiGet('/referensi/problem-kategori');

        el('rowsTableBody').innerHTML = rows.map((row, idx) => {
            const perluReview = row._review.perlu_review;
            const alasanTitle = (row._review.alasan || []).join(' | ');
            const jamMulai = row.Time_Start ? row.Time_Start.substring(11, 16) : '';
            const jamSelesai = row.Time_End ? row.Time_End.substring(11, 16) : '';
            const kategoriOpts = '<option value="">-- pilih --</option>' +
                kategoriOptionsCache.map(k =>
                    `<option value="${k.kode}" ${row.ProblemCode === k.kode ? 'selected' : ''}>${k.kode} — ${k.nama}</option>`
                ).join('');

            return `
                <tr class="${perluReview ? 'table-danger' : ''}" data-idx="${idx}" title="${alasanTitle}">
                    <td>${idx + 1}</td>
                    <td><input type="time" class="form-control form-control-sm js-time-start" data-idx="${idx}" value="${jamMulai}"></td>
                    <td><input type="time" class="form-control form-control-sm js-time-end" data-idx="${idx}" value="${jamSelesai}"></td>
                    <td>${row.Time_Total ?? '-'}</td>
                    <td>
                        <select class="form-select form-select-sm js-row-kategori" data-idx="${idx}">${kategoriOpts}</select>
                        <small class="text-muted"><code>${row._raw_code}</code></small>
                    </td>
                    <td>
                        <select class="form-select form-select-sm js-row-detail" data-idx="${idx}" ${row.ProblemCode ? '' : 'disabled'}>
                            <option value="">-- pilih kategori dulu --</option>
                        </select>
                    </td>
                    <td>${perluReview ? '<span class="badge bg-danger">Perlu Review</span>' : '<span class="badge bg-success">OK</span>'}</td>
                    <td><button class="btn btn-sm btn-outline-danger delete-row-btn" data-idx="${idx}">✕</button></td>
                    <td><button class="btn btn-sm btn-outline-secondary btn-preview-img" type="button">🖼</button></td>
                </tr>`;
        }).join('');

        // preload detail utk baris yg sudah punya ProblemCode dari AI
        for (const row of rows) {
            if (row.ProblemCode) await loadRowDetailOptions(rows.indexOf(row), row.ProblemCode, row.Problem_Desc);
        }

        bindRowEvents(rows);
    }

    el('rowsTableBody').addEventListener('click', (e) => {
        if (!e.target.classList.contains('btn-preview-img')) return;
        if (!currentData.preview_token) {
            alert('Foto tidak tersedia untuk ditinjau.');
            return;
        }
        window.open(`/paper-scan/preview/${currentData.preview_token}`, '_blank');
    });

    async function loadRowDetailOptions(idx, kategoriKode, selectedDesc = null) {
        const detailSelect = document.querySelector(`.js-row-detail[data-idx="${idx}"]`);
        if (!kategoriKode) {
            detailSelect.innerHTML = '<option value="">-- pilih kategori dulu --</option>';
            detailSelect.disabled = true;
            return;
        }
        const details = await apiGet(`/referensi/problem-detail?kategori=${encodeURIComponent(kategoriKode)}`);
        detailSelect.innerHTML = '<option value="">-- pilih detail --</option>' +
            details.map(d => `<option value="${d.kode}" data-desc="${d.nama}" ${d.nama === selectedDesc ? 'selected' : ''}>${d.nama}</option>`).join('');
        detailSelect.disabled = false;
    }

    function bindRowEvents(rows) {
        document.querySelectorAll('.js-time-start, .js-time-end').forEach(inp => {
            inp.addEventListener('change', () => {
                const idx = parseInt(inp.dataset.idx, 10);
                const field = inp.classList.contains('js-time-start') ? 'Time_Start' : 'Time_End';
                const existing = currentData.rows[idx][field];
                const tgl = (existing ? existing.substring(0, 10) : null)
                    || currentData.header.tanggal_parsed
                    || el('tanggalInput').value;
                currentData.rows[idx][field] = `${tgl} ${inp.value}:00`;

                // Hitung ulang Time_Total kalau kedua jam sudah terisi (mis. baris manual)
                const row = currentData.rows[idx];
                if (row.Time_Start && row.Time_End) {
                    const start = new Date(row.Time_Start.replace(' ', 'T'));
                    const end = new Date(row.Time_End.replace(' ', 'T'));
                    const diffMinutes = Math.round((end - start) / 60000);
                    row.Time_Total = diffMinutes > 0 ? diffMinutes : null;
                    renderRows(currentData.rows); // re-render supaya kolom Menit ter-update
                    return; // renderRows sudah re-bind event, hindari double update
                }

                updateSaveButtonState();
            });
        });

        document.querySelectorAll('.js-row-kategori').forEach(sel => {
            sel.addEventListener('change', async () => {
                const idx = parseInt(sel.dataset.idx, 10);
                currentData.rows[idx].ProblemCode = sel.value || null;
                currentData.rows[idx].Problem_Desc = null; // reset, wajib pilih detail lagi
                await loadRowDetailOptions(idx, sel.value);
                updateSaveButtonState();
            });
        });

        document.querySelectorAll('.js-row-detail').forEach(sel => {
            sel.addEventListener('change', () => {
                const idx = parseInt(sel.dataset.idx, 10);
                const opt = sel.options[sel.selectedIndex];
                currentData.rows[idx].Problem_Desc = opt ? (opt.dataset.desc || null) : null;
                updateSaveButtonState();
            });
        });

        document.querySelectorAll('.delete-row-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                currentData.rows.splice(parseInt(btn.dataset.idx, 10), 1);
                renderRows(currentData.rows);
            });
        });
    }

    el('saveAllBtn').addEventListener('click', async () => {
        // propagasi field header yg mungkin diedit user ke SEMUA baris sebelum kirim
        const tanggal = el('tanggalInput').value;
        const speed = el('speedInput').value;
        const nik = el('operatorSelect').value;
        currentData.rows.forEach((r) => {
            r.Tgl_Trs = tanggal;
            r.Speed_Mesin = speed;
            r.NIK = nik;
        });

        el('saveAllBtn').disabled = true;
        el('saveAllBtn').textContent = 'Menyimpan...';

        const result = await apiPost('/paper-scan/store', { rows: currentData.rows });

        const box = el('saveResultBox');
        box.classList.remove('d-none', 'alert-success', 'alert-warning');
        if (result.gagal === 0) {
            box.classList.add('alert-success');
            box.textContent = `✓ Semua ${result.berhasil} baris berhasil disimpan.`;
        } else {
            box.classList.add('alert-warning');
            box.textContent = `${result.berhasil} baris tersimpan, ${result.gagal} baris GAGAL (lihat console untuk detail).`;
            console.log('Detail baris gagal:', result.failed);
        }

        el('saveAllBtn').textContent = 'Simpan Semua';
        el('saveAllBtn').disabled = false;
    });
})();
</script>
@endpush