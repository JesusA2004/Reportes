/** Shared number/currency/percent formatters for the Radiografía dashboard. */

const fullCurrency = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' })
const integer = new Intl.NumberFormat('es-MX')

/** Full precision currency, e.g. $1,234,567.89 */
export function money(v: number | null | undefined): string {
    return fullCurrency.format(Number(v ?? 0))
}

/** Compact currency for cards/charts, e.g. $1.2M / $850K / $930 */
export function moneyCompact(v: number | null | undefined): string {
    const n = Number(v ?? 0)
    const sign = n < 0 ? '-' : ''
    const abs = Math.abs(n)
    if (abs >= 1_000_000) return `${sign}$${(abs / 1_000_000).toFixed(abs >= 10_000_000 ? 0 : 1)}M`
    if (abs >= 1_000) return `${sign}$${(abs / 1_000).toFixed(abs >= 10_000 ? 0 : 1)}K`
    return `${sign}$${abs.toFixed(0)}`
}

/** Percentage with 2 decimals, e.g. 35.04% */
export function percent(v: number | null | undefined): string {
    return `${Number(v ?? 0).toFixed(2)}%`
}

/** Plain integer with thousands separators */
export function num(v: number | null | undefined): string {
    return integer.format(Number(v ?? 0))
}

/**
 * Money that distinguishes a real $0 from "not attributable at this scope" (null).
 * Never collapse null into $0.00 — that's exactly the silent fallback the
 * scoped radiography dashboard must never do (see RadiographySnapshotBuilder::
 * applyScope()/summaryFromRow(), which emits null for fields that can't be
 * attributed to a branch/employee).
 */
export function moneyOrNa(v: number | null | undefined, label = 'No atribuible'): string {
    return v === null || v === undefined ? label : money(v)
}

/** Percent counterpart of moneyOrNa(). */
export function percentOrNa(v: number | null | undefined, label = 'No atribuible'): string {
    return v === null || v === undefined ? label : percent(v)
}
