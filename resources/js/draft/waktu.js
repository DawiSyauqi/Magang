// =============================================================================
// waktu.js — chart halaman Waktu & Shift (perencanaan.md 1.6: W1–W6)
// =============================================================================
import { echarts, mount, base, axisStyle, fmtMenit } from './chart-theme';
import { CustomChart } from 'echarts/charts';
import { CalendarComponent, MarkAreaComponent } from 'echarts/components';

echarts.use([CustomChart, CalendarComponent, MarkAreaComponent]);

const data = JSON.parse(document.getElementById('waktuData').textContent);
const warna = Object.fromEntries(data.kategori.map((k) => [k.kode, k.warna]));
const namaKat = Object.fromEntries(data.kategori.map((k) => [k.kode, k.nama]));
const hariId = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
const bulanId = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const tgl = (iso) => new Date(iso + 'T00:00:00');
const labelTgl = (iso) => `${tgl(iso).getDate()}/${tgl(iso).getMonth() + 1}`;
const skalaOranye = (t) => (t.dark ? ['#211f1d', '#5a2a0c', '#c2410c', '#fb923c', '#fde68a'] : ['#f5f3f0', '#fed7aa', '#fb923c', '#ea580c', '#9a3412']);
const warnaShift = (t) => (t.dark ? ['#fdba74', '#fb923c', '#c2410c'] : ['#fdba74', '#ea580c', '#9a3412']);

// ---------------------------------------------------------------- W1 kalender
mount(document.getElementById('chartKalender'), (t) => {
    const maks = Math.max(1, ...data.kalender.map((d) => d[1]));
    return {
        ...base(t),
        tooltip: {
            ...base(t).tooltip,
            formatter: (p) => {
                const [iso, menit, kej] = p.value;
                return `<b>${hariId[tgl(iso).getDay()]}, ${labelTgl(iso)}</b><br>${menit ? fmtMenit(menit) + ' · ' + kej + ' kejadian' : 'tidak ada downtime'}<br><span style="opacity:.7">klik untuk timeline</span>`;
            },
        },
        visualMap: {
            min: 0, max: maks, dimension: 1, orient: 'horizontal', right: 0, top: 0, itemWidth: 10, itemHeight: 120,
            text: ['lama', 'sebentar'], textStyle: { color: t.muted, fontSize: 10 }, inRange: { color: skalaOranye(t) },
        },
        calendar: {
            range: [data.periode.dari, data.periode.sampai],
            top: 46, left: 40, right: 12, bottom: 8, cellSize: ['auto', 'auto'],
            splitLine: { lineStyle: { color: t.border, width: 1.5 } },
            itemStyle: { color: t.surfaceAlt, borderColor: t.surface, borderWidth: 3 },
            dayLabel: { firstDay: 1, nameMap: ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'], color: t.muted, fontSize: 10 },
            monthLabel: { nameMap: bulanId, color: t.text, fontSize: 11, fontWeight: 600 },
            yearLabel: { show: false },
        },
        series: [{ type: 'heatmap', coordinateSystem: 'calendar', data: data.kalender }],
    };
}, (chart) => chart.on('click', (p) => p.value && p.value[1] && (window.location.href = data.url_tanggal.replace('__TGL__', p.value[0]))));

// ---------------------------------------------------------------- W2 heatmap jam × hari
mount(document.getElementById('chartHeatmap'), (t) => {
    const jam = Array.from({ length: 24 }, (_, i) => String(i).padStart(2, '0'));
    return {
        ...base(t),
        tooltip: { ...base(t).tooltip, formatter: (p) => `<b>${data.heatmap.hari[p.value[1]]}, ${jam[p.value[0]]}:00–${jam[(p.value[0] + 1) % 24]}:00</b><br>${fmtMenit(p.value[2])}` },
        grid: { left: 8, right: 8, top: 8, bottom: 44, containLabel: true },
        xAxis: {
            type: 'category', data: jam, ...axisStyle(t), splitLine: { show: false },
            axisLabel: { color: t.muted, fontSize: 10, interval: 0, formatter: (v) => (['07', '15', '23'].includes(v) ? `{s|${v}}` : v), rich: { s: { color: t.text, fontWeight: 700, fontSize: 10 } } },
        },
        yAxis: { type: 'category', data: data.heatmap.hari, inverse: true, ...axisStyle(t), splitLine: { show: false } },
        visualMap: {
            min: 0, max: Math.max(1, data.heatmap.maks), orient: 'horizontal', left: 'center', bottom: 0, itemWidth: 10, itemHeight: 140,
            text: ['banyak', 'sedikit'], textStyle: { color: t.muted, fontSize: 10 }, inRange: { color: skalaOranye(t) },
        },
        series: [{ type: 'heatmap', data: data.heatmap.data, itemStyle: { borderColor: t.surface, borderWidth: 2, borderRadius: 4 } }],
    };
});

// ---------------------------------------------------------------- W2b rata-rata per hari dalam minggu
mount(document.getElementById('chartDow'), (t) => {
    const nama = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
    const maks = Math.max(...data.rata_dow);
    return {
        ...base(t),
        tooltip: { ...base(t).tooltip, trigger: 'axis', axisPointer: { type: 'shadow' }, formatter: (p) => `<b>${p[0].name}</b><br>rata-rata ${fmtMenit(p[0].value)} per hari` },
        grid: { left: 8, right: 78, top: 8, bottom: 8, containLabel: true },
        xAxis: { type: 'value', ...axisStyle(t), axisLabel: { show: false } },
        yAxis: { type: 'category', data: nama, inverse: true, ...axisStyle(t), splitLine: { show: false }, axisLabel: { color: t.text, fontWeight: 600, fontSize: 11 } },
        series: [{
            type: 'bar', barWidth: 16,
            data: data.rata_dow.map((v) => ({ value: v, itemStyle: { color: v === maks && v > 0 ? t.accent : (t.dark ? '#5a2a0c' : '#fed7aa'), borderRadius: [0, 6, 6, 0] } })),
            label: { show: true, position: 'right', color: t.muted, fontSize: 11, formatter: (p) => fmtMenit(p.value) },
        }],
    };
});

// ---------------------------------------------------------------- W3 penyebab per shift
mount(document.getElementById('chartShiftKat'), (t) => ({
    ...base(t),
    tooltip: {
        ...base(t).tooltip, trigger: 'axis', axisPointer: { type: 'shadow' },
        formatter: (p) => `<b>${p[0].name}</b><br>` + p.filter((x) => x.value > 0).sort((a, b) => b.value - a.value)
            .map((x) => `${x.marker} ${x.seriesName} ${namaKat[x.seriesName]}: ${fmtMenit(x.value)}`).join('<br>'),
    },
    legend: { top: 0, right: 0, textStyle: { color: t.muted, fontSize: 11 }, itemWidth: 10, itemHeight: 10, icon: 'roundRect' },
    grid: { left: 8, right: 16, top: 30, bottom: 4, containLabel: true },
    xAxis: { type: 'value', ...axisStyle(t) },
    yAxis: { type: 'category', data: data.shift.map((s) => `Shift ${s.shift} · ${s.jam}`), inverse: true, ...axisStyle(t), splitLine: { show: false }, axisLabel: { color: t.text, fontWeight: 600, fontSize: 11 } },
    series: data.kategori.map((k, i) => ({
        name: k.kode, type: 'bar', stack: 's', barWidth: 22,
        data: data.shift.map((s) => s.kategori[i]),
        itemStyle: { color: warna[k.kode], borderRadius: i === data.kategori.length - 1 ? [0, 5, 5, 0] : 0 },
        emphasis: { focus: 'series' },
    })),
}));

// ---------------------------------------------------------------- W4 mingguan vs periode lalu
mount(document.getElementById('chartMingguan'), (t) => {
    const ini = data.mingguan.ini;
    const lalu = data.mingguan.lalu;
    return {
        ...base(t),
        tooltip: {
            ...base(t).tooltip, trigger: 'axis',
            formatter: (p) => {
                const i = p[0].dataIndex;
                let s = `<b>Minggu ke-${i + 1}</b><br>${p[0].marker} mulai ${labelTgl(ini[i].minggu)}: <b>${fmtMenit(ini[i].menit)}</b>`;
                if (lalu[i]) s += `<br>${p[1]?.marker ?? ''} periode lalu (mulai ${labelTgl(lalu[i].minggu)}): ${fmtMenit(lalu[i].menit)}`;
                return s;
            },
        },
        legend: { top: 0, right: 0, textStyle: { color: t.muted, fontSize: 11 }, itemWidth: 14, itemHeight: 8 },
        grid: { left: 8, right: 8, top: 34, bottom: 8, containLabel: true },
        xAxis: { type: 'category', data: ini.map((w, i) => `Mg ${i + 1}\n${labelTgl(w.minggu)}`), ...axisStyle(t), splitLine: { show: false } },
        yAxis: { type: 'value', name: 'menit', nameTextStyle: { color: t.subtle, fontSize: 10, align: 'left' }, ...axisStyle(t) },
        series: [
            { name: 'Periode ini', type: 'bar', barMaxWidth: 34, data: ini.map((w) => w.menit), itemStyle: { color: t.accent, borderRadius: [5, 5, 0, 0] } },
            { name: 'Periode lalu', type: 'line', data: lalu.slice(0, ini.length).map((w) => w.menit), symbol: 'circle', symbolSize: 6, lineStyle: { color: t.muted, type: 'dashed', width: 1.5 }, itemStyle: { color: t.muted } },
        ],
    };
});

// ---------------------------------------------------------------- W5 posisi kejadian dalam shift
mount(document.getElementById('chartPosisi'), (t) => {
    const blok = data.posisi_shift.blok;
    const n = data.posisi_shift.data['1'].length;
    const label = Array.from({ length: n }, (_, i) => `${Math.floor(i * blok / 60)}:${String((i * blok) % 60).padStart(2, '0')}`);
    const c = warnaShift(t);
    return {
        ...base(t),
        tooltip: {
            ...base(t).tooltip, trigger: 'axis',
            formatter: (p) => `<b>Jam ke-${p[0].name} sejak shift mulai</b><br>` + p.map((x) => `${x.marker} ${x.seriesName}: ${x.value} kejadian`).join('<br>'),
        },
        legend: { top: 0, right: 0, textStyle: { color: t.muted, fontSize: 11 }, itemWidth: 14, itemHeight: 8 },
        grid: { left: 8, right: 8, top: 34, bottom: 8, containLabel: true },
        xAxis: { type: 'category', data: label, boundaryGap: false, ...axisStyle(t), splitLine: { show: false }, axisLabel: { color: t.muted, fontSize: 10, interval: 1 } },
        yAxis: { type: 'value', name: 'kejadian', nameTextStyle: { color: t.subtle, fontSize: 10, align: 'left' }, ...axisStyle(t) },
        series: ['1', '2', '3'].map((s, i) => ({
            name: `Shift ${s}`, type: 'line', smooth: 0.3, symbol: 'none', data: data.posisi_shift.data[s],
            lineStyle: { width: 2.2, color: c[i] }, itemStyle: { color: c[i] },
            areaStyle: i === 0 ? { color: c[i], opacity: 0.08 } : undefined,
        })),
    };
});

// ---------------------------------------------------------------- W6 timeline semua mesin
mount(document.getElementById('chartTimeline'), (t) => {
    const tl = data.timeline;
    const idx = Object.fromEntries(tl.mesin.map((m, i) => [m, i]));
    const labelMenit = (m) => `${String((7 + Math.floor(m / 60)) % 24).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
    return {
        ...base(t),
        tooltip: {
            ...base(t).tooltip,
            formatter: (p) => {
                if (!p.data?.info) return '';
                const k = p.data.info;
                return `<b>${k.mesin}</b> · ${k.jam} · shift ${k.shift}<br>${k.kategori} ${namaKat[k.kategori]}<br>${k.detail}<br>${fmtMenit(k.menit)} · ${k.no_trs}`;
            },
        },
        grid: { left: 8, right: 64, top: 24, bottom: 8, containLabel: true },
        xAxis: {
            type: 'value', min: 0, max: 1440, interval: 120, position: 'top',
            axisLabel: { color: t.muted, fontSize: 11, formatter: labelMenit },
            axisLine: { show: false }, axisTick: { show: false }, splitLine: { lineStyle: { color: t.border, type: [4, 4] } },
        },
        yAxis: {
            type: 'category', data: tl.mesin, inverse: true, axisLine: { show: false }, axisTick: { show: false },
            axisLabel: { color: t.text, fontSize: 11, fontWeight: 600 },
        },
        series: [{
            type: 'custom',
            renderItem: (params, api) => {
                const y = api.value(0);
                const a = api.coord([api.value(1), y]);
                const b = api.coord([api.value(2), y]);
                const h = api.size([0, 1])[1] * 0.6;
                const rect = echarts.graphic.clipRectByRect(
                    { x: a[0], y: a[1] - h / 2, width: Math.max(2, b[0] - a[0]), height: h },
                    { x: params.coordSys.x, y: params.coordSys.y, width: params.coordSys.width, height: params.coordSys.height },
                );
                return rect && { type: 'rect', shape: { ...rect, r: 3 }, style: api.style() };
            },
            encode: { x: [1, 2], y: 0 },
            data: tl.kejadian.map((k) => ({ value: [idx[k.mesin], k.mulai, k.selesai], itemStyle: { color: warna[k.kategori] }, info: k })),
            markArea: {
                silent: true, label: { show: false },
                data: [
                    [{ xAxis: 0, itemStyle: { color: t.accent, opacity: 0.03 } }, { xAxis: 480 }],
                    [{ xAxis: 480, itemStyle: { color: t.accent, opacity: 0.07 } }, { xAxis: 960 }],
                    [{ xAxis: 960, itemStyle: { color: t.dark ? '#ffffff' : '#1c1917', opacity: 0.05 } }, { xAxis: 1440 }],
                ],
            },
            markLine: { silent: true, symbol: 'none', lineStyle: { color: t.muted, width: 1 }, label: { show: false }, data: [{ xAxis: 480 }, { xAxis: 960 }] },
        }, {
            // Total menit per mesin di ujung kanan baris.
            type: 'custom',
            renderItem: (params, api) => {
                const p = api.coord([1440, api.value(0)]);
                return { type: 'text', style: { x: p[0] + 10, y: p[1], text: api.value(1) ? fmtMenit(api.value(1)) : '–', fill: api.value(1) ? t.text : t.subtle, font: '600 11px Inter, sans-serif', verticalAlign: 'middle' } };
            },
            encode: { y: 0 }, silent: true, tooltip: { show: false },
            data: tl.mesin.map((m, i) => [i, tl.menit_mesin[m] || 0]),
        }],
    };
}, (chart) => chart.on('click', (p) => p.data?.info && (window.location.href = data.url_profil.replace('__KODE__', p.data.info.mesin))));
