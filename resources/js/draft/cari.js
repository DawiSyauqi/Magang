// Pencarian global di topbar draft (saran 2.13). Hasil dari /draft/cari?q=,
// dikelompokkan (Halaman, Mesin, Catatan, Masalah, Item, Operator).
// Keyboard: "/" atau Ctrl+K fokus, ↑/↓ pilih, Enter buka, Esc tutup.
const root = document.getElementById('dxCari');

if (root) {
    const input = root.querySelector('[data-cari-input]');
    const kotak = root.querySelector('[role="combobox"]');
    const hasil = document.getElementById('dxCariHasil');
    let pilih = -1;
    let timer = null;
    let urutan = 0;

    const tautan = () => [...hasil.querySelectorAll('a')];
    const tandai = (i) => {
        const a = tautan();
        if (!a.length) return;
        pilih = (i + a.length) % a.length;
        a.forEach((x, k) => x.setAttribute('aria-selected', k === pilih ? 'true' : 'false'));
        a[pilih].scrollIntoView({ block: 'nearest' });
    };
    const tutup = () => {
        hasil.hidden = true;
        kotak.setAttribute('aria-expanded', 'false');
        root.classList.remove('terbuka');
    };
    const pesan = (teks) => {
        const d = document.createElement('div');
        d.className = 'dx-cari-kosong';
        d.textContent = teks;
        hasil.replaceChildren(d);
        hasil.hidden = false;
    };

    const tampilkan = (grup, q) => {
        pilih = -1;
        if (!grup.length) { pesan('Tidak ada yang cocok dengan "' + q + '".'); return; }
        const frag = document.createDocumentFragment();
        grup.forEach((g) => {
            const h = document.createElement('div');
            h.className = 'dx-cari-grup';
            h.textContent = g.judul + (g.lebih ? ' (+' + g.lebih + ' lagi)' : '');
            frag.append(h);
            g.hasil.forEach((r) => {
                const a = document.createElement('a');
                a.href = r.url;
                a.setAttribute('role', 'option');
                const i = document.createElement('i'); i.className = 'bi ' + r.ikon;
                const isi = document.createElement('div');
                const l = document.createElement('div'); l.className = 'l'; l.textContent = r.label;
                const s = document.createElement('div'); s.className = 's'; s.textContent = r.sub;
                isi.append(l, s);
                a.append(i, isi);
                frag.append(a);
            });
        });
        hasil.replaceChildren(frag);
        hasil.hidden = false;
        kotak.setAttribute('aria-expanded', 'true');
    };

    const cari = () => {
        const q = input.value.trim();
        if (q.length < 2) { if (q.length) pesan('Ketik minimal 2 huruf.'); else tutup(); return; }
        const ini = ++urutan;
        const url = new URL(root.dataset.url, location.href);
        url.searchParams.set('q', q);
        fetch(url, { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((j) => { if (ini === urutan) tampilkan(j.grup || [], q); })
            .catch(() => { if (ini === urutan) pesan('Pencarian gagal, coba lagi.'); });
    };

    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(cari, 200); });
    input.addEventListener('focus', () => { if (input.value.trim().length >= 2) cari(); });
    input.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); tandai(pilih + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); tandai(pilih - 1); }
        else if (e.key === 'Enter') {
            const a = tautan()[pilih >= 0 ? pilih : 0];
            if (a) { e.preventDefault(); location.href = a.href; }
        } else if (e.key === 'Escape') { tutup(); input.blur(); }
    });
    document.addEventListener('click', (e) => { if (!root.contains(e.target)) tutup(); });
    document.addEventListener('keydown', (e) => {
        const ketik = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName) || document.activeElement?.isContentEditable;
        if ((e.key === '/' && !ketik) || (e.key.toLowerCase() === 'k' && (e.ctrlKey || e.metaKey))) {
            e.preventDefault();
            root.classList.add('terbuka');
            input.focus();
            input.select();
        }
    });
    // Layar sempit: kotak pencarian disembunyikan, dibuka lewat tombol ikon.
    root.querySelector('[data-cari-buka]').addEventListener('click', () => {
        root.classList.add('terbuka');
        input.focus();
    });
    input.addEventListener('blur', () => setTimeout(() => {
        if (!root.contains(document.activeElement) && hasil.hidden) root.classList.remove('terbuka');
    }, 150));
}
