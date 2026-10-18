// =============================================================================
// chart-theme.js — ECharts yang dipakai halaman draft + gaya dasar chart.
// Warna diambil dari CSS variables (draft.scss), jadi otomatis ikut
// light/dark. Ganti gaya chart = ubah file ini + token di draft.scss.
// =============================================================================
import * as echarts from 'echarts/core';
import { BarChart, LineChart, PieChart, HeatmapChart } from 'echarts/charts';
import {
    GridComponent, TooltipComponent, LegendComponent, VisualMapComponent,
    MarkLineComponent, DataZoomComponent,
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';

echarts.use([
    BarChart, LineChart, PieChart, HeatmapChart,
    GridComponent, TooltipComponent, LegendComponent, VisualMapComponent,
    MarkLineComponent, DataZoomComponent, CanvasRenderer,
]);

export { echarts };

export function tokens() {
    const s = getComputedStyle(document.documentElement);
    const v = (name) => s.getPropertyValue(name).trim();
    return {
        text: v('--text'),
        muted: v('--text-muted'),
        subtle: v('--text-subtle'),
        border: v('--border'),
        surface: v('--surface'),
        surfaceAlt: v('--surface-alt'),
        accent: v('--accent'),
        up: v('--up'),
        down: v('--down'),
        dark: document.documentElement.getAttribute('data-theme') === 'dark',
    };
}

/** Opsi dasar yang dipakai semua chart. */
export function base(t) {
    return {
        backgroundColor: 'transparent',
        textStyle: { fontFamily: 'Inter, system-ui, sans-serif', color: t.muted },
        animationDuration: 500,
        tooltip: {
            backgroundColor: t.surface,
            borderColor: t.border,
            borderWidth: 1,
            textStyle: { color: t.text, fontSize: 12 },
            extraCssText: 'border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);',
        },
    };
}

export function axisStyle(t) {
    return {
        axisLine: { lineStyle: { color: t.border } },
        axisTick: { show: false },
        axisLabel: { color: t.muted, fontSize: 11 },
        splitLine: { lineStyle: { color: t.border, type: [4, 4] } },
    };
}

export const fmtMenit = (m) => {
    const j = Math.floor(m / 60);
    return j ? `${j} j ${m % 60} m` : `${m} m`;
};

// Garis prioritas Pareto (%), diatur admin di Pengaturan (default 80 = aturan 80/20).
export const ambangPareto = () => Number(document.querySelector('meta[name="pareto-ambang"]')?.content) || 80;

// Gradasi besaran untuk SEMUA heatmap/kalender/matriks (keputusan 19-10-2026):
// rendah = biru → biru muda → kuning pucat → oranye → tinggi = merah (gaya peta suhu).
export const skalaPanas = (t) => (t.dark
    ? ['#2b6cb0', '#5b9cc4', '#d6cf7a', '#f08a4b', '#e5484d']
    : ['#2c7bb6', '#abd9e9', '#ffffbf', '#fdae61', '#d7191c']);

// Versi pekat (terbesar → terkecil) untuk blok berlabel putih, mis. treemap.
export const skalaPekat = ['#d7191c', '#f46d43', '#e08e1b', '#4a90c2', '#2c7bb6'];

// Warna shift 1/2/3 (selaras dengan skala di atas).
export const warnaShift = (t) => (t.dark ? ['#5b9cc4', '#fbbf24', '#f87171'] : ['#2c7bb6', '#f59e0b', '#d7191c']);

export const fmtAngka = (n) => Number(n).toLocaleString('id-ID');

/**
 * Daftarkan chart: digambar sekarang, digambar ulang saat tema berubah,
 * dan menyesuaikan ukuran saat kontainer berubah.
 * `setup(chart)` (opsional) dipanggil tiap kali chart dibuat, mis. untuk
 * memasang handler klik supaya tidak hilang setelah ganti tema.
 */
export function mount(el, buildOption, setup) {
    if (!el) return null;
    let chart;
    const init = () => {
        chart = echarts.init(el, null, { renderer: 'canvas' });
        chart.setOption(buildOption(tokens()), true);
        if (setup) setup(chart);
    };
    init();

    new ResizeObserver(() => chart.resize()).observe(el);
    document.addEventListener('themechange', () => {
        chart.dispose();
        init();
    });
    return chart;
}
