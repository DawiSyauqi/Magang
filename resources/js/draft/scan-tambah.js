// =============================================================================
// scan-tambah.js — halaman Tambah Data: foto dari kamera HP, kamera komputer
// (getUserMedia), atau file (termasuk seret-lepas). Semua foto dikumpulkan
// lalu dikirim sekaligus lewat <input name="foto[]"> (DataTransfer).
// =============================================================================
const form = document.getElementById('formUnggah');
const kirim = document.getElementById('fotoKirim');
const sumber = document.getElementById('sumber');
const thumbs = document.getElementById('thumbs');
const panelFoto = document.getElementById('panelFoto');
const btnKirim = document.getElementById('btnKirim');
const MAKS = 10;

/** @type {{file: File, sumber: string, url: string}[]} */
let daftar = [];

function render() {
    thumbs.innerHTML = '';
    daftar.forEach((f, i) => {
        const el = document.createElement('div');
        el.className = 'sc-thumb';
        el.innerHTML = `<img alt=""><span></span><button type="button" aria-label="Hapus foto"><i class="bi bi-x"></i></button>`;
        el.querySelector('img').src = f.url;
        el.querySelector('span').textContent = `${f.file.name} · ${(f.file.size / 1048576).toFixed(1)} MB`;
        el.querySelector('button').addEventListener('click', () => {
            URL.revokeObjectURL(f.url);
            daftar.splice(i, 1);
            render();
        });
        thumbs.appendChild(el);
    });
    panelFoto.hidden = daftar.length === 0;
    document.getElementById('jumlahFoto').textContent = `(${daftar.length})`;
    btnKirim.disabled = daftar.length === 0;
}

function tambah(files, asal) {
    for (const file of files) {
        if (!file.type.startsWith('image/')) continue;
        if (daftar.length >= MAKS) { alert(`Maksimal ${MAKS} foto sekali kirim.`); break; }
        daftar.push({ file, sumber: asal, url: URL.createObjectURL(file) });
    }
    render();
}

// Kamera HP & pilih file: <input type=file> di dalam label.
document.querySelectorAll('[data-masuk]').forEach((input) => {
    input.addEventListener('change', () => {
        tambah(input.files, input.closest('[data-sumber]').dataset.sumber);
        input.value = '';
    });
});

// Seret-lepas file ke kartu.
['dragenter', 'dragover'].forEach((ev) => form.addEventListener(ev, (e) => { e.preventDefault(); form.classList.add('dragover'); }));
['dragleave', 'drop'].forEach((ev) => form.addEventListener(ev, (e) => { e.preventDefault(); form.classList.remove('dragover'); }));
form.addEventListener('drop', (e) => tambah(e.dataTransfer.files, 'file'));

// ------------------------------------------------------------- kamera komputer
const video = document.getElementById('video');
const panelKamera = document.getElementById('panelKamera');
const pilihKamera = document.getElementById('pilihKamera');
const info = document.getElementById('kameraInfo');
const btnKameraPc = document.getElementById('btnKameraPc');
let stream = null;

if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
    btnKameraPc.classList.add('disabled');
    info.hidden = false;
    info.innerHTML = 'Kamera komputer hanya bisa dipakai lewat <strong>localhost</strong> atau <strong>HTTPS</strong>. Di jaringan intranet (http://alamat-IP), pakai <em>Kamera HP</em> atau <em>Pilih file</em>, atau aktifkan HTTPS di server.';
}

async function bukaKamera(deviceId) {
    tutupKamera();
    try {
        stream = await navigator.mediaDevices.getUserMedia({
            video: deviceId ? { deviceId: { exact: deviceId }, width: { ideal: 3840 }, height: { ideal: 2160 } }
                : { facingMode: 'environment', width: { ideal: 3840 }, height: { ideal: 2160 } },
            audio: false,
        });
    } catch (e) {
        info.hidden = false;
        info.textContent = `Kamera tidak bisa dibuka (${e.name}). Pastikan izin kamera diberikan di browser.`;
        return;
    }
    video.srcObject = stream;
    panelKamera.hidden = false;
    const kamera = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'videoinput');
    const aktif = stream.getVideoTracks()[0].getSettings().deviceId;
    pilihKamera.innerHTML = kamera.map((d, i) => `<option value="${d.deviceId}" ${d.deviceId === aktif ? 'selected' : ''}>${d.label || `Kamera ${i + 1}`}</option>`).join('');
    pilihKamera.hidden = kamera.length < 2;
}

function tutupKamera() {
    stream?.getTracks().forEach((t) => t.stop());
    stream = null;
    panelKamera.hidden = true;
}

btnKameraPc.addEventListener('click', () => { if (!btnKameraPc.classList.contains('disabled')) bukaKamera(); });
pilihKamera.addEventListener('change', () => bukaKamera(pilihKamera.value));
document.getElementById('btnTutupKamera').addEventListener('click', tutupKamera);
document.getElementById('btnJepret').addEventListener('click', () => {
    const c = document.createElement('canvas');
    c.width = video.videoWidth;
    c.height = video.videoHeight;
    c.getContext('2d').drawImage(video, 0, 0);
    c.toBlob((blob) => {
        const nama = `kamera_${new Date().toISOString().replace(/\D/g, '').slice(0, 14)}.jpg`;
        tambah([new File([blob], nama, { type: 'image/jpeg' })], 'kamera_pc');
    }, 'image/jpeg', 0.92);
});

// ------------------------------------------------------------- kirim
form.addEventListener('submit', (e) => {
    if (!daftar.length) { e.preventDefault(); return; }
    const dt = new DataTransfer();
    daftar.forEach((f) => dt.items.add(f.file));
    kirim.files = dt.files;
    // Sumber yang paling banyak dipakai di kiriman ini (untuk Statistik AI).
    const hitung = daftar.reduce((a, f) => ({ ...a, [f.sumber]: (a[f.sumber] || 0) + 1 }), {});
    sumber.value = Object.entries(hitung).sort((a, b) => b[1] - a[1])[0][0];
    tutupKamera();
    btnKirim.disabled = true;
    btnKirim.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Mengunggah…';
    // Minta izin notifikasi browser (hanya berlaku di localhost/HTTPS).
    if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
});
