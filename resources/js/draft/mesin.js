// =============================================================================
// mesin.js — chart halaman Analisis Mesin (perencanaan.md 1.4: M2, M3, M4)
// =============================================================================
import { echarts, mount, base, axisStyle, fmtMenit, skalaPekat } from './chart-theme';
import { ScatterChart, TreemapChart } from 'echarts/charts';
import { MarkAreaComponent } from 'echarts/components';

echarts.use([ScatterChart, TreemapChart, MarkAreaComponent]);

const data = JSON.parse(document.getElementById('mesinData').textContent);
const keProfil = (kode) => { window.location.href = data.url_profil.replace('__KODE__', encodeURIComponent(kode)); };
const labelTgl = (iso) => { const d = new Date(iso + 'T00:00:00'); return `${d.getDate()}/${d.getMonth() + 1}`; };

// ---------------------------------------------------------------- M3 sebaran frekuensi vs durasi
mount(document.getElementById('chartSebaran'), (t) => {
    const s = data.sebaran;
    const maksTotal = Math.max(1, ...s.titik.map((p) => p[2]));
    return {
        ...base(t),
        tooltip: {
            ...base(t).tooltip,
            formatter: (p) => `<b>${p.value[3]}</b> ${p.value[4]}<br>${p.value[0]} kejadian · rata-rata ${p.value[1]} mnt<br>Total <b>${fmtMenit(p.value[2])}</b><br><span style="opacity:.7">klik untuk profil</span>`,
        },
        grid: { left: 8, right: 24, top: 24, bottom: 8, containLabel: true },
        xAxis: { type: 'value', name: 'jumlah kejadian', nameLocation: 'middle', nameGap: 26, nameTextStyle: { color: t.subtle, fontSize: 11 }, scale: true, ...axisStyle(t) },
        yAxis: { type: 'value', name: 'rata-rata menit', nameTextStyle: { color: t.subtle, fontSize: 11, align: 'left' }, scale: true, ...axisStyle(t) },
        series: [{
            type: 'scatter',
            data: s.titik,
            symbolSize: (v) => 14 + (v[2] / maksTotal) * 34,
            itemStyle: {
                color: (p) => (p.value[0] >= s.median_kejadian && p.value[1] >= s.median_rata ? t.accent : (t.dark ? '#6f6962' : '#a19a93')),
                opacity: 0.85, borderColor: t.surface, borderWidth: 1.5,
            },
            label: { show: true, formatter: (p) => p.value[3], position: 'top', color: t.text, fontSize: 11, fontWeight: 600 },
            emphasis: { scale: 1.15 },
            markLine: {
                silent: true, symbol: 'none', lineStyle: { color: t.muted, type: 'dashed', width: 1 }, label: { show: false },
                data: [{ xAxis: s.median_kejadian }, { yAxis: s.median_rata }],
            },
            markArea: {
                silent: true, itemStyle: { color: t.accent, opacity: 0.06 },
                label: { show: true, position: 'insideTopRight', color: t.accent, fontSize: 11, fontWeight: 600 },
                data: [[{ name: 'KRITIS: sering & lama', xAxis: s.median_kejadian, yAxis: s.median_rata }, { xAxis: 'max', yAxis: 'max' }]],
            },
        }],
    };
}, (chart) => chart.on('click', (p) => p.value && keProfil(p.value[3])));

// ---------------------------------------------------------------- M2 treemap kelompok
mount(document.getElementById('chartKelompok'), (t) => {
    const total = data.kelompok.reduce((a, k) => a + k.value, 0) || 1;
    // Gradasi per kelompok: paling besar = merah, paling kecil = biru (label putih tetap terbaca).
    const palet = skalaPekat;
    return {
        ...base(t),
        tooltip: {
            ...base(t).tooltip,
            formatter: (p) => {
                const d = p.data;
                const judul = d.nama_mesin ? `<b>${d.name}</b> ${d.nama_mesin}` : `<b>Kelompok ${d.name}</b>`;
                return `${judul}<br>${fmtMenit(d.value)} · ${(d.value / total * 100).toFixed(1).replace('.', ',')}%`;
            },
        },
        series: [{
            type: 'treemap', roam: false, nodeClick: false, breadcrumb: { show: false },
            top: 4, left: 0, right: 0, bottom: 0,
            levels: [
                { itemStyle: { gapWidth: 4, borderColor: t.surface, borderWidth: 0 } },
                { itemStyle: { gapWidth: 2, borderColor: t.surface, borderWidth: 2, borderRadius: 6 }, upperLabel: { show: true, height: 22, color: '#fff', fontWeight: 700, fontSize: 11 } },
            ],
            upperLabel: { show: true, height: 22, color: '#fff', fontWeight: 700 },
            label: { show: true, formatter: (p) => `${p.name}\n${fmtMenit(p.value)}`, fontSize: 11, fontWeight: 600, color: '#fff', lineHeight: 15 },
            data: data.kelompok.map((k, i) => ({
                name: k.nama, value: k.value,
                itemStyle: { color: palet[Math.min(i, palet.length - 1)], borderRadius: 8 },
                children: k.children.map((c) => ({ name: c.nama, value: c.value, nama_mesin: c.nama_mesin })),
            })),
        }],
    };
}, (chart) => chart.on('click', (p) => p.data?.nama_mesin && keProfil(p.data.name)));

// ---------------------------------------------------------------- M4 perbandingan tren
mount(document.getElementById('chartBanding'), (t) => {
    const warnaGaris = [t.accent, '#2563eb', '#10b981', '#8b5cf6'];
    return {
        ...base(t),
        tooltip: { ...base(t).tooltip, trigger: 'axis', valueFormatter: (v) => fmtMenit(v) },
        legend: { top: 0, right: 0, textStyle: { color: t.muted, fontSize: 11 }, itemWidth: 14, itemHeight: 8 },
        grid: { left: 8, right: 8, top: 34, bottom: data.banding.tanggal.length > 45 ? 40 : 8, containLabel: true },
        dataZoom: data.banding.tanggal.length > 45 ? [{ type: 'slider', height: 18, bottom: 6, borderColor: t.border }] : [],
        xAxis: { type: 'category', data: data.banding.tanggal.map(labelTgl), boundaryGap: false, ...axisStyle(t), splitLine: { show: false } },
        yAxis: { type: 'value', name: 'menit', nameTextStyle: { color: t.subtle, fontSize: 10, align: 'left' }, ...axisStyle(t) },
        series: data.banding.seri.map((s, i) => ({
            name: s.kode, type: 'line', data: s.data, smooth: 0.3, symbol: 'circle', symbolSize: 5,
            showSymbol: data.banding.tanggal.length <= 31,
            lineStyle: { width: i === 0 ? 2.5 : 2, color: warnaGaris[i] }, itemStyle: { color: warnaGaris[i] },
        })),
    };
});
