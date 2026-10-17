// =============================================================================
// statistik-ai.js — chart halaman admin Statistik AI Baca Kertas (saran 2.9)
// =============================================================================
import { mount, base, axisStyle } from './chart-theme';

const data = JSON.parse(document.getElementById('aiData').textContent);
const labelTgl = (iso) => { const d = new Date(iso + 'T00:00:00'); return `${d.getDate()}/${d.getMonth() + 1}`; };

mount(document.getElementById('chartHarian'), (t) => ({
    ...base(t),
    tooltip: {
        ...base(t).tooltip, trigger: 'axis',
        formatter: (p) => {
            const h = data.per_hari[p[0].dataIndex];
            return `<b>${labelTgl(h.tanggal)}</b><br>${h.scan} lembar<br>Ragu: ${h.ragu ?? '–'}%<br>Dikoreksi: ${h.koreksi ?? '–'}%`;
        },
    },
    legend: { top: 0, right: 0, textStyle: { color: t.muted, fontSize: 11 }, itemWidth: 12, itemHeight: 8 },
    grid: { left: 8, right: 8, top: 34, bottom: 8, containLabel: true },
    xAxis: { type: 'category', data: data.per_hari.map((h) => labelTgl(h.tanggal)), ...axisStyle(t), splitLine: { show: false } },
    yAxis: [
        { type: 'value', name: 'lembar', nameTextStyle: { color: t.subtle, fontSize: 10, align: 'left' }, ...axisStyle(t) },
        { type: 'value', min: 0, max: 40, ...axisStyle(t), splitLine: { show: false }, axisLabel: { color: t.muted, fontSize: 11, formatter: '{value}%' } },
    ],
    series: [
        { name: 'Lembar discan', type: 'bar', data: data.per_hari.map((h) => h.scan), barMaxWidth: 16, itemStyle: { color: t.dark ? '#5a2a0c' : '#fed7aa', borderRadius: [3, 3, 0, 0] } },
        { name: '% ragu', type: 'line', yAxisIndex: 1, data: data.per_hari.map((h) => h.ragu), connectNulls: true, symbol: 'none', smooth: 0.3, lineStyle: { color: t.accent, width: 2 }, itemStyle: { color: t.accent } },
        { name: '% dikoreksi', type: 'line', yAxisIndex: 1, data: data.per_hari.map((h) => h.koreksi), connectNulls: true, symbol: 'none', smooth: 0.3, lineStyle: { color: t.down, width: 2 }, itemStyle: { color: t.down } },
    ],
}));

mount(document.getElementById('chartDetik'), (t) => {
    const kunci = Object.keys(data.detik).map(Number);
    return {
        ...base(t),
        tooltip: { ...base(t).tooltip, trigger: 'axis', axisPointer: { type: 'shadow' }, formatter: (p) => `<b>${p[0].name} detik</b><br>${p[0].value} foto` },
        grid: { left: 8, right: 8, top: 16, bottom: 8, containLabel: true },
        xAxis: { type: 'category', data: kunci.map((k) => `${k}–${k + 4}`), ...axisStyle(t), splitLine: { show: false } },
        yAxis: { type: 'value', name: 'foto', nameTextStyle: { color: t.subtle, fontSize: 10, align: 'left' }, ...axisStyle(t) },
        series: [{ type: 'bar', data: kunci.map((k) => data.detik[k]), barMaxWidth: 28, itemStyle: { color: t.accent, borderRadius: [4, 4, 0, 0] } }],
    };
});
