// =============================================================================
// mesin-profil.js — chart halaman profil mesin: sparkline, timeline
// 07:00→07:00 (custom series), tren harian, porsi kategori.
// =============================================================================
import { echarts, mount, base, axisStyle, fmtMenit } from './chart-theme';
import { CustomChart } from 'echarts/charts';
import { MarkAreaComponent } from 'echarts/components';

echarts.use([CustomChart, MarkAreaComponent]);

const data = JSON.parse(document.getElementById('profilData').textContent);
const warna = Object.fromEntries(data.kategori.map((k) => [k.kode, k.warna]));
const namaKat = Object.fromEntries(data.kategori.map((k) => [k.kode, k.nama]));
const hariId = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
const tgl = (iso) => new Date(iso + 'T00:00:00');
const labelTgl = (iso) => `${tgl(iso).getDate()}/${tgl(iso).getMonth() + 1}`;
const JAM_AWAL = 7;
const labelMenit = (m) => `${String((JAM_AWAL + Math.floor(m / 60)) % 24).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;

// ---------------------------------------------------------------- sparkline KPI
document.querySelectorAll('[data-spark]').forEach((el) => {
    const nilai = JSON.parse(el.dataset.spark);
    mount(el, (t) => ({
        ...base(t), animation: false,
        grid: { left: 0, right: 0, top: 2, bottom: 2 },
        xAxis: { type: 'category', show: false, data: nilai.map((_, i) => i) },
        yAxis: { type: 'value', show: false, min: 0 },
        series: [{ type: 'line', data: nilai, smooth: true, symbol: 'none', lineStyle: { width: 1.5, color: t.accent }, areaStyle: { color: t.accent, opacity: 0.12 } }],
    }));
});

// ---------------------------------------------------------------- timeline (Gantt) 07:00 → 07:00
mount(document.getElementById('chartTimeline'), (t) => {
    const tanggal = data.timeline.tanggal;
    const idx = Object.fromEntries(tanggal.map((d, i) => [d, i]));
    const kejadian = data.timeline.kejadian.map((k) => ({
        value: [idx[k.tanggal], k.mulai, k.selesai, k.selesai - k.mulai],
        itemStyle: { color: warna[k.kategori] },
        info: k,
    }));
    return {
        ...base(t),
        tooltip: {
            ...base(t).tooltip,
            formatter: (p) => {
                const k = p.data.info;
                return `<b>${k.jam}</b> · shift ${k.shift}<br>${k.kategori} ${namaKat[k.kategori]}<br>${k.detail}<br>${fmtMenit(p.value[3])} · ${k.no_trs}`;
            },
        },
        grid: { left: 8, right: 16, top: 26, bottom: 8, containLabel: true },
        xAxis: {
            type: 'value', min: 0, max: 1440, interval: 120, position: 'top',
            axisLabel: { color: t.muted, fontSize: 11, formatter: (v) => labelMenit(v) },
            axisLine: { show: false }, axisTick: { show: false },
            splitLine: { lineStyle: { color: t.border, type: [4, 4] } },
        },
        yAxis: {
            type: 'category', data: tanggal.map((d) => `${hariId[tgl(d).getDay()]} ${labelTgl(d)}`), inverse: true,
            axisLine: { show: false }, axisTick: { show: false }, axisLabel: { color: t.text, fontSize: 11, fontWeight: 600 },
        },
        series: [{
            type: 'custom',
            renderItem: (params, api) => {
                const y = api.value(0);
                const a = api.coord([api.value(1), y]);
                const b = api.coord([api.value(2), y]);
                const h = api.size([0, 1])[1] * 0.56;
                const rect = echarts.graphic.clipRectByRect(
                    { x: a[0], y: a[1] - h / 2, width: Math.max(2, b[0] - a[0]), height: h },
                    { x: params.coordSys.x, y: params.coordSys.y, width: params.coordSys.width, height: params.coordSys.height },
                );
                return rect && { type: 'rect', shape: { ...rect, r: 3 }, style: api.style() };
            },
            encode: { x: [1, 2], y: 0 },
            data: kejadian,
            // Pita latar per shift (480 menit = 8 jam).
            markArea: {
                silent: true,
                label: { show: false },
                data: [
                    [{ name: '', xAxis: 0, itemStyle: { color: t.accent, opacity: 0.03 } }, { xAxis: 480 }],
                    [{ name: '', xAxis: 480, itemStyle: { color: t.accent, opacity: 0.07 } }, { xAxis: 960 }],
                    [{ name: '', xAxis: 960, itemStyle: { color: t.dark ? '#ffffff' : '#1c1917', opacity: 0.05 } }, { xAxis: 1440 }],
                ],
            },
            // Garis pergantian shift (15:00, 23:00); jamnya sudah terbaca di sumbu atas.
            markLine: {
                silent: true, symbol: 'none', lineStyle: { color: t.muted, width: 1 }, label: { show: false },
                data: [{ xAxis: 480 }, { xAxis: 960 }],
            },
        }],
    };
});

// ---------------------------------------------------------------- tren harian
mount(document.getElementById('chartTren'), (t) => ({
    ...base(t),
    tooltip: { ...base(t).tooltip, trigger: 'axis', valueFormatter: (v) => fmtMenit(v) },
    grid: { left: 8, right: 8, top: 20, bottom: data.tren.tanggal.length > 45 ? 40 : 8, containLabel: true },
    dataZoom: data.tren.tanggal.length > 45 ? [{ type: 'slider', height: 18, bottom: 6, borderColor: t.border }] : [],
    xAxis: { type: 'category', data: data.tren.tanggal.map(labelTgl), ...axisStyle(t), splitLine: { show: false } },
    yAxis: { type: 'value', ...axisStyle(t) },
    series: [{
        name: 'Downtime', type: 'bar', data: data.tren.menit, barMaxWidth: 16,
        itemStyle: { color: t.accent, borderRadius: [4, 4, 0, 0] },
        markLine: {
            silent: true, symbol: 'none', lineStyle: { color: t.muted, type: 'dashed' },
            label: { color: t.muted, fontSize: 10, position: 'insideEndTop', formatter: () => `rata-rata ${fmtMenit(Math.round(data.tren.rata))}` },
            data: [{ yAxis: data.tren.rata }],
        },
    }],
}));

// ---------------------------------------------------------------- porsi kategori
mount(document.getElementById('chartKategori'), (t) => {
    const total = data.pareto.reduce((a, k) => a + k.menit, 0);
    return {
        ...base(t),
        tooltip: { ...base(t).tooltip, formatter: (p) => `<b>${p.data.kode} · ${namaKat[p.data.kode]}</b><br>${fmtMenit(p.value)} · ${p.percent}%` },
        legend: { bottom: 0, textStyle: { color: t.muted, fontSize: 11 }, itemWidth: 10, itemHeight: 10 },
        series: [{
            type: 'pie', radius: ['50%', '76%'], center: ['50%', '44%'], padAngle: 2,
            itemStyle: { borderRadius: 6, borderColor: t.surface, borderWidth: 2 },
            label: {
                show: true, position: 'center', formatter: () => `{v|${fmtMenit(total)}}\n{l|total}`,
                rich: { v: { fontSize: 17, fontWeight: 700, color: t.text, lineHeight: 24 }, l: { fontSize: 11, color: t.muted } },
            },
            data: data.pareto.map((k) => ({ name: k.kode, kode: k.kode, value: k.menit, itemStyle: { color: warna[k.kode] } })),
        }],
    };
});
