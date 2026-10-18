// =============================================================================
// laporan.js — chart di halaman laporan cetak. Renderer SVG (tajam saat
// dicetak / disimpan PDF), tanpa animasi, selalu warna tema terang.
// =============================================================================
import * as echarts from 'echarts/core';
import { BarChart, LineChart, CustomChart } from 'echarts/charts';
import { GridComponent, MarkAreaComponent, MarkLineComponent } from 'echarts/components';
import { SVGRenderer } from 'echarts/renderers';

// Garis prioritas Pareto (%) yang diatur admin (default 80).
const ambangPareto = () => Number(document.querySelector('meta[name="pareto-ambang"]')?.content) || 80;

echarts.use([BarChart, LineChart, CustomChart, GridComponent, MarkAreaComponent, MarkLineComponent, SVGRenderer]);

const data = JSON.parse(document.getElementById('lapData').textContent);
const C = { text: '#1c1917', muted: '#6b6560', border: '#e8e4df', accent: '#ea580c' };
const font = { fontFamily: 'Inter, system-ui, sans-serif', fontSize: 9, color: C.muted };
const axis = { axisLine: { lineStyle: { color: C.border } }, axisTick: { show: false }, axisLabel: { ...font }, splitLine: { lineStyle: { color: C.border, type: [3, 3] } } };
const labelTgl = (iso) => { const d = new Date(iso + 'T00:00:00'); return `${d.getDate()}`; };

function gambar(nama, option) {
    const el = document.querySelector(`[data-chart="${nama}"]`);
    if (!el) return;
    const chart = echarts.init(el, null, { renderer: 'svg' });
    chart.setOption({ animation: false, textStyle: font, ...option });
    window.addEventListener('beforeprint', () => chart.resize());
    window.addEventListener('afterprint', () => chart.resize());
}

if (data.jenis === 'harian') {
    // Timeline 07:00 → 07:00, hanya mesin yang punya kejadian.
    const tl = data.timeline;
    const mesin = tl.mesin.filter((m) => tl.kejadian.some((k) => k.mesin === m));
    const idx = Object.fromEntries(mesin.map((m, i) => [m, i]));
    const jam = (m) => `${String((7 + Math.floor(m / 60)) % 24).padStart(2, '0')}:00`;
    gambar('timeline', {
        grid: { left: 4, right: 18, top: 16, bottom: 2, containLabel: true },
        xAxis: { type: 'value', min: 0, max: 1440, interval: 120, position: 'top', ...axis, axisLabel: { ...font, formatter: jam } },
        yAxis: { type: 'category', data: mesin, inverse: true, axisLine: { show: false }, axisTick: { show: false }, axisLabel: { ...font, color: C.text, fontWeight: 600 } },
        series: [{
            type: 'custom',
            renderItem: (params, api) => {
                const a = api.coord([api.value(1), api.value(0)]);
                const b = api.coord([api.value(2), api.value(0)]);
                const h = api.size([0, 1])[1] * 0.62;
                return { type: 'rect', shape: { x: a[0], y: a[1] - h / 2, width: Math.max(1.5, b[0] - a[0]), height: h, r: 2 }, style: api.style() };
            },
            encode: { x: [1, 2], y: 0 },
            data: tl.kejadian.map((k) => ({ value: [idx[k.mesin], k.mulai, k.selesai], itemStyle: { color: data.warna[k.kategori] } })),
            markArea: {
                silent: true,
                data: [
                    [{ xAxis: 480, itemStyle: { color: C.accent, opacity: 0.06 } }, { xAxis: 960 }],
                    [{ xAxis: 960, itemStyle: { color: '#1c1917', opacity: 0.05 } }, { xAxis: 1440 }],
                ],
            },
        }],
    });
}

if (data.jenis === 'bulanan') {
    gambar('tren', {
        grid: { left: 4, right: 4, top: 8, bottom: 2, containLabel: true },
        xAxis: { type: 'category', data: data.tren.tanggal.map(labelTgl), ...axis, splitLine: { show: false } },
        yAxis: { type: 'value', ...axis },
        series: [{
            type: 'bar', data: data.tren.menit, barMaxWidth: 10, itemStyle: { color: C.accent, borderRadius: [2, 2, 0, 0] },
            markLine: { silent: true, symbol: 'none', lineStyle: { color: C.muted, type: 'dashed' }, label: { ...font, position: 'insideEndTop', formatter: 'rata-rata' }, data: [{ yAxis: data.tren.rata }] },
        }],
    });

    gambar('pareto', {
        grid: { left: 4, right: 4, top: 8, bottom: 2, containLabel: true },
        xAxis: { type: 'category', data: data.kategori.map((k) => k.kode), ...axis, splitLine: { show: false } },
        yAxis: [{ type: 'value', ...axis }, { type: 'value', min: 0, max: 100, ...axis, splitLine: { show: false }, axisLabel: { ...font, formatter: '{value}%' } }],
        series: [
            { type: 'bar', barMaxWidth: 22, data: data.kategori.map((k) => ({ value: k.menit, itemStyle: { color: k.warna, borderRadius: [2, 2, 0, 0] } })) },
            {
                type: 'line', yAxisIndex: 1, data: data.kategori.map((k) => k.kumulatif), symbolSize: 4, lineStyle: { color: C.text, width: 1 }, itemStyle: { color: C.text },
                markLine: { silent: true, symbol: 'none', lineStyle: { color: '#dc2626', type: 'dashed' }, label: { ...font, color: '#dc2626', position: 'insideEndTop', formatter: ambangPareto() + '%' }, data: [{ yAxis: ambangPareto() }] },
            },
        ],
    });
}
