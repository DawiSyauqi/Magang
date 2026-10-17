// =============================================================================
// operator.js — chart halaman Analisis Operator (perencanaan.md 1.7: O1, O2)
// =============================================================================
import { mount, base, axisStyle, fmtMenit } from './chart-theme';

const data = JSON.parse(document.getElementById('operatorData').textContent);
const op = data.operator;

// ---------------------------------------------------------------- O1 menit per shift tercatat
mount(document.getElementById('chartPerShift'), (t) => {
    const urut = [...op].sort((a, b) => b.per_shift - a.per_shift);
    const rata = op.length ? op.reduce((a, o) => a + o.per_shift, 0) / op.length : 0;
    return {
        ...base(t),
        tooltip: {
            ...base(t).tooltip, trigger: 'axis', axisPointer: { type: 'shadow' },
            formatter: (p) => {
                const o = urut[p[0].dataIndex];
                return `<b>${o.nama}</b> (${o.nik})<br>${o.per_shift} mnt per shift tercatat<br>${o.shift} shift · ${fmtMenit(o.menit)} total`;
            },
        },
        grid: { left: 8, right: 40, top: 20, bottom: 8, containLabel: true },
        xAxis: { type: 'value', ...axisStyle(t) },
        yAxis: { type: 'category', data: urut.map((o) => o.nama), inverse: true, ...axisStyle(t), splitLine: { show: false }, axisLabel: { color: t.text, fontSize: 11 } },
        series: [{
            type: 'bar', barWidth: 14, data: urut.map((o) => o.per_shift),
            itemStyle: { color: t.accent, borderRadius: [0, 5, 5, 0] },
            label: { show: true, position: 'right', color: t.muted, fontSize: 10, formatter: (p) => `${Math.round(p.value)} m` },
            markLine: {
                silent: true, symbol: 'none', lineStyle: { color: t.muted, type: 'dashed' },
                label: { color: t.muted, fontSize: 10, formatter: 'rata-rata', position: 'start' }, data: [{ xAxis: rata }],
            },
        }],
    };
});

// ---------------------------------------------------------------- O2 komposisi kategori per operator (100%)
mount(document.getElementById('chartKategori'), (t) => ({
    ...base(t),
    tooltip: {
        ...base(t).tooltip, trigger: 'axis', axisPointer: { type: 'shadow' },
        formatter: (p) => {
            const o = op[p[0].dataIndex];
            return `<b>${o.nama}</b><br>` + p.filter((x) => x.value > 0).sort((a, b) => b.value - a.value)
                .map((x) => `${x.marker} ${x.seriesName} ${data.kategori.find((k) => k.kode === x.seriesName).nama}: ${x.value.toFixed(1).replace('.', ',')}% (${fmtMenit(o.kategori[x.seriesIndex])})`).join('<br>');
        },
    },
    legend: { top: 0, right: 0, textStyle: { color: t.muted, fontSize: 11 }, itemWidth: 10, itemHeight: 10, icon: 'roundRect' },
    grid: { left: 8, right: 16, top: 30, bottom: 4, containLabel: true },
    xAxis: { type: 'value', max: 100, ...axisStyle(t), axisLabel: { color: t.muted, fontSize: 11, formatter: '{value}%' } },
    yAxis: { type: 'category', data: op.map((o) => o.nama), inverse: true, ...axisStyle(t), splitLine: { show: false }, axisLabel: { color: t.text, fontSize: 11 } },
    series: data.kategori.map((k, i) => ({
        name: k.kode, type: 'bar', stack: 'p', barWidth: 16,
        data: op.map((o) => (o.menit ? o.kategori[i] / o.menit * 100 : 0)),
        itemStyle: { color: k.warna }, emphasis: { focus: 'series' },
    })),
}));
