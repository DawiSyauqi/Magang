// =============================================================================
// draft3/ringkasan.js — chart variasi desain 3 (referensi Savance, glass)
// Data sama dengan draft 1 (DowntimeAnalytics::ringkasan).
// =============================================================================
import { echarts, fmtMenit, fmtAngka } from '../draft/chart-theme';

const data = JSON.parse(document.getElementById('ringkasanData').textContent);
const warna = Object.fromEntries(data.kategori.map((k) => [k.kode, k.warna]));
const namaKat = Object.fromEntries(data.kategori.map((k) => [k.kode, k.nama]));
const hariId = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
const tgl = (iso) => new Date(iso + 'T00:00:00');
const labelTgl = (iso) => `${tgl(iso).getDate()}/${tgl(iso).getMonth() + 1}`;

function tk() {
    const s = getComputedStyle(document.documentElement);
    const v = (n) => s.getPropertyValue(n).trim();
    return {
        text: v('--text'), muted: v('--muted'), subtle: v('--subtle'), line: v('--glass-border'),
        orange: v('--accent'), lavender: v('--lavender'), yellow: v('--yellow'), sky: v('--sky'), red: v('--red'),
        dark: document.documentElement.getAttribute('data-theme') !== 'light',
    };
}
const grad = (c1, c2, h = false) => ({ type: 'linear', x: 0, y: 0, x2: h ? 1 : 0, y2: h ? 0 : 1, colorStops: [{ offset: 0, color: c1 }, { offset: 1, color: c2 }] });
// Hex + alpha (0..1) → rgba, supaya batang bisa memudar ke bawah seperti referensi.
const alpha = (hex, a) => {
    const h = hex.replace('#', '');
    const n = parseInt(h.length === 3 ? h.split('').map((c) => c + c).join('') : h, 16);
    return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${a})`;
};

function base(t) {
    return {
        backgroundColor: 'transparent',
        textStyle: { fontFamily: 'Inter, system-ui, sans-serif', color: t.muted },
        tooltip: {
            backgroundColor: t.dark ? 'rgba(30,22,18,.92)' : 'rgba(255,255,255,.95)', borderColor: t.line, borderWidth: 1,
            textStyle: { color: t.text, fontSize: 12 }, extraCssText: 'border-radius:14px;backdrop-filter:blur(12px);box-shadow:0 10px 30px rgba(0,0,0,.25);',
        },
    };
}
const axis = (t) => ({
    axisLine: { show: false }, axisTick: { show: false },
    axisLabel: { color: t.muted, fontSize: 11 },
    splitLine: { lineStyle: { color: t.line, type: [3, 4] } },
});

function mount(id, build) {
    const el = document.getElementById(id);
    if (!el) return;
    let chart = echarts.init(el);
    const render = () => chart.setOption(build(tk()), true);
    render();
    new ResizeObserver(() => chart.resize()).observe(el);
    document.addEventListener('themechange', () => { chart.dispose(); chart = echarts.init(el); render(); });
}

// ------------------------------------------------------------ sparkline total
mount('svSpark', (t) => ({
    ...base(t), animation: false,
    grid: { left: 0, right: 0, top: 4, bottom: 0 },
    xAxis: { type: 'category', show: false, data: data.kpi.total.spark.map((_, i) => i) },
    yAxis: { type: 'value', show: false, min: 0 },
    series: [{ type: 'line', data: data.kpi.total.spark, smooth: true, symbol: 'none', lineStyle: { color: t.orange, width: 2 }, areaStyle: { color: grad(alpha(t.orange, 0.45), alpha(t.orange, 0)) } }],
}));

// ------------------------------------------------------------ tren: batang menit + kejadian
mount('svTren', (t) => ({
    ...base(t),
    tooltip: {
        ...base(t).tooltip, trigger: 'axis', axisPointer: { type: 'shadow', shadowStyle: { color: 'rgba(255,255,255,.05)' } },
        formatter: (p) => {
            const iso = data.tren.tanggal[p[0].dataIndex];
            return `<b>${hariId[tgl(iso).getDay()]}, ${labelTgl(iso)}</b><br>${p.map((x) => `${x.marker} ${x.seriesName}: <b>${x.seriesIndex === 0 ? fmtMenit(x.value) : fmtAngka(x.value)}</b>`).join('<br>')}`;
        },
    },
    legend: { top: 0, right: 0, icon: 'circle', itemWidth: 8, textStyle: { color: t.muted, fontSize: 11 } },
    grid: { left: 4, right: 4, top: 30, bottom: 4, containLabel: true },
    xAxis: { type: 'category', data: data.tren.tanggal.map(labelTgl), ...axis(t), splitLine: { show: false } },
    yAxis: [{ type: 'value', ...axis(t) }, { type: 'value', ...axis(t), splitLine: { show: false } }],
    series: [
        { name: 'Menit', type: 'bar', barWidth: 7, barGap: '40%', data: data.tren.menit, itemStyle: { borderRadius: 6, color: grad(t.orange, alpha(t.orange, 0.15)) } },
        { name: 'Kejadian', type: 'bar', yAxisIndex: 1, barWidth: 7, data: data.tren.kejadian, itemStyle: { borderRadius: 6, color: grad(t.lavender, alpha(t.lavender, 0.15)) } },
    ],
}));

// ------------------------------------------------------------ per shift (batang tebal memudar)
mount('svShift', (t) => {
    const c = [t.orange, t.lavender, t.sky];
    return {
        ...base(t),
        tooltip: { ...base(t).tooltip, trigger: 'axis', axisPointer: { type: 'none' }, formatter: (p) => { const s = data.per_shift[p[0].dataIndex]; return `<b>Shift ${s.shift}</b> (${s.jam})<br>${fmtMenit(s.menit)} · ${s.kejadian} kejadian`; } },
        grid: { left: 4, right: 4, top: 26, bottom: 4, containLabel: true },
        xAxis: { type: 'category', data: data.per_shift.map((s) => `Shift ${s.shift}`), ...axis(t), splitLine: { show: false } },
        yAxis: { type: 'value', ...axis(t) },
        series: [{
            type: 'bar', barWidth: 34,
            data: data.per_shift.map((s, i) => ({ value: s.menit, itemStyle: { borderRadius: [12, 12, 4, 4], color: grad(c[i], alpha(c[i], 0.25)) } })),
            label: { show: true, position: 'top', color: t.text, fontSize: 11, fontWeight: 600, formatter: (p) => fmtMenit(p.value) },
        }],
    };
});

// ------------------------------------------------------------ pareto (warna kategori memudar)
mount('svPareto', (t) => ({
    ...base(t),
    tooltip: { ...base(t).tooltip, trigger: 'axis', axisPointer: { type: 'none' }, formatter: (p) => { const k = data.pareto[p[0].dataIndex]; return `<b>${k.kode} · ${k.nama}</b><br>${fmtMenit(k.menit)} · ${k.kejadian} kejadian<br>Kumulatif <b>${k.kumulatif}%</b>`; } },
    grid: { left: 4, right: 4, top: 16, bottom: 4, containLabel: true },
    xAxis: { type: 'category', data: data.pareto.map((k) => k.kode), ...axis(t), splitLine: { show: false } },
    yAxis: [
        { type: 'value', ...axis(t), axisLabel: { show: false } },
        { type: 'value', min: 0, max: 100, axisLabel: { formatter: '{value}%', color: t.muted, fontSize: 10 }, axisLine: { show: false }, splitLine: { show: false } },
    ],
    series: [
        { type: 'bar', barWidth: '48%', data: data.pareto.map((k) => ({ value: k.menit, itemStyle: { borderRadius: [8, 8, 2, 2], color: grad(warna[k.kode], alpha(warna[k.kode], 0.2)) } })) },
        {
            type: 'line', yAxisIndex: 1, data: data.pareto.map((k) => k.kumulatif), smooth: true, symbolSize: 6,
            lineStyle: { color: t.yellow, width: 2 }, itemStyle: { color: t.yellow },
            markLine: { silent: true, symbol: 'none', lineStyle: { color: t.red, type: 'dashed' }, label: { formatter: '80%', color: t.red, fontSize: 10, position: 'insideEndTop' }, data: [{ yAxis: 80 }] },
        },
    ],
}));

// ------------------------------------------------------------ durasi rata-rata 14 hari (line kuning)
mount('svRata', (t) => {
    const nilai = data.kpi.rata.spark;
    const akhir = tgl(data.periode.sampai);
    const label = nilai.map((_, i) => { const d = new Date(akhir); d.setDate(d.getDate() - (nilai.length - 1 - i)); return `${d.getDate()}/${d.getMonth() + 1}`; });
    const maks = Math.max(...nilai, 0);
    return {
        ...base(t),
        tooltip: { ...base(t).tooltip, trigger: 'axis', formatter: (p) => `<b>${p[0].name}</b><br>${p[0].value} mnt / kejadian` },
        grid: { left: 4, right: 10, top: 20, bottom: 4, containLabel: true },
        xAxis: { type: 'category', data: label, boundaryGap: false, ...axis(t), splitLine: { show: false } },
        yAxis: { type: 'value', ...axis(t) },
        series: [{
            type: 'line', data: nilai, smooth: false, symbol: 'circle', symbolSize: 6,
            lineStyle: { color: t.yellow, width: 2 }, itemStyle: { color: t.yellow },
            areaStyle: { color: grad(alpha(t.yellow, 0.18), alpha(t.yellow, 0)) },
            markPoint: maks ? { symbol: 'roundRect', symbolSize: [46, 20], itemStyle: { color: alpha(t.yellow, 0.25) }, label: { color: t.yellow, fontSize: 10, fontWeight: 700 }, data: [{ type: 'max', name: 'maks' }] } : undefined,
        }],
    };
});

// ------------------------------------------------------------ top mesin
mount('svMesin', (t) => {
    const m = data.top_mesin;
    const rev = (a) => [...a].reverse();
    return {
        ...base(t),
        tooltip: {
            ...base(t).tooltip, trigger: 'axis', axisPointer: { type: 'none' },
            formatter: (p) => {
                const i = m.mesin.length - 1 - p[0].dataIndex;
                let s = `<b>${m.mesin[i]}</b> ${m.nama[i]}<br>Total <b>${fmtMenit(m.total[i])}</b><br>`;
                p.filter((x) => x.value > 0).sort((a, b) => b.value - a.value).forEach((x) => { s += `${x.marker} ${x.seriesName} ${namaKat[x.seriesName]}: ${fmtMenit(x.value)}<br>`; });
                return s;
            },
        },
        legend: { top: 0, left: 0, icon: 'circle', itemWidth: 8, textStyle: { color: t.muted, fontSize: 10 } },
        grid: { left: 4, right: 8, top: 30, bottom: 4, containLabel: true },
        xAxis: { type: 'value', ...axis(t) },
        yAxis: { type: 'category', data: rev(m.mesin), ...axis(t), axisLabel: { color: t.text, fontWeight: 600, fontSize: 11 }, splitLine: { show: false } },
        series: m.seri.map((s, idx) => ({
            name: s.kode, type: 'bar', stack: 'x', barWidth: 11, data: rev(s.data),
            itemStyle: { color: alpha(warna[s.kode], 0.85), borderRadius: idx === m.seri.length - 1 ? [0, 6, 6, 0] : idx === 0 ? [6, 0, 0, 6] : 0 },
        })),
    };
});

// ------------------------------------------------------------ heatmap
mount('svHeatmap', (t) => {
    const jam = Array.from({ length: 24 }, (_, i) => String(i).padStart(2, '0'));
    return {
        ...base(t),
        tooltip: { ...base(t).tooltip, formatter: (p) => `<b>${data.heatmap.hari[p.value[1]]}, ${jam[p.value[0]]}:00</b><br>${fmtMenit(p.value[2])}` },
        grid: { left: 4, right: 4, top: 8, bottom: 44, containLabel: true },
        xAxis: {
            type: 'category', data: jam, ...axis(t), splitLine: { show: false },
            axisLabel: { color: t.muted, fontSize: 10, interval: 0, formatter: (v) => (['07', '15', '23'].includes(v) ? `{s|${v}}` : v), rich: { s: { color: t.text, fontWeight: 700, fontSize: 10 } } },
        },
        yAxis: { type: 'category', data: data.heatmap.hari, inverse: true, ...axis(t), splitLine: { show: false } },
        visualMap: {
            min: 0, max: Math.max(1, data.heatmap.maks), orient: 'horizontal', left: 'center', bottom: 0, itemWidth: 10, itemHeight: 160,
            text: ['banyak', 'sedikit'], textStyle: { color: t.muted, fontSize: 10 },
            inRange: { color: t.dark ? ['rgba(255,255,255,.06)', '#6d5dfc', '#b9b4f5', '#f4a259', '#ff7a59'] : ['rgba(255,255,255,.7)', '#c7c2f7', '#7d74e0', '#f4a259', '#e0562f'] },
        },
        series: [{ type: 'heatmap', data: data.heatmap.data, itemStyle: { borderColor: 'transparent', borderWidth: 3, borderRadius: 8 } }],
    };
});
