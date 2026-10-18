// Lonceng pemberitahuan di topbar draft: memuat daftar dari /draft/pemberitahuan
// setelah halaman tampil (tidak memperlambat render), menandai yang belum dibaca.
// Status "sudah dibaca" hanya kenyamanan per browser (localStorage); di halaman
// asli bisa dipindah ke file per pengguna tanpa mengubah tampilan.
const KUNCI = 'dx-notif-dibaca';
const root = document.getElementById('dxNotif');

function bacaTersimpan() {
    try { return new Set(JSON.parse(localStorage.getItem(KUNCI) || '[]')); } catch (e) { return new Set(); }
}
function simpan(set) {
    try { localStorage.setItem(KUNCI, JSON.stringify([...set].slice(-300))); } catch (e) { /* mode privat */ }
}
function el(tag, cls, teks) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (teks !== undefined) e.textContent = teks;
    return e;
}
function waktuLabel(w) {
    const d = new Date(w.replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return w;
    const menit = Math.round((Date.now() - d.getTime()) / 60000);
    if (menit < 1) return 'baru saja';
    if (menit < 60) return menit + ' mnt lalu';
    if (menit < 1440) return Math.round(menit / 60) + ' jam lalu';
    return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' }) + ' ' + w.slice(11, 16);
}

if (root) {
    const list = root.querySelector('[data-notif-list]');
    const badge = root.querySelector('[data-notif-badge]');
    let items = [];

    const render = () => {
        const dibaca = bacaTersimpan();
        const belum = items.filter((i) => !dibaca.has(i.id)).length;
        badge.hidden = belum === 0;
        badge.textContent = belum > 9 ? '9+' : String(belum);
        list.replaceChildren();
        if (!items.length) {
            list.append(el('div', 'dx-notif-empty', 'Tidak ada pemberitahuan. Semua aman 👍'));
            return;
        }
        items.forEach((i) => {
            const a = el('a', 'dx-notif-item ' + i.tingkat + (dibaca.has(i.id) ? ' dibaca' : ''));
            a.href = i.url;
            const isi = el('div', 'isi');
            isi.append(el('div', 'judul', i.judul), el('div', 'pesan', i.pesan), el('div', 'waktu', waktuLabel(i.waktu)));
            a.append(el('i', 'bi ' + i.ikon), isi);
            a.addEventListener('click', () => { const s = bacaTersimpan(); s.add(i.id); simpan(s); });
            list.append(a);
        });
    };

    const muat = () => fetch(root.dataset.url, { headers: { Accept: 'application/json' } })
        .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
        .then((j) => {
            items = j.items || [];
            root.querySelector('[data-notif-waktu]').textContent = 'pukul ' + j.dihitung;
            render();
        })
        .catch(() => { list.replaceChildren(el('div', 'dx-notif-empty', 'Gagal memuat pemberitahuan.')); });

    root.querySelector('[data-notif-baca]').addEventListener('click', () => {
        const s = bacaTersimpan();
        items.forEach((i) => s.add(i.id));
        simpan(s);
        render();
    });

    muat();
    setInterval(muat, 5 * 60 * 1000); // segarkan tiap 5 menit selama halaman terbuka
}
