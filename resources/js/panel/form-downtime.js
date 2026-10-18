// Form Tambah/Ubah/Hapus data downtime di halaman Data Downtime (aplikasi asli).
// Logika sama dengan dashboard lama: dropdown dari /referensi/*, cari operator,
// durasi otomatis, isi form Ubah dari /dashboard/{no_trs}/edit-data.
const root = document.getElementById('formDowntime');

function isiPilihan(select, items, placeholder) {
    const awal = select.dataset.initial || '';
    select.replaceChildren(new Option(placeholder, ''));
    items.forEach((it) => {
        const o = new Option(it.kode + ' — ' + it.nama, it.kode);
        o.dataset.nama = it.nama;
        select.append(o);
    });
    if (awal) select.value = awal;
}

// Isian terakhir petugas (per browser) untuk mengisi form Tambah berikutnya.
const KUNCI_TERAKHIR = 'dx-input-terakhir';
const bacaTerakhir = () => { try { return JSON.parse(localStorage.getItem(KUNCI_TERAKHIR) || 'null'); } catch (e) { return null; } };
const simpanTerakhir = (v) => { try { localStorage.setItem(KUNCI_TERAKHIR, JSON.stringify(v)); } catch (e) { /* mode privat */ } };

// Shift dari jam komputer sesuai jadwal config('produksi.shift'): mulai <= jam < selesai (shift 3 melewati tengah malam).
function shiftSekarang(jadwal, d = new Date()) {
    const m = d.getHours() * 60 + d.getMinutes();
    const ke = (s) => { const [h, mm] = s.split(':').map(Number); return h * 60 + mm; };
    for (const [s, j] of Object.entries(jadwal)) {
        const a = ke(j.mulai);
        const b = ke(j.selesai);
        if (a < b ? m >= a && m < b : m >= a || m < b) return s;
    }
    return '1';
}
const tanggalHariIni = (d = new Date()) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const jamKini = (d = new Date()) => {
    const m = Math.floor((d.getHours() * 60 + d.getMinutes()) / 10) * 10; // formulir memakai kotak 10 menit
    return `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
};

const ambil = (url) => fetch(url, { headers: { Accept: 'application/json' } }).then((r) => (r.ok ? r.json() : Promise.reject(r.status)));

function siapkanModal(modalEl, url) {
    const q = (s) => modalEl.querySelector(s);
    const mesin = q('.js-mesin-select');
    const mesinNama = q('.js-mesin-nama');
    const item = q('.js-item-select');
    const itemNama = q('.js-item-nama');
    const kategori = q('.js-problem-kategori');
    const detail = q('.js-problem-detail');
    const desc = q('.js-problem-desc');
    const nik = q('.js-nik-input');
    const opNama = q('.js-operator-nama');
    const saran = q('.js-operator-saran');
    const mulai = q('.js-time-start');
    const selesai = q('.js-time-end');
    const durasi = q('.js-total-durasi');
    let mesinData = [];
    let itemData = [];
    let detailData = [];

    const muatMesin = ambil(url.mesin).then((d) => {
        mesinData = d;
        isiPilihan(mesin, d, 'Pilih mesin…');
        if (mesin.value) mesin.dispatchEvent(new Event('change'));
    });

    mesin.addEventListener('change', () => {
        const m = mesinData.find((x) => x.kode === mesin.value);
        mesinNama.textContent = m ? m.nama : '';
        itemNama.textContent = '';
        if (!mesin.value) {
            item.disabled = true;
            item.replaceChildren(new Option('Pilih mesin dulu', ''));
            return;
        }
        item.replaceChildren(new Option('Memuat…', ''));
        ambil(url.item + '?mesin=' + encodeURIComponent(mesin.value)).then((d) => {
            itemData = d;
            item.disabled = d.length === 0;
            isiPilihan(item, d, d.length ? 'Pilih item…' : 'Mesin ini tidak punya daftar item');
            if (item.value) item.dispatchEvent(new Event('change'));
        });
    });
    item.addEventListener('change', () => {
        const i = itemData.find((x) => x.kode === item.value);
        itemNama.textContent = i ? i.nama : '';
    });

    const muatKategori = ambil(url.kategori).then((d) => {
        isiPilihan(kategori, d, 'Pilih kategori…');
        if (kategori.value) kategori.dispatchEvent(new Event('change'));
    });
    kategori.addEventListener('change', () => {
        if (!kategori.value) {
            detail.disabled = true;
            detail.replaceChildren(new Option('Pilih kategori dulu', ''));
            desc.value = '';
            return;
        }
        detail.replaceChildren(new Option('Memuat…', ''));
        ambil(url.detail + '?kategori=' + encodeURIComponent(kategori.value)).then((d) => {
            detailData = d;
            detail.disabled = false;
            isiPilihan(detail, d, 'Pilih detail…');
            // Data lama: hanya teks Problem_Desc yang tersimpan → cocokkan teksnya.
            if (!detail.value && desc.value) {
                const cocok = d.find((x) => x.nama === desc.value);
                if (cocok) detail.value = cocok.kode;
            }
            if (detail.value) detail.dispatchEvent(new Event('change'));
        });
    });
    detail.addEventListener('change', () => {
        const x = detailData.find((y) => y.kode === detail.value);
        desc.value = x ? x.nama : '';
    });

    let tunda = null;
    nik.addEventListener('input', () => {
        clearTimeout(tunda);
        opNama.textContent = '';
        const kata = nik.value.trim();
        if (kata.length < 2) { saran.hidden = true; return; }
        tunda = setTimeout(() => ambil(url.operator + '?q=' + encodeURIComponent(kata)).then((d) => {
            saran.replaceChildren();
            d.forEach((op) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.textContent = op.nik + ' — ' + op.nama;
                b.addEventListener('click', () => {
                    nik.value = op.nik;
                    opNama.textContent = op.nama;
                    saran.hidden = true;
                });
                saran.append(b);
            });
            saran.hidden = d.length === 0;
        }), 250);
    });
    document.addEventListener('click', (e) => { if (!saran.contains(e.target) && e.target !== nik) saran.hidden = true; });

    const hitung = () => {
        if (!mulai.value || !selesai.value) { durasi.textContent = '0 menit'; return; }
        const [a, b] = mulai.value.split(':').map(Number);
        const [c, d] = selesai.value.split(':').map(Number);
        let t = c * 60 + d - (a * 60 + b);
        if (t <= 0) t += 1440; // melewati tengah malam (shift 3)
        durasi.textContent = t + ' menit';
    };
    mulai.addEventListener('change', hitung);
    selesai.addEventListener('change', hitung);
    hitung();

    modalEl.querySelectorAll('[data-jam-kini]').forEach((b) => b.addEventListener('click', () => {
        const el = q(b.dataset.jamKini);
        el.value = jamKini();
        el.dispatchEvent(new Event('change'));
    }));

    return Promise.all([muatMesin, muatKategori]);
}

if (root) {
    const url = JSON.parse(root.dataset.url);
    const tambah = document.getElementById('modalTambahData');
    const ubah = document.getElementById('modalUbahData');
    const siapTambah = siapkanModal(tambah, url);
    const siapUbah = siapkanModal(ubah, url);
    const jadwal = JSON.parse(root.dataset.shift || '{}');

    // Form Tambah selalu dibuka kosong lalu diisi otomatis (kecuali setelah gagal validasi,
    // saat isian sebelumnya dipertahankan).
    let pertahankan = root.dataset.buka === 'tambah';
    tambah.addEventListener('show.bs.modal', () => {
        if (pertahankan) { pertahankan = false; return; }
        const form = tambah.querySelector('form');
        const q = (s) => tambah.querySelector(s);
        form.reset();
        ['.js-mesin-nama', '.js-operator-nama', '.js-item-nama'].forEach((s) => { q(s).textContent = ''; });
        q('[name="tgl_trs"]').value = tanggalHariIni();
        q('.js-shift').value = shiftSekarang(jadwal);
        q('.js-total-durasi').textContent = '0 menit';
        const t = bacaTerakhir();
        q('.js-prefill-terakhir').hidden = !t;
        q('.js-prefill-info').hidden = false;
        siapTambah.then(() => {
            const m = q('.js-mesin-select');
            q('.js-item-select').dataset.initial = t?.itemno || '';
            m.value = t?.mesin_code || '';
            m.dispatchEvent(new Event('change'));
            q('.js-problem-kategori').value = '';
            q('.js-problem-kategori').dispatchEvent(new Event('change'));
            if (t) {
                q('.js-nik-input').value = t.nik || '';
                q('.js-operator-nama').textContent = t.operator_nama || '';
                q('[name="speed_mesin"]').value = t.speed_mesin || '';
            }
        });
    });
    // Ingat isian yang biasanya sama antar-kejadian (mesin, operator, speed, item).
    tambah.querySelector('form').addEventListener('submit', () => {
        const q = (s) => tambah.querySelector(s);
        simpanTerakhir({
            mesin_code: q('.js-mesin-select').value, itemno: q('.js-item-select').value,
            nik: q('.js-nik-input').value, operator_nama: q('.js-operator-nama').textContent,
            speed_mesin: q('[name="speed_mesin"]').value,
        });
    });

    document.querySelectorAll('[data-aksi-tambah]').forEach((b) => b.addEventListener('click', (e) => {
        e.stopPropagation();
        bootstrap.Modal.getOrCreateInstance(tambah).show();
    }));

    // Ubah: isi form dari data tersimpan.
    document.querySelectorAll('[data-aksi-ubah]').forEach((b) => b.addEventListener('click', (e) => {
        e.stopPropagation();
        const noTrs = b.dataset.aksiUbah || document.getElementById('panelDetailJudul').textContent;
        const form = ubah.querySelector('form');
        ubah.querySelector('.js-judul-notrs').textContent = noTrs;
        bootstrap.Offcanvas.getInstance(document.getElementById('panelDetail'))?.hide();
        bootstrap.Modal.getOrCreateInstance(ubah).show();
        Promise.all([siapUbah, ambil(form.dataset.urlUpdate + '/' + encodeURIComponent(noTrs) + '/edit-data')]).then(([, d]) => {
            const q = (s) => ubah.querySelector(s);
            form.action = form.dataset.urlUpdate + '/' + encodeURIComponent(noTrs);
            q('.js-editing-no-trs').value = noTrs;
            q('[name="tgl_trs"]').value = d.tgl_trs;
            q('[name="shift"]').value = d.shift;
            q('[name="speed_mesin"]').value = d.speed_mesin;
            q('.js-time-start').value = d.time_start;
            q('.js-time-end').value = d.time_end;
            q('.js-time-end').dispatchEvent(new Event('change'));
            q('.js-nik-input').value = d.nik;
            q('.js-operator-nama').textContent = d.operator_nama || '';
            q('.js-problem-desc').value = d.problem_desc || '';
            q('.js-item-select').dataset.initial = d.itemno || '';
            q('.js-problem-detail').dataset.initial = d.problem_detail_kode || '';
            const m = q('.js-mesin-select');
            m.value = d.mesin_code;
            m.dispatchEvent(new Event('change'));
            const k = q('.js-problem-kategori');
            k.value = d.problem_code;
            k.dispatchEvent(new Event('change'));
        }).catch(() => alert('Gagal memuat data ' + noTrs + '. Tutup lalu coba lagi.'));
    }));

    // Hapus: konfirmasi dulu.
    const hapus = document.getElementById('modalHapusData');
    document.querySelectorAll('[data-aksi-hapus]').forEach((b) => b.addEventListener('click', (e) => {
        e.stopPropagation();
        const noTrs = b.dataset.aksiHapus || document.getElementById('panelDetailJudul').textContent;
        document.getElementById('formHapusData').action = url.hapus + '/' + encodeURIComponent(noTrs);
        hapus.querySelector('.js-hapus-notrs').textContent = noTrs;
        bootstrap.Offcanvas.getInstance(document.getElementById('panelDetail'))?.hide();
        bootstrap.Modal.getOrCreateInstance(hapus).show();
    }));

    // Buka otomatis: ?tambah=1 (menu Tambah Data) atau setelah validasi gagal.
    const buka = root.dataset.buka;
    if (buka === 'tambah') bootstrap.Modal.getOrCreateInstance(tambah).show();
    if (buka === 'edit') {
        siapUbah.then(() => {
            ubah.querySelector('.js-judul-notrs').textContent = ubah.querySelector('.js-editing-no-trs').value;
            bootstrap.Modal.getOrCreateInstance(ubah).show();
        });
    }
}
