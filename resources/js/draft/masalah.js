// =============================================================================
// masalah.js — chart halaman Analisis Masalah (perencanaan.md 1.5: P1–P5)
// =============================================================================
import { echarts, mount, base, axisStyle, fmtMenit, tokens } from './chart-theme';
import { SunburstChart, BoxplotChart } from 'echarts/charts';

echarts.use([SunburstChart, BoxplotChart]);

const data = JSON.parse(document.getElementById('masalahData').textContent);
const warna = Object.fromEntries(data.meta.map((k) => [k.kode, k.warna]));
const namaKat = Object.fromEntries(data.meta.map((k) => [k.kode, k.nama]));
const keProfil = (kode) => { window.location.href = data.url_profil.replace('__KODE__', encodeURIComponent(kode)); };
const labelTgl = (iso) => { const d = new Date(iso + 'T00:00:00'); return `${d.getDate()}/${d.getMonth() + 1}`; };
const pendek = (s, n = 16) => (s.length > n ? s.slice(0, n - 1) + '…' : s);

// ---------------------------------------------------------------- P1 Pareto + drill-down
let fokus = null; // kode kategori yang sedang dibuka, null = semua kategori

function opsiPareto(t) {
    const k = fokus ? data.kategori.find((x) => x.kode === fokus) : null;
    const item = k
        ? k.detail.map((d) => ({ label: d.detail, menit: d.menit, kejadian: d.kejadian, warna: k.warna, ket: `${d.mesin} mesin` }))
        : data.kategori.map((x) => ({ label: x.kode, menit: x.menit, kejadian: x.kejadian, warna: x.warna, ket: x.nama }));
    const total = item.reduce((a, x) => a + x.menit, 0) || 1;
    let kum = 0;
    const kumulatif = item.map((x) => { kum += x.menit; return +(kum / total * 100).toFixed(1); });

    return {
        ...base(t),
        tooltip: {
            ...base(t).tooltip, trigger: 'axis', axisPointer: { type: 'shadow' },
            formatter: (p) => {
                const i = p[0].dataIndex;
                const x = item[i];
                const porsi = (x.menit / total * 100).toFixed(1);
                const n = i + 1;
                const jumlah = !k && n <= 3 ? item.slice(0, n).map((y) => y.label).join(' + ') : `${n} ${k ? 'detail' : 'kategori'} teratas`;
                return `<b>${x.label}</b>${k ? '' : ' · ' + x.ket}<br>${fmtMenit(x.menit)} · ${x.kejadian} kejadian${k ? ' · ' + x.ket : ''} · <b>${porsi}%</b>`
                    + `<br><span style="opacity:.75">Garis (kumulatif):</span> ${jumlah} = <b>${kumulatif[i]}%</b>`
                    + (k ? '' : '<br><span style="opacity:.7">klik untuk detail</span>');
            },
        },
        legend: { top: 0, right: 0, itemWidth: 12, itemHeight: 8, textStyle: { color: t.muted, fontSize: 11 } },
        grid: { left: 8, right: 8, top: 34, bottom: 8, containLabel: true },
        xAxis: {
            type: 'category', data: item.map((x) => x.label), ...axisStyle(t), splitLine: { show: false },
            axisLabel: { color: t.muted, fontSize: 11, interval: 0, rotate: k && item.length > 4 ? 28 : 0, formatter: (v) => (k ? pendek(v) : v) },
        },
        yAxis: [
            { type: 'value', name: 'menit', nameTextStyle: { color: t.subtle, fontSize: 10, align: 'left' }, ...axisStyle(t) },
            { type: 'value', min: 0, max: 100, axisLabel: { formatter: '{value}%', color: t.muted, fontSize: 11 }, axisLine: { show: false }, splitLine: { show: false } },
        ],
        series: [
            { name: 'Menit', type: 'bar', barMaxWidth: 40, itemStyle: { color: t.muted }, data: item.map((x) => ({ value: x.menit, itemStyle: { color: x.warna, borderRadius: [5, 5, 0, 0] } })), cursor: k ? 'default' : 'pointer' },
            {
                name: 'Kumulatif % (s.d. 100%)', type: 'line', yAxisIndex: 1, data: kumulatif, symbol: 'circle', symbolSize: 6,
                lineStyle: { color: t.text, width: 1.5 }, itemStyle: { color: t.text },
                markLine: { silent: true, symbol: 'none', lineStyle: { color: t.up, type: 'dashed' }, label: { formatter: '80%', color: t.up, fontSize: 10, position: 'insideEndTop' }, data: [{ yAxis: 80 }] },
            },
        ],
    };
}

let pareto; // instance aktif (diganti setiap tema berubah)
function gambarPareto() {
    const k = fokus ? data.kategori.find((x) => x.kode === fokus) : null;
    document.getElementById('paretoJudul').textContent = k ? `Detail masalah ${k.kode} · ${k.nama}` : 'Pareto kategori masalah';
    document.getElementById('paretoSub').textContent = k
        ? `${k.detail.length} detail masalah · ${fmtMenit(k.menit)} total`
        : 'Batang = menit per kategori · garis = persentase kumulatif (dijumlah dari kiri, selalu naik sampai 100%) · kategori di kiri garis 80% = prioritas · klik batang untuk detail';
    document.getElementById('paretoKembali').classList.toggle('d-none', !k);
    pareto.setOption(opsiPareto(tokens()), true);
}
mount(document.getElementById('chartPareto'), opsiPareto, (chart) => {
    pareto = chart;
    chart.on('click', (p) => {
        if (!fokus && p.seriesIndex === 0) { fokus = data.kategori[p.dataIndex].kode; gambarPareto(); }
    });
});
document.getElementById('paretoKembali').addEventListener('click', () => { fokus = null; gambarPareto(); });

// ---------------------------------------------------------------- P2 sunburst
mount(document.getElementById('chartSunburst'), (t) => ({
    ...base(t),
    tooltip: { ...base(t).tooltip, formatter: (p) => `<b>${p.data.namaPanjang || p.name}</b><br>${fmtMenit(p.value)}` },
    series: [{
        type: 'sunburst', radius: ['14%', '96%'], sort: undefined, nodeClick: 'rootToNode',
        itemStyle: { borderColor: t.surface, borderWidth: 2, borderRadius: 4 },
        label: { color: '#fff', fontSize: 10, fontWeight: 600, minAngle: 9 },
        levels: [
            {},
            { r0: '14%', r: '48%', label: { rotate: 0, fontSize: 12, fontWeight: 700 } },
            { r0: '48%', r: '96%', label: { rotate: 'radial', align: 'right', fontSize: 10, formatter: (p) => pendek(p.name, 14) }, itemStyle: { opacity: 0.82 } },
        ],
        data: data.kategori.map((k) => ({
            name: k.kode, namaPanjang: `${k.kode} · ${k.nama}`, value: k.menit, itemStyle: { color: k.warna },
            children: k.detail.map((d) => ({ name: d.detail, value: d.menit, itemStyle: { color: k.warna } })),
        })),
    }],
}));

// ---------------------------------------------------------------- P3 tren per kategori (ditumpuk)
mount(document.getElementById('chartTren'), (t) => ({
    ...base(t),
    tooltip: {
        ...base(t).tooltip, trigger: 'axis',
        formatter: (p) => {
            const tot = p.reduce((a, x) => a + x.value, 0);
            const isi = p.filter((x) => x.value > 0).sort((a, b) => b.value - a.value)
                .map((x) => `${x.marker} ${x.seriesName} ${namaKat[x.seriesName]}: ${fmtMenit(x.value)}`).join('<br>');
            return `<b>${data.tren.mingguan ? 'Minggu ' : ''}${p[0].axisValue}</b> · ${fmtMenit(tot)}<br>${isi}`;
        },
    },
    legend: { top: 0, right: 0, textStyle: { color: t.muted, fontSize: 11 }, itemWidth: 12, itemHeight: 8 },
    grid: { left: 8, right: 8, top: 34, bottom: data.tren.label.length > 45 ? 40 : 8, containLabel: true },
    dataZoom: data.tren.label.length > 45 ? [{ type: 'slider', height: 18, bottom: 6, borderColor: t.border }] : [],
    xAxis: { type: 'category', data: data.tren.label.map(labelTgl), ...axisStyle(t), splitLine: { show: false } },
    yAxis: { type: 'value', name: 'menit', nameTextStyle: { color: t.subtle, fontSize: 10, align: 'left' }, ...axisStyle(t) },
    series: data.tren.seri.map((s) => ({
        name: s.kode, type: 'bar', stack: 'k', data: s.data, barMaxWidth: 22,
        itemStyle: { color: warna[s.kode] }, emphasis: { focus: 'series' },
    })),
}));

// ---------------------------------------------------------------- P4 matriks mesin × kategori
mount(document.getElementById('chartMatriks'), (t) => {
    const m = data.matriks;
    return {
        ...base(t),
        tooltip: { ...base(t).tooltip, formatter: (p) => `<b>${m.mesin[p.value[1]]}</b> · ${m.kategori[p.value[0]]} ${namaKat[m.kategori[p.value[0]]]}<br>${fmtMenit(p.value[2])}` },
        grid: { left: 8, right: 8, top: 8, bottom: 46, containLabel: true },
        xAxis: { type: 'category', data: m.kategori, position: 'top', ...axisStyle(t), splitLine: { show: false }, axisLabel: { color: t.text, fontWeight: 600, fontSize: 11 } },
        yAxis: { type: 'category', data: m.mesin, inverse: true, ...axisStyle(t), splitLine: { show: false }, axisLabel: { color: t.text, fontWeight: 600, fontSize: 11 } },
        visualMap: {
            min: 0, max: Math.max(1, m.maks), orient: 'horizontal', left: 'center', bottom: 0, itemWidth: 10, itemHeight: 140,
            text: ['lama', 'sebentar'], textStyle: { color: t.muted, fontSize: 10 },
            inRange: { color: t.dark ? ['#211f1d', '#5a2a0c', '#c2410c', '#fb923c', '#fde68a'] : ['#f5f3f0', '#fed7aa', '#fb923c', '#ea580c', '#9a3412'] },
        },
        series: [{
            type: 'heatmap', data: m.data,
            label: { show: true, color: t.text, fontSize: 9, formatter: (p) => (p.value[2] >= 60 ? Math.round(p.value[2] / 60) + 'j' : '') },
            itemStyle: { borderColor: t.surface, borderWidth: 2, borderRadius: 4 },
            emphasis: { itemStyle: { borderColor: t.text, borderWidth: 1 } },
        }],
    };
}, (chart) => chart.on('click', (p) => p.value && keProfil(data.matriks.mesin[p.value[1]])));

// ---------------------------------------------------------------- P5 durasi (boxplot)
mount(document.getElementById('chartDurasi'), (t) => ({
    ...base(t),
    tooltip: {
        ...base(t).tooltip,
        formatter: (p) => {
            const [, mn, q1, md, q3, mx] = p.value;
            return `<b>${p.name} · ${namaKat[p.name]}</b><br>Median <b>${md} mnt</b><br>50% kejadian: ${q1}–${q3} mnt<br>Terpendek ${mn} · terlama ${mx} mnt`;
        },
    },
    grid: { left: 8, right: 16, top: 8, bottom: 8, containLabel: true },
    xAxis: { type: 'value', name: 'menit', nameLocation: 'end', nameTextStyle: { color: t.subtle, fontSize: 10 }, ...axisStyle(t) },
    yAxis: { type: 'category', data: data.durasi.map((d) => d.kode), inverse: true, ...axisStyle(t), splitLine: { show: false }, axisLabel: { color: t.text, fontWeight: 600, fontSize: 11 } },
    series: [{
        type: 'boxplot', boxWidth: [8, 18],
        data: data.durasi.map((d) => ({ value: d.box, itemStyle: { color: warna[d.kode] + '33', borderColor: warna[d.kode], borderWidth: 1.5 } })),
    }],
}));
