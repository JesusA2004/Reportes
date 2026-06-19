<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import {
    ArrowLeft, AlertTriangle, FileSpreadsheet, FileText, Search, ChevronDown, ChevronUp, Download,
    HandCoins, TrendingUp, Landmark, Percent, Receipt, Wallet, Gauge, Building2, Banknote, CheckCircle2,
} from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import KpiCard from '@/components/radiography/KpiCard.vue'
import ChartCard from '@/components/radiography/ChartCard.vue'
import EbitdaBadge from '@/components/radiography/EbitdaBadge.vue'
import EmptyState from '@/components/radiography/EmptyState.vue'
import FilterBar from '@/components/radiography/FilterBar.vue'
import { money, percent as fmtPercent, num } from '@/lib/format'
import { chartColors, horizontalBarOptions, columnOptions, stackedBarOptions, donutOptions } from '@/lib/chart-theme'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    period: any
    snapshot: any | null
    run: any | null
    hasExcelExport: boolean
    hasPdfExport: boolean
    excelUrl: string
    pdfUrl: string
    branches: { id: number; name: string }[]
    employees: { id: number; name: string }[]
    allPeriods: { id: number; label: string; code: string; type: string; has_snapshot: boolean }[]
    filteredExcelBaseUrl: string
    filteredPdfBaseUrl: string
    filteredDataUrl: string
    updateSaldoInicialUrl: string
}>()

// ── Filtered export config (genera Excel/PDF por sucursal, gestor o comparativo) ──
const showFilteredPanel = ref(false)
const filteredScope      = ref<'general' | 'branch' | 'employee'>('general')
const filteredType       = ref<'simple' | 'month_vs_month' | 'bimester_vs_bimester' | 'quarter_vs_quarter'>('simple')
const filteredBranchId   = ref<number | null>(null)
const filteredEmployeeId = ref<number | null>(null)
const filteredComparePeriodId = ref<number | null>(null)
const filteredExtraAmount = ref<string>('')
const filteredExtraNotes  = ref<string>('')

const isComparative = computed(() => filteredType.value !== 'simple')

const periodTypeForFilter = computed((): string | null => {
    if (filteredType.value === 'month_vs_month') return 'monthly'
    if (filteredType.value === 'bimester_vs_bimester') return 'bimestral'
    if (filteredType.value === 'quarter_vs_quarter') return 'quarterly'
    return null
})

const comparePeriodOptions = computed(() => {
    const ptype = periodTypeForFilter.value
    return props.allPeriods.filter(p => {
        if (p.id === props.period.id) return false
        if (ptype && p.type !== ptype) return false
        return true
    })
})

function buildFilteredUrl(format: 'xlsx' | 'pdf'): string {
    const base = format === 'xlsx' ? props.filteredExcelBaseUrl : props.filteredPdfBaseUrl
    const params = new URLSearchParams()

    params.set('report_type', filteredType.value)

    if (isComparative.value) {
        if (filteredComparePeriodId.value) params.set('compare_period_id', String(filteredComparePeriodId.value))
        if (filteredScope.value !== 'general') params.set('scope', filteredScope.value)
        if (filteredScope.value === 'branch' && filteredBranchId.value) params.set('branch_id', String(filteredBranchId.value))
        if (filteredScope.value === 'employee' && filteredEmployeeId.value) params.set('employee_id', String(filteredEmployeeId.value))
    } else {
        params.set('scope', filteredScope.value)
        if (filteredScope.value === 'branch' && filteredBranchId.value) params.set('branch_id', String(filteredBranchId.value))
        if (filteredScope.value === 'employee') {
            if (filteredEmployeeId.value) params.set('employee_id', String(filteredEmployeeId.value))
            if (filteredExtraAmount.value) params.set('extra_employee_expense_amount', filteredExtraAmount.value)
            if (filteredExtraNotes.value) params.set('extra_employee_expense_notes', filteredExtraNotes.value)
        }
    }

    return base + '?' + params.toString()
}

const filteredXlsxUrl = computed(() => buildFilteredUrl('xlsx'))
const filteredPdfUrl  = computed(() => buildFilteredUrl('pdf'))

const canDownloadFiltered = computed(() => {
    if (isComparative.value) {
        if (!filteredComparePeriodId.value) return false
        const cmp = props.allPeriods.find((p: any) => p.id === filteredComparePeriodId.value)
        if (!cmp?.has_snapshot) return false
    }
    if (!isComparative.value && filteredScope.value === 'branch' && !filteredBranchId.value) return false
    if (!isComparative.value && filteredScope.value === 'employee' && !filteredEmployeeId.value) return false
    return true
})

// ── Filtered preview data (AJAX) ─────────────────────────────────────────────
const filteredPreview   = ref<any>(null)
const filteredLoading   = ref(false)
const filteredFetchError = ref<string | null>(null)

async function fetchFilteredPreview() {
    if (filteredScope.value === 'general' || isComparative.value) {
        filteredPreview.value = null
        filteredFetchError.value = null
        return
    }
    if (filteredScope.value === 'branch' && !filteredBranchId.value) {
        filteredPreview.value = null
        return
    }
    if (filteredScope.value === 'employee' && !filteredEmployeeId.value) {
        filteredPreview.value = null
        return
    }

    filteredLoading.value = true
    filteredFetchError.value = null
    filteredPreview.value = null

    try {
        const params = new URLSearchParams({ scope: filteredScope.value })
        if (filteredScope.value === 'branch' && filteredBranchId.value)
            params.set('branch_id', String(filteredBranchId.value))
        if (filteredScope.value === 'employee' && filteredEmployeeId.value)
            params.set('employee_id', String(filteredEmployeeId.value))

        const resp = await fetch(`${props.filteredDataUrl}?${params}`, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
        const json = await resp.json()
        if (!resp.ok || json.error) {
            filteredFetchError.value = json.error ?? `Error ${resp.status}`
        } else {
            filteredPreview.value = json
        }
    } catch (e: any) {
        filteredFetchError.value = e?.message ?? 'Error de red al cargar datos filtrados'
    } finally {
        filteredLoading.value = false
    }
}

watch([filteredScope, filteredBranchId, filteredEmployeeId], fetchFilteredPreview)

onMounted(() => {
    const params = new URLSearchParams(window.location.search)
    const scope  = params.get('scope')
    if (scope === 'branch') {
        const branchId = Number(params.get('branch_id')) || null
        if (branchId) {
            showFilteredPanel.value = true
            filteredScope.value     = 'branch'
            filteredBranchId.value  = branchId
        }
    } else if (scope === 'employee') {
        const employeeId = Number(params.get('employee_id')) || null
        if (employeeId) {
            showFilteredPanel.value  = true
            filteredScope.value      = 'employee'
            filteredEmployeeId.value = employeeId
        }
    }
})

// ════════════════════════════════════════════════════════════════════════════
// DASHBOARD DATA LAYER — todo derivado del snapshot ya cargado, sin recálculos
// distintos a Excel/PDF. EBITDA usa los mismos umbrales que
// RadiographyStyleHelper::ebitdaCategory() (300,000 / 100,000).
// ════════════════════════════════════════════════════════════════════════════
type TabKey = 'resumen' | 'sucursales' | 'ingresos' | 'gastos' | 'nomina' | 'mora' | 'productos' | 'prestamos' | 'categoria' | 'gestores'
const activeTab = ref<TabKey>('resumen')

const snap   = computed(() => props.snapshot)
const sum    = computed(() => snap.value?.summary ?? {})
const charts = computed(() => snap.value?.charts ?? {})

const branchRadiography = computed(() => snap.value?.branch_radiography ?? null)
const brGlobal  = computed(() => branchRadiography.value?.global ?? null)
const brRaw     = computed(() => (branchRadiography.value?.branches ?? []) as any[])

function ebitdaCategoryOf(value: number): 'SENIOR' | 'JUNIOR' | 'MANTENIDO' {
    if (value >= 300_000) return 'SENIOR'
    if (value >= 100_000) return 'JUNIOR'
    return 'MANTENIDO'
}

// ── Ingresos / Cobranza ───────────────────────────────────────────────────────
const ingrCapital   = computed(() => Number(brGlobal.value?.capital_recuperado)  || 0)
const ingrInteres   = computed(() => Number(brGlobal.value?.interes_recuperado)  || 0)
const ingrImpuesto  = computed(() => Number(brGlobal.value?.impuesto_recuperado) || 0)
const ingrMultas    = computed(() => Number(brGlobal.value?.charges)             || 0)
const ingrCargosIni = computed(() => Number(brGlobal.value?.cargos_inicio)       || 0)
const ingrComAper   = computed(() => Number(brGlobal.value?.comision_apertura)   || 0)

// ── Nómina ─────────────────────────────────────────────────────────────────────
const nomNomina   = computed(() => Number(brGlobal.value?.nomina_total)    || 0)
const nomComis    = computed(() => Number(brGlobal.value?.comisiones)      || 0)
const nomVac      = computed(() => Number(brGlobal.value?.vacaciones)      || 0)
const nomPrimaVac = computed(() => Number(brGlobal.value?.prima_vacacional)|| 0)
const nomBonos    = computed(() => Number(brGlobal.value?.bonos)           || 0)

const NOI_DEDUCTION_LABELS = new Set(['Descuentos Infonavit', 'Pensión Alimenticia', 'Descuento Servicios Moto',
    'Financiamiento de Motos (desc.)', 'Préstamo Personal', 'Subsidio para el Empleo APL',
    'Otros descuentos NOI', 'Descuento de uniformes'])

const nomDetalle = computed<{ label: string; value: number }[]>(() => {
    const det = brGlobal.value?.nomina_detalle as Record<string, number> | undefined
    if (!det) return []
    return Object.entries(det).filter(([, v]) => Number(v) > 0).map(([label, v]) => ({ label, value: Number(v) }))
})
const nomTotal = computed(() => nomNomina.value + nomComis.value + nomVac.value + nomPrimaVac.value + nomBonos.value + nomDetalle.value.reduce((s, r) => s + r.value, 0))
const nomDescuentosNOI = computed(() => nomDetalle.value.filter(r => NOI_DEDUCTION_LABELS.has(r.label)).reduce((s, r) => s + r.value, 0))
const nomNeto = computed(() => nomTotal.value - nomDescuentosNOI.value)

// ── Préstamos intersucursales ─────────────────────────────────────────────────
const fondeoGlobal = computed(() => Number(brGlobal.value?.prestamos_fondea) || 0)

// ── Cartera / mora global ──────────────────────────────────────────────────────
const recGlobal     = computed(() => Number(brGlobal.value?.recuperacion_total) || 0)
const colGlobal     = computed(() => Number(brGlobal.value?.colocacion)         || 0)
const carteraGlobal = computed(() => Number(brGlobal.value?.valor_cartera)      || 0)
const excGlobal     = computed(() => Number(brGlobal.value?.excedentes)         || 0)
const mora0_30g    = computed(() => Number(brGlobal.value?.mora_0_30)     || 0)
const mora31_60g   = computed(() => Number(brGlobal.value?.mora_31_60)    || 0)
const mora61_90g   = computed(() => Number(brGlobal.value?.mora_61_90)    || 0)
const mora91_120g  = computed(() => Number(brGlobal.value?.mora_91_120)   || 0)
const mora120plusG = computed(() => Number(brGlobal.value?.mora_120_plus) || 0)
const moraTotalGlobal = computed(() => mora0_30g.value + mora31_60g.value + mora61_90g.value + mora91_120g.value + mora120plusG.value)

const moraBucketsGlobal = computed(() => [
    { label: 'Mora 1-30',   value: mora0_30g.value },
    { label: 'Mora 31-60',  value: mora31_60g.value },
    { label: 'Mora 61-90',  value: mora61_90g.value },
    { label: 'Mora 91-120', value: mora91_120g.value },
    { label: 'Mora 120+',   value: mora120plusG.value },
])

// ── Gastos ─────────────────────────────────────────────────────────────────────
const brGlobalGastos = computed(() => {
    const det = brGlobal.value?.gastos_detalle as Record<string, number> | undefined
    if (!det) return []
    return Object.entries(det).map(([concepto, total]) => ({ concepto, total: Number(total) })).filter(c => c.total > 0).sort((a, b) => b.total - a.total)
})
const brGlobalGastosTotal = computed(() => Number(brGlobal.value?.gastos_operativos) || 0)

// Desglose bruto (fuente legacy fact_expenses) — complementa la vista canónica
const gastosDetail     = computed(() => snap.value?.sections?.expenses_detail ?? {})
const gastosByCategory = computed(() => gastosDetail.value?.byCategory ?? [])
const gastosByConcept  = computed(() => gastosDetail.value?.byConcept ?? [])
const gastosByEmployee = computed(() => gastosDetail.value?.byEmployee ?? [])
const gastosBySource   = computed(() => gastosDetail.value?.bySource ?? [])

// ── EBITDA global ──────────────────────────────────────────────────────────────
// EBITDA = Saldo inicial en caja + Ingresos totales (Recuperación/Cobranza) − Colocación
// − Gastos totales (operativos + Nómina y Capital Humano). Misma fórmula que Excel/PDF
// (RadiographyWorkbookBuilder::buildGlobalSheet / radiography-pdf.blade.php).
const saldoInicialCaja  = computed(() => Number(snap.value?.saldo_inicial_caja) || 0)
const saldoFinalCaja    = computed(() => snap.value?.saldo_final_caja !== null && snap.value?.saldo_final_caja !== undefined ? Number(snap.value.saldo_final_caja) : null)
const gastosEbitdaTotal = computed(() => brGlobalGastosTotal.value + nomNeto.value)
const utilidadGlobal    = computed(() => saldoInicialCaja.value + recGlobal.value - colGlobal.value - gastosEbitdaTotal.value)
// Diferencia = EBITDA − Envío de utilidad a corporativo. Puede ser negativa — no se fuerza a 0;
// ese es justamente el saldo a llevar como saldo inicial del siguiente periodo.
const diferencia        = computed(() => utilidadGlobal.value - excGlobal.value)
const enConciliacion    = computed(() => brGlobal.value === null)

// ── Captura de saldo inicial en caja (único insumo que no viene de ninguna fuente importada) ──
const saldoInicialEditing = ref(false)
const saldoInicialInput   = ref('')
const saldoInicialSaving  = ref(false)

function startEditSaldoInicial() {
    saldoInicialInput.value = saldoInicialCaja.value ? String(saldoInicialCaja.value) : ''
    saldoInicialEditing.value = true
}

function saveSaldoInicial() {
    const value = Number(saldoInicialInput.value)
    if (Number.isNaN(value)) return
    saldoInicialSaving.value = true
    router.post(props.updateSaldoInicialUrl, { saldo_inicial_caja: value }, {
        preserveScroll: true,
        onFinish: () => { saldoInicialSaving.value = false; saldoInicialEditing.value = false },
    })
}

// ── Sucursales — fuente canónica única (branch_radiography.branches) ─────────
// nominaFull replica exactamente RadiographyStyleHelper::branchEbitdaEstimate():
// nomina_total + comisiones + bonos + vacaciones + prima_vacacional + detalle.
// Así el EBITDA/categoría por sucursal coincide siempre con Excel y PDF.
const branchesFull = computed(() => {
    return brRaw.value.map((b: any) => {
        const moraSum = (Number(b.mora_0_30) || 0) + (Number(b.mora_31_60) || 0) + (Number(b.mora_61_90) || 0) + (Number(b.mora_91_120) || 0) + (Number(b.mora_120_plus) || 0)
        const cartera = Number(b.valor_cartera) || 0
        const nominaFull = (Number(b.nomina_total) || 0) + (Number(b.comisiones) || 0) + (Number(b.bonos) || 0)
            + (Number(b.vacaciones) || 0) + (Number(b.prima_vacacional) || 0)
            + Object.values((b.nomina_detalle ?? {}) as Record<string, number>).reduce((s: number, v: any) => s + (Number(v) || 0), 0)
        const recuperacion = Number(b.recuperacion_total) || 0
        const gastos = Number(b.gastos_operativos) || 0
        const ebitda = recuperacion - gastos - nominaFull
        return {
            nombre: b.sucursal,
            recuperacion,
            colocacion: Number(b.colocacion) || 0,
            cartera,
            vencida: moraSum,
            mora: cartera > 0 ? (moraSum / cartera) * 100 : 0,
            gastos,
            nomina: nominaFull,
            ebitda,
            categoria: ebitdaCategoryOf(ebitda),
            mora_0_30: Number(b.mora_0_30) || 0,
            mora_31_60: Number(b.mora_31_60) || 0,
            mora_61_90: Number(b.mora_61_90) || 0,
            mora_91_120: Number(b.mora_91_120) || 0,
            mora_120_plus: Number(b.mora_120_plus) || 0,
        }
    }).sort((a, b) => a.nombre.localeCompare(b.nombre))
})

const categoriaCounts = computed(() => {
    const counts: Record<string, number> = { SENIOR: 0, JUNIOR: 0, MANTENIDO: 0 }
    for (const b of branchesFull.value) counts[b.categoria] = (counts[b.categoria] ?? 0) + 1
    return counts
})

// ── Empleados / Gestores fusionados ───────────────────────────────────────────
const empGest = computed(() => snap.value?.sections?.employees_gestores ?? [])

// ── Préstamos activos — agregado por sucursal (misma lógica que Excel/PDF) ───
const activeLoansByBranch = computed(() => {
    const rows = (snap.value?.sections?.active_loans ?? []) as any[]
    const map = new Map<string, { sucursal: string; count: number; saldo: number; vencido: number }>()
    for (const al of rows) {
        const key = al.sucursal ?? 'Sin sucursal'
        if (!map.has(key)) map.set(key, { sucursal: key, count: 0, saldo: 0, vencido: 0 })
        const entry = map.get(key)!
        entry.count++
        entry.saldo += Number(al.saldo_activo) || 0
        entry.vencido += Number(al.vencido) || 0
    }
    return Array.from(map.values())
        .map(e => ({ ...e, pct: e.saldo > 0 ? (e.vencido / e.saldo) * 100 : 0 }))
        .sort((a, b) => a.sucursal.localeCompare(b.sucursal))
})
const activeLoansTotals = computed(() => {
    const rows = activeLoansByBranch.value
    const count = rows.reduce((s, r) => s + r.count, 0)
    const saldo = rows.reduce((s, r) => s + r.saldo, 0)
    const vencido = rows.reduce((s, r) => s + r.vencido, 0)
    return { count, saldo, vencido, pct: saldo > 0 ? (vencido / saldo) * 100 : 0 }
})

// ── Productos ──────────────────────────────────────────────────────────────────
const productosRows = computed(() => snap.value?.sections?.products ?? [])

// ════════════════════════════════════════════════════════════════════════════
// FILTROS DE VISTA EN VIVO — sucursal / producto / mora / gestor / categoría
// ════════════════════════════════════════════════════════════════════════════
const vfBranch    = ref('')
const vfProduct   = ref('')
const vfBucket    = ref('')
const vfGestor    = ref('')
const vfCategoria = ref('')

const vfBranchOptions    = computed(() => branchesFull.value.map(b => b.nombre))
const vfProductOptions   = computed(() => productosRows.value.map((p: any) => p.producto))
const vfBucketOptions    = computed(() => moraBucketsGlobal.value.map(b => b.label))
const vfGestorOptions    = computed(() => (empGest.value as any[]).map(e => e.name).sort())
const vfCategoriaOptions = ['SENIOR', 'JUNIOR', 'MANTENIDO']

const vfBranchRow  = computed(() => vfBranch.value ? branchesFull.value.find(b => b.nombre === vfBranch.value) ?? null : null)
const vfGestorRow  = computed(() => vfGestor.value ? (empGest.value as any[]).find(e => e.name === vfGestor.value) ?? null : null)
const vfProductRow = computed(() => vfProduct.value ? productosRows.value.find((p: any) => p.producto === vfProduct.value) ?? null : null)

const MORA_BUCKET_FIELD: Record<string, string> = {
    'Mora 1-30': 'mora_0_30', 'Mora 31-60': 'mora_31_60', 'Mora 61-90': 'mora_61_90',
    'Mora 91-120': 'mora_91_120', 'Mora 120+': 'mora_120_plus',
}
const vfBucketValue = computed<number | null>(() => {
    if (!vfBucket.value) return null
    const field = MORA_BUCKET_FIELD[vfBucket.value]
    if (!field) return null
    const source = vfBranchRow.value ?? { [field]: (moraBucketsGlobal.value.find(b => b.label === vfBucket.value)?.value ?? 0) }
    return Number((source as any)[field]) || 0
})

const vfHasFilters = computed(() => !!(vfBranch.value || vfProduct.value || vfBucket.value || vfGestor.value || vfCategoria.value))
function vfClearAll() {
    vfBranch.value = ''; vfProduct.value = ''; vfBucket.value = ''; vfGestor.value = ''; vfCategoria.value = ''
}

// Sucursales visibles tras filtro de categoría (afecta tablas/gráficas por sucursal)
const branchesFiltered = computed(() => {
    let rows = branchesFull.value
    if (vfCategoria.value) rows = rows.filter(b => b.categoria === vfCategoria.value)
    return rows
})

// ── KPIs principales (recalculados con el filtro de sucursal activo) ─────────
const kpiRec     = computed(() => vfBranchRow.value ? vfBranchRow.value.recuperacion : recGlobal.value)
const kpiCol     = computed(() => vfBranchRow.value ? vfBranchRow.value.colocacion   : colGlobal.value)
const kpiCartera = computed(() => vfBranchRow.value ? vfBranchRow.value.cartera      : carteraGlobal.value)
const kpiMora = computed(() => {
    if (vfBucketValue.value !== null) return vfBucketValue.value
    if (vfBranchRow.value) return vfBranchRow.value.vencida
    return moraTotalGlobal.value
})
const kpiMoraPct = computed(() => {
    if (vfBucketValue.value !== null) return kpiCartera.value > 0 ? (vfBucketValue.value / kpiCartera.value) * 100 : 0
    if (vfBranchRow.value) return vfBranchRow.value.mora
    return kpiCartera.value > 0 ? (kpiMora.value / kpiCartera.value) * 100 : 0
})
const kpiGastos = computed(() => vfBranchRow.value ? vfBranchRow.value.gastos : brGlobalGastosTotal.value)
const kpiNomina = computed(() => vfBranchRow.value ? vfBranchRow.value.nomina : nomTotal.value)
const kpiUtil = computed(() => vfBranchRow.value ? vfBranchRow.value.ebitda : (brGlobal.value ? utilidadGlobal.value : 0))

const kpiMoraLabel = computed(() => vfBucket.value ? `Mora · ${vfBucket.value}` : 'Mora total')
const kpiUtilLabel = computed(() => vfBranchRow.value ? 'EBITDA estimado' : 'EBITDA')
const vfActiveBadge = computed(() => vfBranchRow.value ? `Vista filtrada · ${vfBranch.value}` : null)

// ── Alertas (Resumen) ─────────────────────────────────────────────────────────
const alertas = computed(() => {
    const list: { text: string; tone: 'red' | 'amber' }[] = []
    if (kpiMoraPct.value > 25) list.push({ text: `Índice de mora alto: ${fmtPercent(kpiMoraPct.value)} de la cartera.`, tone: 'red' })
    if (kpiUtil.value < 0) list.push({ text: `EBITDA negativo: ${money(kpiUtil.value)}.`, tone: 'red' })
    const mantenidos = branchesFull.value.filter(b => b.categoria === 'MANTENIDO')
    if (mantenidos.length > 0) list.push({ text: `${mantenidos.length} sucursal(es) en categoría MANTENIDO: ${mantenidos.map(b => b.nombre).join(', ')}.`, tone: 'amber' })
    return list
})

// ── Filtros de tabla Empleados / Gestores ─────────────────────────────────────
const searchEmp     = ref('')
const filterBranch  = ref('')
const branchOptions = computed(() => props.branches.map(b => b.name).sort())
const filteredEmp = computed(() => {
    let rows = empGest.value as any[]
    if (searchEmp.value.trim()) {
        const q = searchEmp.value.trim().toLowerCase()
        rows = rows.filter((r: any) => (r.name ?? '').toLowerCase().includes(q) || (r.branch ?? '').toLowerCase().includes(q))
    }
    if (filterBranch.value) rows = rows.filter((r: any) => r.branch === filterBranch.value)
    if (vfGestor.value) rows = rows.filter((r: any) => r.name === vfGestor.value)
    return rows
})
const showAllEmp = ref(false)
const empVisible = computed(() => showAllEmp.value ? filteredEmp.value : filteredEmp.value.slice(0, 15))

const topGestoresColocacion = computed(() => [...(empGest.value as any[])].filter(e => (e.colocacion ?? 0) > 0).sort((a, b) => b.colocacion - a.colocacion).slice(0, 10))

// ── Gastos: tabla jerárquica (sucursal padre + conceptos hijos) ──────────────
const gastosSearch = ref('')
const gastosTreeAll = computed(() => {
    return branchesFull.value.map(b => {
        const raw = brRaw.value.find((r: any) => r.sucursal === b.nombre)
        const det = (raw?.gastos_detalle ?? {}) as Record<string, number>
        const conceptos = Object.entries(det).filter(([, v]) => Number(v) > 0).map(([concepto, total]) => ({ concepto, total: Number(total) })).sort((a, b) => b.total - a.total)
        return { sucursal: b.nombre, total: b.gastos, conceptos }
    }).filter(g => g.total > 0)
})
const gastosTree = computed(() => {
    const q = gastosSearch.value.trim().toLowerCase()
    if (!q) return gastosTreeAll.value
    return gastosTreeAll.value
        .map(g => ({ ...g, conceptos: g.conceptos.filter(c => c.concepto.toLowerCase().includes(q)) }))
        .filter(g => g.sucursal.toLowerCase().includes(q) || g.conceptos.length > 0)
})
const expandedGastosBranch = ref<string | null>(null)

// ── Nómina: tabla jerárquica (sucursal padre + conceptos hijos) ──────────────
const nominaTree = computed(() => {
    return branchesFull.value.map(b => {
        const raw = brRaw.value.find((r: any) => r.sucursal === b.nombre)
        const det = (raw?.nomina_detalle ?? {}) as Record<string, number>
        const base = [
            { concepto: 'Sueldos',          total: Number(raw?.nomina_total) || 0 },
            { concepto: 'Comisiones',       total: Number(raw?.comisiones) || 0 },
            { concepto: 'Bonos',            total: Number(raw?.bonos) || 0 },
            { concepto: 'Vacaciones',       total: Number(raw?.vacaciones) || 0 },
            { concepto: 'Prima vacacional', total: Number(raw?.prima_vacacional) || 0 },
            ...Object.entries(det).filter(([, v]) => Number(v) > 0).map(([concepto, total]) => ({ concepto, total: Number(total) })),
        ].filter(c => c.total > 0)
        const descuentos = base.filter(c => NOI_DEDUCTION_LABELS.has(c.concepto)).reduce((s, c) => s + c.total, 0)
        return { sucursal: b.nombre, total: b.nomina, neto: b.nomina - descuentos, descuentos, conceptos: base }
    }).filter(n => n.total > 0)
})
const expandedNominaBranch = ref<string | null>(null)

const pct = fmtPercent

const tabs: { key: TabKey; label: string }[] = [
    { key: 'resumen',    label: 'Resumen' },
    { key: 'sucursales', label: 'Sucursales' },
    { key: 'ingresos',   label: 'Ingresos / Cobranza' },
    { key: 'gastos',     label: 'Gastos' },
    { key: 'nomina',     label: 'Nómina' },
    { key: 'mora',       label: 'Mora / Cartera' },
    { key: 'productos',  label: 'Productos' },
    { key: 'prestamos',  label: 'Préstamos activos' },
    { key: 'categoria',  label: 'Categoría EBITDA' },
    { key: 'gestores',   label: 'Gestores' },
]

// ════════════════════════════════════════════════════════════════════════════
// GRÁFICAS — ApexCharts. Reactivas a los filtros de vista vía computed.
// ════════════════════════════════════════════════════════════════════════════
function dimColors(labels: string[], selected: string, base: string | string[]): string[] {
    const baseArr = Array.isArray(base) ? base : labels.map(() => base)
    if (!selected) return baseArr
    return labels.map((l, i) => (l === selected ? baseArr[i % baseArr.length] : '#cbd5e1'))
}

// Resumen: Recuperación vs Colocación
const recColSeries = computed(() => [{ name: 'Monto', data: [kpiRec.value, kpiCol.value] }])
const recColOptions = computed(() => ({
    ...columnOptions(['Recuperación / Cobranza', 'Colocación']),
    colors: [chartColors.teal, chartColors.blue],
}))

// Resumen: Cartera vs Vencida (donut)
const carteraDonutSeries = computed(() => {
    const sana = Math.max(0, kpiCartera.value - kpiMora.value)
    return [sana, kpiMora.value]
})
const carteraDonutOptions = computed(() => donutOptions(['Cartera sana', 'Cartera vencida'], [chartColors.teal, chartColors.red]))

// Mora por bucket (stacked/column)
const moraBucketSeries = computed(() => [{ name: 'Mora', data: moraBucketsGlobal.value.map(b => b.value) }])
const moraBucketOptions = computed(() => ({
    ...stackedBarOptions(moraBucketsGlobal.value.map(b => b.label), [chartColors.red]),
    colors: dimColors(moraBucketsGlobal.value.map(b => b.label), vfBucket.value, chartColors.red),
}))

// Sucursales: ranking por recuperación / cartera / EBITDA
function rankingSeries(field: 'recuperacion' | 'cartera' | 'ebitda', limit = 13) {
    return [...branchesFiltered.value].sort((a, b) => b[field] - a[field]).slice(0, limit)
}
const rankingRecuperacion = computed(() => rankingSeries('recuperacion'))
const rankingRecuperacionOptions = computed(() => ({
    ...horizontalBarOptions(rankingRecuperacion.value.map(b => b.nombre)),
    colors: dimColors(rankingRecuperacion.value.map(b => b.nombre), vfBranch.value, chartColors.teal),
}))
const rankingRecuperacionSeries = computed(() => [{ name: 'Recuperación', data: rankingRecuperacion.value.map(b => b.recuperacion) }])

const rankingCartera = computed(() => rankingSeries('cartera'))
const rankingCarteraOptions = computed(() => ({
    ...horizontalBarOptions(rankingCartera.value.map(b => b.nombre)),
    colors: dimColors(rankingCartera.value.map(b => b.nombre), vfBranch.value, chartColors.blue),
}))
const rankingCarteraSeries = computed(() => [{ name: 'Cartera', data: rankingCartera.value.map(b => b.cartera) }])

const rankingEbitda = computed(() => rankingSeries('ebitda'))
const rankingEbitdaOptions = computed(() => ({
    ...horizontalBarOptions(rankingEbitda.value.map(b => b.nombre)),
    colors: rankingEbitda.value.map(b => (b.ebitda < 0 ? chartColors.red : chartColors.green)),
}))
const rankingEbitdaSeries = computed(() => [{ name: 'EBITDA', data: rankingEbitda.value.map(b => b.ebitda) }])

// Categoría EBITDA: distribución (donut) + EBITDA por sucursal coloreado por categoría
const categoriaDonutOptions = computed(() => donutOptions(['SENIOR', 'JUNIOR', 'MANTENIDO'], [chartColors.green, chartColors.amber, chartColors.red]))
const categoriaDonutSeries = computed(() => [categoriaCounts.value.SENIOR, categoriaCounts.value.JUNIOR, categoriaCounts.value.MANTENIDO])

const categoriaColorMap: Record<string, string> = { SENIOR: chartColors.green, JUNIOR: chartColors.amber, MANTENIDO: chartColors.red }
const ebitdaPorSucursal = computed(() => [...branchesFiltered.value].sort((a, b) => b.ebitda - a.ebitda))
const ebitdaPorSucursalOptions = computed(() => ({
    ...horizontalBarOptions(ebitdaPorSucursal.value.map(b => b.nombre)),
    colors: ebitdaPorSucursal.value.map(b => categoriaColorMap[b.categoria]),
}))
const ebitdaPorSucursalSeries = computed(() => [{ name: 'EBITDA', data: ebitdaPorSucursal.value.map(b => b.ebitda) }])

// Ingresos / Cobranza: colocación por producto
const productosSorted = computed(() => [...productosRows.value].sort((a: any, b: any) => (b.colocacion ?? 0) - (a.colocacion ?? 0)))
const colocacionProductoOptions = computed(() => ({
    ...horizontalBarOptions(productosSorted.value.map((p: any) => p.producto)),
    colors: dimColors(productosSorted.value.map((p: any) => p.producto), vfProduct.value, chartColors.teal),
}))
const colocacionProductoSeries = computed(() => [{ name: 'Colocación', data: productosSorted.value.map((p: any) => p.colocacion ?? 0) }])

// Ingresos / Cobranza: ranking por sucursal (colocación)
const colocacionSucursalOptions = computed(() => ({
    ...horizontalBarOptions(branchesFiltered.value.map(b => b.nombre)),
    colors: dimColors(branchesFiltered.value.map(b => b.nombre), vfBranch.value, chartColors.blue),
}))
const colocacionSucursalSeries = computed(() => [{ name: 'Colocación', data: [...branchesFiltered.value].map(b => b.colocacion) }])

// Gastos: por sucursal / por categoría
const gastosPorSucursalSorted = computed(() => [...branchesFiltered.value].filter(b => b.gastos > 0).sort((a, b) => b.gastos - a.gastos))
const gastosPorSucursalOptions = computed(() => ({
    ...horizontalBarOptions(gastosPorSucursalSorted.value.map(b => b.nombre)),
    colors: dimColors(gastosPorSucursalSorted.value.map(b => b.nombre), vfBranch.value, chartColors.amber),
}))
const gastosPorSucursalSeries = computed(() => [{ name: 'Gastos', data: gastosPorSucursalSorted.value.map(b => b.gastos) }])

const gastosPorCategoriaOptions = computed(() => horizontalBarOptions(brGlobalGastos.value.slice(0, 10).map(g => g.concepto), [chartColors.teal]))
const gastosPorCategoriaSeries = computed(() => [{ name: 'Monto', data: brGlobalGastos.value.slice(0, 10).map(g => g.total) }])

// Nómina por sucursal
const nominaPorSucursalSorted = computed(() => [...branchesFiltered.value].filter(b => b.nomina > 0).sort((a, b) => b.nomina - a.nomina))
const nominaPorSucursalOptions = computed(() => ({
    ...horizontalBarOptions(nominaPorSucursalSorted.value.map(b => b.nombre)),
    colors: dimColors(nominaPorSucursalSorted.value.map(b => b.nombre), vfBranch.value, chartColors.teal),
}))
const nominaPorSucursalSeries = computed(() => [{ name: 'Nómina', data: nominaPorSucursalSorted.value.map(b => b.nomina) }])

// Mora / Cartera: top sucursales con más vencida
const topVencidaBranches = computed(() => [...branchesFiltered.value].filter(b => b.vencida > 0).sort((a, b) => b.vencida - a.vencida).slice(0, 10))
const topVencidaOptions = computed(() => horizontalBarOptions(topVencidaBranches.value.map(b => b.nombre), [chartColors.red]))
const topVencidaSeries = computed(() => [{ name: 'Vencida', data: topVencidaBranches.value.map(b => b.vencida) }])

// Préstamos activos: saldo / vencido por sucursal
const prestamosFiltered = computed(() => {
    let rows = activeLoansByBranch.value
    if (vfBranch.value) rows = rows.filter(r => r.sucursal === vfBranch.value)
    return rows
})
const prestamosSaldoOptions = computed(() => horizontalBarOptions(prestamosFiltered.value.map(r => r.sucursal), [chartColors.blue]))
const prestamosSaldoSeries = computed(() => [{ name: 'Saldo activo', data: prestamosFiltered.value.map(r => r.saldo) }])
const prestamosVencidoOptions = computed(() => horizontalBarOptions(prestamosFiltered.value.map(r => r.sucursal), [chartColors.red]))
const prestamosVencidoSeries = computed(() => [{ name: 'Vencido', data: prestamosFiltered.value.map(r => r.vencido) }])

// Gestores: ranking por colocación
const rankingGestoresOptions = computed(() => horizontalBarOptions(topGestoresColocacion.value.map((e: any) => e.name), [chartColors.teal]))
const rankingGestoresSeries = computed(() => [{ name: 'Colocación', data: topGestoresColocacion.value.map((e: any) => e.colocacion) }])
</script>

<template>
    <div class="min-h-screen bg-slate-50">

        <!-- HERO HEADER -->
        <div class="bg-slate-950 px-6 py-7 text-white">
            <div class="mx-auto max-w-screen-2xl">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-widest text-indigo-400">Radiografía Financiera</p>
                        <h1 class="mt-1 text-2xl font-black">{{ period.label }}</h1>
                        <p class="mt-1 text-sm text-slate-400">
                            <span v-if="snap && enConciliacion" class="mr-2 inline-flex items-center gap-1.5 rounded-full bg-amber-400/20 px-2.5 py-0.5 text-xs font-black text-amber-300">
                                EN CONCILIACIÓN
                            </span>
                            <span v-if="snap">Generado {{ snap.generated_at }} · v{{ snap.version }}</span>
                            <span v-else>Sin radiografía generada</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a :href="hasExcelExport ? excelUrl : '#'"
                           :class="hasExcelExport ? 'bg-emerald-600 hover:bg-emerald-500' : 'bg-slate-700 opacity-40 pointer-events-none'"
                           class="inline-flex h-9 items-center gap-2 rounded-xl px-4 text-sm font-bold text-white transition">
                            <FileSpreadsheet class="size-4" /> Excel
                        </a>
                        <a :href="hasPdfExport ? pdfUrl : '#'"
                           :class="hasPdfExport ? 'bg-rose-600 hover:bg-rose-500' : 'bg-slate-700 opacity-40 pointer-events-none'"
                           class="inline-flex h-9 items-center gap-2 rounded-xl px-4 text-sm font-bold text-white transition">
                            <FileText class="size-4" /> PDF
                        </a>
                        <a href="/historico-general"
                           class="inline-flex h-9 items-center gap-2 rounded-xl bg-slate-700 px-4 text-sm font-bold text-slate-200 transition hover:bg-slate-600">
                            <ArrowLeft class="size-4" /> Histórico
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sin snapshot -->
        <div v-if="!snap" class="mx-auto max-w-screen-2xl px-6 py-20">
            <EmptyState title="Sin radiografía generada" description="Genera la radiografía en Histórico General para ver el dashboard completo." />
        </div>

        <template v-else>
            <!-- BANNER CONCILIACIÓN -->
            <div v-if="enConciliacion" class="bg-amber-400 px-6 py-3">
                <div class="mx-auto max-w-screen-2xl flex flex-wrap items-center gap-3">
                    <AlertTriangle class="size-5 shrink-0 text-amber-900" />
                    <span class="font-black text-amber-950 text-sm tracking-wide">REPORTE EN CONCILIACIÓN — NO CIERRE FINAL</span>
                </div>
            </div>

            <div class="mx-auto max-w-screen-2xl space-y-5 px-6 py-5">

                <!-- KPI CARDS -->
                <div>
                    <p v-if="vfActiveBadge" class="mb-3 inline-flex items-center gap-1.5 rounded-full bg-indigo-50 px-3 py-1 text-xs font-black text-indigo-700">
                        {{ vfActiveBadge }}
                    </p>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
                        <KpiCard label="Recuperación / Cobranza" :value="money(kpiRec)" :icon="HandCoins" tone="teal" />
                        <KpiCard label="Colocación" :value="money(kpiCol)" :icon="TrendingUp" tone="blue" />
                        <KpiCard label="Cartera" :value="money(kpiCartera)" :icon="Landmark" tone="teal" />
                        <KpiCard label="Cartera vencida" :value="money(kpiMora)" :icon="AlertTriangle" :tone="kpiMoraPct > 25 ? 'red' : 'amber'" />
                        <KpiCard label="Mora %" :value="pct(kpiMoraPct)" :icon="Percent" :tone="kpiMoraPct > 25 ? 'red' : 'teal'" />
                        <KpiCard label="Gastos operativos" :value="money(kpiGastos)" :icon="Receipt" tone="amber" />
                        <KpiCard label="Nómina" :value="money(kpiNomina)" :icon="Wallet" tone="blue" />
                        <KpiCard :label="kpiUtilLabel" :value="money(kpiUtil)" :icon="Gauge" :tone="kpiUtil < 0 ? 'red' : 'green'" />
                    </div>
                </div>

                <!-- FILTROS DE VISTA EN VIVO -->
                <FilterBar
                    v-model:branch="vfBranch"
                    v-model:product="vfProduct"
                    v-model:bucket="vfBucket"
                    v-model:gestor="vfGestor"
                    v-model:categoria="vfCategoria"
                    :branch-options="vfBranchOptions"
                    :product-options="vfProductOptions"
                    :bucket-options="vfBucketOptions"
                    :gestor-options="vfGestorOptions"
                    :categoria-options="vfCategoriaOptions"
                />

                <!-- Ficha gestor / producto seleccionados -->
                <div v-if="vfGestor || vfProduct" class="rounded-2xl border bg-white p-4 shadow-sm">
                    <div v-if="vfGestor" class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4 lg:grid-cols-7">
                        <template v-if="vfGestorRow">
                            <div><p class="text-xs text-slate-400">Gestor</p><p class="font-black text-slate-900 truncate">{{ vfGestorRow.name }}</p></div>
                            <div><p class="text-xs text-slate-400">Sucursal</p><p class="font-bold text-slate-700">{{ vfGestorRow.branch }}</p></div>
                            <div><p class="text-xs text-slate-400">Colocación</p><p class="font-bold text-indigo-700">{{ vfGestorRow.colocacion > 0 ? money(vfGestorRow.colocacion) : '—' }}</p></div>
                            <div><p class="text-xs text-slate-400">Recuperación</p><p class="font-bold">{{ vfGestorRow.recuperacion > 0 ? money(vfGestorRow.recuperacion) : '—' }}</p></div>
                            <div><p class="text-xs text-slate-400">Cartera</p><p class="font-bold">{{ vfGestorRow.cartera > 0 ? money(vfGestorRow.cartera) : '—' }}</p></div>
                            <div><p class="text-xs text-slate-400">Mora %</p><p class="font-bold" :class="vfGestorRow.mora > 25 ? 'text-red-700' : ''">{{ vfGestorRow.cartera > 0 ? pct(vfGestorRow.mora) : '—' }}</p></div>
                            <div><p class="text-xs text-slate-400">Neto nómina</p><p class="font-bold">{{ vfGestorRow.neto > 0 ? money(vfGestorRow.neto) : '—' }}</p></div>
                        </template>
                        <p v-else class="text-sm text-slate-400 italic">Sin información disponible para este gestor.</p>
                    </div>
                    <div v-if="vfProduct" class="mt-3 border-t pt-3" :class="!vfGestor ? 'mt-0 border-t-0 pt-0' : ''">
                        <div v-if="vfProductRow" class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                            <div><p class="text-xs text-slate-400">Producto</p><p class="font-black text-slate-900 truncate">{{ vfProductRow.producto }}</p></div>
                            <div><p class="text-xs text-slate-400">Colocación</p><p class="font-bold text-indigo-700">{{ money(vfProductRow.colocacion) }}</p></div>
                            <div><p class="text-xs text-slate-400">Operaciones</p><p class="font-bold">{{ num(vfProductRow.operaciones) }}</p></div>
                            <div><p class="text-xs text-slate-400">Cartera</p><p class="font-bold">{{ money(vfProductRow.cartera ?? 0) }}</p></div>
                        </div>
                        <p v-else class="text-sm text-slate-400 italic">Sin información disponible para este producto.</p>
                    </div>
                </div>

                <!-- EXPORTACIÓN FILTRADA (Excel/PDF por sucursal, gestor o comparativo) -->
                <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                    <button @click="showFilteredPanel = !showFilteredPanel"
                            class="flex w-full items-center justify-between px-5 py-3.5 text-sm font-bold text-slate-700 hover:bg-slate-50 transition">
                        <span class="flex items-center gap-2"><Download class="size-4 text-indigo-500" /> Reportes filtrados (por sucursal, gestor o comparativo)</span>
                        <ChevronDown v-if="!showFilteredPanel" class="size-4 text-slate-400" />
                        <ChevronUp v-else class="size-4 text-slate-400" />
                    </button>

                    <div v-if="showFilteredPanel" class="border-t px-5 py-4 space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Tipo de comparación</label>
                                <select v-model="filteredType" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100">
                                    <option value="simple">Simple (este periodo)</option>
                                    <option value="month_vs_month">Mes vs Mes</option>
                                    <option value="bimester_vs_bimester">Bimestre vs Bimestre</option>
                                    <option value="quarter_vs_quarter">Trimestre vs Trimestre</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Alcance del archivo</label>
                                <select v-model="filteredScope" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100">
                                    <option value="general">General</option>
                                    <option value="branch">Por sucursal</option>
                                    <option value="employee">Por gestor</option>
                                </select>
                            </div>
                            <div v-if="isComparative">
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Periodo a comparar</label>
                                <select v-model="filteredComparePeriodId" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100">
                                    <option :value="null">— Seleccionar —</option>
                                    <option v-for="p in comparePeriodOptions" :key="p.id" :value="p.id" :disabled="!p.has_snapshot">{{ p.label }}{{ !p.has_snapshot ? ' (sin radiografía)' : '' }}</option>
                                </select>
                            </div>
                            <div v-if="filteredScope === 'branch'">
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Sucursal</label>
                                <select v-model="filteredBranchId" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100">
                                    <option :value="null">— Seleccionar —</option>
                                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                                </select>
                            </div>
                            <div v-if="filteredScope === 'employee'">
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Gestor / Empleado</label>
                                <select v-model="filteredEmployeeId" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100">
                                    <option :value="null">— Seleccionar —</option>
                                    <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
                                </select>
                            </div>
                        </div>

                        <div v-if="filteredScope === 'employee'" class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Gasto general asignado ($)</label>
                                <input v-model="filteredExtraAmount" type="number" min="0" step="0.01" placeholder="0.00"
                                       class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Notas del gasto</label>
                                <input v-model="filteredExtraNotes" type="text" placeholder="Descripción del gasto asignado…"
                                       class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100" />
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2 pt-1">
                            <a :href="canDownloadFiltered ? filteredXlsxUrl : '#'"
                               :class="canDownloadFiltered ? 'bg-emerald-600 hover:bg-emerald-500' : 'bg-slate-300 pointer-events-none opacity-50'"
                               class="inline-flex h-9 items-center gap-2 rounded-xl px-4 text-sm font-bold text-white transition">
                                <FileSpreadsheet class="size-4" /> Descargar Excel
                            </a>
                            <a :href="canDownloadFiltered ? filteredPdfUrl : '#'"
                               :class="canDownloadFiltered ? 'bg-rose-600 hover:bg-rose-500' : 'bg-slate-300 pointer-events-none opacity-50'"
                               class="inline-flex h-9 items-center gap-2 rounded-xl px-4 text-sm font-bold text-white transition">
                                <FileText class="size-4" /> Descargar PDF
                            </a>
                            <p v-if="!canDownloadFiltered" class="self-center text-xs text-amber-600 font-semibold">
                                <span v-if="isComparative && !filteredComparePeriodId">Selecciona un periodo a comparar.</span>
                                <span v-else-if="filteredScope === 'branch'">Selecciona una sucursal.</span>
                                <span v-else-if="filteredScope === 'employee'">Selecciona un gestor.</span>
                            </p>
                        </div>

                        <div v-if="filteredLoading" class="text-xs text-slate-500 italic">Cargando datos…</div>
                        <div v-else-if="filteredFetchError" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">{{ filteredFetchError }}</div>
                    </div>
                </div>

                <!-- TABS -->
                <div class="flex flex-wrap gap-1 border-b border-slate-200">
                    <button v-for="t in tabs" :key="t.key" @click="activeTab = t.key"
                        class="relative px-3.5 py-2.5 text-xs font-bold transition border-b-2 whitespace-nowrap"
                        :class="activeTab === t.key ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-800'">
                        {{ t.label }}
                    </button>
                </div>

                <!-- ══════════ RESUMEN ══════════ -->
                <div v-show="activeTab === 'resumen'" class="space-y-5">
                    <div v-if="alertas.length" class="space-y-2">
                        <div v-for="(a, i) in alertas" :key="i" class="flex items-center gap-2 rounded-2xl border px-4 py-2.5 text-sm font-bold"
                             :class="a.tone === 'red' ? 'border-red-200 bg-red-50 text-red-700' : 'border-amber-200 bg-amber-50 text-amber-700'">
                            <AlertTriangle class="size-4 shrink-0" /> {{ a.text }}
                        </div>
                    </div>

                    <!-- RESUMEN FINANCIERO GENERAL -->
                    <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                        <div class="border-b bg-slate-50 px-5 py-3">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Resumen financiero general</h3>
                        </div>
                        <table class="w-full text-sm">
                            <tbody>
                                <tr class="border-b"><td class="px-5 py-2.5 text-slate-600 font-medium">Recuperación total</td><td class="px-5 py-2.5 text-right font-black text-slate-950">{{ money(recGlobal) }}</td></tr>
                                <tr class="border-b bg-slate-50/60"><td class="px-5 py-2.5 text-slate-600 font-medium">Colocación total</td><td class="px-5 py-2.5 text-right font-black text-slate-950">{{ money(colGlobal) }}</td></tr>
                                <tr class="border-b"><td class="px-5 py-2.5 text-slate-600 font-medium">Cartera total</td><td class="px-5 py-2.5 text-right font-black text-slate-950">{{ money(carteraGlobal) }}</td></tr>
                                <tr class="border-b bg-slate-50/60"><td class="px-5 py-2.5 text-slate-600 font-medium">Cartera vencida / Mora total</td><td class="px-5 py-2.5 text-right font-black" :class="moraTotalGlobal > 0 ? 'text-red-700' : 'text-slate-950'">{{ money(moraTotalGlobal) }}</td></tr>
                                <tr class="border-b"><td class="px-5 py-2.5 text-slate-600 font-medium">Índice de mora</td><td class="px-5 py-2.5 text-right font-black" :class="kpiMoraPct > 25 ? 'text-red-700' : 'text-slate-950'">{{ pct(kpiMoraPct) }}</td></tr>
                                <tr class="border-b bg-slate-50/60"><td class="px-5 py-2.5 text-slate-600 font-medium">Gastos operativos</td><td class="px-5 py-2.5 text-right font-black text-slate-950">{{ money(brGlobalGastosTotal) }}</td></tr>
                                <tr class="border-b"><td class="px-5 py-2.5 text-slate-600 font-medium">Nómina y Capital Humano</td><td class="px-5 py-2.5 text-right font-black text-slate-950">{{ money(nomTotal) }}</td></tr>
                                <tr class="border-b-2 border-indigo-200 bg-indigo-50"><td class="px-5 py-2.5 font-black text-indigo-900">EBITDA</td><td class="px-5 py-2.5 text-right font-black text-lg" :class="utilidadGlobal < 0 ? 'text-red-700' : 'text-indigo-900'">{{ money(utilidadGlobal) }}</td></tr>
                                <tr class="border-b bg-slate-50/60"><td class="px-5 py-2.5 text-slate-600 font-medium">Envío de utilidad a corporativo</td><td class="px-5 py-2.5 text-right font-black text-slate-950">{{ money(excGlobal) }}</td></tr>
                                <tr class="border-b"><td class="px-5 py-2.5 font-black text-slate-800">Diferencia</td><td class="px-5 py-2.5 text-right font-black" :class="diferencia < 0 ? 'text-red-700' : 'text-emerald-700'">{{ money(diferencia) }}</td></tr>
                                <tr class="border-b bg-slate-50/60">
                                    <td class="px-5 py-2.5 text-slate-600 font-medium">Saldo inicial en caja</td>
                                    <td class="px-5 py-2.5 text-right">
                                        <span v-if="!saldoInicialEditing" class="inline-flex items-center gap-2">
                                            <span class="font-black text-slate-950">{{ money(saldoInicialCaja) }}</span>
                                            <button type="button" class="text-xs font-bold text-indigo-600 hover:text-indigo-800" @click="startEditSaldoInicial">Editar</button>
                                        </span>
                                        <span v-else class="inline-flex items-center gap-2">
                                            <input v-model="saldoInicialInput" type="number" step="0.01"
                                                   class="w-36 rounded-lg border border-slate-200 px-2 py-1 text-right text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100" />
                                            <button type="button" :disabled="saldoInicialSaving" class="rounded-lg bg-indigo-600 px-3 py-1 text-xs font-bold text-white hover:bg-indigo-500 disabled:opacity-50" @click="saveSaldoInicial">Guardar</button>
                                            <button type="button" class="text-xs font-bold text-slate-400 hover:text-slate-600" @click="saldoInicialEditing = false">Cancelar</button>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-5 py-2.5 text-slate-600 font-medium">Saldo final / saldo para siguiente periodo</td>
                                    <td class="px-5 py-2.5 text-right font-black" :class="(saldoFinalCaja ?? diferencia) < 0 ? 'text-red-700' : 'text-slate-950'">
                                        {{ saldoFinalCaja !== null ? money(saldoFinalCaja) : money(diferencia) }}
                                        <span v-if="saldoFinalCaja === null" class="ml-1 text-[10.5px] font-normal text-slate-400">(= Diferencia, no configurado manualmente)</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-if="saldoInicialCaja === 0" class="border-t bg-amber-50 px-5 py-2.5 text-xs font-semibold text-amber-700">
                            ⚠ Este periodo no tiene saldo inicial en caja capturado — el EBITDA se está calculando con $0.00. Captúralo arriba para un cálculo correcto.
                        </p>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <ChartCard title="Recuperación vs Colocación" :series="recColSeries" :options="recColOptions" type="bar" :height="240" />
                        <ChartCard title="Cartera vs Cartera vencida" :series="carteraDonutSeries" :options="carteraDonutOptions" type="donut" :height="240" />
                    </div>
                    <ChartCard title="Mora por bucket" :series="moraBucketSeries" :options="moraBucketOptions" type="bar" :height="260" />

                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                            <div class="border-b bg-slate-50 px-5 py-3"><h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Top sucursales por cartera</h3></div>
                            <table v-if="branchesFull.length" class="w-full text-sm">
                                <tbody>
                                    <tr v-for="b in [...branchesFull].sort((a,b)=>b.cartera-a.cartera).slice(0,6)" :key="b.nombre" class="border-b last:border-0 hover:bg-slate-50">
                                        <td class="px-4 py-2 font-bold">{{ b.nombre }}</td>
                                        <td class="px-4 py-2 text-right">{{ money(b.cartera) }}</td>
                                        <td class="px-4 py-2 text-right"><EbitdaBadge :categoria="b.categoria" /></td>
                                    </tr>
                                </tbody>
                            </table>
                            <EmptyState v-else class="m-4" title="Sin datos de sucursales" />
                        </div>
                        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                            <div class="border-b bg-slate-50 px-5 py-3"><h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Categoría por EBITDA</h3></div>
                            <div v-if="branchesFull.length" class="flex flex-wrap gap-2 p-4">
                                <span v-for="b in branchesFull" :key="b.nombre" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 px-2.5 py-1.5 text-xs font-bold text-slate-700">
                                    {{ b.nombre }} <EbitdaBadge :categoria="b.categoria" />
                                </span>
                            </div>
                            <EmptyState v-else class="m-4" title="Sin datos de categoría EBITDA" />
                        </div>
                    </div>
                </div>

                <!-- ══════════ SUCURSALES ══════════ -->
                <div v-show="activeTab === 'sucursales'" class="space-y-5">
                    <template v-if="branchesFiltered.length">
                        <div class="grid gap-4 lg:grid-cols-3">
                            <ChartCard title="Ranking por recuperación" :series="rankingRecuperacionSeries" :options="rankingRecuperacionOptions" type="bar" :height="320" />
                            <ChartCard title="Ranking por cartera" :series="rankingCarteraSeries" :options="rankingCarteraOptions" type="bar" :height="320" />
                            <ChartCard title="EBITDA por sucursal" :series="rankingEbitdaSeries" :options="rankingEbitdaOptions" type="bar" :height="320" />
                        </div>
                        <div class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                    <tr>
                                        <th class="px-4 py-3 text-left">Sucursal</th>
                                        <th class="px-4 py-3 text-right">Recuperación</th>
                                        <th class="px-4 py-3 text-right">Colocación</th>
                                        <th class="px-4 py-3 text-right">Cartera</th>
                                        <th class="px-4 py-3 text-right">Vencida</th>
                                        <th class="px-4 py-3 text-right">Mora %</th>
                                        <th class="px-4 py-3 text-right">Gastos</th>
                                        <th class="px-4 py-3 text-right">Nómina</th>
                                        <th class="px-4 py-3 text-right">EBITDA</th>
                                        <th class="px-4 py-3 text-center">Categoría</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="b in branchesFiltered" :key="b.nombre" class="cursor-pointer border-t hover:bg-slate-50"
                                        :class="vfBranch === b.nombre ? 'bg-indigo-50' : ''" @click="vfBranch = vfBranch === b.nombre ? '' : b.nombre">
                                        <td class="px-4 py-2.5 font-bold">{{ b.nombre }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(b.recuperacion) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(b.colocacion) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(b.cartera) }}</td>
                                        <td class="px-4 py-2.5 text-right" :class="b.vencida > 0 ? 'font-bold text-red-700' : ''">{{ money(b.vencida) }}</td>
                                        <td class="px-4 py-2.5 text-right font-bold" :class="b.mora > 25 ? 'text-red-700' : ''">{{ pct(b.mora) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(b.gastos) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(b.nomina) }}</td>
                                        <td class="px-4 py-2.5 text-right font-bold" :class="b.ebitda < 0 ? 'text-red-700' : 'text-emerald-700'">{{ money(b.ebitda) }}</td>
                                        <td class="px-4 py-2.5 text-center"><EbitdaBadge :categoria="b.categoria" /></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                    <EmptyState v-else title="Sin sucursales para este filtro" description="Ajusta o limpia los filtros para ver datos por sucursal." />
                </div>

                <!-- ══════════ INGRESOS / COBRANZA ══════════ -->
                <div v-show="activeTab === 'ingresos'" class="space-y-5">
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <KpiCard label="Cobranza total" :value="money(recGlobal)" :icon="HandCoins" tone="teal" />
                        <KpiCard label="Colocación total" :value="money(colGlobal)" :icon="TrendingUp" tone="blue" />
                        <KpiCard label="Capital recuperado" :value="money(ingrCapital)" :icon="Banknote" tone="teal" />
                        <KpiCard label="Intereses recuperados" :value="money(ingrInteres)" :icon="Percent" tone="blue" />
                    </div>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <ChartCard title="Colocación por producto" :series="colocacionProductoSeries" :options="colocacionProductoOptions" type="bar" :height="280" />
                        <ChartCard title="Colocación por sucursal" :series="colocacionSucursalSeries" :options="colocacionSucursalOptions" type="bar" :height="280" />
                    </div>
                    <div class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                        <div class="border-b bg-slate-50 px-5 py-3"><h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Ranking por producto</h3></div>
                        <table v-if="productosSorted.length" class="w-full text-sm">
                            <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                <tr><th class="px-4 py-3 text-left">Producto</th><th class="px-4 py-3 text-right">Operaciones</th><th class="px-4 py-3 text-right">Colocación</th><th class="px-4 py-3 text-right">Recuperación</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in productosSorted" :key="p.producto" class="cursor-pointer border-t hover:bg-slate-50"
                                    :class="vfProduct === p.producto ? 'bg-indigo-50' : ''" @click="vfProduct = vfProduct === p.producto ? '' : p.producto">
                                    <td class="px-4 py-2.5 font-bold">{{ p.producto }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ num(p.operaciones) }}</td>
                                    <td class="px-4 py-2.5 text-right font-black">{{ money(p.colocacion) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(p.recuperacion ?? 0) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <EmptyState v-else class="m-4" title="Sin datos de producto" description="Verifica que el archivo de ministraciones incluya la columna de producto financiero." />
                    </div>
                </div>

                <!-- ══════════ GASTOS ══════════ -->
                <div v-show="activeTab === 'gastos'" class="space-y-5">
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <KpiCard label="Gastos operativos" :value="money(kpiGastos)" :icon="Receipt" tone="amber" />
                        <KpiCard v-for="s in gastosBySource" :key="s.fuente" :label="`Fuente: ${s.fuente}`" :value="money(s.total)" tone="neutral" />
                    </div>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <ChartCard title="Gastos por sucursal" :series="gastosPorSucursalSeries" :options="gastosPorSucursalOptions" type="bar" :height="300" />
                        <ChartCard title="Top categorías de gasto" :series="gastosPorCategoriaSeries" :options="gastosPorCategoriaOptions" type="bar" :height="300" />
                    </div>

                    <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                        <div class="flex items-center justify-between border-b bg-slate-50 px-5 py-3">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Gastos por sucursal — detalle</h3>
                            <div class="relative">
                                <Search class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-slate-400" />
                                <input v-model="gastosSearch" type="text" placeholder="Buscar sucursal o concepto…"
                                       class="w-56 rounded-xl border border-slate-200 bg-white py-1.5 pl-8 pr-2 text-xs focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100" />
                            </div>
                        </div>
                        <div v-if="gastosTree.length">
                            <div v-for="g in gastosTree" :key="g.sucursal" class="border-b last:border-0">
                                <button @click="expandedGastosBranch = expandedGastosBranch === g.sucursal ? null : g.sucursal"
                                        class="flex w-full items-center justify-between px-5 py-2.5 text-sm font-bold text-slate-800 hover:bg-slate-50 transition">
                                    <span class="flex items-center gap-2"><Building2 class="size-3.5 text-slate-400" /> {{ g.sucursal }}</span>
                                    <span class="flex items-center gap-3">
                                        {{ money(g.total) }}
                                        <ChevronDown v-if="expandedGastosBranch !== g.sucursal" class="size-3.5 text-slate-400" />
                                        <ChevronUp v-else class="size-3.5 text-slate-400" />
                                    </span>
                                </button>
                                <table v-if="expandedGastosBranch === g.sucursal && g.conceptos.length" class="w-full text-xs">
                                    <tbody>
                                        <tr v-for="c in g.conceptos" :key="c.concepto" class="border-t bg-slate-50/60">
                                            <td class="px-8 py-1.5 text-slate-600">{{ c.concepto }}</td>
                                            <td class="px-5 py-1.5 text-right font-semibold text-slate-700">{{ money(c.total) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <EmptyState v-else class="m-4" title="Sin gastos para este filtro" />
                    </div>
                </div>

                <!-- ══════════ NÓMINA ══════════ -->
                <div v-show="activeTab === 'nomina'" class="space-y-5">
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <KpiCard label="Nómina total" :value="money(kpiNomina)" :icon="Wallet" tone="blue" />
                        <KpiCard label="Sueldos" :value="money(nomNomina)" tone="teal" />
                        <KpiCard label="Comisiones" :value="money(nomComis)" tone="teal" />
                        <KpiCard label="Bonos" :value="money(nomBonos)" tone="teal" />
                    </div>
                    <ChartCard title="Nómina por sucursal" :series="nominaPorSucursalSeries" :options="nominaPorSucursalOptions" type="bar" :height="320" />

                    <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                        <div class="border-b bg-slate-50 px-5 py-3"><h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Nómina por sucursal — detalle</h3></div>
                        <div v-if="nominaTree.length">
                            <div v-for="n in nominaTree" :key="n.sucursal" class="border-b last:border-0">
                                <button @click="expandedNominaBranch = expandedNominaBranch === n.sucursal ? null : n.sucursal"
                                        class="flex w-full items-center justify-between px-5 py-2.5 text-sm font-bold text-slate-800 hover:bg-slate-50 transition">
                                    <span class="flex items-center gap-2"><Building2 class="size-3.5 text-slate-400" /> {{ n.sucursal }}</span>
                                    <span class="flex items-center gap-3 text-right">
                                        <span class="text-xs text-slate-400">Neto {{ money(n.neto) }}</span>
                                        {{ money(n.total) }}
                                        <ChevronDown v-if="expandedNominaBranch !== n.sucursal" class="size-3.5 text-slate-400" />
                                        <ChevronUp v-else class="size-3.5 text-slate-400" />
                                    </span>
                                </button>
                                <table v-if="expandedNominaBranch === n.sucursal" class="w-full text-xs">
                                    <tbody>
                                        <tr v-for="c in n.conceptos" :key="c.concepto" class="border-t bg-slate-50/60">
                                            <td class="px-8 py-1.5 text-slate-600">{{ c.concepto }}</td>
                                            <td class="px-5 py-1.5 text-right font-semibold" :class="NOI_DEDUCTION_LABELS.has(c.concepto) ? 'text-red-600' : 'text-slate-700'">
                                                {{ NOI_DEDUCTION_LABELS.has(c.concepto) ? '-' + money(c.total) : money(c.total) }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <EmptyState v-else class="m-4" title="Sin datos de nómina por sucursal" />
                    </div>
                </div>

                <!-- ══════════ MORA / CARTERA ══════════ -->
                <div v-show="activeTab === 'mora'" class="space-y-5">
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <KpiCard label="Cartera total" :value="money(kpiCartera)" :icon="Landmark" tone="teal" />
                        <KpiCard :label="kpiMoraLabel" :value="money(kpiMora)" :icon="AlertTriangle" :tone="kpiMoraPct > 25 ? 'red' : 'amber'" />
                        <KpiCard label="Mora %" :value="pct(kpiMoraPct)" :icon="Percent" :tone="kpiMoraPct > 25 ? 'red' : 'teal'" />
                        <KpiCard label="Cartera sana" :value="money(Math.max(0, kpiCartera - kpiMora))" :icon="CheckCircle2" tone="green" />
                    </div>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <ChartCard title="Cartera sana vs vencida" :series="carteraDonutSeries" :options="carteraDonutOptions" type="donut" :height="260" />
                        <ChartCard title="Mora por bucket" :series="moraBucketSeries" :options="moraBucketOptions" type="bar" :height="260" />
                    </div>
                    <ChartCard title="Top sucursales con más cartera vencida" :series="topVencidaSeries" :options="topVencidaOptions" type="bar" :height="300" />

                    <div class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                        <div class="border-b bg-slate-50 px-5 py-3"><h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Distribución por días vencidos</h3></div>
                        <table v-if="snap.sections?.portfolio_buckets?.length" class="w-full text-sm">
                            <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                <tr><th class="px-4 py-3 text-left">Bucket</th><th class="px-4 py-3 text-right">Contratos</th><th class="px-4 py-3 text-right">Balance</th><th class="px-4 py-3 text-right">Vencido</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="b in snap.sections.portfolio_buckets" :key="b.label" class="border-t hover:bg-slate-50">
                                    <td class="px-4 py-2.5 font-semibold">{{ b.label }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ num(b.contratos) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(b.balance) }}</td>
                                    <td class="px-4 py-2.5 text-right font-bold" :class="b.vencida > 0 && b.label !== 'Al corriente' ? 'text-red-700' : ''">{{ money(b.vencida) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <EmptyState v-else class="m-4" title="Sin datos de días vencidos" />
                    </div>
                </div>

                <!-- ══════════ PRODUCTOS ══════════ -->
                <div v-show="activeTab === 'productos'" class="space-y-5">
                    <template v-if="productosRows.length">
                        <ChartCard title="Colocación por producto" :series="colocacionProductoSeries" :options="colocacionProductoOptions" type="bar" :height="300" />
                        <div class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                    <tr><th class="px-4 py-3 text-left">Producto</th><th class="px-4 py-3 text-right">Operaciones</th><th class="px-4 py-3 text-right">Colocación</th><th class="px-4 py-3 text-right">Recuperación</th><th class="px-4 py-3 text-right">Cartera</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="p in productosSorted" :key="p.producto" class="cursor-pointer border-t hover:bg-slate-50"
                                        :class="vfProduct === p.producto ? 'bg-indigo-50' : ''" @click="vfProduct = vfProduct === p.producto ? '' : p.producto">
                                        <td class="px-4 py-2.5 font-bold">{{ p.producto }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ num(p.operaciones) }}</td>
                                        <td class="px-4 py-2.5 text-right font-black">{{ money(p.colocacion) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(p.recuperacion ?? 0) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(p.cartera ?? 0) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                    <EmptyState v-else title="Sin datos de producto" description="Verifica que el archivo de ministraciones incluya la columna de producto financiero." />
                </div>

                <!-- ══════════ PRÉSTAMOS ACTIVOS ══════════ -->
                <div v-show="activeTab === 'prestamos'" class="space-y-5">
                    <template v-if="activeLoansByBranch.length">
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <KpiCard label="Créditos activos" :value="num(activeLoansTotals.count)" :icon="Banknote" tone="teal" />
                            <KpiCard label="Saldo activo" :value="money(activeLoansTotals.saldo)" :icon="Wallet" tone="blue" />
                            <KpiCard label="Vencido" :value="money(activeLoansTotals.vencido)" :icon="AlertTriangle" :tone="activeLoansTotals.pct > 25 ? 'red' : 'amber'" />
                            <KpiCard label="% Vencido" :value="pct(activeLoansTotals.pct)" :icon="Percent" :tone="activeLoansTotals.pct > 25 ? 'red' : 'teal'" />
                        </div>
                        <div class="grid gap-4 lg:grid-cols-2">
                            <ChartCard title="Saldo activo por sucursal" :series="prestamosSaldoSeries" :options="prestamosSaldoOptions" type="bar" :height="300" />
                            <ChartCard title="Vencido por sucursal" :series="prestamosVencidoSeries" :options="prestamosVencidoOptions" type="bar" :height="300" />
                        </div>
                        <div class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                    <tr><th class="px-4 py-3 text-left">Sucursal</th><th class="px-4 py-3 text-right">Créditos activos</th><th class="px-4 py-3 text-right">Saldo activo</th><th class="px-4 py-3 text-right">Vencido</th><th class="px-4 py-3 text-right">% Vencido</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="r in prestamosFiltered" :key="r.sucursal" class="cursor-pointer border-t hover:bg-slate-50"
                                        :class="vfBranch === r.sucursal ? 'bg-indigo-50' : ''" @click="vfBranch = vfBranch === r.sucursal ? '' : r.sucursal">
                                        <td class="px-4 py-2.5 font-bold">{{ r.sucursal }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ num(r.count) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(r.saldo) }}</td>
                                        <td class="px-4 py-2.5 text-right" :class="r.vencido > 0 ? 'font-bold text-red-700' : ''">{{ money(r.vencido) }}</td>
                                        <td class="px-4 py-2.5 text-right font-bold" :class="r.pct > 25 ? 'text-red-700' : ''">{{ pct(r.pct) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                    <EmptyState v-else title="Sin préstamos activos registrados" description="No hay créditos activos para este periodo." />
                </div>

                <!-- ══════════ CATEGORÍA EBITDA ══════════ -->
                <div v-show="activeTab === 'categoria'" class="space-y-5">
                    <template v-if="branchesFull.length">
                        <div class="grid gap-4 lg:grid-cols-3">
                            <ChartCard title="Distribución por categoría" :series="categoriaDonutSeries" :options="categoriaDonutOptions" type="donut" :height="280" class="lg:col-span-1" />
                            <ChartCard title="EBITDA por sucursal" :series="ebitdaPorSucursalSeries" :options="ebitdaPorSucursalOptions" type="bar" :height="280" class="lg:col-span-2" />
                        </div>
                        <div class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                    <tr><th class="px-4 py-3 text-left">Sucursal</th><th class="px-4 py-3 text-right">Recuperación</th><th class="px-4 py-3 text-right">Gastos</th><th class="px-4 py-3 text-right">Nómina</th><th class="px-4 py-3 text-right">EBITDA estimado</th><th class="px-4 py-3 text-center">Categoría</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="b in branchesFiltered" :key="b.nombre" class="cursor-pointer border-t hover:bg-slate-50"
                                        :class="vfBranch === b.nombre ? 'bg-indigo-50' : ''" @click="vfBranch = vfBranch === b.nombre ? '' : b.nombre">
                                        <td class="px-4 py-2.5 font-bold">{{ b.nombre }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(b.recuperacion) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(b.gastos) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(b.nomina) }}</td>
                                        <td class="px-4 py-2.5 text-right font-black" :class="b.ebitda < 0 ? 'text-red-700' : 'text-emerald-700'">{{ money(b.ebitda) }}</td>
                                        <td class="px-4 py-2.5 text-center"><EbitdaBadge :categoria="b.categoria" /></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="text-xs italic text-slate-400">EBITDA = Recuperación − Gastos − Nómina completa estimada por sucursal.</p>
                    </template>
                    <EmptyState v-else title="Sin datos para calcular categoría EBITDA" />
                </div>

                <!-- ══════════ GESTORES ══════════ -->
                <div v-show="activeTab === 'gestores'" class="space-y-5">
                    <template v-if="empGest.length">
                        <ChartCard v-if="topGestoresColocacion.length" title="Ranking de gestores por colocación" :series="rankingGestoresSeries" :options="rankingGestoresOptions" type="bar" :height="280" />

                        <div class="flex flex-wrap gap-3">
                            <div class="relative flex-1 min-w-52">
                                <Search class="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-slate-400" />
                                <input v-model="searchEmp" type="text" placeholder="Buscar por nombre o sucursal…"
                                       class="w-full rounded-xl border border-slate-200 bg-white py-2 pl-9 pr-4 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100" />
                            </div>
                            <select v-model="filterBranch" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100">
                                <option value="">Todas las sucursales</option>
                                <option v-for="b in branchOptions" :key="b" :value="b">{{ b }}</option>
                            </select>
                        </div>

                        <div class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                            <table class="w-full text-xs">
                                <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3 text-left sticky left-0 bg-slate-50">Gestor</th>
                                        <th class="px-3 py-3 text-left">Sucursal</th>
                                        <th class="px-3 py-3 text-right">Colocación</th>
                                        <th class="px-3 py-3 text-right">Recuperación</th>
                                        <th class="px-3 py-3 text-right">Cartera</th>
                                        <th class="px-3 py-3 text-right">Mora %</th>
                                        <th class="px-3 py-3 text-right">Neto nómina</th>
                                        <th class="px-3 py-3 text-right">Gastos</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="e in empVisible" :key="e.name + e.branch" class="cursor-pointer border-t hover:bg-slate-50"
                                        :class="vfGestor === e.name ? 'bg-indigo-50' : ''" @click="vfGestor = vfGestor === e.name ? '' : e.name">
                                        <td class="px-3 py-2 font-bold sticky left-0 bg-white whitespace-nowrap">{{ e.name }}</td>
                                        <td class="px-3 py-2 text-slate-600 whitespace-nowrap"><span :class="e.branch === 'Sin sucursal' ? 'text-amber-600 font-semibold' : ''">{{ e.branch }}</span></td>
                                        <td class="px-3 py-2 text-right font-bold text-indigo-700">{{ e.colocacion > 0 ? money(e.colocacion) : '—' }}</td>
                                        <td class="px-3 py-2 text-right">{{ e.recuperacion > 0 ? money(e.recuperacion) : '—' }}</td>
                                        <td class="px-3 py-2 text-right">{{ e.cartera > 0 ? money(e.cartera) : '—' }}</td>
                                        <td class="px-3 py-2 text-right" :class="e.mora > 25 ? 'font-bold text-red-700' : ''">{{ e.cartera > 0 ? pct(e.mora) : '—' }}</td>
                                        <td class="px-3 py-2 text-right font-bold">{{ e.neto > 0 ? money(e.neto) : '—' }}</td>
                                        <td class="px-3 py-2 text-right">{{ e.gastos > 0 ? money(e.gastos) : '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="filteredEmp.length > 15" class="text-center">
                            <button @click="showAllEmp = !showAllEmp" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-2 text-sm font-bold text-slate-600 shadow-sm hover:bg-slate-50 transition">
                                {{ showAllEmp ? 'Ver menos' : `Ver todos (${filteredEmp.length})` }}
                            </button>
                        </div>
                        <p class="text-xs text-slate-400">Mostrando {{ empVisible.length }} de {{ filteredEmp.length }} registros.</p>
                    </template>
                    <EmptyState v-else title="Sin datos de gestores" description="Verifica que el archivo NOI fue procesado para este periodo." />
                </div>

            </div>
        </template>
    </div>
</template>
