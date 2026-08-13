@extends('layouts.app') {{-- sesuaikan nama layout Blade utama Anda --}}

@section('content')
@push('styles')
<style>
    :root {
        --ir-accent: #0FD9C4;
        --ir-accent-hover: #0BC1AE;
        --ir-accent-light: rgba(15, 217, 196, .12);
        --ir-cyan: #22E1FF;
        --ir-green: #00E68A;
        --ir-amber: #FFB020;
        --ir-coral: #FF4D6A;
    }
    .ir-card { border: none; border-radius: 14px; box-shadow: 0 2px 10px rgba(28,30,33,.06); }
    .ir-btn-primary {
        background-color: var(--ir-accent); border-color: var(--ir-accent);
        border-radius: 999px; color: #fff; font-weight: 600;
    }
    .ir-btn-primary:hover { background-color: var(--ir-accent-hover); border-color: var(--ir-accent-hover); }
    .ir-badge { border-radius: 999px; padding: .3rem .7rem; font-size: .75rem; font-weight: 600; }
    .ir-badge-review { background-color: rgba(255,176,32,.15); color: #B5780B; }
    .ir-badge-ok { background-color: rgba(0,230,138,.15); color: #0A9A5C; }
    .ir-icon-circle {
        width: 48px; height: 48px; border-radius: 50%;
        background-color: var(--ir-accent-light); color: var(--ir-accent);
        display: inline-flex; align-items: center; justify-content: center; font-size: 1.4rem;
    }
</style>
@endpush
<div class="d-flex align-items-center justify-content-between px-3 px-md-4 py-2" style="background-color: var(--dt-charcoal);">
    <a href="{{ route('dashboard') }}" class="d-flex align-items-center gap-2 text-white text-decoration-none">
        <i class="bi bi-arrow-left"></i>
        <span class="fw-semibold small">Kembali ke Dashboard</span>
    </a>
    <span class="text-white-50 small d-none d-md-inline">
        <i class="bi bi-stars" style="color: var(--ir-accent);"></i> AI Baca Kertas
    </span>
</div>
<div class="container py-3" style="max-width: 900px;">
    <h4 class="mb-3">Ambil Foto Kertas — Laporan Proses Drawing Harian</h4>

    {{-- ===================== STATE 1: IDLE (form upload) ===================== --}}
    
    <section id="state-idle">
        <div class="card ir-card">
            <div class="card-body text-center py-5">
                <div class="ir-icon-circle mb-3"><i class="bi bi-camera"></i></div>
                <button type="button" id="btn-open-camera" class="btn ir-btn-primary btn-lg mb-3 px-4">
                    Ambil Foto
                </button>
                <div class="mb-3">
                    <input type="file" id="photoInput" accept="image/*" capture="environment" class="form-control" style="display:none;">
                    <button id="analyzeBtn" class="btn ir-btn-primary btn-lg d-none px-4" disabled>Analisa Foto</button>
                </div>
                <div class="mt-3">
                    <a href="{{ route('dashboard') }}" class="btn btn-link text-muted">Batal, kembali ke Dashboard</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Overlay kamera custom -- taruh di luar <section>, sejajar dengan section lain -->
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
    <!-- Overlay penyesuaian 4-titik sudut, khusus close-up grid -->
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
    {{-- ===================== STATE 2: LOADING ===================== --}}
    <section id="state-loading" class="d-none text-center py-5">
        <div class="spinner-border mb-3" style="color: var(--ir-accent);" role="status"></div>
        <p>Sedang membaca kertas... (bisa sampai beberapa menit, mohon tunggu)</p>
    </section>

    {{-- ===================== STATE 3: RETAKE ===================== --}}
    <section id="state-retake" class="d-none">
        <div class="alert alert-warning">
            <p id="retakeMessage"></p>
            <button id="retakeBtn" class="btn btn-warning">Foto Ulang</button>
            <a href="{{ route('dashboard') }}" class="btn btn-link text-muted">Batal, kembali ke Dashboard</a>
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
            <a href="{{ route('dashboard') }}" class="btn btn-link text-muted">Batal, kembali ke Dashboard</a>
        </div>
    </section>

    {{-- ===================== STATE 4b: FOTO CLOSE-UP SECTION ===================== --}}
    <section id="state-section-photo" class="d-none">
        <div class="alert alert-warning">
            <p id="sectionPhotoMessage"></p>
            <button type="button" id="btn-open-camera-section" class="btn btn-warning">📷 Foto Bagian Ini</button>
            <button type="button" id="fallbackManualBtn" class="btn btn-outline-secondary">Lewati, Isi Manual</button>
            <a href="{{ route('dashboard') }}" class="btn btn-link text-muted">Batal, kembali ke Dashboard</a>
            <input type="file" id="sectionPhotoInput" accept="image/*" capture="environment" class="form-control d-none">
        </div>
    </section>
    {{-- ===================== STATE 5: ERROR ===================== --}}
    <section id="state-error" class="d-none">
        <div class="alert alert-danger">
            <p id="errorMessage"></p>
            <button id="retryBtn" class="btn btn-secondary">Coba Lagi</button>
            <a href="{{ route('dashboard') }}" class="btn btn-link text-muted">Batal, kembali ke Dashboard</a>
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
                <input type="text" id="mesinSearch" class="form-control" list="mesinList" placeholder="Cari kode/nama mesin...">
                <datalist id="mesinList"></datalist>
                <input type="hidden" id="mesinSelect">
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
                <p id="itemStatus" class="small mt-1"></p>
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
    <div id="backToDashboardBox" class="d-none mb-3">
        <a href="{{ route('dashboard') }}" class="btn btn-primary">Kembali ke Dashboard</a>
    </div>

    <button id="saveAllBtn" class="btn ir-btn-primary btn-lg" disabled>Simpan Semua</button>
</section>

</div>
@endsection

@push('scripts')
<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    let currentToken = null;
    let currentData = null; // {header, rows} hasil analyze sukses
    let currentFailingSection = null; // 'header'|'speed_size'|'grid' -- section yg sedang ditunggu close-up-nya
    let mesinOptionsCache = null;
    let operatorOptionsCache = null;

    const el = (id) => document.getElementById(id);
    const states = ['idle', 'loading', 'retake', 'shift', 'section-photo', 'error', 'review'];
    function showState(name) {
        states.forEach((s) => el(`state-${s}`).classList.toggle('d-none', s !== name));
    }

    // ---------- KAMERA CUSTOM (Fase P) ----------
    let cameraStream = null;

    el('btn-open-camera').addEventListener('click', async () => {
        cameraCaptureTarget = 'full';
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            console.warn('getUserMedia tidak didukung, fallback ke input file biasa.');
            el('photoInput').click();
            return;
        }
        el('camera-overlay').style.display = 'block';
        checkOrientationAndProceed();
    });

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
            console.error('Gagal akses kamera:', err);
            closeCameraOverlay();
            el('photoInput').click();
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

            const isGridCloseup = cameraCaptureTarget === 'section' && currentFailingSection === 'grid';
            const targetRatio = isGridCloseup ? 4.0 : 1.4;
            const guideText = cameraCaptureTarget === 'section'
                ? 'Pegang HP tegak lurus, foto HANYA kotak grid jam ini'
                : 'Posisikan kertas di dalam kotak';

            let boxW = canvas.width * 0.9;
            let boxH = boxW / targetRatio;
            if (boxH > canvas.height * 0.85) {
                boxH = canvas.height * 0.85;
                boxW = boxH * targetRatio;
            }
            const boxX = (canvas.width - boxW) / 2;
            const boxY = (canvas.height - boxH) / 2;

            ctx.strokeStyle = 'rgba(0, 255, 100, 0.9)';
            ctx.lineWidth = 3;
            ctx.setLineDash([12, 8]);
            ctx.strokeRect(boxX, boxY, boxW, boxH);

            ctx.setLineDash([]);
            ctx.fillStyle = 'rgba(0, 255, 100, 0.9)';
            ctx.font = '16px sans-serif';
            ctx.fillText(guideText, boxX, boxY - 10);
        }

        resize();
        window.addEventListener('resize', resize);
    }

    el('btn-capture').addEventListener('click', () => {
        const video = el('camera-video');
        const canvas = el('capture-canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);

        canvas.toBlob((blob) => {
            const file = new File([blob], `capture_${Date.now()}.jpg`, { type: 'image/jpeg' });
            closeCameraOverlay();

            if (cameraCaptureTarget === 'section') {
                if (currentFailingSection === 'grid') {
                    showCornerAdjustUI(file);
                } else {
                    submitSectionPhoto(file);
                }
                return;
            }

            const dt = new DataTransfer();
            dt.items.add(file);
            el('photoInput').files = dt.files;
            el('analyzeBtn').disabled = false;
            el('analyzeBtn').click();
        }, 'image/jpeg', 0.92);
    });

    el('sectionPhotoInput').addEventListener('change', () => {
        const file = el('sectionPhotoInput').files[0];
        if (file) {
            if (currentFailingSection === 'grid') {
                showCornerAdjustUI(file);
            } else {
                submitSectionPhoto(file);
            }
            el('sectionPhotoInput').value = '';
        }
    });

    function showCornerAdjustUI(file) {
        const img = new Image();
        img.onload = () => {
            const canvas = el('corner-adjust-canvas');
            const dpr = window.devicePixelRatio || 1;
            const viewW = window.innerWidth;
            const viewH = window.innerHeight;
            canvas.width = viewW * dpr;
            canvas.height = viewH * dpr;

            const baseScale = Math.min(viewW / img.width, viewH / img.height);
            const baseOffsetX = (viewW - img.width * baseScale) / 2;
            const baseOffsetY = (viewH - img.height * baseScale) / 2;

            cornerAdjustState = {
                img, dpr, viewW, viewH, baseScale, baseOffsetX, baseOffsetY,
                zoom: 1, panX: 0, panY: 0, dragIdx: null, pendingFile: file,
                pinch: null, // {startDist, startZoom, startPanX, startPanY, focalScreenX, focalScreenY}
                // titik disimpan sbg fraksi 0-1 RELATIF KE GAMBAR ASLI (u,v) --
                // bukan koordinat layar -- supaya tahan terhadap zoom/pan.
                points: [
                    { u: 0.15, v: 0.4 },  // kiri-atas
                    { u: 0.85, v: 0.4 },  // kanan-atas
                    { u: 0.15, v: 0.6 },  // kiri-bawah
                ],
            };

            el('corner-adjust-overlay').style.display = 'block';
            redrawCornerAdjust();
        };
        img.src = URL.createObjectURL(file);
    }

    // ---- transformasi koordinat: (u,v) fraksi gambar <-> posisi layar ----
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

        // Marker KECIL (ring + titik tengah), bukan lingkaran solid besar --
        // supaya tidak menutupi pandangan ke garis kertas di bawahnya.
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

        const threshold = sw * 12;
        const snappedScreenX = bestColScore > threshold ? (sx / s.dpr + bestCol / s.dpr) : screenX;
        const snappedScreenY = bestRowScore > threshold ? (sy / s.dpr + bestRow / s.dpr) : screenY;
        return screenToUv(snappedScreenX, snappedScreenY);
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
            return nearestDist < 40 ? nearestIdx : -1; // target sentuh 40px, lebih besar drpd marker visual (10px)
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

        // Fallback mouse (desktop testing) -- drag titik saja, tanpa pinch/pan.
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
        showState('section-photo');
    });

    el('btn-corner-confirm').addEventListener('click', () => {
        const s = cornerAdjustState;
        const normalized = s.points.map((p) => ({
            x: Math.min(Math.max(p.u, 0), 1),
            y: Math.min(Math.max(p.v, 0), 1),
        }));
        el('corner-adjust-overlay').style.display = 'none';
        const file = s.pendingFile;
        cornerAdjustState = null;
        submitSectionPhoto(file, normalized);
    });

    async function submitSectionPhoto(file, points = null) {
        const fd = new FormData();
        fd.append('photo', file);
        fd.append('token', currentToken);
        if (points) fd.append('points', JSON.stringify(points));
        showState('loading');
        try {
            const data = await apiPost('/paper-scan/analyze/section-photo', fd, false);
            handlePipelineResult(data);
        } catch (e) {
            el('errorMessage').textContent = 'Tidak bisa menghubungi server. Cek koneksi internet.';
            showState('error');
        }
    }

    el('btn-cancel-camera').addEventListener('click', closeCameraOverlay);
    // ---------- STATE 4b: foto close-up section ----------
    let cameraCaptureTarget = 'full'; // 'full' | 'section' -- menentukan endpoint tujuan saat capture
    let cornerAdjustState = null; // {img, points:[{x,y}x4], dragIdx, canvasW, canvasH, pendingFile}
    el('btn-open-camera-section').addEventListener('click', () => {
        cameraCaptureTarget = 'section';
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            console.warn('getUserMedia tidak didukung, fallback ke input file biasa.');
            el('sectionPhotoInput').click();
            return;
        }
        el('camera-overlay').style.display = 'block';
        checkOrientationAndProceed();
    });

    el('fallbackManualBtn').addEventListener('click', () => {
        // Lanjut ke review dgn section itu dikosongkan + ditandai perlu_review.
        // currentData BELUM ada di titik ini kalau ini kegagalan pertama kali
        // (sebelum sempat sukses sama sekali) -- perlu diminta dari server.
        submitSectionFallbackManual();
    });

    async function submitSectionFallbackManual() {
        showState('loading');
        const data = await apiPost('/paper-scan/analyze/section-photo/fallback', {
            token: currentToken, section: currentFailingSection,
        });
        handlePipelineResult(data);
    }
    function closeCameraOverlay() {
        if (cameraStream) {
            cameraStream.getTracks().forEach(track => track.stop());
            cameraStream = null;
        }
        el('camera-overlay').style.display = 'none';
        el('rotate-prompt').style.display = 'none';
        el('camera-active-area').style.display = 'none';
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
        } else if (data.status === 'needs_section_photo') {
            currentToken = data.token;
            currentFailingSection = data.section;
            const retryNote = data.retry ? ' Masih belum terbaca, coba lagi lebih dekat & jelas.' : '';
            el('sectionPhotoMessage').textContent =
                `Bagian "${data.section_label}" tidak terbaca jelas.${retryNote} `
                + `Foto ulang HANYA bagian ini saja (lebih dekat & jelas).`;
            showState('section-photo');
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
        const hasFile = el('photoInput').files.length > 0;
        el('analyzeBtn').disabled = !hasFile;
        if (hasFile) {
            el('analyzeBtn').click(); // auto-trigger juga di jalur fallback, sama seperti jalur capture kamera
        }
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
        const mesinList = el('mesinList');
        mesinList.innerHTML = mesinOptionsCache.map((m) =>
            `<option data-kode="${m.kode}" value="${m.kode} — ${m.nama}"></option>`
        ).join('');
        if (h.mesin_resolution.resrceno) {
            const matched = mesinOptionsCache.find(m => m.kode === h.mesin_resolution.resrceno);
            if (matched) {
                el('mesinSearch').value = `${matched.kode} — ${matched.nama}`;
                el('mesinSelect').value = matched.kode;
            }
        }
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

    /**
     * Cocokkan size_raw ("00.80 MM") ke daftar item (yg ITEMDESC-nya mengandung
     * teks ukuran, mis. "...DIA.00.80 MM") -- MURNI SARAN, tetap wajib
     * dikonfirmasi user (tidak auto-submit), sesuai pola Mesin.
     */
    function findItemCandidatesBySize(items, sizeRaw) {
        if (!sizeRaw) return [];

        const m = sizeRaw.match(/([0-9]+[.,][0-9]+)/);
        if (!m) return [];

        const num = parseFloat(m[1].replace(',', '.'));
        if (isNaN(num)) return [];

        // Bentuk beberapa varian teks yg mungkin muncul di ITEMDESC (leading
        // zero beda-beda: "0.80", "00.80", ".80" semua mengacu angka yg sama).
        const variants = [
            num.toFixed(2),                          // "0.80"
            '0' + num.toFixed(2),                    // "00.80"
            num.toFixed(2).replace(/^0/, ''),         // ".80"
        ];

        return items.filter((i) =>
            variants.some((v) => i.nama && i.nama.includes(v))
        );
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
            el('itemStatus').textContent = '';
            return;
        }
        const items = await apiGet(`/referensi/item?mesin=${encodeURIComponent(mesinCode)}`);
        itemList.innerHTML = items.map((i) =>
            `<option data-kode="${i.kode}" value="${i.kode} — ${i.nama}"></option>`
        ).join('');
        itemSearch.disabled = false;
        itemSearch.placeholder = 'Cari kode/nama item...';

        // PATCH: saran Item berdasarkan Size yang terbaca dari kertas.
        const sizeRaw = currentData.header.size_raw;
        const candidates = findItemCandidatesBySize(items, sizeRaw);

        if (candidates.length === 1) {
            const match = candidates[0];
            itemSearch.value = `${match.kode} — ${match.nama}`;
            el('itemSelect').value = match.kode;
            applyItemToAllRows(match.kode);
            el('itemStatus').textContent = `⚠ Saran otomatis dari Size kertas ("${sizeRaw}") -- wajib diperiksa.`;
            el('itemStatus').className = 'small mt-1 text-warning';
        } else if (candidates.length > 1) {
            el('itemStatus').textContent = `⚠ ${candidates.length} item cocok dengan Size "${sizeRaw}" -- pilih manual dari daftar.`;
            el('itemStatus').className = 'small mt-1 text-warning';
        } else if (sizeRaw) {
            el('itemStatus').textContent = `Tidak ada item yang cocok dengan Size "${sizeRaw}" -- pilih manual.`;
            el('itemStatus').className = 'small mt-1 text-muted';
        } else {
            el('itemStatus').textContent = '';
        }

        updateSaveButtonState();
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

    el('mesinSearch').addEventListener('input', () => {
        const opts = el('mesinList').querySelectorAll('option');
        const match = Array.from(opts).find(o => o.value === el('mesinSearch').value);
        el('mesinSelect').value = match ? match.dataset.kode : '';
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
                    <td>${perluReview ? '<span class="ir-badge ir-badge-review">Perlu Review</span>' : '<span class="ir-badge ir-badge-ok">OK</span>'}</td>
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

            el('saveAllBtn').classList.add('d-none');
            el('backToDashboardBox').classList.remove('d-none');
            return;
        }

        // PATCH: sebagian gagal -- buang baris yg SUKSES dari currentData.rows
        // SEBELUM tombol Simpan diaktifkan lagi, supaya retry TIDAK mengirim
        // ulang baris yg sudah tersimpan (mencegah duplikat No_Trs). Index di
        // result.failed[].index merujuk ke posisi SEBELUM array ini diubah,
        // jadi filter dilakukan pakai index asli, bukan setelah splice.
        const failedIndexes = new Set(result.failed.map(f => f.index));
        currentData.rows = currentData.rows.filter((_, idx) => failedIndexes.has(idx));

        box.classList.add('alert-warning');
        box.textContent = `${result.berhasil} baris tersimpan, ${result.gagal} baris GAGAL. `
            + `Baris yang sudah sukses telah dihapus dari daftar -- perbaiki sisa baris di bawah lalu Simpan lagi.`;
        console.log('Detail baris gagal:', result.failed);

        renderRows(currentData.rows); // re-render, sekarang cuma tampilkan baris gagal

        el('saveAllBtn').textContent = 'Simpan Semua';
        el('saveAllBtn').disabled = false;
        updateSaveButtonState();
    });
})();
</script>
@endpush