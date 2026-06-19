import { moneyCompact, percent } from '@/lib/format'

/** Shared financial color palette — matches the Excel/PDF radiografía palette. */
export const chartColors = {
    teal: '#106A59',
    tealLight: '#1DC1A2',
    blue: '#5B9BD5',
    red: '#DC2626',
    amber: '#D97706',
    gray: '#94A3B8',
    green: '#16A34A',
} as const

/** Rotating palette for multi-category charts (ranking, productos, etc). */
export const categoryPalette = ['#106A59', '#5B9BD5', '#1DC1A2', '#D97706', '#94A3B8', '#0EA5E9', '#7C3AED', '#DC2626']

const fontFamily = 'ui-sans-serif, system-ui, -apple-system, sans-serif'

function baseChart(extra: Record<string, any> = {}) {
    return {
        chart: { toolbar: { show: false }, fontFamily, animations: { speed: 250 }, ...extra.chart },
        grid: { borderColor: '#eef2f7', strokeDashArray: 3, ...extra.grid },
        dataLabels: { enabled: false, ...extra.dataLabels },
        legend: { fontFamily, fontSize: '11px', labels: { colors: '#475569' }, ...extra.legend },
        tooltip: { theme: 'light', ...extra.tooltip },
        states: { hover: { filter: { type: 'lighten' } } },
    }
}

/** Horizontal bar ranking — sucursales, gestores, top conceptos, etc. */
export function horizontalBarOptions(categories: string[], colors: string[] = [chartColors.teal]) {
    return {
        ...baseChart({ chart: { type: 'bar' } }),
        plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '62%' } },
        colors,
        xaxis: {
            categories,
            labels: { style: { colors: '#64748b', fontSize: '10px' }, formatter: (v: number) => moneyCompact(v) },
        },
        yaxis: { labels: { style: { colors: '#334155', fontSize: '10.5px' } } },
        tooltip: { theme: 'light', y: { formatter: (v: number) => money(v) } },
    }
}

/** Vertical column comparison — recuperación vs colocación, etc. */
export function columnOptions(categories: string[], colors: string[] = [chartColors.teal, chartColors.blue]) {
    return {
        ...baseChart({ chart: { type: 'bar' } }),
        plotOptions: { bar: { columnWidth: '55%', borderRadius: 6 } },
        colors,
        xaxis: { categories, labels: { style: { colors: '#64748b', fontSize: '10.5px' } } },
        yaxis: { labels: { style: { colors: '#64748b', fontSize: '10px' }, formatter: (v: number) => moneyCompact(v) } },
        tooltip: { y: { formatter: (v: number) => money(v) } },
    }
}

/** Stacked horizontal/vertical bars — mora por bucket, nómina por concepto, etc. */
export function stackedBarOptions(categories: string[], colors: string[] = categoryPalette, horizontal = false) {
    return {
        ...baseChart({ chart: { type: 'bar', stacked: true } }),
        plotOptions: { bar: { horizontal, borderRadius: 4 } },
        colors,
        xaxis: {
            categories,
            labels: horizontal
                ? { style: { colors: '#64748b', fontSize: '10px' }, formatter: (v: number) => moneyCompact(v) }
                : { style: { colors: '#64748b', fontSize: '10.5px' } },
        },
        tooltip: { y: { formatter: (v: number) => money(v) } },
    }
}

/** Donut — cartera sana vs vencida, categoría EBITDA, etc. */
export function donutOptions(labels: string[], colors: string[] = [chartColors.teal, chartColors.red]) {
    return {
        ...baseChart({ chart: { type: 'donut' } }),
        labels,
        colors,
        legend: { position: 'bottom', fontFamily, fontSize: '11px', labels: { colors: '#475569' } },
        dataLabels: {
            enabled: true,
            formatter: (v: number) => percent(v),
            style: { fontSize: '10px', fontWeight: 700 },
        },
        plotOptions: { pie: { donut: { size: '68%', labels: { show: true, total: { show: true, label: 'Total', formatter: (w: any) => moneyCompact(w.globals.seriesTotals.reduce((a: number, b: number) => a + b, 0)) } } } } },
        tooltip: { y: { formatter: (v: number) => money(v) } },
    }
}

function money(v: number) {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(v)
}
