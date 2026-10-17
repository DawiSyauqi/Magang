// =============================================================================
// draft4/ringkasan.js — chart variasi 4 (sederhana & ramah semua umur).
// Hanya 2 chart batang sederhana: label besar, tanpa sumbu ganda, tooltip
// dengan kalimat lengkap. Sisanya berupa daftar HTML di Blade.
// =============================================================================
import { echarts } from '../draft/chart-theme';

const data = JSON.parse(document.getElementById('ringkasanData').textContent);
const namaHari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
const tgl = (iso) => new Date(iso + 'T00:00:00');
const fmtLama = (m) => {
    const j = Math.floor(m / 60);
    return j ? `${j} jam${m % 60 ? ' ' + (m % 60) + ' menit' : ''}` : `${m} menit`;
};

function tk() {
    const s = getComputedStyle(document.documentElement);
    const v = (n) => s.getPropertyValue(n).trim();
    // Ukuran huruf chart ikut pilihan A / A+ / A++.
    const skala = parseFloat(s.fontSize) / 16;
    return {
        text: v('--text'), muted: v('--muted'), line: v('--line'), card: v('--card'), soft: v('--soft'),
        accent: v('--accent'), warn: '#e8770e', fs: Math.round(13 * skala),
    };
}

function base(t) {
    return {
        backgroundColor: 'transparent',
        textStyle: { fontFamily: 'Inter, system-ui, sans-serif', color: t.muted, fontSize: t.fs },
        tooltip: {
            backgroundColor: t.card, borderColor: t.line, borderWidth: 1, padding: [10, 14],
            textStyle: { color: t.text, fontSize: t.fs + 1 }, extraCssText: 'border-radius:12px;box-shadow:0 6px 20px rgba(0,0,0,.15);',
        },
    };
}
const axis = (t) => ({
    axisLine: { lineStyle: { color: t.line } }, axisTick: { show: false },
    axisLabel: { color: t.muted, fontSize: t.fs },
    splitLine: { lineStyle: { color: t.line } },
});

function mount(id, build) {
    const el = document.getElementById(id);
    if (!el) return;
    let chart = echarts.init(el);
    const render = () => chart.setOption(build(tk()), true);
    render();
    new ResizeObserver(() => { chart.resize(); render(); }).observe(el);
    document.addEventListener('themechange', () => { chart.dispose(); chart = echarts.init(el); render(); });
}

// ------------------------------------------------------------ waktu berhenti per hari
mount('spHarian', (t) => {
    const banyak = data.tren.tanggal.length > 31;
    return {
        ...base(t),
        tooltip: {
            ...base(t).tooltip, trigger: 'axis', axisPointer: { type: 'shadow' },
            formatter: (p) => {
                const iso = data.tren.tanggal[p[0].dataIndex];
                const d = tgl(iso);
                return `<b>${namaHari[d.getDay()]}, ${d.getDate()}/${d.getMonth() + 1}</b><br>Berhenti ${fmtLama(p[0].value)}<br>${data.tren.kejadian[p[0].dataIndex]} kali`;
            },
        },
        grid: { left: 8, right: 16, top: 16, bottom: banyak ? 44 : 8, containLabel: true },
        dataZoom: banyak ? [{ type: 'slider', height: 22, bottom: 6 }] : [],
        xAxis: {
            type: 'category', data: data.tren.tanggal.map((iso) => { const d = tgl(iso); return `${d.getDate()}/${d.getMonth() + 1}`; }),
            ...axis(t), splitLine: { show: false },
        },
        yAxis: {
            type: 'value', ...axis(t), axisLine: { show: false },
            axisLabel: { color: t.muted, fontSize: t.fs, formatter: (v) => (v >= 60 ? `${Math.round(v / 60)} jam` : `${v} m`) },
        },
        series: [{
            type: 'bar', data: data.tren.menit, barMaxWidth: 28,
            itemStyle: { color: t.accent, borderRadius: [6, 6, 0, 0] },
            emphasis: { itemStyle: { color: t.warn } },
            markLine: {
                silent: true, symbol: 'none', lineStyle: { color: t.text, type: 'dashed', width: 1.5 },
                label: { show: true, position: 'insideEndTop', color: t.text, fontSize: t.fs, fontWeight: 600, formatter: 'rata-rata' },
                data: [{ yAxis: data.tren.rata }],
            },
        }],
    };
});

// ------------------------------------------------------------ waktu berhenti per jam
mount('spJam', (t) => {
    const perJam = Array(24).fill(0);
    data.heatmap.data.forEach(([j, , v]) => { perJam[j] += v; });
    const maks = Math.max(...perJam);
    return {
        ...base(t),
        tooltip: {
            ...base(t).tooltip, trigger: 'axis', axisPointer: { type: 'shadow' },
            formatter: (p) => {
                const j = p[0].dataIndex;
                return `<b>Pukul ${String(j).padStart(2, '0')}.00–${String((j + 1) % 24).padStart(2, '0')}.00</b><br>Total berhenti ${fmtLama(p[0].value)}`;
            },
        },
        grid: { left: 8, right: 8, top: 16, bottom: 8, containLabel: true },
        xAxis: {
            type: 'category', data: perJam.map((_, i) => String(i).padStart(2, '0')), ...axis(t), splitLine: { show: false },
            axisLabel: { color: t.muted, fontSize: t.fs, interval: (i) => i % 3 === 1 || [7, 15, 23].includes(i) },
        },
        yAxis: {
            type: 'value', ...axis(t), axisLine: { show: false },
            axisLabel: { color: t.muted, fontSize: t.fs, formatter: (v) => (v >= 60 ? `${Math.round(v / 60)} jam` : `${v} m`) },
        },
        series: [{
            type: 'bar', barWidth: '62%',
            data: perJam.map((v) => ({ value: v, itemStyle: { color: v === maks && v > 0 ? t.warn : t.accent, borderRadius: [5, 5, 0, 0], opacity: v === maks ? 1 : 0.8 } })),
            markLine: {
                silent: true, symbol: 'none', lineStyle: { color: t.muted, type: 'solid', width: 1, opacity: 0.6 },
                label: { show: true, position: 'end', color: t.muted, fontSize: t.fs - 1, formatter: (p) => p.name },
                data: [{ xAxis: '07', name: 'S1' }, { xAxis: '15', name: 'S2' }, { xAxis: '23', name: 'S3' }],
            },
        }],
    };
});

