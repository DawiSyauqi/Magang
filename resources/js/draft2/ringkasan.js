// =============================================================================
// draft2/ringkasan.js — chart variasi desain 2 (referensi NexHome)
// Data sama dengan draft 1 (DowntimeAnalytics::ringkasan).
// =============================================================================
import { echarts, fmtMenit, fmtAngka } from '../draft/chart-theme';
import { GaugeChart } from 'echarts/charts';

echarts.use([GaugeChart]);

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
        text: v('--text'), muted: v('--muted'), subtle: v('--subtle'), line: v('--line'),
        card: v('--card'), card2: v('--card-2'), accent: v('--accent'), accent2: v('--accent-2'),
        green: v('--green'), red: v('--red'),
        dark: document.documentElement.getAttribute('data-theme') !== 'light',
    };
}

const grad = (c1, c2, horizontal = false) => ({
    type: 'linear', x: 0, y: 0, x2: horizontal ? 1 : 0, y2: horizontal ? 0 : 1,
    colorStops: [{ offset: 0, color: c1 }, { offset: 1, color: c2 }],
});

function base(t) {
    return {
        backgroundColor: 'transparent',
        textStyle: { fontFamily: 'Inter, system-ui, sans-serif', color: t.muted },
        tooltip: {
            backgroundColor: t.dark ? 'rgba(21,28,42,.96)' : '#fff', borderColor: t.line, borderWidth: 1,
            textStyle: { color: t.text, fontSize: 12 }, extraCssText: 'border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.3);',
        },
    };
}
const axis = (t) => ({
    axisLine: { show: false }, axisTick: { show: false },
    axisLabel: { color: t.muted, fontSize: 11 },
    splitLine: { lineStyle: { color: t.line } },
});

function mount(id, build) {
    const el = document.getElementById(id);
    if (!el) return;
    let chart = echarts.init(el);
    const render = () => chart.setOption(build(tk()), true);
    render();
    new ResizeObserver(() => chart.resize()).observe(el);
    document.addEventListener('themechange', () => { chart.dispose(); chart = echarts.init(el); render(); });
    return { get chart() { return chart; }, render };
}

// ------------------------------------------------------------ gauge availability
mount('nxGauge', (t) => ({
    ...base(t),
    series: [{
        type: 'gauge', startAngle: 210, endAngle: -30, min: 80, max: 100, radius: '100%', center: ['50%', '60%'],
        progress: { show: true, roundCap: true, width: 12, itemStyle: { color: grad(t.green, '#a7f3d0', true) } },
        axisLine: { roundCap: true, lineStyle: { width: 12, color: [[1, t.dark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.07)']] } },
        pointer: { show: false }, axisTick: { show: false }, splitLine: { show: false }, axisLabel: { show: false },
        anchor: { show: false },
        title: { offsetCenter: [0, '38%'], color: t.muted, fontSize: 11 },
        detail: {
            offsetCenter: [0, '2%'], valueAnimation: true, color: t.text, fontSize: 26, fontWeight: 700,
            formatter: (v) => `${v.toFixed(1).replace('.', ',')}%`,
        },
        data: [{ value: Math.max(80, data.kpi.persen.availability), name: 'availability' }],
    }],
}));

// ------------------------------------------------------------ sparkline downtime %
mount('nxSparkPersen', (t) => ({
    ...base(t), animation: false,
    grid: { left: 0, right: 0, top: 4, bottom: 0 },
    xAxis: { type: 'category', show: false, data: data.kpi.total.spark.map((_, i) => i) },
    yAxis: { type: 'value', show: false, min: 0 },
    series: [{
        type: 'line', data: data.kpi.total.spark, smooth: true, symbol: 'none',
        lineStyle: { color: t.green, width: 1.5 }, areaStyle: { color: grad(t.green + '66', t.green + '00') },
    }],
}));

// ------------------------------------------------------------ ring total (kini vs lalu)
mount('nxRing', (t) => {
    const now = data.kpi.total.nilai, lalu = data.kpi.total.lalu || 1;
    const rasio = Math.min(150, Math.round(now / lalu * 100));
    return {
        ...base(t),
        series: [{
            type: 'gauge', startAngle: 200, endAngle: -20, min: 0, max: 150, radius: '100%', center: ['50%', '62%'],
            progress: { show: true, roundCap: true, width: 14, itemStyle: { color: grad('#ffd08a', t.accent, true), shadowBlur: 12, shadowColor: t.accent + '88' } },
            axisLine: { roundCap: true, lineStyle: { width: 14, color: [[1, t.dark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.07)']] } },
            pointer: { show: false }, axisTick: { show: false }, splitLine: { show: false }, axisLabel: { show: false },
            title: { offsetCenter: [0, '72%'], color: t.muted, fontSize: 11 },
            detail: { offsetCenter: [0, '-4%'], color: t.text, fontSize: 22, fontWeight: 700, formatter: () => fmtMenit(now) },
            data: [{ value: rasio, name: `${rasio}% dari periode lalu` }],
        }],
    };
});

// ------------------------------------------------------------ hero tren (tab menit/kejadian)
let seriTren = 'menit';
const tren = mount('nxTren', (t) => {
    const nilai = data.tren[seriTren];
    const menit = seriTren === 'menit';
    return {
        ...base(t),
        tooltip: {
            ...base(t).tooltip, trigger: 'axis',
            formatter: (p) => {
                const iso = data.tren.tanggal[p[0].dataIndex];
                return `<b>${hariId[tgl(iso).getDay()]}, ${labelTgl(iso)}</b><br>${menit ? fmtMenit(p[0].value) : fmtAngka(p[0].value) + ' kejadian'}`;
            },
        },
        grid: { left: 4, right: 8, top: 16, bottom: 4, containLabel: true },
        xAxis: { type: 'category', data: data.tren.tanggal.map(labelTgl), boundaryGap: false, ...axis(t), splitLine: { show: false } },
        yAxis: { type: 'value', ...axis(t) },
        series: [{
            type: 'line', data: nilai, smooth: 0.4, symbol: 'circle', symbolSize: 7, showSymbol: false,
            lineStyle: { width: 3, color: grad('#ffc46b', t.accent, true), shadowBlur: 14, shadowColor: t.accent + '66' },
            itemStyle: { color: t.accent, borderColor: '#fff', borderWidth: 2 },
            areaStyle: { color: grad(t.accent + '55', t.accent + '00') },
            markLine: menit ? {
                silent: true, symbol: 'none', lineStyle: { color: t.muted, type: 'dashed' },
                label: { color: t.muted, fontSize: 10, position: 'insideEndTop', formatter: 'rata-rata' },
                data: [{ yAxis: data.tren.rata }],
            } : undefined,
        }],
    };
});
document.querySelectorAll('#nxTrenTabs a').forEach((a) => a.addEventListener('click', (e) => {
    e.preventDefault();
    document.querySelectorAll('#nxTrenTabs a').forEach((x) => x.classList.toggle('active', x === a));
    seriTren = a.dataset.seri;
    tren.render();
}));

// ------------------------------------------------------------ 7 hari (batang tertinggi disorot)
mount('nxWeek', (t) => {
    const tanggal = data.tren.tanggal.slice(-7);
    const nilai = data.tren.menit.slice(-7);
    const maks = Math.max(...nilai, 0);
    return {
        ...base(t),
        tooltip: { ...base(t).tooltip, trigger: 'axis', axisPointer: { type: 'none' }, formatter: (p) => `<b>${labelTgl(tanggal[p[0].dataIndex])}</b><br>${fmtMenit(p[0].value)}` },
        grid: { left: 0, right: 0, top: 30, bottom: 0, containLabel: true },
        xAxis: { type: 'category', data: tanggal.map((d) => hariId[tgl(d).getDay()]), ...axis(t), splitLine: { show: false } },
        yAxis: { type: 'value', ...axis(t), axisLabel: { show: false } },
        series: [{
            type: 'bar', barWidth: '46%',
            data: nilai.map((v) => ({
                value: v,
                itemStyle: {
                    borderRadius: 8,
                    color: v === maks ? grad('#ffc46b', t.accent) : grad('#5eead4', t.dark ? '#115e59' : '#99f6e4'),
                },
                label: v === maks ? {
                    show: true, position: 'top', distance: 6, formatter: () => fmtMenit(v),
                    backgroundColor: t.accent, color: '#fff', padding: [4, 8], borderRadius: 8, fontSize: 11, fontWeight: 600,
                } : { show: false },
            })),
        }],
    };
});

// ------------------------------------------------------------ pareto
mount('nxPareto', (t) => ({
    ...base(t),
    tooltip: {
        ...base(t).tooltip, trigger: 'axis', axisPointer: { type: 'shadow' },
        formatter: (p) => { const k = data.pareto[p[0].dataIndex]; return `<b>${k.kode} · ${k.nama}</b><br>${fmtMenit(k.menit)} · ${k.kejadian} kejadian<br>Kumulatif <b>${k.kumulatif}%</b>`; },
    },
    grid: { left: 4, right: 4, top: 20, bottom: 4, containLabel: true },
    xAxis: { type: 'category', data: data.pareto.map((k) => k.kode), ...axis(t), splitLine: { show: false } },
    yAxis: [
        { type: 'value', ...axis(t) },
        { type: 'value', min: 0, max: 100, axisLabel: { formatter: '{value}%', color: t.muted, fontSize: 10 }, axisLine: { show: false }, splitLine: { show: false } },
    ],
    series: [
        { type: 'bar', barWidth: '50%', data: data.pareto.map((k) => ({ value: k.menit, itemStyle: { borderRadius: 10, color: grad(warna[k.kode], warna[k.kode] + '33') } })) },
        {
            type: 'line', yAxisIndex: 1, data: data.pareto.map((k) => k.kumulatif), smooth: true, symbolSize: 7,
            lineStyle: { color: t.accent2, width: 2 }, itemStyle: { color: t.accent2 },
            markLine: { silent: true, symbol: 'none', lineStyle: { color: t.red, type: 'dashed' }, label: { formatter: '80%', color: t.red, fontSize: 10, position: 'insideEndTop' }, data: [{ yAxis: 80 }] },
        },
    ],
}));

// ------------------------------------------------------------ shift
mount('nxShift', (t) => {
    const total = data.per_shift.reduce((a, s) => a + s.menit, 0);
    const c = [[t.accent, '#ffc46b'], ['#2dd4bf', '#0f766e'], ['#a78bfa', '#6d28d9']];
    return {
        ...base(t),
        tooltip: { ...base(t).tooltip, formatter: (p) => { const s = data.per_shift[p.dataIndex]; return `<b>Shift ${s.shift}</b> (${s.jam})<br>${fmtMenit(s.menit)} · ${s.kejadian} kejadian${s.dominan ? '<br>Dominan ' + s.dominan + ' ' + namaKat[s.dominan] : ''}`; } },
        legend: { bottom: 0, icon: 'circle', itemWidth: 8, textStyle: { color: t.muted, fontSize: 11 } },
        series: [{
            type: 'pie', radius: ['56%', '80%'], center: ['50%', '44%'], padAngle: 3,
            itemStyle: { borderRadius: 10 },
            label: { show: true, position: 'center', formatter: () => `{v|${total ? fmtMenit(total) : '0 m'}}\n{l|total}`, rich: { v: { color: t.text, fontSize: 17, fontWeight: 700, lineHeight: 24 }, l: { color: t.muted, fontSize: 11 } } },
            data: data.per_shift.map((s, i) => ({ name: `S${s.shift} ${total ? Math.round(s.menit / total * 100) : 0}%`, value: s.menit, itemStyle: { color: grad(c[i][0], c[i][1]) } })),
        }],
    };
});

// ------------------------------------------------------------ top mesin
mount('nxMesin', (t) => {
    const m = data.top_mesin;
    const rev = (a) => [...a].reverse();
    return {
        ...base(t),
        tooltip: {
            ...base(t).tooltip, trigger: 'axis', axisPointer: { type: 'shadow' },
            formatter: (p) => {
                const i = m.mesin.length - 1 - p[0].dataIndex;
                let s = `<b>${m.mesin[i]}</b> ${m.nama[i]}<br>Total <b>${fmtMenit(m.total[i])}</b><br>`;
                p.filter((x) => x.value > 0).sort((a, b) => b.value - a.value).forEach((x) => { s += `${x.marker} ${x.seriesName} ${namaKat[x.seriesName]}: ${fmtMenit(x.value)}<br>`; });
                return s;
            },
        },
        grid: { left: 4, right: 8, top: 6, bottom: 4, containLabel: true },
        xAxis: { type: 'value', ...axis(t), axisLabel: { show: false } },
        yAxis: { type: 'category', data: rev(m.mesin), ...axis(t), axisLabel: { color: t.text, fontWeight: 600, fontSize: 11 }, splitLine: { show: false } },
        series: m.seri.map((s, idx) => ({
            name: s.kode, type: 'bar', stack: 'x', barWidth: 12, data: rev(s.data),
            itemStyle: { color: warna[s.kode], borderRadius: idx === m.seri.length - 1 ? [0, 6, 6, 0] : idx === 0 ? [6, 0, 0, 6] : 0 },
        })),
    };
});

// ------------------------------------------------------------ heatmap
mount('nxHeatmap', (t) => {
    const jam = Array.from({ length: 24 }, (_, i) => String(i).padStart(2, '0'));
    return {
        ...base(t),
        tooltip: { ...base(t).tooltip, formatter: (p) => `<b>${data.heatmap.hari[p.value[1]]}, ${jam[p.value[0]]}:00</b><br>${fmtMenit(p.value[2])}` },
        grid: { left: 4, right: 4, top: 10, bottom: 44, containLabel: true },
        xAxis: {
            type: 'category', data: jam, ...axis(t), splitLine: { show: false },
            axisLabel: { color: t.muted, fontSize: 10, interval: 0, formatter: (v) => (['07', '15', '23'].includes(v) ? `{s|${v}}` : v), rich: { s: { color: t.accent, fontWeight: 700, fontSize: 10 } } },
        },
        yAxis: { type: 'category', data: data.heatmap.hari, inverse: true, ...axis(t), splitLine: { show: false } },
        visualMap: {
            min: 0, max: Math.max(1, data.heatmap.maks), orient: 'horizontal', left: 'center', bottom: 0, itemWidth: 10, itemHeight: 160,
            text: ['banyak', 'sedikit'], textStyle: { color: t.muted, fontSize: 10 },
            inRange: { color: t.dark ? ['#1a2333', '#134e4a', '#14b8a6', '#f59e42', '#ef4444'] : ['#f5efe7', '#99f6e4', '#14b8a6', '#f59e42', '#dc2626'] },
        },
        series: [{ type: 'heatmap', data: data.heatmap.data, itemStyle: { borderColor: t.card, borderWidth: 3, borderRadius: 7 } }],
    };
});
