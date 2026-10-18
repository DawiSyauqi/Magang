// =============================================================================
// ringkasan.js — chart halaman Ringkasan (perencanaan.md 1.3, R1–R5 + sparkline)
// Data dari <script id="ringkasanData"> (hasil DowntimeAnalytics::ringkasan).
// =============================================================================
import { mount, base, axisStyle, fmtMenit, fmtAngka } from './chart-theme';

const data = JSON.parse(document.getElementById('ringkasanData').textContent);
const warna = Object.fromEntries(data.kategori.map((k) => [k.kode, k.warna]));
const namaKat = Object.fromEntries(data.kategori.map((k) => [k.kode, k.nama]));
const hariId = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];

const labelTanggal = (iso) => {
    const d = new Date(iso + 'T00:00:00');
    return `${d.getDate()}/${d.getMonth() + 1}`;
};

// ---------------------------------------------------------------- sparkline
document.querySelectorAll('[data-spark]').forEach((el) => {
    const nilai = JSON.parse(el.dataset.spark);
    mount(el, (t) => ({
        ...base(t),
        animation: false,
        grid: { left: 0, right: 0, top: 2, bottom: 2 },
        xAxis: { type: 'category', show: false, data: nilai.map((_, i) => i) },
        yAxis: { type: 'value', show: false, min: 0 },
        series: [{
            type: 'line', data: nilai, smooth: true, symbol: 'none',
            lineStyle: { width: 1.5, color: t.accent },
            areaStyle: { color: t.accent, opacity: 0.12 },
        }],
    }));
});

// ---------------------------------------------------------------- R1 tren
mount(document.getElementById('chartTren'), (t) => ({
    ...base(t),
    tooltip: {
        ...base(t).tooltip,
        trigger: 'axis',
        formatter: (p) => {
            const iso = data.tren.tanggal[p[0].dataIndex];
            const d = new Date(iso + 'T00:00:00');
            let s = `<b>${hariId[d.getDay()]}, ${labelTanggal(iso)}</b><br>`;
            p.forEach((x) => {
                s += `${x.marker} ${x.seriesName}: <b>${x.seriesIndex === 0 ? fmtMenit(x.value) : fmtAngka(x.value) + ' kali'}</b><br>`;
            });
            return s;
        },
    },
    legend: { top: 0, right: 0, textStyle: { color: t.muted, fontSize: 11 }, itemWidth: 12, itemHeight: 8 },
    grid: { left: 8, right: 8, top: 40, bottom: data.tren.tanggal.length > 45 ? 40 : 8, containLabel: true },
    dataZoom: data.tren.tanggal.length > 45 ? [{ type: 'slider', height: 18, bottom: 6, borderColor: t.border }] : [],
    xAxis: { type: 'category', data: data.tren.tanggal.map(labelTanggal), ...axisStyle(t), splitLine: { show: false } },
    yAxis: [
        { type: 'value', name: 'menit', nameTextStyle: { color: t.subtle, fontSize: 10, align: 'left' }, ...axisStyle(t), max: (v) => Math.ceil(Math.max(v.max, data.target?.harian || 0) * 1.08 / 100) * 100 },
        { type: 'value', ...axisStyle(t), splitLine: { show: false } },
    ],
    series: [
        {
            name: 'Downtime', type: 'line', data: data.tren.menit, smooth: 0.3, symbol: 'circle', symbolSize: 5, showSymbol: data.tren.tanggal.length <= 31,
            lineStyle: { width: 2.5, color: t.accent }, itemStyle: { color: t.accent },
            areaStyle: { color: { type: 'linear', x: 0, y: 0, x2: 0, y2: 1, colorStops: [{ offset: 0, color: t.accent + '40' }, { offset: 1, color: t.accent + '00' }] } },
            markLine: {
                silent: true, symbol: 'none',
                lineStyle: { color: t.muted, type: 'dashed', width: 1 },
                label: { color: t.muted, fontSize: 10, formatter: () => `rata-rata ${fmtMenit(Math.round(data.tren.rata))}`, position: 'insideEndTop' },
                data: [
                    { yAxis: data.tren.rata },
                    // Garis target hanya muncul bila admin mengaktifkan target (Pengaturan).
                    ...(data.target?.harian ? [{ yAxis: data.target.harian, lineStyle: { color: t.up, type: 'solid', width: 1.5 }, label: { formatter: `target ${fmtMenit(data.target.harian)}`, color: t.up, position: 'insideStartTop' } }] : []),
                ],
            },
        },
        {
            name: 'Kejadian', type: 'bar', yAxisIndex: 1, data: data.tren.kejadian, barMaxWidth: 10,
            itemStyle: { color: t.dark ? '#3a3632' : '#e2ddd6', borderRadius: [3, 3, 0, 0] },
            z: 1,
        },
    ],
}));

// ---------------------------------------------------------------- R4 shift
mount(document.getElementById('chartShift'), (t) => {
    // Satu keluarga oranye, dibedakan terang-gelapnya.
    const warnaShift = t.dark ? ['#fdba74', '#fb923c', '#c2410c'] : ['#fdba74', '#ea580c', '#9a3412'];
    const total = data.per_shift.reduce((a, s) => a + s.menit, 0);
    return {
        ...base(t),
        tooltip: {
            ...base(t).tooltip,
            trigger: 'item',
            formatter: (p) => {
                const s = data.per_shift[p.dataIndex];
                return `<b>Shift ${s.shift}</b> (${s.jam})<br>${fmtMenit(s.menit)} · ${s.kejadian} kejadian<br>`
                    + (s.dominan ? `Dominan: ${s.dominan} ${namaKat[s.dominan]}` : '');
            },
        },
        legend: { bottom: 0, textStyle: { color: t.muted, fontSize: 11 }, itemWidth: 10, itemHeight: 10 },
        graphic: [],
        series: [{
            type: 'pie', radius: ['52%', '78%'], center: ['50%', '45%'], padAngle: 2,
            itemStyle: { borderRadius: 6, borderColor: t.surface, borderWidth: 2 },
            label: {
                show: true, position: 'center', color: t.text,
                formatter: () => `{v|${fmtMenit(total)}}\n{l|total}`,
                rich: { v: { fontSize: 18, fontWeight: 700, color: t.text, lineHeight: 26 }, l: { fontSize: 11, color: t.muted } },
            },
            emphasis: { scale: true, scaleSize: 4, label: { show: true } },
            data: data.per_shift.map((s, i) => ({
                name: `Shift ${s.shift} · ${total ? Math.round(s.menit / total * 100) : 0}%`, value: s.menit,
                itemStyle: { color: warnaShift[i] },
            })),
        }],
    };
});

// ---------------------------------------------------------------- R2 pareto
mount(document.getElementById('chartPareto'), (t) => ({
    ...base(t),
    tooltip: {
        ...base(t).tooltip,
        trigger: 'axis',
        formatter: (p) => {
            const i = p[0].dataIndex;
            const k = data.pareto[i];
            const total = data.pareto.reduce((a, x) => a + x.menit, 0) || 1;
            const porsi = (k.menit / total * 100).toFixed(1);
            const kode = data.pareto.slice(0, i + 1).map((x) => x.kode);
            const jumlah = kode.length > 3 ? `${kode.length} kategori teratas` : kode.join(' + ');
            return `<b>${k.kode} · ${k.nama}</b><br>${fmtMenit(k.menit)} · ${k.kejadian} kejadian · <b>${porsi}%</b> dari total`
                + `<br><span style="opacity:.75">Garis (kumulatif):</span> ${jumlah} = <b>${k.kumulatif}%</b> dari total downtime`;
        },
    },
    legend: { top: 0, right: 0, itemWidth: 12, itemHeight: 8, textStyle: { color: t.muted, fontSize: 11 } },
    grid: { left: 8, right: 8, top: 34, bottom: 8, containLabel: true },
    xAxis: { type: 'category', data: data.pareto.map((k) => k.kode), ...axisStyle(t), splitLine: { show: false } },
    yAxis: [
        { type: 'value', name: 'menit', nameTextStyle: { color: t.subtle, fontSize: 10, align: 'left' }, ...axisStyle(t) },
        { type: 'value', min: 0, max: 100, axisLabel: { formatter: '{value}%', color: t.muted, fontSize: 11 }, axisLine: { show: false }, splitLine: { show: false } },
    ],
    series: [
        {
            name: 'Menit per kategori', type: 'bar', barMaxWidth: 36, itemStyle: { color: t.muted },
            data: data.pareto.map((k) => ({ value: k.menit, itemStyle: { color: warna[k.kode], borderRadius: [5, 5, 0, 0] } })),
        },
        {
            name: 'Kumulatif % (s.d. 100%)', type: 'line', yAxisIndex: 1, data: data.pareto.map((k) => k.kumulatif), smooth: false,
            symbol: 'circle', symbolSize: 6, lineStyle: { color: t.text, width: 1.5 }, itemStyle: { color: t.text },
            markLine: {
                silent: true, symbol: 'none', lineStyle: { color: t.up, type: 'dashed' },
                label: { formatter: '80%', color: t.up, fontSize: 10, position: 'insideEndTop' }, data: [{ yAxis: 80 }],
            },
        },
    ],
}));

// ---------------------------------------------------------------- R3 top mesin
mount(document.getElementById('chartMesin'), (t) => {
    const m = data.top_mesin;
    // Urutan dibalik supaya mesin terburuk di atas (sumbu kategori Y dari bawah).
    const rev = (a) => [...a].reverse();
    return {
        ...base(t),
        tooltip: {
            ...base(t).tooltip,
            trigger: 'axis', axisPointer: { type: 'shadow' },
            formatter: (p) => {
                const i = m.mesin.length - 1 - p[0].dataIndex;
                let s = `<b>${m.mesin[i]}</b> ${m.nama[i]}<br>Total: <b>${fmtMenit(m.total[i])}</b><br>`;
                p.filter((x) => x.value > 0).sort((a, b) => b.value - a.value)
                    .forEach((x) => { s += `${x.marker} ${x.seriesName} ${namaKat[x.seriesName]}: ${fmtMenit(x.value)}<br>`; });
                return s;
            },
        },
        legend: { top: 0, left: 0, textStyle: { color: t.muted, fontSize: 11 }, itemWidth: 10, itemHeight: 10, icon: 'roundRect' },
        grid: { left: 8, right: 16, top: 30, bottom: 4, containLabel: true },
        xAxis: { type: 'value', ...axisStyle(t) },
        yAxis: { type: 'category', data: rev(m.mesin), ...axisStyle(t), axisLabel: { color: t.text, fontWeight: 600, fontSize: 11 }, splitLine: { show: false } },
        series: m.seri.map((s, idx) => ({
            name: s.kode, type: 'bar', stack: 'total', barMaxWidth: 18,
            data: rev(s.data),
            itemStyle: { color: warna[s.kode], borderRadius: idx === m.seri.length - 1 ? [0, 4, 4, 0] : 0 },
            emphasis: { focus: 'series' },
        })),
    };
});

// ---------------------------------------------------------------- R5 heatmap
mount(document.getElementById('chartHeatmap'), (t) => {
    const jam = Array.from({ length: 24 }, (_, i) => String(i).padStart(2, '0'));
    return {
        ...base(t),
        tooltip: {
            ...base(t).tooltip,
            formatter: (p) => `<b>${data.heatmap.hari[p.value[1]]}, ${jam[p.value[0]]}:00–${jam[(p.value[0] + 1) % 24]}:00</b><br>${fmtMenit(p.value[2])} downtime`,
        },
        grid: { left: 8, right: 8, top: 26, bottom: 46, containLabel: true },
        xAxis: {
            type: 'category', data: jam, splitArea: { show: false },
            ...axisStyle(t), splitLine: { show: false },
            // Jam awal shift (07, 15, 23) ditebalkan.
            axisLabel: {
                color: t.muted, fontSize: 10, interval: 0,
                formatter: (v) => (['07', '15', '23'].includes(v) ? `{s|${v}}` : v),
                rich: { s: { color: t.text, fontWeight: 700, fontSize: 10 } },
            },
        },
        yAxis: { type: 'category', data: data.heatmap.hari, inverse: true, ...axisStyle(t), splitLine: { show: false } },
        visualMap: {
            min: 0, max: Math.max(1, data.heatmap.maks), calculable: false, orient: 'horizontal', left: 'center', bottom: 0,
            itemWidth: 10, itemHeight: 140, textStyle: { color: t.muted, fontSize: 10 },
            text: ['banyak', 'sedikit'],
            inRange: { color: t.dark ? ['#211f1d', '#5a2a0c', '#c2410c', '#fb923c', '#fde68a'] : ['#f5f3f0', '#fed7aa', '#fb923c', '#ea580c', '#9a3412'] },
        },
        series: [{
            type: 'heatmap', data: data.heatmap.data,
            itemStyle: { borderColor: t.surface, borderWidth: 2, borderRadius: 4 },
            emphasis: { itemStyle: { borderColor: t.text, borderWidth: 1 } },
        }],
    };
});
