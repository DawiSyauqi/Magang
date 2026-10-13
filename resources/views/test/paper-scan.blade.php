@extends('layouts.app') {{-- sesuaikan nama layout Blade utama Anda --}}

@section('content')
@push('styles')
<style>
    :root {
        --ir-accent: var(--text);
        --ir-accent-hover: var(--n-800);
        --ir-accent-light: var(--surface-alt);
    }
    .ir-card { 
        border: 1px solid var(--border); 
        border-radius: var(--r-lg); 
        background: var(--surface);
    }
    .ir-btn-primary {
        background-color: var(--text); border-color: var(--text);
        border-radius: 999px; color: var(--bg); font-weight: 600;
        transition: background-color .15s ease, border-color .15s ease, transform .15s ease;
    }
    .ir-btn-primary:hover { 
        background-color: var(--n-800); border-color: var(--n-800); 
        color: var(--bg);
    }
    :root[data-theme="dark"] .ir-btn-primary {
        background-color: var(--n-0); border-color: var(--n-0); color: var(--n-900);
    }
    :root[data-theme="dark"] .ir-btn-primary:hover {
        background-color: var(--n-200); border-color: var(--n-200); color: var(--n-900);
    }
    .ir-btn-secondary {
        background-color: var(--surface); border: 1.5px solid var(--border);
        border-radius: 999px; color: var(--text); font-weight: 600;
        transition: background-color .15s ease, border-color .15s ease, transform .15s ease;
    }
    .ir-btn-secondary:hover:not(:disabled) { 
        background-color: var(--surface-alt); border-color: var(--text-muted); 
        color: var(--text);
    }
    .ir-btn-secondary:disabled {
        opacity: 0.45; cursor: not-allowed;
    }
    .ir-badge { border-radius: 999px; padding: .3rem .7rem; font-size: .75rem; font-weight: 600; }
    .ir-badge-review { background-color: rgba(245, 158, 11, 0.12); color: var(--warning); }
    .ir-badge-ok { background-color: rgba(34, 197, 94, 0.12); color: var(--success); }
    .ir-icon-circle {
        width: 48px; height: 48px; border-radius: 50%;
        background-color: var(--surface-alt); color: var(--text);
        display: inline-flex; align-items: center; justify-content: center; font-size: 1.4rem;
        border: 1px solid var(--border);
    }
    .table-row-review {
        background-color: rgba(239, 68, 68, 0.04) !important;
    }
    :root[data-theme="dark"] .table-row-review {
        background-color: rgba(239, 68, 68, 0.08) !important;
    }
</style>
@endpush
<div class="d-flex align-items-center justify-content-between px-3 px-md-4 py-2 border-bottom" style="background: var(--surface); transition: background-color .2s ease;">
    <span class="fw-semibold small" style="color: var(--text);">MODE UJI — tanpa login &amp; tanpa database</span>
    <div class="d-flex align-items-center gap-3">
        @include('layouts.partials._theme-toggle')
        <span class="text-muted small d-none d-md-inline">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 4px; color: var(--text-muted);">
                <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
            </svg>
            AI Baca Kertas
        </span>
    </div>
</div>
<div class="container py-3" style="max-width: 900px;">
    <h4 class="mb-2">Ambil Foto Kertas — Laporan Proses Drawing Harian</h4>
    <div class="alert alert-warning small mb-3">
        Halaman uji bagian AI saja. Salinan dari <code>paper-scan/index</code>, tetapi tidak terhubung ke database:
        mesin, operator, item, dan kategori masalah tidak dicocokkan ke dropdown — hasil bacaan ditampilkan sebagai teks,
        dan tidak ada tombol simpan.
    </div>

    {{-- ===================== STATE 1: IDLE (form upload) ===================== --}}
    
    <section id="state-main">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>1. Konfirmasi Mesin & Info Umum</span>
                <span id="section1-status-badge"></span>
            </div>
            <div class="card-body">
                <div class="text-center mb-3">
                    <div class="d-flex justify-content-center align-items-center gap-2 flex-wrap mb-2">
                        <button type="button" id="btn-open-camera-s1" class="btn ir-btn-primary">
                            📷 Ambil Foto (Header + Speed/Size)
                        </button>
                        <button type="button" id="btn-pick-file-s1" class="btn ir-btn-secondary">
                            📁 Ambil File
                        </button>
                    </div>
                    <input type="file" id="photoInputS1" accept="image/*" capture="environment" class="d-none">
                    <input type="file" id="fileInputS1" accept="image/*" class="d-none">
                    <div id="section1-loading" class="d-none mt-2">
                        <div class="spinner-border spinner-border-sm" style="color: var(--ir-accent);"></div>
                        Menganalisa...
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Tanggal</label>
                        <input type="date" id="tanggalInput" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Shift</label>
                        <select id="shiftSelectManual" class="form-select">
                            <option value="">-- pilih --</option>
                            <option value="1">Shift 1</option>
                            <option value="2">Shift 2</option>
                            <option value="3">Shift 3</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Speed (m/mnt)</label>
                        <input type="text" inputmode="decimal" id="speedInput" class="form-control">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Mesin (teks di kertas)</label>
                        <input type="text" id="mesinRawInput" class="form-control">
                        <p id="mesinStatus" class="small mt-1 text-muted"></p>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Size (teks di kertas)</label>
                        <input type="text" id="sizeRawInput" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Operator (teks di kertas)</label>
                        <input type="text" id="operatorRawInput" class="form-control" placeholder="Section 1 saat ini tidak membaca operator">
                    </div>
                </div>
                <p id="section1-time" class="small text-muted mt-3 mb-1"></p>
                <details class="small">
                    <summary>Respons mentah server (Section 1)</summary>
                    <pre id="section1-raw" class="mb-0" style="white-space: pre-wrap;"></pre>
                </details>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>2. Downtime Grid</span>
                <span id="section2-status-badge"></span>
            </div>
            <div class="card-body">
                <div class="text-center mb-3">
                    <div class="d-flex justify-content-center align-items-center gap-2 flex-wrap mb-2">
                        <button type="button" id="btn-open-camera-s2" class="btn ir-btn-primary" disabled>
                            📷 Ambil Foto Grid Downtime
                        </button>
                        <button type="button" id="btn-pick-file-s2" class="btn ir-btn-secondary" disabled>
                            📁 Ambil File
                        </button>
                    </div>
                    <p id="section2-lock-note" class="small text-muted mt-1">Isi Shift dulu di Section 1 sebelum ambil foto grid.</p>
                    <input type="file" id="photoInputS2" accept="image/*" capture="environment" class="d-none">
                    <input type="file" id="fileInputS2" accept="image/*" class="d-none">
                    <div id="section2-loading" class="d-none mt-2">
                        <div class="spinner-border spinner-border-sm" style="color: var(--ir-accent);"></div>
                        Menganalisa...
                    </div>
                </div>

                <p id="section2-time" class="small text-muted mb-1"></p>
                <details class="small mb-3">
                    <summary>Grid terbaca per kotak (Section 2)</summary>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0 text-center">
                            <thead><tr><th>Jam</th><th>1</th><th>2</th><th>3</th><th>4</th><th>5</th><th>6</th></tr></thead>
                            <tbody id="gridRawBody"><tr><td colspan="7" class="text-muted">Belum ada.</td></tr></tbody>
                        </table>
                    </div>
                </details>

                <div id="section2-table-wrapper">
                    <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>No</th><th>Jam Mulai</th><th>Jam Selesai</th><th>Menit</th>
                                <th>Kode Kertas</th><th>ProblemCode</th><th>ProblemCodeD</th><th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="rowsTableBody"></tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <section id="state-error" class="d-none">
        <div class="alert alert-danger">
            <p id="errorMessage"></p>
            <button id="retryBtn" class="btn btn-secondary">Tutup</button>
        </div>
    </section>

    <!-- Overlay crop-rectangle KHUSUS close-up grid, tampil SEBELUM overlay 3-titik -->
    <div id="rect-crop-overlay" style="display:none; position:fixed; inset:0; z-index:10000; background:#000;">
        <canvas id="rect-crop-canvas" style="width:100%; height:100%; touch-action:none; display:block;"></canvas>
        <p style="position:absolute; top:16px; left:0; right:0; text-align:center; color:white; font-size:14px;">
            Geser & perbesar kotak untuk memilih area grid yang ingin dipotong
        </p>
        <div style="position:absolute; bottom:16px; left:0; right:0; display:flex; justify-content:center; gap:12px;">
            <button type="button" id="btn-rect-cancel" class="btn btn-outline-light">Batal</button>
            <button type="button" id="btn-rect-confirm" class="btn btn-success btn-lg">✓ Lanjut Tandai Sudut</button>
        </div>
    </div>

    <!-- Overlay kamera custom -->
    <div id="camera-overlay" style="display:none; position:fixed; inset:0; z-index:9999; background:#000;">
        <div id="rotate-prompt" style="display:none; position:absolute; inset:0; flex-direction:column;
            align-items:center; justify-content:center; color:white; text-align:center;">
            <div style="font-size:64px;">🔄</div>
            <p style="font-size:20px; margin-top:16px;">Putar HP Anda ke posisi mendatar<br>untuk memotret kertas dengan hasil terbaik</p>
        </div>
        <div id="camera-active-area" style="display:none; position:relative; width:100%; height:100%;">
            <video id="camera-video" autoplay playsinline style="width:100%; height:100%; object-fit:cover;"></video>
            <canvas id="viewfinder-overlay" style="position:absolute; top:0; left:0; width:100%; height:100%; pointer-events:none;"></canvas>
            <button type="button" id="btn-capture" style="position:absolute; bottom:24px; left:50%;
                    transform:translateX(-50%); width:70px; height:70px; border-radius:50%;
                    background:white; border:4px solid #ccc;"></button>
            <button type="button" id="btn-cancel-camera" style="position:absolute; top:16px; right:16px;
                    color:white; background:none; border:none; font-size:28px;">✕</button>
        </div>
        <canvas id="capture-canvas" style="display:none;"></canvas>
    </div>

    <!-- Overlay penyesuaian 4-titik/3-titik sudut, khusus close-up grid -->
    <div id="corner-adjust-overlay" style="display:none; position:fixed; inset:0; z-index:10000; background:#000;">
        <canvas id="corner-adjust-canvas" style="width:100%; height:100%; touch-action:none; display:block;"></canvas>
        <p style="position:absolute; top:16px; left:0; right:0; text-align:center; color:white; font-size:14px;">
            Cubit layar (pinch) untuk perbesar, geser 1 jari untuk geser tampilan,<br>lalu tarik 3 titik ke sudut baris grid yang gagal
        </p>
        <div style="position:absolute; bottom:16px; left:0; right:0; display:flex; justify-content:center; gap:12px;">
            <button type="button" id="btn-corner-cancel" class="btn btn-outline-light">Batal</button>
            <button type="button" id="btn-corner-confirm" class="btn btn-success btn-lg">✓ Sudah Pas</button>
        </div>
    </div>


</div>
@endsection

@push('scripts')
<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const URL_SECTION1 = @json(route('test.paper-scan.section1'));
    const URL_SECTION2 = @json(route('test.paper-scan.section2'));
    const URL_FINALIZE = @json(route('test.paper-scan.finalize'));
    let section1Data = null; // hasil sukses section 1 (atau null kalau manual semua)
    let section2GridWaktu = null; // hasil sukses section 2
    let currentRows = []; // hasil finalize() -- baris siap review

    const el = (id) => document.getElementById(id);

    async function apiPost(url, body, isJson = true) {
        const headers = { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' };
        if (isJson) headers['Content-Type'] = 'application/json';
        const res = await fetch(url, { method: 'POST', headers, body: isJson ? JSON.stringify(body) : body });
        return res.json();
    }

    function showError(msg) {
        el('errorMessage').textContent = msg;
        el('state-main').classList.add('d-none');
        el('state-error').classList.remove('d-none');
    }
    el('retryBtn').addEventListener('click', () => {
        el('state-error').classList.add('d-none');
        el('state-main').classList.remove('d-none');
    });

    // ================== SECTION 1: kamera + submit ==================
    let cameraStream = null;
    let cameraCaptureTarget = null; // 's1' | 's2'
    let rectCropState = null;
    let cornerAdjustState = null;

    el('btn-open-camera-s1').addEventListener('click', () => openCameraFor('s1'));
    el('btn-open-camera-s2').addEventListener('click', () => openCameraFor('s2'));
    el('btn-pick-file-s1').addEventListener('click', () => el('fileInputS1').click());
    el('btn-pick-file-s2').addEventListener('click', () => el('fileInputS2').click());

    function openCameraFor(target) {
        cameraCaptureTarget = target;
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            (target === 's1' ? el('photoInputS1') : el('photoInputS2')).click();
            return;
        }
        el('camera-overlay').style.display = 'block';
        checkOrientationAndProceed();
    }

    /**
     * Sama seperti checkOrientationAndProceed (kamera), tapi generik --
     * dipakai jg utk overlay crop-rectangle & corner-adjust (poin 2 Anda:
     * proses crop/wrap sebaiknya di landscape).
     */
    function ensureLandscapeThenRun(callback) {
        function isLandscapeNow() {
            return screen.orientation
                ? screen.orientation.type.startsWith('landscape')
                : window.innerWidth > window.innerHeight;
        }

        // PATCH: selalu tunda 1 frame + sedikit delay sebelum menjalankan
        // callback (walau SUDAH landscape saat fungsi ini dipanggil) --
        // browser (terutama iOS) kadang laporkan innerWidth/innerHeight
        // yg BELUM ter-update tepat saat event orientationchange/resize
        // menyala, menyebabkan canvas dihitung dgn ukuran salah/nol
        // (gambar jadi tampak blank krn digambar di luar area terlihat).
        function runWhenSettled() {
            requestAnimationFrame(() => {
                setTimeout(callback, 100);
            });
        }

        if (isLandscapeNow()) {
            runWhenSettled();
            return;
        }

        const promptEl = document.createElement('div');
        promptEl.id = 'landscape-prompt-generic';
        promptEl.style.cssText = 'position:fixed; inset:0; z-index:10001; background:#000; display:flex; flex-direction:column; align-items:center; justify-content:center; color:white; text-align:center;';
        promptEl.innerHTML = '<div style="font-size:64px;">🔄</div><p style="font-size:20px; margin-top:16px;">Putar HP Anda ke posisi mendatar<br>untuk menandai sudut grid dengan hasil terbaik</p>';
        document.body.appendChild(promptEl);

        function onChange() {
            if (isLandscapeNow()) {
                promptEl.remove();
                window.removeEventListener('orientationchange', onChange);
                window.removeEventListener('resize', onChange);
                runWhenSettled();
            }
        }
        window.addEventListener('orientationchange', onChange);
        window.addEventListener('resize', onChange);
    }

    function checkOrientationAndProceed() {
        const isLandscape = screen.orientation
            ? screen.orientation.type.startsWith('landscape')
            : window.innerWidth > window.innerHeight;
        if (!isLandscape) {
            el('rotate-prompt').style.display = 'flex';
            el('camera-active-area').style.display = 'none';
            window.addEventListener('orientationchange', onOrientationChange);
            window.addEventListener('resize', onOrientationChange);
        } else {
            startCameraStream();
        }
    }

    /**
     * KHUSUS overlay wrapping (corner-adjust/3-titik) -- dipaksa landscape
     * (poin Anda: wrapping tetap landscape). TIDAK dipakai di rect-crop
     * (itu tetap portrait, supaya gambar tidak melar/distorsi tampilan).
     */
    function ensureLandscapeThenRun(callback) {
        const isLandscape = screen.orientation
            ? screen.orientation.type.startsWith('landscape')
            : window.innerWidth > window.innerHeight;
        if (isLandscape) { callback(); return; }

        const promptEl = document.createElement('div');
        promptEl.id = 'landscape-prompt-generic';
        promptEl.style.cssText = 'position:fixed; inset:0; z-index:10001; background:#000; display:flex; flex-direction:column; align-items:center; justify-content:center; color:white; text-align:center;';
        promptEl.innerHTML = '<div style="font-size:64px;">🔄</div><p style="font-size:20px; margin-top:16px;">Putar HP Anda ke posisi mendatar<br>untuk menandai sudut grid dengan hasil terbaik</p>';
        document.body.appendChild(promptEl);

        function onChange() {
            const nowLandscape = screen.orientation
                ? screen.orientation.type.startsWith('landscape')
                : window.innerWidth > window.innerHeight;
            if (nowLandscape) {
                promptEl.remove();
                window.removeEventListener('orientationchange', onChange);
                window.removeEventListener('resize', onChange);
                callback();
            }
        }
        window.addEventListener('orientationchange', onChange);
        window.addEventListener('resize', onChange);
    }

    function onOrientationChange() {
        const isLandscape = screen.orientation
            ? screen.orientation.type.startsWith('landscape')
            : window.innerWidth > window.innerHeight;
        if (isLandscape) {
            el('rotate-prompt').style.display = 'none';
            window.removeEventListener('orientationchange', onOrientationChange);
            window.removeEventListener('resize', onOrientationChange);
            startCameraStream();
        }
    }
    async function startCameraStream() {
        try {
            cameraStream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment', width: { ideal: 1920 }, height: { ideal: 1080 } }
            });
            const video = el('camera-video');
            video.srcObject = cameraStream;
            el('camera-active-area').style.display = 'block';
            drawViewfinderGuide();
        } catch (err) {
            closeCameraOverlay();
            (cameraCaptureTarget === 's1' ? el('photoInputS1') : el('photoInputS2')).click();
        }
    }
    function drawViewfinderGuide() {
        const canvas = el('viewfinder-overlay');
        const video = el('camera-video');
        function resize() {
            canvas.width = video.clientWidth;
            canvas.height = video.clientHeight;
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            const isS2 = cameraCaptureTarget === 's2';
            const targetRatio = isS2 ? 2.5 : 1.4;
            const guideText = isS2 ? 'Foto area grid downtime (boleh sedikit longgar)' : 'Posisikan seluruh kertas di dalam kotak';
            let boxW = canvas.width * 0.9;
            let boxH = boxW / targetRatio;
            if (boxH > canvas.height * 0.85) { boxH = canvas.height * 0.85; boxW = boxH * targetRatio; }
            const boxX = (canvas.width - boxW) / 2, boxY = (canvas.height - boxH) / 2;
            ctx.strokeStyle = 'rgba(0, 255, 100, 0.9)';
            ctx.lineWidth = 3; ctx.setLineDash([12, 8]);
            ctx.strokeRect(boxX, boxY, boxW, boxH);
            ctx.setLineDash([]);
            ctx.fillStyle = 'rgba(0, 255, 100, 0.9)';
            ctx.font = '16px sans-serif';
            ctx.fillText(guideText, boxX, boxY - 10);
        }
        resize();
        window.addEventListener('resize', resize);
    }
    function closeCameraOverlay() {
        if (cameraStream) { cameraStream.getTracks().forEach(t => t.stop()); cameraStream = null; }
        el('camera-overlay').style.display = 'none';
        el('rotate-prompt').style.display = 'none';
        el('camera-active-area').style.display = 'none';
    }
    el('btn-cancel-camera').addEventListener('click', closeCameraOverlay);

    el('btn-capture').addEventListener('click', () => {
        const video = el('camera-video');
        const canvas = el('capture-canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        canvas.toBlob((blob) => {
            const file = new File([blob], `capture_${Date.now()}.jpg`, { type: 'image/jpeg' });
            closeCameraOverlay();
            if (cameraCaptureTarget === 's1') {
                submitSection1(file);
            } else {
                showRectCropUI(file); // S2: crop-rectangle DULU (poin 1), baru 3-titik
            }
        }, 'image/jpeg', 0.92);
    });

    el('photoInputS1').addEventListener('change', () => {
        const f = el('photoInputS1').files[0];
        if (f) { submitSection1(f); el('photoInputS1').value = ''; }
    });
    el('fileInputS1').addEventListener('change', () => {
        const f = el('fileInputS1').files[0];
        if (f) { submitSection1(f); el('fileInputS1').value = ''; }
    });
    el('photoInputS2').addEventListener('change', () => {
        const f = el('photoInputS2').files[0];
        if (f) { showRectCropUI(f); el('photoInputS2').value = ''; }
    });
    el('fileInputS2').addEventListener('change', () => {
        const f = el('fileInputS2').files[0];
        if (f) { showRectCropUI(f); el('fileInputS2').value = ''; }
    });

    async function submitSection1(file) {
        el('section1-loading').classList.remove('d-none');
        const fd = new FormData();
        fd.append('photo', file);
        try {
            const res = await apiPost(URL_SECTION1, fd, false);
            el('section1-loading').classList.add('d-none');
            showTimeAndRaw('section1', res);
            if (res.status === 'needs_retake') {
                alert(res.message);
                return;
            }
            if (res.status === 'error') { showError(res.message); return; }

            section1Data = res.data;
            await applySection1ToForm(res.data);
        } catch (e) {
            el('section1-loading').classList.add('d-none');
            showError('Tidak bisa menghubungi server.');
        }
    }

    async function applySection1ToForm(data) {
        el('tanggalInput').value = data.tanggal_raw ? parseTanggalToInputDate(data.tanggal_raw) : el('tanggalInput').value;
        el('speedInput').value = data.speed ?? el('speedInput').value;
        if (data.shift) el('shiftSelectManual').value = data.shift;
        el('mesinRawInput').value = data.mesin_raw ?? '';
        el('sizeRawInput').value = data.size_raw ?? '';
        el('operatorRawInput').value = data.operator_nama ?? '';

        // Tanpa DB: hanya cek file alias mesin (storage/app/mesin_aliases.json), AI mesin tidak dipanggil.
        const r = data.mesin_resolution || {};
        el('mesinStatus').textContent = (r.sumber === 'alias'
            ? `Terdaftar di alias -> ${r.resrceno}.`
            : 'Belum ada di alias (pencocokan ke MFRESMAS dilewati, tanpa DB).')
            + ` Teks tanggal di kertas: "${data.tanggal_raw ?? '-'}".`;

        const badge = el('section1-status-badge');
        badge.innerHTML = (data.header_partial_failure || data.speed_size_partial_failure)
            ? '<span class="ir-badge ir-badge-review">Sebagian perlu dilengkapi manual</span>'
            : '<span class="ir-badge ir-badge-ok">Terbaca</span>';

        updateSection2LockState();
    }

    function showTimeAndRaw(section, res) {
        if (res.elapsed_seconds !== undefined) {
            el(`${section}-time`).textContent = `Lama proses di server: ${res.elapsed_seconds} detik (status: ${res.status})`;
        }
        const rawEl = el(`${section}-raw`);
        if (rawEl) rawEl.textContent = JSON.stringify(res, null, 2);
    }

    function parseTanggalToInputDate(raw) {
        const m = raw.match(/(\d{1,2})[\s.\-\/](\d{1,2})[\s.\-\/](\d{4})/);
        if (!m) return '';
        return `${m[3]}-${m[2].padStart(2,'0')}-${m[1].padStart(2,'0')}`;
    }

    el('shiftSelectManual').addEventListener('change', updateSection2LockState);

    function updateSection2LockState() {
        const hasShift = !!el('shiftSelectManual').value;
        el('btn-open-camera-s2').disabled = !hasShift;
        el('btn-pick-file-s2').disabled = !hasShift;
        el('section2-lock-note').classList.toggle('d-none', hasShift);
    }

    // ================== SECTION 2: crop-rectangle -> 3-titik -> submit ==================
    function showRectCropUI(file) {
        const img = new Image();
        img.onload = () => {
            const canvas = el('rect-crop-canvas');
            const dpr = window.devicePixelRatio || 1;
            const viewW = window.innerWidth, viewH = window.innerHeight;
            canvas.width = viewW * dpr; canvas.height = viewH * dpr;
            const baseScale = Math.min(viewW / img.width, viewH / img.height);
            const baseOffsetX = (viewW - img.width * baseScale) / 2;
            const baseOffsetY = (viewH - img.height * baseScale) / 2;

            rectCropState = {
                img, dpr, viewW, viewH, baseScale, baseOffsetX, baseOffsetY, pendingFile: file,
                rect: { x0: 0.1, y0: 0.3, x1: 0.9, y1: 0.7 },
                dragHandle: null,
                zoom: 1, panX: 0, panY: 0, pinch: null, // BARU -- dukung pinch-zoom & pan gambar
            };
            el('rect-crop-overlay').style.display = 'block';
            redrawRectCrop();
        };
        img.src = URL.createObjectURL(file);
    }

    function rectUvToScreen(u, v) {
        const s = rectCropState;
        const baseX = s.baseOffsetX + u * s.img.width * s.baseScale;
        const baseY = s.baseOffsetY + v * s.img.height * s.baseScale;
        return { x: baseX * s.zoom + s.panX, y: baseY * s.zoom + s.panY };
    }
    function rectScreenToUv(x, y) {
        const s = rectCropState;
        const baseX = (x - s.panX) / s.zoom;
        const baseY = (y - s.panY) / s.zoom;
        return {
            u: (baseX - s.baseOffsetX) / (s.img.width * s.baseScale),
            v: (baseY - s.baseOffsetY) / (s.img.height * s.baseScale),
        };
    }

    function redrawRectCrop() {
        const s = rectCropState;
        const canvas = el('rect-crop-canvas');
        const ctx = canvas.getContext('2d');
        ctx.setTransform(s.dpr, 0, 0, s.dpr, 0, 0);
        ctx.clearRect(0, 0, s.viewW, s.viewH);

        const topLeft = rectUvToScreen(0, 0);
        ctx.drawImage(
            s.img, topLeft.x, topLeft.y,
            s.img.width * s.baseScale * s.zoom, s.img.height * s.baseScale * s.zoom
        );

        const p0 = rectUvToScreen(s.rect.x0, s.rect.y0);
        const p1 = rectUvToScreen(s.rect.x1, s.rect.y1);

        ctx.fillStyle = 'rgba(0,0,0,0.55)';
        ctx.fillRect(0, 0, s.viewW, p0.y);
        ctx.fillRect(0, p1.y, s.viewW, s.viewH - p1.y);
        ctx.fillRect(0, p0.y, p0.x, p1.y - p0.y);
        ctx.fillRect(p1.x, p0.y, s.viewW - p1.x, p1.y - p0.y);

        ctx.strokeStyle = 'rgba(0,255,100,0.95)';
        ctx.lineWidth = 2;
        ctx.strokeRect(p0.x, p0.y, p1.x - p0.x, p1.y - p0.y);

        const handles = { tl: p0, tr: { x: p1.x, y: p0.y }, bl: { x: p0.x, y: p1.y }, br: p1 };
        Object.values(handles).forEach(h => {
            ctx.fillStyle = 'rgba(0,255,100,0.95)';
            ctx.fillRect(h.x - 8, h.y - 8, 16, 16);
        });
    }

    function rectPointerPos(e) {
        const rect = el('rect-crop-canvas').getBoundingClientRect();
        const t = e.touches ? e.touches[0] : e;
        return { x: t.clientX - rect.left, y: t.clientY - rect.top };
    }

    function initRectCropHandlers() {
        const canvas = el('rect-crop-canvas');
        function hitTest(x, y) {
            const s = rectCropState;
            const p0 = rectUvToScreen(s.rect.x0, s.rect.y0);
            const p1 = rectUvToScreen(s.rect.x1, s.rect.y1);
            const near = (px, py) => Math.hypot(px - x, py - y) < 30;
            if (near(p0.x, p0.y)) return 'tl';
            if (near(p1.x, p0.y)) return 'tr';
            if (near(p0.x, p1.y)) return 'bl';
            if (near(p1.x, p1.y)) return 'br';
            if (x > p0.x && x < p1.x && y > p0.y && y < p1.y) return 'move';
            return null;
        }
        function pinchDistance(t0, t1) { return Math.hypot(t1.clientX - t0.clientX, t1.clientY - t0.clientY); }
        function pinchMidpoint(t0, t1) {
            const rect = canvas.getBoundingClientRect();
            return { x: (t0.clientX + t1.clientX) / 2 - rect.left, y: (t0.clientY + t1.clientY) / 2 - rect.top };
        }

        let lastPos = null, panState = null;

        function onTouchStart(e) {
            const s = rectCropState;
            if (e.touches.length === 2) {
                s.dragHandle = null; panState = null;
                const dist = pinchDistance(e.touches[0], e.touches[1]);
                const mid = pinchMidpoint(e.touches[0], e.touches[1]);
                s.pinch = { startDist: dist, startZoom: s.zoom, startPanX: s.panX, startPanY: s.panY, focal: mid };
            } else if (e.touches.length === 1) {
                const pos = rectPointerPos(e.touches[0]);
                const handle = hitTest(pos.x, pos.y);
                if (handle) {
                    s.dragHandle = handle;
                } else {
                    s.dragHandle = null;
                    panState = { startX: pos.x, startY: pos.y, startPanX: s.panX, startPanY: s.panY };
                }
                lastPos = pos;
            }
        }

        function onTouchMove(e) {
            const s = rectCropState;
            e.preventDefault();

            if (e.touches.length === 2 && s.pinch) {
                const dist = pinchDistance(e.touches[0], e.touches[1]);
                const newZoom = Math.min(Math.max(s.pinch.startZoom * (dist / s.pinch.startDist), 0.5), 8); // BARU: min 0.5 -- bisa zoom OUT lebih dari 1:1
                const f = s.pinch.focal;
                const baseX = (f.x - s.pinch.startPanX) / s.pinch.startZoom;
                const baseY = (f.y - s.pinch.startPanY) / s.pinch.startZoom;
                s.zoom = newZoom;
                s.panX = f.x - baseX * newZoom;
                s.panY = f.y - baseY * newZoom;
                redrawRectCrop();
                return;
            }

            if (e.touches.length === 1) {
                const pos = rectPointerPos(e.touches[0]);
                if (s.dragHandle) {
                    applyRectDrag(s.dragHandle, pos, lastPos);
                    lastPos = pos;
                    redrawRectCrop();
                } else if (panState) {
                    s.panX = panState.startPanX + (pos.x - panState.startX);
                    s.panY = panState.startPanY + (pos.y - panState.startY);
                    redrawRectCrop();
                }
            }
        }

        function onTouchEnd(e) {
            const s = rectCropState;
            if (e.touches.length < 2) s.pinch = null;
            if (e.touches.length === 0) { s.dragHandle = null; panState = null; lastPos = null; }
        }

        canvas.addEventListener('touchstart', onTouchStart, { passive: true });
        canvas.addEventListener('touchmove', onTouchMove, { passive: false });
        canvas.addEventListener('touchend', onTouchEnd);

        // Fallback mouse (desktop): drag handle saja + wheel utk zoom.
        let mouseHandle = null;
        canvas.addEventListener('mousedown', (e) => {
            const pos = { x: e.clientX - canvas.getBoundingClientRect().left, y: e.clientY - canvas.getBoundingClientRect().top };
            mouseHandle = hitTest(pos.x, pos.y);
            lastPos = pos;
        });
        canvas.addEventListener('mousemove', (e) => {
            if (!mouseHandle) return;
            const pos = { x: e.clientX - canvas.getBoundingClientRect().left, y: e.clientY - canvas.getBoundingClientRect().top };
            applyRectDrag(mouseHandle, pos, lastPos);
            lastPos = pos;
            redrawRectCrop();
        });
        window.addEventListener('mouseup', () => { mouseHandle = null; });
        canvas.addEventListener('wheel', (e) => {
            e.preventDefault();
            const s = rectCropState;
            const delta = e.deltaY < 0 ? 1.1 : 0.9;
            s.zoom = Math.min(Math.max(s.zoom * delta, 0.5), 8);
            redrawRectCrop();
        }, { passive: false });
    }

    // PATCH: batas minimum ukuran kotak crop DIPERKECIL DRASTIS (dari 0.05
    // jadi 0.01 fraksi) -- sesuai permintaan, hilangkan batasan kecil yg
    // terlalu ketat.
    function applyRectDrag(handle, pos, lastPos) {
        const s = rectCropState;
        const duv = rectScreenToUv(pos.x, pos.y);
        const MIN_SIZE = 0.01;
        if (handle === 'move') {
            const dPrev = rectScreenToUv(lastPos.x, lastPos.y);
            const dx = duv.u - dPrev.u, dy = duv.v - dPrev.v;
            const w = s.rect.x1 - s.rect.x0, h = s.rect.y1 - s.rect.y0;
            s.rect.x0 += dx; s.rect.x1 = s.rect.x0 + w;
            s.rect.y0 += dy; s.rect.y1 = s.rect.y0 + h;
        } else if (handle === 'tl') { s.rect.x0 = Math.min(duv.u, s.rect.x1 - MIN_SIZE); s.rect.y0 = Math.min(duv.v, s.rect.y1 - MIN_SIZE); }
        else if (handle === 'tr') { s.rect.x1 = Math.max(duv.u, s.rect.x0 + MIN_SIZE); s.rect.y0 = Math.min(duv.v, s.rect.y1 - MIN_SIZE); }
        else if (handle === 'bl') { s.rect.x0 = Math.min(duv.u, s.rect.x1 - MIN_SIZE); s.rect.y1 = Math.max(duv.v, s.rect.y0 + MIN_SIZE); }
        else if (handle === 'br') { s.rect.x1 = Math.max(duv.u, s.rect.x0 + MIN_SIZE); s.rect.y1 = Math.max(duv.v, s.rect.y0 + MIN_SIZE); }
    }
    initRectCropHandlers();

    el('btn-rect-cancel').addEventListener('click', () => {
        el('rect-crop-overlay').style.display = 'none';
        rectCropState = null;
    });

    el('btn-rect-confirm').addEventListener('click', () => {
        const s = rectCropState;
        const { img, rect } = s;
        const cropCanvas = document.createElement('canvas');
        const cw = Math.round((rect.x1 - rect.x0) * img.width);
        const ch = Math.round((rect.y1 - rect.y0) * img.height);
        cropCanvas.width = cw; cropCanvas.height = ch;
        cropCanvas.getContext('2d').drawImage(
            img, rect.x0 * img.width, rect.y0 * img.height, cw, ch, 0, 0, cw, ch
        );
        cropCanvas.toBlob((blob) => {
            const croppedFile = new File([blob], `cropped_${Date.now()}.jpg`, { type: 'image/jpeg' });
            el('rect-crop-overlay').style.display = 'none';
            rectCropState = null;
            showCornerAdjustUI(croppedFile);
        }, 'image/jpeg', 0.92);
    });

    function showCornerAdjustUI(file) {
        const img = new Image();
        img.onload = () => {
            // PATCH: tunda SEMUA kalkulasi ukuran (canvas, baseScale, offset)
            // sampai landscape benar-benar terkonfirmasi -- window.innerWidth/
            // innerHeight SEBELUM rotasi selesai masih portrait, kalau dipakai
            // duluan hasilnya distorsi (canvas ke-set ukuran lama, gambar
            // digambar setelah dimensi window sudah berubah).
            ensureLandscapeThenRun(() => {
                const canvas = el('corner-adjust-canvas');
                const dpr = window.devicePixelRatio || 1;
                const viewW = window.innerWidth;
                const viewH = window.innerHeight;
                canvas.width = viewW * dpr;
                canvas.height = viewH * dpr;
                canvas.style.width = viewW + 'px';
                canvas.style.height = viewH + 'px';

                const baseScale = Math.min(viewW / img.width, viewH / img.height);
                const baseOffsetX = (viewW - img.width * baseScale) / 2;
                const baseOffsetY = (viewH - img.height * baseScale) / 2;

                cornerAdjustState = {
                    img, dpr, viewW, viewH, baseScale, baseOffsetX, baseOffsetY,
                    zoom: 1, panX: 0, panY: 0, dragIdx: null, pendingFile: file,
                    pinch: null,
                    points: [
                        { u: 0.15, v: 0.4 },
                        { u: 0.85, v: 0.4 },
                        { u: 0.15, v: 0.6 },
                    ],
                };

                el('corner-adjust-overlay').style.display = 'block';
                redrawCornerAdjust();
            });
        };
        img.src = URL.createObjectURL(file);
    }

    function redrawCornerAdjust() {
        const s = cornerAdjustState;
        const canvas = el('corner-adjust-canvas');
        const ctx = canvas.getContext('2d');
        ctx.setTransform(s.dpr, 0, 0, s.dpr, 0, 0);
        ctx.clearRect(0, 0, s.viewW, s.viewH);

        const topLeft = uvToScreen(0, 0);
        ctx.drawImage(
            s.img, topLeft.x, topLeft.y,
            s.img.width * s.baseScale * s.zoom, s.img.height * s.baseScale * s.zoom
        );

        const [A, B, C] = s.points.map((p) => uvToScreen(p.u, p.v));
        const D = { x: B.x + C.x - A.x, y: B.y + C.y - A.y };

        function lerp(p1, p2, t) { return { x: p1.x + (p2.x - p1.x) * t, y: p1.y + (p2.y - p1.y) * t }; }
        function pointAt(u, v) {
            const top = lerp(A, B, u);
            const bottom = lerp(C, D, u);
            return lerp(top, bottom, v);
        }

        ctx.strokeStyle = 'rgba(255, 60, 60, 0.85)';
        ctx.lineWidth = 2;
        for (let b = 0; b <= 8; b++) {
            const u = b / 8;
            const p1 = pointAt(u, 0), p2 = pointAt(u, 1);
            ctx.beginPath(); ctx.moveTo(p1.x, p1.y); ctx.lineTo(p2.x, p2.y); ctx.stroke();
        }
        ctx.strokeStyle = 'rgba(255, 220, 60, 0.7)';
        ctx.lineWidth = 1;
        for (let b = 0; b < 8; b++) {
            for (let c = 1; c < 6; c++) {
                const u = (b + c / 6) / 8;
                const p1 = pointAt(u, 0), p2 = pointAt(u, 1);
                ctx.beginPath(); ctx.moveTo(p1.x, p1.y); ctx.lineTo(p2.x, p2.y); ctx.stroke();
            }
        }

        s.points.forEach((p) => {
            const scr = uvToScreen(p.u, p.v);
            ctx.beginPath();
            ctx.arc(scr.x, scr.y, 10, 0, Math.PI * 2);
            ctx.strokeStyle = 'rgba(0, 255, 100, 0.95)';
            ctx.lineWidth = 2;
            ctx.stroke();
            ctx.beginPath();
            ctx.arc(scr.x, scr.y, 2.5, 0, Math.PI * 2);
            ctx.fillStyle = 'rgba(0, 255, 100, 0.95)';
            ctx.fill();
        });
    }

    function uvToScreen(u, v) {
        const s = cornerAdjustState;
        const baseX = s.baseOffsetX + u * s.img.width * s.baseScale;
        const baseY = s.baseOffsetY + v * s.img.height * s.baseScale;
        return { x: baseX * s.zoom + s.panX, y: baseY * s.zoom + s.panY };
    }

    function screenToUv(x, y) {
        const s = cornerAdjustState;
        const baseX = (x - s.panX) / s.zoom;
        const baseY = (y - s.panY) / s.zoom;
        return {
            u: (baseX - s.baseOffsetX) / (s.img.width * s.baseScale),
            v: (baseY - s.baseOffsetY) / (s.img.height * s.baseScale),
        };
    }

    function cornerAdjustPointerPos(touch) {
        const rect = el('corner-adjust-canvas').getBoundingClientRect();
        return { x: touch.clientX - rect.left, y: touch.clientY - rect.top };
    }

    function snapToNearestEdge(screenX, screenY, searchRadius = 22) {
        const s = cornerAdjustState;
        const canvas = el('corner-adjust-canvas');
        const ctx = canvas.getContext('2d');

        const r = searchRadius;
        const sx = Math.max(0, Math.round((screenX - r) * s.dpr));
        const sy = Math.max(0, Math.round((screenY - r) * s.dpr));
        const sw = Math.min(canvas.width - sx, Math.round(r * 2 * s.dpr));
        const sh = Math.min(canvas.height - sy, Math.round(r * 2 * s.dpr));
        if (sw <= 2 || sh <= 2) return { u: screenToUv(screenX, screenY).u, v: screenToUv(screenX, screenY).v };

        let imgData;
        try { imgData = ctx.getImageData(sx, sy, sw, sh); }
        catch (e) { const uv = screenToUv(screenX, screenY); return uv; }

        const data = imgData.data;
        const gray = new Float32Array(sw * sh);
        for (let i = 0; i < sw * sh; i++) {
            gray[i] = 0.299 * data[i * 4] + 0.587 * data[i * 4 + 1] + 0.114 * data[i * 4 + 2];
        }

        let bestCol = -1, bestColScore = 0;
        for (let cx = 1; cx < sw - 1; cx++) {
            let score = 0;
            for (let cy = 0; cy < sh; cy++) score += Math.abs(gray[cy * sw + cx + 1] - gray[cy * sw + cx - 1]);
            if (score > bestColScore) { bestColScore = score; bestCol = cx; }
        }
        let bestRow = -1, bestRowScore = 0;
        for (let cy = 1; cy < sh - 1; cy++) {
            let score = 0;
            for (let cx = 0; cx < sw; cx++) score += Math.abs(gray[(cy + 1) * sw + cx] - gray[(cy - 1) * sw + cx]);
            if (score > bestRowScore) { bestRowScore = score; bestRow = cy; }
        }

        // PATCH: magnet dilemahkan -- (a) threshold dinaikkan (butuh tepi
        // lebih jelas/tegas baru snap aktif), (b) hasil snap di-BLEND
        // dengan posisi jari asli (70% titik asli + 30% arah tarikan snap)
        // supaya tidak "melompat" tegas ke tepi, lebih terasa lembut.
        const threshold = sw * 20; // naik dari 12 -> lebih sulit trigger
        const blendFactor = 0.3;   // 0 = snap penuh (lama), 1 = tanpa snap sama sekali

        const targetScreenX = bestColScore > threshold ? (sx / s.dpr + bestCol / s.dpr) : screenX;
        const targetScreenY = bestRowScore > threshold ? (sy / s.dpr + bestRow / s.dpr) : screenY;

        const blendedX = screenX + (targetScreenX - screenX) * (1 - blendFactor);
        const blendedY = screenY + (targetScreenY - screenY) * (1 - blendFactor);

        return screenToUv(blendedX, blendedY);
    }

    function initCornerAdjustDragHandlers() {
        const canvas = el('corner-adjust-canvas');

        function findNearestPointIdx(screenX, screenY) {
            const s = cornerAdjustState;
            let nearestIdx = -1, nearestDist = Infinity;
            s.points.forEach((p, i) => {
                const scr = uvToScreen(p.u, p.v);
                const d = Math.hypot(scr.x - screenX, scr.y - screenY);
                if (d < nearestDist) { nearestDist = d; nearestIdx = i; }
            });
            return nearestDist < 40 ? nearestIdx : -1;
        }

        function pinchDistance(t0, t1) {
            return Math.hypot(t1.clientX - t0.clientX, t1.clientY - t0.clientY);
        }
        function pinchMidpoint(t0, t1) {
            const rect = canvas.getBoundingClientRect();
            return { x: (t0.clientX + t1.clientX) / 2 - rect.left, y: (t0.clientY + t1.clientY) / 2 - rect.top };
        }

        function onTouchStart(e) {
            const s = cornerAdjustState;
            if (e.touches.length === 2) {
                s.dragIdx = null;
                const dist = pinchDistance(e.touches[0], e.touches[1]);
                const mid = pinchMidpoint(e.touches[0], e.touches[1]);
                s.pinch = { startDist: dist, startZoom: s.zoom, startPanX: s.panX, startPanY: s.panY, focal: mid };
            } else if (e.touches.length === 1) {
                const pos = cornerAdjustPointerPos(e.touches[0]);
                const idx = findNearestPointIdx(pos.x, pos.y);
                if (idx >= 0) {
                    s.dragIdx = idx;
                } else {
                    s.dragIdx = null;
                    s.pan = { startX: pos.x, startY: pos.y, startPanX: s.panX, startPanY: s.panY };
                }
            }
        }

        function onTouchMove(e) {
            const s = cornerAdjustState;
            e.preventDefault();

            if (e.touches.length === 2 && s.pinch) {
                const dist = pinchDistance(e.touches[0], e.touches[1]);
                const newZoom = Math.min(Math.max(s.pinch.startZoom * (dist / s.pinch.startDist), 1), 8);
                const f = s.pinch.focal;
                const baseX = (f.x - s.pinch.startPanX) / s.pinch.startZoom;
                const baseY = (f.y - s.pinch.startPanY) / s.pinch.startZoom;
                s.zoom = newZoom;
                s.panX = f.x - baseX * newZoom;
                s.panY = f.y - baseY * newZoom;
                redrawCornerAdjust();
                return;
            }

            if (e.touches.length === 1) {
                const pos = cornerAdjustPointerPos(e.touches[0]);
                if (s.dragIdx !== null) {
                    const uv = snapToNearestEdge(pos.x, pos.y);
                    s.points[s.dragIdx] = uv;
                    redrawCornerAdjust();
                } else if (s.pan) {
                    s.panX = s.pan.startPanX + (pos.x - s.pan.startX);
                    s.panY = s.pan.startPanY + (pos.y - s.pan.startY);
                    redrawCornerAdjust();
                }
            }
        }

        function onTouchEnd(e) {
            const s = cornerAdjustState;
            if (e.touches.length < 2) s.pinch = null;
            if (e.touches.length === 0) { s.dragIdx = null; s.pan = null; }
        }

        canvas.addEventListener('touchstart', onTouchStart, { passive: true });
        canvas.addEventListener('touchmove', onTouchMove, { passive: false });
        canvas.addEventListener('touchend', onTouchEnd);

        let mouseDragIdx = null;
        canvas.addEventListener('mousedown', (e) => {
            const pos = { x: e.clientX - canvas.getBoundingClientRect().left, y: e.clientY - canvas.getBoundingClientRect().top };
            mouseDragIdx = findNearestPointIdx(pos.x, pos.y);
        });
        canvas.addEventListener('mousemove', (e) => {
            if (mouseDragIdx === null || mouseDragIdx < 0) return;
            const pos = { x: e.clientX - canvas.getBoundingClientRect().left, y: e.clientY - canvas.getBoundingClientRect().top };
            cornerAdjustState.points[mouseDragIdx] = snapToNearestEdge(pos.x, pos.y);
            redrawCornerAdjust();
        });
        window.addEventListener('mouseup', () => { mouseDragIdx = null; });
    }

    initCornerAdjustDragHandlers();

    el('btn-corner-cancel').addEventListener('click', () => {
        el('corner-adjust-overlay').style.display = 'none';
        cornerAdjustState = null;
    });

    el('btn-corner-confirm').addEventListener('click', () => {
        const s = cornerAdjustState;
        const normalized = s.points.map(p => ({ x: Math.min(Math.max(p.u, 0), 1), y: Math.min(Math.max(p.v, 0), 1) }));
        el('corner-adjust-overlay').style.display = 'none';
        const file = s.pendingFile;
        cornerAdjustState = null;
        submitSection2(file, normalized);
    });

    async function submitSection2(file, points) {
        const shift = el('shiftSelectManual').value;
        if (!shift) { alert('Pilih Shift dulu di Section 1.'); return; }

        el('section2-loading').classList.remove('d-none');
        const fd = new FormData();
        fd.append('photo', file);
        fd.append('shift', shift);
        fd.append('points', JSON.stringify(points));

        try {
            const res = await apiPost(URL_SECTION2, fd, false);
            el('section2-loading').classList.add('d-none');
            showTimeAndRaw('section2', res);
            if (res.status === 'needs_retake') { alert(res.message); return; }
            if (res.status === 'error') { showError(res.message); return; }

            section2GridWaktu = res.data.grid_waktu;
            renderGridRaw(section2GridWaktu);
            el('section2-status-badge').innerHTML = '<span class="ir-badge ir-badge-ok">Terbaca</span>';
            await refreshRowsFromFinalize();
        } catch (e) {
            el('section2-loading').classList.add('d-none');
            showError('Tidak bisa menghubungi server.');
        }
    }

    // ================== FINALIZE: gabung section1+section2 -> rows ==================
    /**
     * Gabung baris MANUAL yang sudah diketik operator dengan baris baru
     * hasil finalize() (dari foto grid) -- kalau ada baris manual yang
     * jam-mulainya SAMA PERSIS dengan salah satu baris baru, baris manual
     * itu DIBUANG (di-overwrite oleh hasil foto, dianggap operator sudah
     * isi placeholder utk jam itu dan sekarang tergantikan data asli).
     * Baris manual yg jam-mulainya beda (atau belum diisi jamnya sama
     * sekali) tetap dipertahankan, ditambahkan di akhir daftar baru.
     */
    function mergeManualRows(oldRows, newRows) {
        const manualRows = oldRows.filter(r => r._raw_code === '(manual)');
        if (manualRows.length === 0) return newRows;

        const newTimes = new Set(
            newRows.map(r => r.Time_Start ? r.Time_Start.substring(11, 16) : null).filter(Boolean)
        );
        const keptManual = manualRows.filter(r => {
            const t = r.Time_Start ? r.Time_Start.substring(11, 16) : null;
            return !t || !newTimes.has(t); // tanpa jam ATAU jamnya tidak bentrok -> dipertahankan
        });
        return [...newRows, ...keptManual];
    }
    
    async function refreshRowsFromFinalize() {
        const payload = {
            tanggal: el('tanggalInput').value || null,
            mesin_code: el('mesinRawInput').value || null,
            shift: el('shiftSelectManual').value || null,
            speed: el('speedInput').value || null,
            size_raw: el('sizeRawInput').value || null,
            grid_waktu: section2GridWaktu || [],
        };
        const result = await apiPost(URL_FINALIZE, payload);
        if (result.status !== 'success') { showError('Gagal memproses data.'); return; }

        currentRows = mergeManualRows(currentRows, result.rows);
        renderRows(currentRows);
    }

    // ================== Tampilan teks (tanpa dropdown DB) ==================
    const esc = (v) => String(v ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    function renderGridRaw(grid) {
        const rows = (grid || []).filter(r => (r.blok || []).some(v => v !== null));
        el('gridRawBody').innerHTML = rows.length === 0
            ? '<tr><td colspan="7" class="text-muted">Tidak ada kotak terisi.</td></tr>'
            : rows.map(r => `<tr><td class="text-nowrap">${esc(r.jam_mulai)}</td>${
                r.blok.map(v => `<td>${v === null ? '<span class="text-muted">·</span>' : `<code>${esc(v)}</code>`}</td>`).join('')
            }</tr>`).join('');
    }

    // ProblemCode/ProblemCodeD dihitung dari kode kertas (ProblemCodeConverter);
    // deskripsi dari MFPROBLEMD tidak tersedia krn tanpa DB.
    function renderRows(rows) {
        if (!rows || rows.length === 0) {
            el('rowsTableBody').innerHTML = `
                <tr><td colspan="8" class="text-center py-5 border-0">
                    <i class="bi bi-file-earmark-x" style="font-size: 2rem; color: var(--ir-accent);"></i>
                    <p class="text-muted small mt-2 mb-0">Belum ada data downtime terdeteksi dari foto ini.</p>
                </td></tr>`;
            return;
        }

        el('rowsTableBody').innerHTML = rows.map((row, idx) => {
            const debug = row._review.problem_code_debug || {};
            const descParts = (row.Problem_Desc || '').match(/(\S+)\/(\S+)$/);
            const problemCode = row.ProblemCode ?? debug.problem_code ?? '-';
            const problemCodeD = descParts ? descParts[2] : (debug.problem_code_d ?? '-');
            const alasan = (row._review.alasan || []).map(a => `<div class="small text-muted">${esc(a)}</div>`).join('');
            return `
                <tr class="${row._review.perlu_review ? 'table-row-review' : ''}">
                    <td>${idx + 1}</td>
                    <td>${row.Time_Start ? row.Time_Start.substring(11, 16) : '-'}</td>
                    <td>${row.Time_End ? row.Time_End.substring(11, 16) : '-'}</td>
                    <td>${row.Time_Total ?? '-'}</td>
                    <td><code>${esc(row._raw_code)}</code></td>
                    <td>${esc(problemCode)}</td>
                    <td>${esc(problemCodeD)}</td>
                    <td>${row._review.perlu_review ? '<span class="ir-badge ir-badge-review">Perlu Review</span>' : '<span class="ir-badge ir-badge-ok">OK</span>'}${alasan}</td>
                </tr>`;
        }).join('');
    }
})();
</script>})();
</script>
@endpush