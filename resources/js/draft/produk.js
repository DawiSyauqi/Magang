// =============================================================================
// produk.js — chart halaman Produk / Item (perencanaan.md 1.8: I2, I3)
// =============================================================================
import { echarts, mount, base, axisStyle, fmtMenit } from './chart-theme';
import { BoxplotChart } from 'echarts/charts';

echarts.use([BoxplotChart]);

const data = JSON.parse(document.getElementById('produkData').textContent);
const namaKat = Object.fromEntries(data.kategori.map((k) => [k.kode, k.nama]));
const namaItem = Object.fromEntries(data.item.map((i) => [i.kode, i.nama]));
const skalaOranye = (t) => (t.dark ? ['#211f1d', '#5a2a0c', '#c2410c', '#fb923c', '#fde68a'] : ['#f5f3f0', '#fed7aa', '#fb923c', '#ea580c', '#9a3412']);

// ---------------------------------------------------------------- I2 matriks item × kategori
mount(document.getElementById('chartMatriks'), (t) => {
    const m = data.matriks;
    return {
        ...base(t),
        tooltip: { ...base(t).tooltip, formatter: (p) => `<b>${m.item[p.value[1]]}</b> ${namaItem[m.item[p.value[1]]]}<br>${m.kategori[p.value[0]]} ${namaKat[m.kategori[p.value[0]]]}: ${fmtMenit(p.value[2])}` },
        grid: { left: 8, right: 8, top: 8, bottom: 46, containLabel: true },
        xAxis: { type: 'category', data: m.kategori, position: 'top', ...axisStyle(t), splitLine: { show: false }, axisLabel: { color: t.text, fontWeight: 600, fontSize: 11 } },
        yAxis: { type: 'category', data: m.item, inverse: true, ...axisStyle(t), splitLine: { show: false }, axisLabel: { color: t.text, fontWeight: 600, fontSize: 11 } },
        visualMap: {
            min: 0, max: Math.max(1, m.maks), orient: 'horizontal', left: 'center', bottom: 0, itemWidth: 10, itemHeight: 140,
            text: ['lama', 'sebentar'], textStyle: { color: t.muted, fontSize: 10 }, inRange: { color: skalaOranye(t) },
        },
        series: [{
            type: 'heatmap', data: m.data,
            label: { show: true, color: t.text, fontSize: 10, formatter: (p) => (p.value[2] >= 60 ? Math.round(p.value[2] / 60) + 'j' : '') },
            itemStyle: { borderColor: t.surface, borderWidth: 2, borderRadius: 4 },
        }],
    };
});

// ---------------------------------------------------------------- I3b rentang speed
mount(document.getElementById('chartRentang'), (t) => ({
    ...base(t),
    tooltip: {
        ...base(t).tooltip, trigger: 'axis', axisPointer: { type: 'shadow' },
        formatter: (p) => {
            const r = data.speed_rentang[p[0].dataIndex];
            return `<b>${r.label} m/mnt</b><br>${r.kejadian} kejadian · ${fmtMenit(r.menit)}<br>Rata-rata ${r.rata} mnt/kejadian`;
        },
    },
    legend: { top: 0, right: 0, textStyle: { color: t.muted, fontSize: 11 }, itemWidth: 12, itemHeight: 8 },
    grid: { left: 8, right: 8, top: 34, bottom: 8, containLabel: true },
    xAxis: { type: 'category', data: data.speed_rentang.map((r) => r.label), ...axisStyle(t), splitLine: { show: false }, axisLabel: { color: t.muted, fontSize: 10, rotate: 30 } },
    yAxis: [
        { type: 'value', name: 'kejadian', nameTextStyle: { color: t.subtle, fontSize: 10, align: 'left' }, ...axisStyle(t) },
        { type: 'value', ...axisStyle(t), splitLine: { show: false }, axisLabel: { color: t.muted, fontSize: 11, formatter: '{value} m' } },
    ],
    series: [
        { name: 'Kejadian', type: 'bar', data: data.speed_rentang.map((r) => r.kejadian), barMaxWidth: 26, itemStyle: { color: t.dark ? '#5a2a0c' : '#fed7aa', borderRadius: [4, 4, 0, 0] } },
        { name: 'Rata-rata lama', type: 'line', yAxisIndex: 1, data: data.speed_rentang.map((r) => r.rata), symbol: 'circle', symbolSize: 6, lineStyle: { color: t.accent, width: 2.5 }, itemStyle: { color: t.accent } },
    ],
}));

// ---------------------------------------------------------------- I3a boxplot speed per mesin
mount(document.getElementById('chartSpeed'), (t) => ({
    ...base(t),
    tooltip: {
        ...base(t).tooltip,
        formatter: (p) => {
            const [, mn, q1, md, q3, mx] = p.value;
            return `<b>${p.name}</b><br>Median <b>${md} m/mnt</b><br>50% data: ${q1}–${q3}<br>Rentang ${mn}–${mx}<br><span style="opacity:.7">klik untuk profil</span>`;
        },
    },
    grid: { left: 8, right: 8, top: 16, bottom: 8, containLabel: true },
    xAxis: { type: 'category', data: data.speed_mesin.map((s) => s.mesin), ...axisStyle(t), splitLine: { show: false }, axisLabel: { color: t.text, fontWeight: 600, fontSize: 11 } },
    yAxis: { type: 'value', name: 'm/mnt', nameTextStyle: { color: t.subtle, fontSize: 10, align: 'left' }, scale: true, ...axisStyle(t) },
    series: [{
        type: 'boxplot', boxWidth: [10, 30],
        data: data.speed_mesin.map((s) => s.box),
        itemStyle: { color: t.accent + '26', borderColor: t.accent, borderWidth: 1.5 },
    }],
}), (chart) => chart.on('click', (p) => p.name && (window.location.href = data.url_profil.replace('__KODE__', p.name))));
