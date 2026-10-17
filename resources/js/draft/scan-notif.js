// =============================================================================
// scan-notif.js — pemberitahuan "Selesai diproses" di SEMUA halaman draft
// (petugas). Foto dibaca AI di latar belakang, jadi petugas boleh pindah
// halaman; skrip ini menanyakan status antrian dan memunculkan pemberitahuan
// begitu ada foto yang selesai. Juga memakai Notification API bila diizinkan
// (hanya jalan di localhost/HTTPS — di intranet http cukup toast di halaman).
// =============================================================================
const wadah = document.getElementById('scToast');
const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
const JEDA_AKTIF = 4000;
let timer = null;
let sebelumnya = null;
const judulAsli = document.title;

const pesan = {
    selesai: (s) => ({
        ikon: 'bi-check-circle-fill', buruk: false, judul: 'Selesai diproses',
        isi: s.n_ragu ? `${s.n_ragu} kotak perlu dicek.` : 'Semua kotak terbaca yakin.',
        tombol: 'Tinjau sekarang',
    }),
    perlu_foto_ulang: (s) => ({
        ikon: 'bi-camera', buruk: true, judul: 'Selesai diproses — foto perlu diulang',
        isi: s.pesan || 'Formulir tidak terbaca utuh.', tombol: 'Lihat',
    }),
    gagal: (s) => ({
        ikon: 'bi-x-octagon-fill', buruk: true, judul: 'Selesai diproses — gagal',
        isi: s.pesan || 'Terjadi kesalahan saat membaca foto.', tombol: 'Lihat',
    }),
};

function tampilkan(s) {
    const p = (pesan[s.status] || pesan.gagal)(s);
    const el = document.createElement('div');
    el.className = `sc-toast${p.buruk ? ' buruk' : ''}`;
    el.setAttribute('role', 'status');
    el.innerHTML = `<i class="bi ${p.ikon}"></i><div class="min-w-0"><strong></strong><div class="text-muted-x small mb-1"></div>
        <a href="${s.url}">${p.tombol} <i class="bi bi-arrow-right"></i></a></div>
        <button type="button" class="tutup" aria-label="Tutup"><i class="bi bi-x-lg"></i></button>`;
    el.querySelector('strong').textContent = p.judul;
    el.querySelector('.small').textContent = `Formulir ${s.id.slice(9, 11)}:${s.id.slice(11, 13)} · ${p.isi}`;
    el.querySelector('.tutup').addEventListener('click', () => el.remove());
    wadah.appendChild(el);
    setTimeout(() => el.remove(), 30000);

    if ('Notification' in window && Notification.permission === 'granted' && document.hidden) {
        const n = new Notification(p.judul, { body: p.isi, tag: s.id, icon: '/icons/favicon-32.png' });
        n.onclick = () => { window.focus(); location.href = s.url; };
    }
    document.title = `(✓) ${p.judul} · ${judulAsli}`;
    setTimeout(() => { document.title = judulAsli; }, 8000);

    fetch(`${wadah.dataset.antrianUrl}/${s.id}/diberitahu`, {
        method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
    }).catch(() => {});
}

async function cek() {
    let data;
    try {
        const res = await fetch(wadah.dataset.statusUrl, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error(res.status);
        data = await res.json();
    } catch {
        jadwalkan(JEDA_AKTIF * 3);
        return;
    }
    (data.baru_selesai || []).forEach(tampilkan);

    const sidik = JSON.stringify(data.antrian || []);
    if ((sebelumnya !== null && sidik !== sebelumnya) || (data.baru_selesai || []).length) {
        window.dispatchEvent(new CustomEvent('scan:berubah', { detail: data }));
    }
    sebelumnya = sidik;
    window.dispatchEvent(new CustomEvent('scan:status', { detail: data }));

    if (data.berjalan > 0) jadwalkan(JEDA_AKTIF);
}

function jadwalkan(ms) {
    clearTimeout(timer);
    timer = setTimeout(cek, ms);
}

// Halaman lain (mis. setelah unggah) bisa meminta pengecekan dimulai lagi.
window.addEventListener('scan:mulai', () => jadwalkan(500));

if (wadah) cek();
