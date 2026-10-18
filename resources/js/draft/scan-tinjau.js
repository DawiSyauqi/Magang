// =============================================================================
// scan-tinjau.js — layar tinjauan hasil AI Baca Kertas (Eksplorasi 6)
// - kotak ragu (keyakinan < 0,85) ditandai oranye + 3 kandidat; klik kandidat
//   atau ketik untuk mengonfirmasi, lalu lompat ke kotak ragu berikutnya;
// - setiap perubahan menghitung ulang "Kejadian yang akan disimpan" di server
//   (GridTimeMerger yang sama dengan alur scan lama);
// - klik gambar = perbesar.
// =============================================================================
const zoom = document.getElementById('zoom');
const zoomImg = zoom.querySelector('img');
document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-zoom]');
    if (!el) return;
    zoomImg.src = el.dataset.zoom;
    zoomImg.classList.toggle('kotak', el.hasAttribute('data-zoom-kotak'));
    zoom.classList.add('buka');
});
zoom.addEventListener('click', () => zoom.classList.remove('buka'));
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') zoom.classList.remove('buka'); });

const form = document.getElementById('formTinjau');

// Aplikasi asli: daftar item bergantung mesin (MFBOM/MFBMOP) → muat ulang saat mesin diganti.
(() => {
    const item = document.getElementById('h_size');
    const mesin = document.getElementById('h_mesin');
    if (!item || !mesin || !item.dataset.itemUrl) return;
    mesin.addEventListener('change', () => {
        const awal = item.value;
        item.replaceChildren(new Option('Memuat…', ''));
        fetch(item.dataset.itemUrl + '?mesin=' + encodeURIComponent(mesin.value), { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : []))
            .then((d) => {
                item.replaceChildren(new Option(d.length ? 'Produk…' : 'Mesin ini tidak punya daftar item', ''));
                d.forEach((i) => item.append(new Option(i.nama + ' (' + i.kode + ')', i.kode)));
                item.value = d.some((i) => i.kode === awal) ? awal : '';
                item.dispatchEvent(new Event('change'));
            });
    });
})();
if (form) {
    const bisaUbah = form.dataset.bisaUbah === '1';
    const warna = JSON.parse(form.dataset.warna || '{}');
    const kategori = JSON.parse(form.dataset.kategori || '{}');
    const kotak = [...form.querySelectorAll('.sc-kotak')];
    const tbody = document.getElementById('pratinjau');
    const mulai = Date.now();

    // Kode sah: x | 0–8 + huruf (0 dan 8 = bukan downtime, boleh tanpa huruf).
    const sah = (v) => v === '' || /^x$|^[08][a-z]?$|^[1-7][a-z]$/.test(v);

    function perbarui(el) {
        const input = el.querySelector('input');
        const v = input.value.trim().toLowerCase();
        el.classList.toggle('diubah', v !== (el.dataset.ai || ''));
        el.classList.toggle('salah', !sah(v));
        el.querySelectorAll('[data-kandidat]').forEach((b) => b.classList.toggle('pilih', b.dataset.kandidat === v));
    }

    function hitungRagu() {
        const sisa = kotak.filter((el) => el.classList.contains('ragu') && !el.classList.contains('dicek')
            && !el.closest('.sc-shift').classList.contains('diabaikan')).length;
        const out = document.getElementById('sisaRagu');
        if (out) out.textContent = sisa;
        return sisa;
    }

    function raguBerikut(dari) {
        const urut = kotak.filter((el) => el.classList.contains('ragu') && !el.classList.contains('dicek')
            && !el.closest('.sc-shift').classList.contains('diabaikan'));
        const target = urut.find((el) => !dari || kotak.indexOf(el) > kotak.indexOf(dari)) || urut[0];
        if (!target) return;
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        target.querySelector('input').focus({ preventScroll: true });
    }

    kotak.forEach((el) => {
        const input = el.querySelector('input');
        perbarui(el);
        if (!bisaUbah) return;
        input.addEventListener('input', () => {
            input.value = input.value.toLowerCase().replace(/\s/g, '');
            el.classList.add('dicek');
            perbarui(el);
            hitungRagu();
            jadwalPratinjau();
        });
        input.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            e.preventDefault(); // Enter tidak mengirim form
            if (el.classList.contains('ragu')) {
                el.classList.add('dicek'); // Enter = "isi kotak ini sudah benar"
                hitungRagu();
                raguBerikut(el);
            } else {
                kotak[kotak.indexOf(el) + 1]?.querySelector('input').focus();
            }
        });
        el.querySelectorAll('[data-kandidat]').forEach((b) => b.addEventListener('click', () => {
            input.value = b.dataset.kandidat;
            el.classList.add('dicek');
            perbarui(el);
            hitungRagu();
            jadwalPratinjau();
            raguBerikut(el);
        }));
    });

    document.getElementById('btnRaguBerikut')?.addEventListener('click', () => raguBerikut(null));

    form.querySelectorAll('[data-abaikan]').forEach((cb) => cb.addEventListener('change', () => {
        cb.closest('.sc-shift').classList.toggle('diabaikan', cb.checked);
        hitungRagu();
        jadwalPratinjau();
    }));
    form.querySelectorAll('[name^="header["]').forEach((el) => el.addEventListener('change', jadwalPratinjau));

    // --------------------------------------------------------- pratinjau baris
    const jam = (dt) => (dt ? dt.slice(11, 16) : '–');
    function render(rows) {
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted-x py-3">Tidak ada kejadian downtime (semua kotak kosong).</td></tr>';
        } else {
            tbody.innerHTML = '';
            rows.forEach((r) => {
                const tr = document.createElement('tr');
                const kat = r.ProblemCode;
                tr.innerHTML = `<td><span class="dx-shift-badge"></span></td><td class="num"></td><td class="num"></td>
                    <td class="num text-end"></td><td></td><td></td><td class="small"></td>`;
                const td = tr.children;
                td[0].firstChild.textContent = r.ShiftCode;
                td[1].textContent = jam(r.Time_Start);
                td[2].textContent = jam(r.Time_End);
                td[3].textContent = `${r.Time_Total} mnt`;
                td[4].innerHTML = kat ? `<span class="dx-cat" style="--c:${warna[kat] || '#64748b'}"></span>` : '';
                if (kat) td[4].firstChild.textContent = `${kat} · ${r._raw_code}`;
                else td[4].textContent = r._raw_code;
                td[5].textContent = r.Problem_Desc || (kat ? kategori[kat] : '–');
                const alasan = r._alasan || [];
                td[6].textContent = alasan.length ? alasan.join(' ') : 'OK';
                td[6].style.color = alasan.length ? 'var(--up)' : 'var(--down)';
                tbody.appendChild(tr);
            });
        }
        const total = rows.reduce((a, r) => a + (r.Time_Total || 0), 0);
        const masalah = rows.filter((r) => (r._alasan || []).length).length;
        const out = document.getElementById('ringkasBaris');
        if (out) out.textContent = `${rows.length} kejadian · ${total} menit${masalah ? ` · ${masalah} bermasalah` : ''}`;
    }

    let timer = null;
    let urutan = 0;
    function jadwalPratinjau() {
        clearTimeout(timer);
        timer = setTimeout(pratinjau, 350);
    }
    async function pratinjau() {
        const ke = ++urutan;
        const data = new FormData(form);
        // Isian nonaktif (mode hanya-baca) tidak ikut FormData — tambahkan manual.
        form.querySelectorAll('[name^="header["]:disabled').forEach((el) => data.set(el.name, el.value));
        try {
            const res = await fetch(form.dataset.pratinjauUrl, { method: 'POST', body: data, headers: { Accept: 'application/json' } });
            const json = await res.json();
            if (ke === urutan) render(json.rows || []);
        } catch {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center py-3" style="color: var(--up)">Gagal menghitung pratinjau.</td></tr>';
        }
    }

    if (form.dataset.final) {
        render(JSON.parse(form.dataset.final).map((r) => ({ ...r, _alasan: [] })));
    } else {
        pratinjau();
    }
    hitungRagu();

    form.addEventListener('submit', (e) => {
        const sisa = hitungRagu();
        const salah = kotak.filter((el) => el.classList.contains('salah') && !el.closest('.sc-shift').classList.contains('diabaikan'));
        if (salah.length) {
            e.preventDefault();
            alert(`${salah.length} kotak berisi kode tidak sah (bergaris merah). Perbaiki dulu.`);
            salah[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            salah[0].querySelector('input').focus({ preventScroll: true });
            return;
        }
        if (sisa && !confirm(`Masih ada ${sisa} kotak ragu yang belum dicek. Simpan saja?`)) {
            e.preventDefault();
            raguBerikut(null);
            return;
        }
        document.getElementById('detikTinjau').value = Math.round((Date.now() - mulai) / 1000);
    });
}
