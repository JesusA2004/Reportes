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
import { chartColors, categoryPalette, horizontalBarOptions, columnOptions, stackedBarOptions, donutOptions } from '@/lib/chart-theme'

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
type TabKey = 'resumen' | 'sucursales' | 'ingresos' | 'gastos' | 'nomina' | 'mora' | 'productos' | 'fondeos' | 'rotacion' | 'categoria' | 'gestores' | 'cobranza'
const activeTab = ref<TabKey>('resumen')

const snap   = computed(() => props.snapshot)
const periodComposite = computed(() => snap.value?.period?.composite ?? null)
const sum    = computed(() => snap.value?.summary ?? {})
const charts = computed(() => snap.value?.charts ?? {})

const branchRadiography = computed(() => snap.value?.branch_radiography ?? null)
const brGlobal  = computed(() => branchRadiography.value?.global ?? null)
const brRaw     = computed(() => (branchRadiography.value?.branches ?? []) as any[])

type EbitdaCategory = 'DIAMANTE' | 'MASTER' | 'SENIOR' | 'JUNIOR' | 'MANTENIDO'
function ebitdaCategoryOf(value: number): EbitdaCategory {
    if (value >= 1_000_000) return 'DIAMANTE'
    if (value >= 600_000)   return 'MASTER'
    if (value >= 300_000)   return 'SENIOR'
    if (value >= 100_000)   return 'JUNIOR'
    return 'MANTENIDO'
}

// ── Ingresos / Cobranza ───────────────────────────────────────────────────────
const ingrCapital      = computed(() => Number(brGlobal.value?.capital_recuperado)   || 0)
const ingrInteres      = computed(() => Number(brGlobal.value?.interes_recuperado)   || 0)
const ingrImpuesto     = computed(() => Number(brGlobal.value?.impuesto_recuperado)  || 0)
const ingrMultas       = computed(() => Number(brGlobal.value?.charges)              || 0)
const ingrCargosAdic   = computed(() => Number(brGlobal.value?.cargos_adicionales)   || 0)
const ingrExcedente    = computed(() => Number(brGlobal.value?.excedente_recuperado) || 0)
const ingrCargosIni    = computed(() => Number(brGlobal.value?.cargos_inicio)        || 0)
const ingrComAper      = computed(() => Number(brGlobal.value?.comision_apertura)    || 0)
const ingrCrece30      = computed(() => Number(brGlobal.value?.seguro_crece_reconocido) || 0)
const ingrOtros        = computed(() => Number(brGlobal.value?.otros_recuperacion)   || 0)
const ingrSumaDesglose = computed(() =>
    ingrCapital.value + ingrInteres.value + ingrImpuesto.value
    + ingrMultas.value + ingrCargosAdic.value + ingrExcedente.value
    + ingrCargosIni.value + ingrComAper.value + ingrCrece30.value + ingrOtros.value
)
// "Otros" desglosado por su concepto real de origen — nunca como bolsa genérica.
const ingrOtrosDetalle = computed<{ label: string; value: number }[]>(() => {
    const det = brGlobal.value?.otros_detalle as Record<string, number> | undefined
    if (!det) return []
    return Object.entries(det).filter(([, v]) => Number(v) !== 0).map(([label, v]) => ({ label, value: Number(v) }))
})

// ── Recuperación por sucursal / por producto (tablas, no solo gráfica) ─────────
const recuperacionPorSucursal = computed(() => brRaw.value.map((b: any) => ({
    sucursal:           b.sucursal,
    capital:            Number(b.capital_recuperado) || 0,
    interes:            Number(b.interes_recuperado) || 0,
    impuesto:           Number(b.impuesto_recuperado) || 0,
    moratorios:         Number(b.charges) || 0,
    cargos_adicionales: Number(b.cargos_adicionales) || 0,
    cargos_inicio:      Number(b.cargos_inicio) || 0,
    comision_apertura:  Number(b.comision_apertura) || 0,
    excedente:          Number(b.excedente_recuperado) || 0,
    seguro_crece_30:    Number(b.seguro_crece_reconocido) || 0,
    otros:              Number(b.otros_recuperacion) || 0,
    total:              Number(b.recuperacion_total) || 0,
})).sort((a, b) => b.total - a.total))

const recuperacionPorProducto = computed(() => {
    const rows = snap.value?.sections?.recovery_by_product?.rows as any[] ?? []
    return rows.map((p: any) => ({
        producto:           p.product,
        capital:            Number(p.capital) || 0,
        interes:            Number(p.interes) || 0,
        impuesto:           Number(p.impuesto) || 0,
        moratorios:         Number(p.moratorios) || 0,
        cargos_adicionales: Number(p.cargos_adicionales) || 0,
        comision_apertura:  Number(p.comision_apertura) || 0,
        excedente:          Number(p.excedente_recuperado) || 0,
        seguro_crece_30:    Number(p.seguro_crece_reconocido) || 0,
        otros:              Number(p.otros) || 0,
        total:              Number(p.total) || 0,
    }))
})

// ── Nómina ─────────────────────────────────────────────────────────────────────
const nomNomina   = computed(() => Number(brGlobal.value?.nomina_total)    || 0)
const nomComis    = computed(() => Number(brGlobal.value?.comisiones)      || 0)
const nomVac      = computed(() => Number(brGlobal.value?.vacaciones)      || 0)
const nomPrimaVac = computed(() => Number(brGlobal.value?.prima_vacacional)|| 0)
const nomBonos    = computed(() => Number(brGlobal.value?.bonos)           || 0)
const nomBonosAcel= computed(() => Number(brGlobal.value?.bonos_aceleradores) || 0)
const nomOtrosPercep = computed(() => Number(brGlobal.value?.otros_percepciones) || 0)
const nomImssPatronal = computed(() => Number(brGlobal.value?.imss_patronal) || 0)
const nomGastosEmpleados = computed(() => Number(brGlobal.value?.gastos_empleados_nomina) || 0)

// Deducciones NOI (ya restadas de nomina_total en backend) — SOLO informativo, filas rojas.
// Debe reflejar exactamente BranchRadiographyCalculator::accumulateNomina()'s deduction labels.
const NOI_DEDUCTION_LABELS = new Set([
    'Pensión Alimenticia',
    'Descuentos Infonavit',
    'Descuentos FONACOT',
    'Descuento Servicios Moto',
    'Financiamiento Celular',
    'Descuento de uniformes',
    'Descuento gastos sin comprobar',
    'Descuento extravío tarjeta de circulación',
    'Descuentos Tienda Mr Lana',
    'Descuento Servicios Automóvil',
    'Descuento faltante en caja',
    'Anticipo de nómina',
    'Préstamo Personal',
    'Descuento nómina — Financiamiento Moto (NOI)',
    'Diferencia NF',
    'IMSS trabajador (retención)',
    'Subsidio para el Empleo APL',
])

// nomina_detalle: deducciones NOI, ya restadas de nomina_total — informativo (transparencia,
// no se vuelve a sumar aquí, YA está restado en nomina_total).
// nomina_informativo: IMSS/Motos/Enganche/Cascos/Finiquito/Médicos — regla vigente 2026-07:
// SÍ forman parte del KPI Nómina (vía imss_patronal + gastos_empleados_nomina, sumados en
// nomTotal abajo). Este desglose es solo el detalle, no algo aparte.
const nomDetalle = computed<{ label: string; value: number }[]>(() => {
    const det = (brGlobal.value?.nomina_detalle ?? {}) as Record<string, number>
    const info = (brGlobal.value?.nomina_informativo ?? {}) as Record<string, number>
    const merged: Record<string, number> = {}
    for (const [k, v] of Object.entries(det)) merged[k] = (merged[k] ?? 0) + (Number(v) || 0)
    for (const [k, v] of Object.entries(info)) merged[k] = (merged[k] ?? 0) + (Number(v) || 0)
    return Object.entries(merged).filter(([, v]) => Number(v) > 0).map(([label, v]) => ({ label, value: Number(v) }))
})
// Total = fuente única, replica BranchRadiographyCalculator::nominaTotalFor() exactamente.
const nomDescuentosNOI = computed(() => nomDetalle.value.filter(r => NOI_DEDUCTION_LABELS.has(r.label)).reduce((s, r) => s + r.value, 0))

// Clasificación Tipo/Afecta total por renglón (regla final 2026-07, sección 7):
// Deducción informativa → NO afecta. IMSS y Gasto empleado → SÍ afectan (ya incluidos en
// imss_patronal / gastos_empleados_nomina, sumados dentro de nomTotal).
function nomRowTipo(label: string): 'Deducción informativa' | 'IMSS' | 'Gasto empleado' {
    if (label === 'IMSS') return 'IMSS'
    if (NOI_DEDUCTION_LABELS.has(label)) return 'Deducción informativa'
    return 'Gasto empleado'
}
const nomDeduccionesInformativas = computed(() => nomDetalle.value.filter(r => nomRowTipo(r.label) === 'Deducción informativa'))
const nomImssRow      = computed(() => nomDetalle.value.filter(r => nomRowTipo(r.label) === 'IMSS'))
const nomGastoEmpleado = computed(() => nomDetalle.value.filter(r => nomRowTipo(r.label) === 'Gasto empleado'))
const nomTotal = computed(() =>
    nomNomina.value + nomComis.value + nomVac.value + nomPrimaVac.value + nomBonos.value + nomBonosAcel.value
    + nomOtrosPercep.value + nomImssPatronal.value + nomGastosEmpleados.value
)
const nomNeto = computed(() => nomTotal.value)

// Percepciones/Deducciones/Neto pagado a trabajadores: informativo — "lo que el trabajador
// recibió", distinto de Nómina y Capital Humano (concepto de gasto de la empresa).
const noiPercepciones = computed(() => Number(snap.value?.summary?.noi_percepciones) || 0)
const noiDeducciones = computed(() => Number(snap.value?.summary?.noi_deducciones) || 0)
const noiNetoPagado = computed(() => Number(snap.value?.summary?.noi_neto_pagado) || 0)

// ── Préstamos intersucursales ─────────────────────────────────────────────────
const fondeoGlobal = computed(() => Number(brGlobal.value?.prestamos_fondea) || 0)
const loans = computed(() => snap.value?.sections?.interbranch_loans ?? {})

// Fondeos entre sucursales operativas (fondea = recibe, neto = $0)
const fondeoOperativo = computed(() => loans.value?.operative_fondeos ?? {})
const fondeoOperTotal = computed(() => Number(fondeoOperativo.value?.fondea_total) || 0)
const fondeoOperDetalle = computed(() => {
    const detail = fondeoOperativo.value?.detail as any[] ?? []
    return detail.map((f: any) => ({
        sucursal_origen:  f.from_branch && f.from_branch !== 'No identificada' ? f.from_branch : '—',
        sucursal_destino: f.to_branch && f.to_branch !== 'No identificada' && f.to_branch !== 'No detectado' ? f.to_branch : '—',
        monto:            f.amount       ?? 0,
        observacion:      [f.observation, f.justification].filter(Boolean).join(' | '),
        fecha:            f.date         ?? '',
    }))
})

// Excedentes / envíos a CORPORATIVO (sección separada)
const excedentesSection = computed(() => loans.value?.excedentes ?? {})
const excedentesTotal   = computed(() => Number(excedentesSection.value?.total) || 0)
const excedentesDetalle = computed(() => {
    const detail = excedentesSection.value?.detail as any[] ?? []
    return detail.map((f: any) => ({
        sucursal_origen:  f.from_branch && f.from_branch !== 'No identificada' ? f.from_branch : '—',
        destino:          f.to_branch   ?? 'CORPORATIVO',
        monto:            f.amount       ?? 0,
        observacion:      [f.observation, f.justification].filter(Boolean).join(' | '),
        fecha:            f.date         ?? '',
        fuente:           f.source       ?? '',
    }))
})

// Backward compat — tabla completa (todos los rows)
const fondeoDetalle = computed(() => {
    const detail = loans.value?.detail as any[] ?? []
    return detail.map((f: any) => ({
        sucursal_origen:  f.from_branch && f.from_branch !== 'No identificada' ? f.from_branch : '—',
        sucursal_destino: f.to_branch && f.to_branch !== 'No identificada' && f.to_branch !== 'No detectado' ? f.to_branch : '—',
        responsable:      f.observation ?? '',
        monto:            f.amount       ?? 0,
        observacion:      [f.observation, f.justification].filter(Boolean).join(' | '),
        fecha:            f.date         ?? '',
        tipo:             f.type        ?? 'fondeo',
    }))
})

// ── Seguros / Coberturas (puente) — no se suman a recuperación ni a gastos ─────
const segurosSaveheartsBruto = computed(() => Number(snap.value?.summary?.recovery_savehearts_bruto) || 0)
const segurosComadresBruto   = computed(() => Number(snap.value?.summary?.recovery_comadres_bruto) || 0)
const segurosCreceBruto      = computed(() => Number(snap.value?.summary?.recovery_crece_bruto) || 0)
const segurosCrece30         = computed(() => Number(snap.value?.summary?.recovery_crece_reconocido) || 0)
const segurosCrece70         = computed(() => Number(snap.value?.summary?.recovery_crece_no_reconocido) || 0)
const segurosPuenteTotal     = computed(() => segurosSaveheartsBruto.value + segurosComadresBruto.value + segurosCrece70.value)

// ── Cartera / mora global ──────────────────────────────────────────────────────
const recGlobal     = computed(() => Number(brGlobal.value?.recuperacion_total) || 0)
const colGlobal     = computed(() => Number(brGlobal.value?.colocacion)         || 0)
// Valor cartera / Cartera vencida GLOBAL: fuente snap.summary (todas las sucursales/rutas,
// único filtro = excluir Aguascalientes). Distinto de la suma de las 13 sucursales oficiales
// (brGlobal.valor_cartera), que sigue usándose para el desglose por sucursal/bucket.
const carteraGlobal = computed(() => Number(snap.value?.summary?.portfolio_total)   || 0)
const moraTotalGlobal = computed(() => Number(snap.value?.summary?.overdue_portfolio) || 0)
const excGlobal     = computed(() => Number(brGlobal.value?.excedentes)         || 0)
const mora0_30g    = computed(() => Number(brGlobal.value?.mora_0_30)     || 0)
const mora31_60g   = computed(() => Number(brGlobal.value?.mora_31_60)    || 0)
const mora61_90g   = computed(() => Number(brGlobal.value?.mora_61_90)    || 0)
const mora91_120g  = computed(() => Number(brGlobal.value?.mora_91_120)   || 0)
const mora120plusG = computed(() => Number(brGlobal.value?.mora_120_plus) || 0)

const moraBucketsGlobal = computed(() => [
    { label: 'Mora 1-30',   value: mora0_30g.value },
    { label: 'Mora 31-60',  value: mora31_60g.value },
    { label: 'Mora 61-90',  value: mora61_90g.value },
    { label: 'Mora 91-120', value: mora91_120g.value },
    { label: 'Mora 120+',   value: mora120plusG.value },
])

const g = (k: string) => Number(brGlobal.value?.[k]) || 0
const moraComponentes = computed(() => {
    const totalMora = moraTotalGlobal.value || 1
    return [
        {
            label: 'Mora 1-30', key: 'mora_0_30',
            capital: g('mora_0_30_capital'), interes: g('mora_0_30_interes'),
            impuesto: g('mora_0_30_impuesto'), moratorio: g('mora_0_30_moratorio'),
            imp_moratorio: g('mora_0_30_imp_moratorio'), total: mora0_30g.value,
            pct: (mora0_30g.value / totalMora * 100),
        },
        {
            label: 'Mora 31-60', key: 'mora_31_60',
            capital: g('mora_31_60_capital'), interes: g('mora_31_60_interes'),
            impuesto: g('mora_31_60_impuesto'), moratorio: g('mora_31_60_moratorio'),
            imp_moratorio: g('mora_31_60_imp_moratorio'), total: mora31_60g.value,
            pct: (mora31_60g.value / totalMora * 100),
        },
        {
            label: 'Mora 61-90', key: 'mora_61_90',
            capital: g('mora_61_90_capital'), interes: g('mora_61_90_interes'),
            impuesto: g('mora_61_90_impuesto'), moratorio: g('mora_61_90_moratorio'),
            imp_moratorio: g('mora_61_90_imp_moratorio'), total: mora61_90g.value,
            pct: (mora61_90g.value / totalMora * 100),
        },
        {
            label: 'Mora 91-120', key: 'mora_91_120',
            capital: g('mora_91_120_capital'), interes: g('mora_91_120_interes'),
            impuesto: g('mora_91_120_impuesto'), moratorio: g('mora_91_120_moratorio'),
            imp_moratorio: g('mora_91_120_imp_moratorio'), total: mora91_120g.value,
            pct: (mora91_120g.value / totalMora * 100),
        },
        {
            label: 'Mora 120+', key: 'mora_120_plus',
            capital: g('mora_120_plus_capital'), interes: g('mora_120_plus_interes'),
            impuesto: g('mora_120_plus_impuesto'), moratorio: g('mora_120_plus_moratorio'),
            imp_moratorio: g('mora_120_plus_imp_moratorio'), total: mora120plusG.value,
            pct: (mora120plusG.value / totalMora * 100),
        },
    ]
})

const moraTotalesComponentes = computed(() => ({
    capital:      g('mora_total_capital'),
    interes:      g('mora_total_interes'),
    impuesto:     g('mora_total_impuesto'),
    moratorio:    g('mora_total_moratorio'),
    imp_moratorio: g('mora_total_imp_moratorio'),
    total:        moraTotalGlobal.value,
}))

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

// ── EBITDA global — CRITERIO FINAL (2026-07) ────────────────────────────────────
// EBITDA NO usa Recuperación total (incluye capital recuperado, que no es ingreso real)
// ni Colocación ni saldo inicial de caja. Ingreso base EBITDA = SOLO los componentes de
// Recuperación que son ingreso real: Intereses + Impuestos + Moratorios/Multas + Comisión
// por apertura + Cargos adicionales + Excedentes recuperados + Seguro CRECE reconocido (30%).
// Misma fórmula exacta que el backend (BranchRadiographyCalculator::ingresoEbitdaBaseFor() /
// ::ebitdaFinalFor() / ::margenEbitdaFor()) y que Excel/PDF — nunca debe divergir.
const saldoInicialCaja  = computed(() => Number(snap.value?.saldo_inicial_caja) || 0)
const saldoFinalCaja    = computed(() => snap.value?.saldo_final_caja !== null && snap.value?.saldo_final_caja !== undefined ? Number(snap.value.saldo_final_caja) : null)
const gastosEbitdaTotal = computed(() => brGlobalGastosTotal.value + nomTotal.value)
const ingresoEbitdaBaseGlobal = computed(() =>
    ingrInteres.value + ingrImpuesto.value + ingrMultas.value
    + ingrComAper.value + ingrCargosAdic.value + ingrExcedente.value + ingrCrece30.value
)
const utilidadGlobal    = computed(() => ingresoEbitdaBaseGlobal.value - gastosEbitdaTotal.value)
const ventaGlobal       = computed(() => ingresoEbitdaBaseGlobal.value)
const margenEbitdaPct   = computed(() => ventaGlobal.value > 0 ? (utilidadGlobal.value / ventaGlobal.value) * 100 : 0)
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
// nominaFull replica exactamente BranchRadiographyCalculator::nominaTotalFor(): NOI neto
// (percepciones − deducciones) + IMSS patronal operativo + gastos de empleados Lendus
// (Financiamiento de Motos, Enganche, Cascos, Finiquito, Gastos médicos). Regla vigente
// 2026-07 — coincide siempre con Excel y PDF, que usan la misma fuente canónica.
const branchesFull = computed(() => {
    return brRaw.value.map((b: any) => {
        const moraSum = (Number(b.mora_0_30) || 0) + (Number(b.mora_31_60) || 0) + (Number(b.mora_61_90) || 0) + (Number(b.mora_91_120) || 0) + (Number(b.mora_120_plus) || 0)
        const cartera = Number(b.valor_cartera) || 0
        const bonos   = Number(b.bonos) || 0
        const nominaFull = (Number(b.nomina_total) || 0) + (Number(b.comisiones) || 0) + bonos
            + (Number(b.bonos_aceleradores) || 0)
            + (Number(b.vacaciones) || 0) + (Number(b.prima_vacacional) || 0)
            + (Number(b.otros_percepciones) || 0)
            + (Number(b.imss_patronal) || 0) + (Number(b.gastos_empleados_nomina) || 0)
        const recuperacion    = Number(b.recuperacion_total) || 0
        const colocacion      = Number(b.colocacion ?? b.colocacion_total ?? b.otorgamientos ?? 0) || 0
        const gastos          = Number(b.gastos_operativos) || 0
        // Ingreso base EBITDA por sucursal — misma fórmula que el global (ver
        // ingresoEbitdaBaseGlobal arriba / BranchRadiographyCalculator::ingresoEbitdaBaseFor()).
        const ventaBranch     = (Number(b.interes_recuperado) || 0) + (Number(b.impuesto_recuperado) || 0)
            + (Number(b.charges) || 0) + (Number(b.comision_apertura) || 0)
            + (Number(b.cargos_adicionales) || 0) + (Number(b.excedente_recuperado) || 0)
            + (Number(b.seguro_crece_reconocido) || 0)
        const gastosTotalBranch = gastos + nominaFull
        const ebitda          = ventaBranch - gastosTotalBranch
        const margenEbitda    = ventaBranch > 0 ? (ebitda / ventaBranch) * 100 : 0
        return {
            nombre: b.sucursal,
            recuperacion,
            colocacion,
            cartera,
            vencida: moraSum,
            mora: cartera > 0 ? (moraSum / cartera) * 100 : 0,
            gastos,
            nomina: nominaFull,
            bonos,
            ebitda,
            margenEbitda,
            categoria: ebitdaCategoryOf(ebitda),
            mora_0_30: Number(b.mora_0_30) || 0,
            mora_31_60: Number(b.mora_31_60) || 0,
            mora_61_90: Number(b.mora_61_90) || 0,
            mora_91_120: Number(b.mora_91_120) || 0,
            mora_120_plus: Number(b.mora_120_plus) || 0,
        }
    }).sort((a, b) => b.ebitda - a.ebitda)
})

const categoriaCounts = computed(() => {
    const counts: Record<string, number> = { DIAMANTE: 0, MASTER: 0, SENIOR: 0, JUNIOR: 0, MANTENIDO: 0 }
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
        const key = al.sucursal ?? '—'
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

// Préstamo activo = SUM(Saldo actual) donde dias_vencidos = 0, excluyendo Aguascalientes.
// "Saldo actual" es saldo_activo (fact_portfolios.balance) — NO capital_activo ("Capital"),
// que es una columna distinta del mismo Excel y no es la regla de negocio vigente.
const prestamoActivoKpi = computed(() => {
    const rows = (snap.value?.sections?.active_loans ?? []) as any[]
    return rows
        .filter((al: any) => Number(al.dias_vencidos) === 0)
        .reduce((sum: number, al: any) => sum + (Number(al.saldo_activo) || 0), 0)
})

// ── Productos ──────────────────────────────────────────────────────────────────
const productosRows = computed(() => snap.value?.sections?.products ?? [])

// ── Fondeos / Excedentes ───────────────────────────────────────────────────────
const fondeoDetalleSection = computed(() => snap.value?.sections?.fondeo_detalle ?? { total: 0, detalle: [] })
const fondeoDetalleRows    = computed(() => (fondeoDetalleSection.value.detalle ?? []) as any[])
const fondeoDetalleTotal   = computed(() => Number(fondeoDetalleSection.value.total) || 0)

const corpFunding     = computed(() => snap.value?.sections?.corporate_funding ?? { total: 0, by_branch: [], by_day: [] })
const corpFundingRows = computed(() => (corpFunding.value.by_branch ?? []) as any[])

// Fondeos por sucursal origen (agregado)
const fondeosPorOrigen = computed(() => {
    const map = new Map<string, number>()
    for (const r of fondeoDetalleRows.value) {
        const key = r.sucursal_origen ?? '—'
        map.set(key, (map.get(key) ?? 0) + Number(r.monto))
    }
    return Array.from(map.entries())
        .map(([sucursal, monto]) => ({ sucursal, monto }))
        .sort((a, b) => b.monto - a.monto)
})

const fondeosPorOrigenOptions = computed(() => donutOptions(fondeosPorOrigen.value.map(r => r.sucursal), categoryPalette))
const fondeosPorOrigenSeries  = computed(() => fondeosPorOrigen.value.map(r => r.monto))

// ── Rotación de Personal ───────────────────────────────────────────────────────
const rotacionData        = computed(() => snap.value?.sections?.rotation ?? null)
const rotacionFuente      = computed(() => (rotacionData.value?.fuente) ?? 'noi')
const rotacionMes         = computed(() => rotacionData.value?.mes ?? '')
const rotacionAltas       = computed(() => Number(rotacionData.value?.altas) || 0)
const rotacionBajas       = computed(() => Number(rotacionData.value?.bajas) || 0)
const rotacionPromedio    = computed(() => Number(rotacionData.value?.promedio) || 0)
const rotacionIndice      = computed(() => Number(rotacionData.value?.indice) || 0)
const rotacionPorSucursal = computed(() => (rotacionData.value?.por_sucursal ?? []) as any[])
const rotacionDetalleMensual = computed(() => (rotacionData.value?.detalle_mensual ?? []) as any[])

const rotacionDetalle        = computed(() => snap.value?.sections?.rotation_detail ?? null)
const rotacionAltasLista     = computed(() => (rotacionDetalle.value?.altas ?? []) as any[])
const rotacionBajasLista     = computed(() => (rotacionDetalle.value?.bajas ?? []) as any[])
const rotacionMesActualLista   = computed(() => (rotacionDetalle.value?.empleados_mes_actual ?? []) as any[])
const rotacionMesAnteriorLista = computed(() => (rotacionDetalle.value?.empleados_mes_anterior ?? []) as any[])
const rotacionMesActualLabel   = computed(() => rotacionDetalle.value?.mes_actual ?? '')
const rotacionMesAnteriorLabel = computed(() => rotacionDetalle.value?.mes_anterior ?? 'periodo anterior')
const rotacionAuditoriaAbierta = ref(false)

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
const vfCategoriaOptions = ['DIAMANTE', 'MASTER', 'SENIOR', 'JUNIOR', 'MANTENIDO']

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

// ── Alertas (Resumen) — vacías; colores suaves en cifras cubren el feedback visual
const alertas = computed(() => [] as { text: string; tone: 'red' | 'amber' }[])

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
// b.nomina (de branchesFull) = percepciones brutas + IMSS + gastos de empleados (regla final
// 2026-07) — las deducciones NOI YA NO se restan, son puramente informativas (descuentos).
const nominaTree = computed(() => {
    return branchesFull.value.map(b => {
        const raw = brRaw.value.find((r: any) => r.sucursal === b.nombre)
        const det: Record<string, number> = {}
        for (const [k, v] of Object.entries((raw?.nomina_detalle ?? {}) as Record<string, number>)) det[k] = (det[k] ?? 0) + (Number(v) || 0)
        for (const [k, v] of Object.entries((raw?.nomina_informativo ?? {}) as Record<string, number>)) det[k] = (det[k] ?? 0) + (Number(v) || 0)
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

// ── Efectividad de Cobranza ─────────────────────────────────────────────────
const ecData = computed(() => snap.value?.sections?.efectividad_cobranza ?? null)
const ecStatus = computed(() => {
    const ec = ecData.value
    if (!ec) return []
    return [
        { key: 'vigente',  label: 'Cobros de créditos vigentes',  tone: 'green', ...ec['vigente']  },
        { key: 'atrasado', label: 'Cobros de créditos atrasados', tone: 'amber', ...ec['atrasado'] },
        { key: 'vencido',  label: 'Cobros de créditos vencidos',  tone: 'red',   ...ec['vencido']  },
    ]
})
const ecTotal = computed(() => ecData.value?.total ?? { capital: 0, interes: 0, impuesto: 0, moratorios: 0, total: 0, contratos: 0 })
const efectividadKpiPct = computed(() => {
    const total = ecTotal.value.total
    if (!ecData.value || total <= 0) return null
    return ((ecData.value?.vigente?.total ?? 0) / total * 100)
})

const tabs: { key: TabKey; label: string }[] = [
    { key: 'resumen',    label: 'Resumen' },
    { key: 'sucursales', label: 'Sucursales' },
    { key: 'ingresos',   label: 'Ingresos / Cobranza' },
    { key: 'gastos',     label: 'Gastos' },
    { key: 'nomina',     label: 'Nómina' },
    { key: 'mora',       label: 'Mora / Cartera' },
    { key: 'cobranza',   label: 'Efectividad de cobranza' },
    { key: 'productos',  label: 'Colocación / Recuperación' },
    { key: 'fondeos',    label: 'Fondeos / Excedentes' },
    { key: 'rotacion',   label: 'Rotación de Personal' },
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
const recColSeries = computed(() => [kpiRec.value, kpiCol.value])
const recColOptions = computed(() => donutOptions(['Recuperación / Cobranza', 'Colocación'], [chartColors.teal, chartColors.blue]))

// Resumen: Cartera vs Vencida (donut)
const carteraDonutSeries = computed(() => {
    const sana = Math.max(0, kpiCartera.value - kpiMora.value)
    return [sana, kpiMora.value]
})
const carteraDonutOptions = computed(() => donutOptions(['Cartera sana', 'Cartera vencida'], [chartColors.teal, chartColors.red]))

// Mora por bucket (donut/pastel — muestra porcentaje y monto)
const moraBucketSeries = computed(() => moraBucketsGlobal.value.map(b => b.value))
const moraBucketOptions = computed(() => donutOptions(
    moraBucketsGlobal.value.map(b => b.label),
    ['#e11d48', '#f97316', '#eab308', '#3b82f6', '#8b5cf6'],
))

// ── Gráficas EBITDA / Gastos / Nómina / Recuperación (criterio final 2026-07) ────
const ebitdaCompSeries = computed(() => [{ name: 'Monto', data: [ingresoEbitdaBaseGlobal.value, gastosEbitdaTotal.value, utilidadGlobal.value] }])
const ebitdaCompOptions = computed(() => columnOptions(['Utilidad bruta', 'Gastos Totales', 'EBITDA'], [chartColors.teal]))

const gastosCompSeries = computed(() => [{ name: 'Monto', data: [brGlobalGastosTotal.value, nomTotal.value, gastosEbitdaTotal.value] }])
const gastosCompOptions = computed(() => columnOptions(['OPEX', 'Nómina y Capital Humano', 'Gastos Totales'], [chartColors.amber]))

const nomCompSeries = computed(() => [
    Math.max(0, nomTotal.value - nomImssPatronal.value - nomGastosEmpleados.value),
    nomImssPatronal.value,
    nomGastosEmpleados.value,
    nomDeduccionesInformativas.value.reduce((s, r) => s + r.value, 0),
])
const nomCompOptions = computed(() => donutOptions(
    ['Percepciones', 'IMSS', 'Gastos empleados', 'Deducciones informativas'],
    [chartColors.teal, chartColors.blue, chartColors.amber, chartColors.gray],
))

// OPEX por concepto (Top 6) — mismos conceptos reales que el Excel, sustituye la
// gráfica de Recuperación/Ingreso — Componentes (esa información ya vive en la
// tabla "Ingresos / Recuperación" de este mismo tab, no se duplica en gráfica).
const opexTopSeries = computed(() => brGlobalGastos.value.slice(0, 6).map(g => g.total))
const opexTopOptions = computed(() => donutOptions(
    brGlobalGastos.value.slice(0, 6).map(g => g.concepto),
    categoryPalette,
))

// Sucursales: ranking por recuperación / cartera / EBITDA
function rankingSeries(field: 'recuperacion' | 'cartera' | 'ebitda', limit = 13) {
    return [...branchesFiltered.value].sort((a, b) => b[field] - a[field]).slice(0, limit)
}
const rankingRecuperacion = computed(() => rankingSeries('recuperacion'))
const rankingRecuperacionOptions = computed(() => donutOptions(
    rankingRecuperacion.value.map(b => b.nombre),
    dimColors(rankingRecuperacion.value.map(b => b.nombre), vfBranch.value, chartColors.teal),
))
const rankingRecuperacionSeries = computed(() => rankingRecuperacion.value.map(b => b.recuperacion))

const rankingCartera = computed(() => rankingSeries('cartera'))
const rankingCarteraOptions = computed(() => donutOptions(
    rankingCartera.value.map(b => b.nombre),
    dimColors(rankingCartera.value.map(b => b.nombre), vfBranch.value, chartColors.blue),
))
const rankingCarteraSeries = computed(() => rankingCartera.value.map(b => b.cartera))

const rankingEbitda = computed(() => rankingSeries('ebitda'))
const rankingEbitdaOptions = computed(() => donutOptions(
    rankingEbitda.value.map(b => b.nombre),
    rankingEbitda.value.map(b => (b.ebitda < 0 ? chartColors.red : chartColors.green)),
))
const rankingEbitdaSeries = computed(() => rankingEbitda.value.map(b => Math.abs(b.ebitda)))

// Categoría EBITDA: distribución (donut) + EBITDA por sucursal coloreado por categoría
const categoriaDonutOptions = computed(() => donutOptions(
    ['DIAMANTE', 'MASTER', 'SENIOR', 'JUNIOR', 'MANTENIDO'],
    ['#0ea5e9', '#8b5cf6', chartColors.green, chartColors.amber, chartColors.red],
))
const categoriaDonutSeries = computed(() => [
    categoriaCounts.value.DIAMANTE,
    categoriaCounts.value.MASTER,
    categoriaCounts.value.SENIOR,
    categoriaCounts.value.JUNIOR,
    categoriaCounts.value.MANTENIDO,
])

const categoriaColorMap: Record<string, string> = {
    DIAMANTE: '#0ea5e9', MASTER: '#8b5cf6',
    SENIOR: chartColors.green, JUNIOR: chartColors.amber, MANTENIDO: chartColors.red,
}
const ebitdaPorSucursal = computed(() => [...branchesFiltered.value].sort((a, b) => b.ebitda - a.ebitda))
const ebitdaPorSucursalOptions = computed(() => donutOptions(
    ebitdaPorSucursal.value.map(b => b.nombre),
    ebitdaPorSucursal.value.map(b => categoriaColorMap[b.categoria] ?? chartColors.teal),
))
const ebitdaPorSucursalSeries = computed(() => ebitdaPorSucursal.value.map(b => Math.abs(b.ebitda)))

// Ingresos / Cobranza: colocación por producto
const productosSorted = computed(() => [...productosRows.value].sort((a: any, b: any) => (b.colocacion ?? 0) - (a.colocacion ?? 0)))
const colocacionProductoOptions = computed(() => donutOptions(
    productosSorted.value.map((p: any) => p.producto),
    dimColors(productosSorted.value.map((p: any) => p.producto), vfProduct.value, chartColors.teal),
))
const colocacionProductoSeries = computed(() => productosSorted.value.map((p: any) => p.colocacion ?? 0))

// Ingresos / Cobranza: ranking por sucursal (colocación)
const colocacionSucursalOptions = computed(() => donutOptions(
    branchesFiltered.value.map(b => b.nombre),
    dimColors(branchesFiltered.value.map(b => b.nombre), vfBranch.value, chartColors.blue),
))
const colocacionSucursalSeries = computed(() => [...branchesFiltered.value].map(b => b.colocacion))

// Gastos: por sucursal / por categoría
const gastosPorSucursalSorted = computed(() => [...branchesFiltered.value].filter(b => b.gastos > 0).sort((a, b) => b.gastos - a.gastos))
const gastosPorSucursalOptions = computed(() => donutOptions(
    gastosPorSucursalSorted.value.map(b => b.nombre),
    dimColors(gastosPorSucursalSorted.value.map(b => b.nombre), vfBranch.value, chartColors.amber),
))
const gastosPorSucursalSeries = computed(() => gastosPorSucursalSorted.value.map(b => b.gastos))

const gastosPorCategoriaOptions = computed(() => donutOptions(brGlobalGastos.value.slice(0, 10).map(g => g.concepto), categoryPalette))
const gastosPorCategoriaSeries = computed(() => brGlobalGastos.value.slice(0, 10).map(g => g.total))

// Nómina por sucursal
const nominaPorSucursalSorted = computed(() => [...branchesFiltered.value].filter(b => b.nomina > 0).sort((a, b) => b.nomina - a.nomina))
const nominaPorSucursalOptions = computed(() => donutOptions(
    nominaPorSucursalSorted.value.map(b => b.nombre),
    dimColors(nominaPorSucursalSorted.value.map(b => b.nombre), vfBranch.value, chartColors.teal),
))
const nominaPorSucursalSeries = computed(() => nominaPorSucursalSorted.value.map(b => b.nomina))

// Mora / Cartera: top sucursales con más vencida
const topVencidaBranches = computed(() => [...branchesFiltered.value].filter(b => b.vencida > 0).sort((a, b) => b.vencida - a.vencida).slice(0, 10))
const topVencidaOptions = computed(() => donutOptions(topVencidaBranches.value.map(b => b.nombre), categoryPalette))
const topVencidaSeries = computed(() => topVencidaBranches.value.map(b => b.vencida))

// Préstamos activos: saldo / vencido por sucursal
const prestamosFiltered = computed(() => {
    let rows = activeLoansByBranch.value
    if (vfBranch.value) rows = rows.filter(r => r.sucursal === vfBranch.value)
    return rows
})
const prestamosSaldoOptions = computed(() => donutOptions(prestamosFiltered.value.map(r => r.sucursal), categoryPalette))
const prestamosSaldoSeries = computed(() => prestamosFiltered.value.map(r => r.saldo))
const prestamosVencidoOptions = computed(() => donutOptions(prestamosFiltered.value.map(r => r.sucursal), categoryPalette))
const prestamosVencidoSeries = computed(() => prestamosFiltered.value.map(r => r.vencido))

// Gestores: ranking por colocación
const rankingGestoresOptions = computed(() => donutOptions(topGestoresColocacion.value.map((e: any) => e.name), categoryPalette))
const rankingGestoresSeries = computed(() => topGestoresColocacion.value.map((e: any) => e.colocacion))
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
                        <div v-if="periodComposite" class="mt-1.5 space-y-0.5">
                            <p class="text-sm font-semibold text-indigo-300">{{ periodComposite.component_range }}</p>
                            <p class="text-xs text-slate-400">
                                Periodo: {{ periodComposite.week_range }} · Rango: {{ periodComposite.date_start }} → {{ periodComposite.date_end }}
                            </p>
                        </div>
                        <p class="mt-1 text-sm text-slate-400">
                            <span v-if="snap && enConciliacion" class="mr-2 inline-flex items-center gap-1.5 rounded-full bg-amber-400/20 px-2.5 py-0.5 text-xs font-black text-amber-300">
                                EN CONCILIACIÓN
                            </span>
                            <span v-if="snap">Radiografía generada: {{ snap.generated_at }}</span>
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
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5">
                        <KpiCard label="Recuperación" :value="money(kpiRec)" :icon="HandCoins" tone="teal" />
                        <KpiCard label="Colocación" :value="money(kpiCol)" :icon="TrendingUp" tone="blue" />
                        <KpiCard label="Valor cartera" :value="money(kpiCartera)" :icon="Landmark" tone="teal" />
                        <KpiCard label="Cartera vencida" :value="money(kpiMora)" :icon="AlertTriangle" :tone="kpiMoraPct > 25 ? 'red' : 'amber'" />
                        <KpiCard label="Mora %" :value="pct(kpiMoraPct)" :icon="Percent" :tone="kpiMoraPct > 25 ? 'red' : 'teal'" />
                        <KpiCard label="OPEX" :value="money(kpiGastos)" :icon="Receipt" tone="amber" />
                        <KpiCard label="Nómina y Capital Humano" :value="money(kpiNomina)" :icon="Wallet" tone="blue" />
                        <KpiCard :label="kpiUtilLabel" :value="money(kpiUtil)" :icon="Gauge" :tone="kpiUtil < 0 ? 'red' : 'green'" />
                        <KpiCard label="Margen EBITDA" :value="pct(margenEbitdaPct)" :icon="Percent" :tone="margenEbitdaPct < 0 ? 'red' : 'green'" />
                        <KpiCard label="Préstamo activo" :value="money(prestamoActivoKpi)" :icon="Banknote" tone="blue" />
                    </div>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <KpiCard label="Percepciones" :value="money(noiPercepciones)" :icon="Wallet" tone="teal" />
                        <KpiCard label="Deducciones" :value="money(noiDeducciones)" :icon="Receipt" tone="amber" />
                        <KpiCard label="Neto pagado a trabajadores" :value="money(noiNetoPagado)" :icon="HandCoins" tone="blue" />
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
                <div class="flex overflow-x-auto border-b border-slate-200 gap-1 scrollbar-none">
                    <button v-for="t in tabs" :key="t.key" @click="activeTab = t.key"
                        class="relative shrink-0 px-3.5 py-2.5 text-xs font-bold transition border-b-2 whitespace-nowrap"
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
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Resumen ejecutivo</h3>
                        </div>
                        <table class="w-full text-sm">
                            <tbody>
                                <tr class="border-b"><td class="px-5 py-2.5 text-slate-600 font-medium">Recuperación total</td><td class="px-5 py-2.5 text-right font-black text-slate-950">{{ money(recGlobal) }}</td></tr>
                                <tr class="border-b bg-slate-50/60"><td class="px-5 py-2.5 text-slate-600 font-medium">Colocación total</td><td class="px-5 py-2.5 text-right font-black text-slate-950">{{ money(colGlobal) }}</td></tr>
                                <tr class="border-b"><td class="px-5 py-2.5 text-slate-600 font-medium">Cartera total</td><td class="px-5 py-2.5 text-right font-black text-slate-950">{{ money(carteraGlobal) }}</td></tr>
                                <tr class="border-b bg-slate-50/60"><td class="px-5 py-2.5 text-slate-600 font-medium">Cartera vencida / Mora total</td><td class="px-5 py-2.5 text-right font-black" :class="moraTotalGlobal > 0 ? 'text-red-700' : 'text-slate-950'">{{ money(moraTotalGlobal) }}</td></tr>
                                <tr class="border-b"><td class="px-5 py-2.5 text-slate-600 font-medium">Índice de mora</td><td class="px-5 py-2.5 text-right font-black" :class="kpiMoraPct > 25 ? 'text-red-700' : 'text-slate-950'">{{ pct(kpiMoraPct) }}</td></tr>
                                <tr class="border-b bg-slate-50/60"><td class="px-5 py-2.5 text-slate-600 font-medium">OPEX</td><td class="px-5 py-2.5 text-right font-black text-slate-950">{{ money(brGlobalGastosTotal) }}</td></tr>
                                <tr class="border-b"><td class="px-5 py-2.5 text-slate-600 font-medium">Nómina y Capital Humano</td><td class="px-5 py-2.5 text-right font-black text-slate-950">{{ money(nomTotal) }}</td></tr>
                                <tr class="border-b-2 border-indigo-200 bg-indigo-50"><td class="px-5 py-2.5 font-black text-indigo-900">EBITDA</td><td class="px-5 py-2.5 text-right font-black text-lg" :class="utilidadGlobal < 0 ? 'text-red-700' : 'text-indigo-900'">{{ money(utilidadGlobal) }}</td></tr>
                                <tr class="border-b bg-slate-50/60"><td class="px-5 py-2.5 text-slate-500 font-medium">Margen EBITDA</td><td class="px-5 py-2.5 text-right font-black" :class="margenEbitdaPct < 0 ? 'text-red-700' : 'text-slate-950'">{{ pct(margenEbitdaPct) }}</td></tr>
                                <tr class="border-b bg-amber-50/40"><td class="px-5 py-2.5 text-slate-700 font-semibold">Excedente enviado a corporativo</td><td class="px-5 py-2.5 text-right font-black text-slate-950">{{ money(excGlobal) }}</td></tr>
                                <tr class="border-b"><td class="px-5 py-2.5 text-slate-600 font-medium">Fondeo entre sucursales (rastreo, no afecta EBITDA)</td><td class="px-5 py-2.5 text-right font-black text-slate-950">{{ money(fondeoGlobal) }}</td></tr>
                                <tr class="border-b bg-slate-50/60"><td class="px-5 py-2.5 text-slate-600 font-medium">Seguros y coberturas canalizadas</td><td class="px-5 py-2.5 text-right font-black text-slate-950">{{ money(segurosPuenteTotal) }}</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- ── DESGLOSES DETALLADOS ─────────────────────────────────── -->
                    <div class="grid gap-4 lg:grid-cols-2">
                        <!-- A) Ingresos / Recuperación -->
                        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                            <div class="border-b bg-emerald-50 px-5 py-3 flex items-center justify-between">
                                <h3 class="text-xs font-black uppercase tracking-wider text-emerald-700">Ingresos / Recuperación</h3>
                                <span class="font-black text-emerald-800">{{ money(recGlobal) }}</span>
                            </div>
                            <table class="w-full text-sm">
                                <tbody>
                                    <tr class="border-b"><td class="px-5 py-2 text-slate-600 font-medium">Recuperación final (ingreso)</td><td class="px-5 py-2 text-right font-black text-emerald-700">{{ money(recGlobal) }}</td></tr>
                                    <tr class="border-b bg-slate-50/60"><td class="px-5 py-2 pl-8 text-slate-500 text-xs">→ Capital recuperado</td><td class="px-5 py-2 text-right text-xs text-slate-600">{{ money(ingrCapital) }}</td></tr>
                                    <tr class="border-b"><td class="px-5 py-2 pl-8 text-slate-500 text-xs">→ Intereses</td><td class="px-5 py-2 text-right text-xs text-slate-600">{{ money(ingrInteres) }}</td></tr>
                                    <tr class="border-b bg-slate-50/60"><td class="px-5 py-2 pl-8 text-slate-500 text-xs">→ Impuestos</td><td class="px-5 py-2 text-right text-xs text-slate-600">{{ money(ingrImpuesto) }}</td></tr>
                                    <tr class="border-b"><td class="px-5 py-2 pl-8 text-slate-500 text-xs">→ Moratorios / Multas</td><td class="px-5 py-2 text-right text-xs text-slate-600">{{ money(ingrMultas) }}</td></tr>
                                    <tr v-if="ingrCargosAdic > 0" class="border-b bg-slate-50/60"><td class="px-5 py-2 pl-8 text-slate-500 text-xs">→ Cargos adicionales</td><td class="px-5 py-2 text-right text-xs text-slate-600">{{ money(ingrCargosAdic) }}</td></tr>
                                    <tr v-if="ingrExcedente > 0" class="border-b"><td class="px-5 py-2 pl-8 text-slate-500 text-xs">→ Excedentes recuperados</td><td class="px-5 py-2 text-right text-xs text-slate-600">{{ money(ingrExcedente) }}</td></tr>
                                    <tr class="border-b bg-slate-50/60"><td class="px-5 py-2 pl-8 text-slate-500 text-xs">→ Cargos al inicio</td><td class="px-5 py-2 text-right text-xs text-slate-600">{{ money(ingrCargosIni) }}</td></tr>
                                    <tr class="border-b"><td class="px-5 py-2 pl-8 text-slate-500 text-xs">→ Comisión por apertura</td><td class="px-5 py-2 text-right text-xs text-slate-600">{{ money(ingrComAper) }}</td></tr>
                                    <tr v-if="ingrCrece30 > 0" class="border-b bg-slate-50/60"><td class="px-5 py-2 pl-8 text-slate-500 text-xs">→ Seguro CRECE reconocido (30%)</td><td class="px-5 py-2 text-right text-xs text-slate-600">{{ money(ingrCrece30) }}</td></tr>
                                    <tr v-for="(o, i) in ingrOtrosDetalle" :key="o.label" class="border-b" :class="i % 2 === 1 ? 'bg-slate-50/60' : ''"><td class="px-5 py-2 pl-8 text-slate-500 text-xs">→ {{ o.label }}</td><td class="px-5 py-2 text-right text-xs text-slate-600">{{ money(o.value) }}</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <!-- B) Colocación -->
                        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                            <div class="border-b bg-blue-50 px-5 py-3 flex items-center justify-between">
                                <h3 class="text-xs font-black uppercase tracking-wider text-blue-700">Colocación por producto</h3>
                                <span class="font-black text-blue-800">{{ money(colGlobal) }}</span>
                            </div>
                            <table class="w-full text-sm">
                                <tbody>
                                    <tr v-for="(p, i) in productosSorted.slice(0, 10)" :key="p.producto"
                                        :class="i % 2 === 1 ? 'bg-slate-50/60' : ''" class="border-b">
                                        <td class="px-5 py-2 text-slate-600">{{ p.producto }}</td>
                                        <td class="px-5 py-2 text-right font-semibold text-slate-800">{{ money(p.colocacion ?? 0) }}</td>
                                    </tr>
                                    <tr v-if="!productosSorted.length"><td colspan="2" class="px-5 py-3 text-xs text-slate-400 italic">Sin desglose por producto disponible.</td></tr>
                                    <tr class="border-t-2 border-blue-200 bg-blue-50"><td class="px-5 py-2.5 font-black text-blue-900">Total colocación</td><td class="px-5 py-2.5 text-right font-black text-blue-900">{{ money(colGlobal) }}</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <!-- C) Cartera / Mora -->
                        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                            <div class="border-b bg-red-50 px-5 py-3"><h3 class="text-xs font-black uppercase tracking-wider text-red-700">Valor Cartera / Mora</h3></div>
                            <table class="w-full text-sm">
                                <tbody>
                                    <tr class="border-b"><td class="px-5 py-2 text-slate-600 font-medium">Valor cartera total</td><td class="px-5 py-2 text-right font-black text-slate-950">{{ money(carteraGlobal) }}</td></tr>
                                    <tr class="border-b bg-slate-50/60"><td class="px-5 py-2 text-slate-600 font-medium">Cartera vencida (5 columnas)</td><td class="px-5 py-2 text-right font-black text-red-700">{{ money(moraTotalGlobal) }}</td></tr>
                                    <tr class="border-b"><td class="px-5 py-2 pl-8 text-slate-500 text-xs">Mora 1-30 días</td><td class="px-5 py-2 text-right text-xs text-red-600">{{ money(mora0_30g) }}</td></tr>
                                    <tr class="border-b bg-slate-50/60"><td class="px-5 py-2 pl-8 text-slate-500 text-xs">Mora 31-60 días</td><td class="px-5 py-2 text-right text-xs text-red-600">{{ money(mora31_60g) }}</td></tr>
                                    <tr class="border-b"><td class="px-5 py-2 pl-8 text-slate-500 text-xs">Mora 61-90 días</td><td class="px-5 py-2 text-right text-xs text-red-600">{{ money(mora61_90g) }}</td></tr>
                                    <tr class="border-b bg-slate-50/60"><td class="px-5 py-2 pl-8 text-slate-500 text-xs">Mora 91-120 días</td><td class="px-5 py-2 text-right text-xs text-red-600">{{ money(mora91_120g) }}</td></tr>
                                    <tr class="border-b"><td class="px-5 py-2 pl-8 text-slate-500 text-xs">Mora 120+ días</td><td class="px-5 py-2 text-right text-xs text-red-600">{{ money(mora120plusG) }}</td></tr>
                                    <tr class="border-b bg-slate-50/60"><td class="px-5 py-2 text-slate-600 font-medium">Cartera sana</td><td class="px-5 py-2 text-right font-black text-emerald-700">{{ money(Math.max(0, carteraGlobal - moraTotalGlobal)) }}</td></tr>
                                    <tr><td class="px-5 py-2.5 font-black text-slate-800">Índice de mora</td><td class="px-5 py-2.5 text-right font-black" :class="kpiMoraPct > 25 ? 'text-red-700' : 'text-slate-950'">{{ pct(kpiMoraPct) }}</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <!-- D) OPEX -->
                        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                            <div class="border-b bg-amber-50 px-5 py-3 flex items-center justify-between">
                                <h3 class="text-xs font-black uppercase tracking-wider text-amber-700">OPEX</h3>
                                <span class="font-black text-amber-800">{{ money(brGlobalGastosTotal) }}</span>
                            </div>
                            <table class="w-full text-sm">
                                <tbody>
                                    <tr v-if="brGlobalGastos.length" class="border-b"><td colspan="2" class="px-5 py-1.5 text-xs font-black uppercase tracking-wider text-slate-400 bg-slate-50">Principales conceptos</td></tr>
                                    <tr v-for="(g, i) in brGlobalGastos.slice(0, 6)" :key="g.concepto"
                                        :class="i % 2 === 0 ? '' : 'bg-slate-50/60'" class="border-b last:border-0">
                                        <td class="px-5 py-1.5 pl-8 text-slate-500 text-xs">{{ g.concepto }}</td>
                                        <td class="px-5 py-1.5 text-right text-xs font-semibold text-slate-700">{{ money(g.total) }}</td>
                                    </tr>
                                    <tr class="border-t-2 border-amber-200 bg-amber-50"><td class="px-5 py-2.5 font-black text-amber-900">OPEX total</td><td class="px-5 py-2.5 text-right font-black text-amber-900">{{ money(brGlobalGastosTotal) }}</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <!-- E) Nómina y Capital Humano -->
                        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                            <div class="border-b bg-blue-50 px-5 py-3 flex items-center justify-between">
                                <h3 class="text-xs font-black uppercase tracking-wider text-blue-700">Nómina y Capital Humano</h3>
                                <span class="font-black text-blue-800">{{ money(nomTotal) }}</span>
                            </div>
                            <table class="w-full text-sm">
                                <tbody>
                                    <tr class="border-b"><td class="px-5 py-2 text-slate-600 font-medium">Sueldos / Nómina</td><td class="px-5 py-2 text-right font-black text-slate-950">{{ money(nomNomina) }}</td></tr>
                                    <tr class="border-b bg-slate-50/60"><td class="px-5 py-2 text-slate-600 font-medium">Comisiones</td><td class="px-5 py-2 text-right font-black text-slate-950">{{ money(nomComis) }}</td></tr>
                                    <tr class="border-b"><td class="px-5 py-2 text-slate-600 font-medium">Vacaciones</td><td class="px-5 py-2 text-right font-black text-slate-950">{{ money(nomVac) }}</td></tr>
                                    <tr class="border-b bg-slate-50/60"><td class="px-5 py-2 text-slate-600 font-medium">Prima vacacional</td><td class="px-5 py-2 text-right font-black text-slate-950">{{ money(nomPrimaVac) }}</td></tr>
                                    <tr class="border-b"><td class="px-5 py-2 text-slate-600 font-medium">Bonos</td><td class="px-5 py-2 text-right font-black text-slate-950">{{ money(nomBonos) }}</td></tr>
                                    <tr class="border-b bg-slate-50/60"><td class="px-5 py-2 text-slate-600 font-medium">Bonos aceleradores</td><td class="px-5 py-2 text-right font-black text-slate-950">{{ money(nomBonosAcel) }}</td></tr>
                                    <tr class="border-b bg-slate-50"><td class="px-5 py-1.5 pl-5 text-slate-600 text-[11px] font-semibold uppercase tracking-wide" colspan="2">IMSS y gastos reales de empleados (sí afectan el total)</td></tr>
                                    <template v-for="(item, i) in [...nomImssRow, ...nomGastoEmpleado]" :key="'afecta-'+item.label">
                                        <tr :class="i % 2 === 0 ? '' : 'bg-slate-50/60'" class="border-b">
                                            <td class="px-5 py-1.5 pl-8 text-slate-600 text-xs">{{ item.label }}</td>
                                            <td class="px-5 py-1.5 text-right text-xs font-semibold text-slate-700">{{ money(item.value) }}</td>
                                        </tr>
                                    </template>
                                    <tr class="border-t-2 border-blue-200 bg-blue-50"><td class="px-5 py-2.5 font-black text-blue-900">Total Nómina y Capital Humano</td><td class="px-5 py-2.5 text-right font-black text-blue-900">{{ money(nomTotal) }}</td></tr>
                                    <tr class="border-b bg-slate-50/60"><td class="px-5 py-1.5 pl-5 text-slate-500 text-[11px] font-semibold uppercase tracking-wide" colspan="2">Deducciones NOI — solo informativas, NO afectan el total</td></tr>
                                    <template v-for="(item, i) in nomDeduccionesInformativas" :key="'ded-'+item.label">
                                        <tr :class="i % 2 === 0 ? '' : 'bg-slate-50/60'" class="border-b">
                                            <td class="px-5 py-1.5 pl-8 text-slate-500 text-xs">{{ item.label }}</td>
                                            <td class="px-5 py-1.5 text-right text-xs font-semibold text-slate-500">{{ money(item.value) }}</td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <!-- F) EBITDA -->
                        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                            <div class="border-b bg-indigo-50 px-5 py-3"><h3 class="text-xs font-black uppercase tracking-wider text-indigo-700">EBITDA — desglose</h3></div>
                            <table class="w-full text-sm">
                                <tbody>
                                    <tr class="border-b"><td class="px-5 py-2 text-slate-600 font-medium">Utilidad bruta</td><td class="px-5 py-2 text-right font-black text-emerald-700">{{ money(ingresoEbitdaBaseGlobal) }}</td></tr>
                                    <tr class="border-b bg-slate-50/60"><td class="px-5 py-2 text-slate-600 font-medium">− Gastos Totales</td><td class="px-5 py-2 text-right font-black text-slate-950">{{ money(gastosEbitdaTotal) }}</td></tr>
                                    <tr class="border-b"><td class="px-5 py-2 pl-8 text-slate-500 text-xs">OPEX</td><td class="px-5 py-2 text-right text-xs text-slate-700">{{ money(brGlobalGastosTotal) }}</td></tr>
                                    <tr class="border-b bg-slate-50/60"><td class="px-5 py-2 pl-8 text-slate-500 text-xs">Nómina y Capital Humano</td><td class="px-5 py-2 text-right text-xs text-slate-700">{{ money(nomTotal) }}</td></tr>
                                    <tr class="border-b-2 border-indigo-200 bg-indigo-50"><td class="px-5 py-2.5 font-black text-indigo-900">EBITDA</td><td class="px-5 py-2.5 text-right font-black text-lg" :class="utilidadGlobal < 0 ? 'text-red-700' : 'text-indigo-900'">{{ money(utilidadGlobal) }}</td></tr>
                                    <tr class="border-b bg-slate-50/60"><td class="px-5 py-2 text-slate-500 font-medium">Margen EBITDA</td><td class="px-5 py-2 text-right font-black" :class="margenEbitdaPct < 0 ? 'text-red-700' : 'text-slate-950'">{{ pct(margenEbitdaPct) }}</td></tr>
                                    <tr class="border-b"><td class="px-5 py-2 text-slate-600 font-medium">Excedente enviado a corporativo <span class="text-[10px] uppercase tracking-wide text-slate-400">(informativo)</span></td><td class="px-5 py-2 text-right font-black text-slate-950">{{ money(excGlobal) }}</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ── GRÁFICAS EBITDA / GASTOS / NÓMINA / RECUPERACIÓN ─────────── -->
                    <div class="grid gap-4 lg:grid-cols-2">
                        <ChartCard title="EBITDA — Utilidad bruta vs Gastos Totales" :series="ebitdaCompSeries" :options="ebitdaCompOptions" type="bar" :height="260" />
                        <ChartCard title="Gastos — OPEX vs Nómina vs Total" :series="gastosCompSeries" :options="gastosCompOptions" type="bar" :height="260" />
                    </div>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <ChartCard title="Nómina — Composición" :series="nomCompSeries" :options="nomCompOptions" type="donut" :height="260" />
                        <ChartCard title="OPEX por Concepto (Top 6)" :series="opexTopSeries" :options="opexTopOptions" type="donut" :height="260" />
                    </div>

                    <!-- ── GRÁFICAS DE DISTRIBUCIÓN ─────────────────────────────── -->
                    <div class="grid gap-4 lg:grid-cols-2">
                        <ChartCard title="Recuperación vs Colocación" :series="recColSeries" :options="recColOptions" type="donut" :height="240" />
                        <ChartCard title="Cartera vs Cartera vencida" :series="carteraDonutSeries" :options="carteraDonutOptions" type="donut" :height="240" />
                    </div>
                    <ChartCard title="Mora por bucket" :series="moraBucketSeries" :options="moraBucketOptions" type="donut" :height="260" />

                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                            <div class="border-b bg-slate-50 px-5 py-3"><h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Top sucursales por cartera</h3></div>
                            <table v-if="branchesFull.length" class="w-full text-sm">
                                <tbody>
                                    <tr v-for="b in [...branchesFull].sort((a,b)=>b.cartera-a.cartera).slice(0,6)" :key="b.nombre" class="border-b last:border-0 hover:bg-slate-50">
                                        <td class="px-4 py-2 font-bold">{{ b.nombre }}</td>
                                        <td class="px-4 py-2 text-right font-black">{{ money(b.cartera) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                            <EmptyState v-else class="m-4" title="Sin datos de sucursales" />
                        </div>
                        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                            <div class="border-b bg-slate-50 px-5 py-3"><h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Categoría por EBITDA</h3></div>
                            <table v-if="branchesFull.length" class="w-full text-sm">
                                <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                    <tr>
                                        <th class="px-4 py-2 text-left">Sucursal</th>
                                        <th class="px-4 py-2 text-right">EBITDA</th>
                                        <th class="px-4 py-2 text-center">Categoría</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="b in branchesFull" :key="b.nombre" class="border-t hover:bg-slate-50">
                                        <td class="px-4 py-2 font-bold">{{ b.nombre }}</td>
                                        <td class="px-4 py-2 text-right font-black" :class="b.ebitda < 0 ? 'text-red-700' : 'text-emerald-700'">{{ money(b.ebitda) }}</td>
                                        <td class="px-4 py-2 text-center"><EbitdaBadge :categoria="b.categoria" /></td>
                                    </tr>
                                </tbody>
                            </table>
                            <EmptyState v-else class="m-4" title="Sin datos de categoría EBITDA" />
                        </div>
                    </div>
                </div>

                <!-- ══════════ SUCURSALES ══════════ -->
                <div v-show="activeTab === 'sucursales'" class="space-y-5">
                    <template v-if="branchesFiltered.length">
                        <div class="grid gap-4 lg:grid-cols-3">
                            <ChartCard title="Ranking por recuperación" :series="rankingRecuperacionSeries" :options="rankingRecuperacionOptions" type="donut" :height="320" />
                            <ChartCard title="Ranking por cartera" :series="rankingCarteraSeries" :options="rankingCarteraOptions" type="donut" :height="320" />
                            <ChartCard title="EBITDA por sucursal" :series="rankingEbitdaSeries" :options="rankingEbitdaOptions" type="donut" :height="320" />
                        </div>
                        <div class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                    <tr>
                                        <th class="px-4 py-3 text-left">Sucursal</th>
                                        <th class="px-4 py-3 text-right">Valor cartera</th>
                                        <th class="px-4 py-3 text-right">Cartera vencida</th>
                                        <th class="px-4 py-3 text-right">Mora %</th>
                                        <th class="px-4 py-3 text-right">OPEX</th>
                                        <th class="px-4 py-3 text-right">Nómina</th>
                                        <th class="px-4 py-3 text-right">Bonos</th>
                                        <th class="px-4 py-3 text-right">EBITDA</th>
                                        <th class="px-4 py-3 text-right">Margen EBITDA</th>
                                        <th class="px-4 py-3 text-center">Categoría</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="b in branchesFiltered" :key="b.nombre" class="cursor-pointer border-t hover:bg-slate-50"
                                        :class="vfBranch === b.nombre ? 'bg-indigo-50' : ''" @click="vfBranch = vfBranch === b.nombre ? '' : b.nombre">
                                        <td class="px-4 py-2.5 font-bold">{{ b.nombre }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(b.cartera) }}</td>
                                        <td class="px-4 py-2.5 text-right" :class="b.vencida > 0 ? 'font-bold text-red-700' : ''">{{ money(b.vencida) }}</td>
                                        <td class="px-4 py-2.5 text-right font-bold" :class="b.mora > 25 ? 'text-red-700' : ''">{{ pct(b.mora) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(b.gastos) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(b.nomina) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(b.bonos) }}</td>
                                        <td class="px-4 py-2.5 text-right font-bold" :class="b.ebitda < 0 ? 'text-red-700' : 'text-emerald-700'">{{ money(b.ebitda) }}</td>
                                        <td class="px-4 py-2.5 text-right font-bold" :class="b.margenEbitda < 0 ? 'text-red-700' : 'text-slate-700'">{{ pct(b.margenEbitda) }}</td>
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
                        <ChartCard title="Colocación por producto" :series="colocacionProductoSeries" :options="colocacionProductoOptions" type="donut" :height="280" />
                        <ChartCard title="Colocación por sucursal" :series="colocacionSucursalSeries" :options="colocacionSucursalOptions" type="donut" :height="280" />
                    </div>
                    <div class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                        <div class="border-b bg-slate-50 px-5 py-3"><h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Ranking por producto (colocación)</h3></div>
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

                    <!-- A) Recuperación por componente -->
                    <div class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                        <div class="border-b bg-slate-50 px-5 py-3"><h3 class="text-xs font-black uppercase tracking-wider text-slate-500">A) Recuperación por componente</h3></div>
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                <tr><th class="px-4 py-3 text-left">Componente</th><th class="px-4 py-3 text-right">Monto</th><th class="px-4 py-3 text-right">% del total</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="c in [
                                        { label: 'Capital recuperado', value: ingrCapital },
                                        { label: 'Intereses', value: ingrInteres },
                                        { label: 'Impuestos', value: ingrImpuesto },
                                        { label: 'Moratorios / Multas', value: ingrMultas },
                                        { label: 'Cargos al inicio', value: ingrCargosIni },
                                        { label: 'Comisión por apertura', value: ingrComAper },
                                        { label: 'Cargos adicionales', value: ingrCargosAdic },
                                        { label: 'Excedentes recuperados', value: ingrExcedente },
                                        { label: 'Seguro CRECE reconocido (30%)', value: ingrCrece30 },
                                        ...ingrOtrosDetalle,
                                    ].filter(c => c.value !== 0)" :key="c.label" class="border-t hover:bg-slate-50">
                                    <td class="px-4 py-2.5 font-semibold">{{ c.label }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(c.value) }}</td>
                                    <td class="px-4 py-2.5 text-right text-slate-500">{{ recGlobal > 0 ? (c.value / recGlobal * 100).toFixed(1) : '0.0' }}%</td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-slate-100 font-black text-xs">
                                <tr><td class="px-4 py-2.5 uppercase tracking-wider">Total recuperación</td><td class="px-4 py-2.5 text-right">{{ money(recGlobal) }}</td><td class="px-4 py-2.5 text-right">100%</td></tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- B) Recuperación por sucursal -->
                    <div class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                        <div class="border-b bg-slate-50 px-5 py-3"><h3 class="text-xs font-black uppercase tracking-wider text-slate-500">B) Recuperación por sucursal</h3></div>
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th class="px-4 py-3 text-left">Sucursal</th><th class="px-4 py-3 text-right">Capital</th><th class="px-4 py-3 text-right">Intereses</th>
                                    <th class="px-4 py-3 text-right">Impuestos</th><th class="px-4 py-3 text-right">Moratorios</th><th class="px-4 py-3 text-right">Cargos adic.</th>
                                    <th class="px-4 py-3 text-right">Cargos inicio</th><th class="px-4 py-3 text-right">Com. apertura</th>
                                    <th class="px-4 py-3 text-right">Excedentes</th><th class="px-4 py-3 text-right">Seguro CRECE 30%</th>
                                    <th class="px-4 py-3 text-right">Otros</th><th class="px-4 py-3 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in recuperacionPorSucursal" :key="r.sucursal" class="border-t hover:bg-slate-50">
                                    <td class="px-4 py-2.5 font-bold">{{ r.sucursal }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(r.capital) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(r.interes) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(r.impuesto) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(r.moratorios) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(r.cargos_adicionales) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(r.cargos_inicio) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(r.comision_apertura) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(r.excedente) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(r.seguro_crece_30) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(r.otros) }}</td>
                                    <td class="px-4 py-2.5 text-right font-black">{{ money(r.total) }}</td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-slate-100 font-black text-xs">
                                <tr><td class="px-4 py-2.5 uppercase tracking-wider">Total</td><td colspan="10"></td><td class="px-4 py-2.5 text-right">{{ money(recGlobal) }}</td></tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- C) Recuperación por producto -->
                    <div class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                        <div class="border-b bg-slate-50 px-5 py-3"><h3 class="text-xs font-black uppercase tracking-wider text-slate-500">C) Recuperación por producto</h3></div>
                        <table v-if="recuperacionPorProducto.length" class="w-full text-sm">
                            <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th class="px-4 py-3 text-left">Producto</th><th class="px-4 py-3 text-right">Capital</th><th class="px-4 py-3 text-right">Intereses</th>
                                    <th class="px-4 py-3 text-right">Impuestos</th><th class="px-4 py-3 text-right">Moratorios</th><th class="px-4 py-3 text-right">Cargos adic.</th>
                                    <th class="px-4 py-3 text-right">Com. apertura</th><th class="px-4 py-3 text-right">Excedentes</th>
                                    <th class="px-4 py-3 text-right">Seguro CRECE 30%</th><th class="px-4 py-3 text-right">Otros</th><th class="px-4 py-3 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in recuperacionPorProducto" :key="p.producto" class="border-t hover:bg-slate-50">
                                    <td class="px-4 py-2.5 font-bold">{{ p.producto }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(p.capital) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(p.interes) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(p.impuesto) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(p.moratorios) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(p.cargos_adicionales) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(p.comision_apertura) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(p.excedente) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(p.seguro_crece_30) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(p.otros) }}</td>
                                    <td class="px-4 py-2.5 text-right font-black">{{ money(p.total) }}</td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-slate-100 font-black text-xs">
                                <tr><td class="px-4 py-2.5 uppercase tracking-wider">Total</td><td colspan="9"></td><td class="px-4 py-2.5 text-right">{{ money(recGlobal) }}</td></tr>
                            </tfoot>
                        </table>
                        <EmptyState v-else class="m-4" title="Sin datos de recuperación por producto" />
                    </div>
                </div>

                <!-- ══════════ GASTOS ══════════ -->
                <div v-show="activeTab === 'gastos'" class="space-y-5">
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <KpiCard label="OPEX Total" :value="money(kpiGastos)" :icon="Receipt" tone="amber" />
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <ChartCard title="Gastos por sucursal" :series="gastosPorSucursalSeries" :options="gastosPorSucursalOptions" type="donut" :height="300" />
                        <ChartCard title="Top categorías de gasto" :series="gastosPorCategoriaSeries" :options="gastosPorCategoriaOptions" type="donut" :height="300" />
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

                    <!-- ── Fondeos entre sucursales operativas ── -->
                    <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                        <div class="border-b bg-slate-50 px-5 py-3 flex items-center justify-between">
                            <div>
                                <h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Fondeos entre sucursales operativas</h3>
                            </div>
                            <span class="text-xs font-black text-slate-700">{{ money(fondeoOperTotal) }}</span>
                        </div>
                        <div v-if="fondeoOperDetalle.length" class="overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead>
                                    <tr class="border-b bg-slate-50/60 text-slate-500">
                                        <th class="px-4 py-2 text-left font-bold">Fecha</th>
                                        <th class="px-4 py-2 text-left font-bold">Fondea (origen)</th>
                                        <th class="px-4 py-2 text-left font-bold">Recibe (destino)</th>
                                        <th class="px-4 py-2 text-right font-bold">Monto</th>
                                        <th class="px-4 py-2 text-left font-bold">Observación</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(f, i) in fondeoOperDetalle" :key="i" class="border-b last:border-0" :class="i % 2 === 0 ? 'bg-white' : 'bg-slate-50/50'">
                                        <td class="px-4 py-1.5 text-slate-500">{{ f.fecha || '—' }}</td>
                                        <td class="px-4 py-1.5 text-slate-700 font-medium">{{ f.sucursal_origen }}</td>
                                        <td class="px-4 py-1.5 text-slate-700 font-medium">{{ f.sucursal_destino }}</td>
                                        <td class="px-4 py-1.5 text-right font-semibold text-slate-800">{{ money(f.monto) }}</td>
                                        <td class="px-4 py-1.5 text-slate-500 text-xs">{{ f.observacion || '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <EmptyState v-else class="m-4" title="Sin fondeos operativos en este periodo" />
                    </div>

                    <!-- ── Excedentes / envío a CORPORATIVO ── -->
                    <div class="rounded-2xl border bg-white shadow-sm overflow-hidden" v-if="excedentesTotal > 0 || excedentesDetalle.length">
                        <div class="border-b bg-amber-50 px-5 py-3 flex items-center justify-between">
                            <div>
                                <h3 class="text-xs font-black uppercase tracking-wider text-amber-700">Excedente enviado a corporativo</h3>
                                <p class="text-xs text-amber-600 mt-0.5">Movimientos de efectivo enviados a corporativo — no afectan EBITDA ni OPEX</p>
                            </div>
                            <span class="text-xs font-black text-amber-800">{{ money(excedentesTotal) }}</span>
                        </div>
                        <div v-if="excedentesDetalle.length" class="overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead>
                                    <tr class="border-b bg-amber-50/60 text-amber-700">
                                        <th class="px-4 py-2 text-left font-bold">Fecha</th>
                                        <th class="px-4 py-2 text-left font-bold">Sucursal origen</th>
                                        <th class="px-4 py-2 text-left font-bold">Destino</th>
                                        <th class="px-4 py-2 text-right font-bold">Monto</th>
                                        <th class="px-4 py-2 text-left font-bold">Observación</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(f, i) in excedentesDetalle" :key="i" class="border-b last:border-0" :class="i % 2 === 0 ? 'bg-white' : 'bg-amber-50/30'">
                                        <td class="px-4 py-1.5 text-slate-500">{{ f.fecha || '—' }}</td>
                                        <td class="px-4 py-1.5 text-slate-700 font-medium">{{ f.sucursal_origen }}</td>
                                        <td class="px-4 py-1.5 font-semibold text-amber-700">{{ f.destino }}</td>
                                        <td class="px-4 py-1.5 text-right font-semibold text-slate-800">{{ money(f.monto) }}</td>
                                        <td class="px-4 py-1.5 text-slate-500 text-xs">{{ f.observacion || '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                        <div class="border-b bg-slate-50 px-5 py-3">
                            <h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Seguros y coberturas canalizadas</h3>
                        </div>
                        <table class="w-full text-sm">
                            <tbody>
                                <tr class="border-b"><td class="px-5 py-2.5 text-slate-600 font-medium">Cobertura Savehearts</td><td class="px-5 py-2.5 text-right font-black text-slate-950">{{ money(segurosSaveheartsBruto) }}</td></tr>
                                <tr class="border-b bg-slate-50/60"><td class="px-5 py-2.5 text-slate-600 font-medium">Cobertura Crédito Grupal / Comadres</td><td class="px-5 py-2.5 text-right font-black text-slate-950">{{ money(segurosComadresBruto) }}</td></tr>
                                <tr class="border-b"><td class="px-5 py-2.5 text-slate-600 font-medium">Seguro CRECE total</td><td class="px-5 py-2.5 text-right font-black text-slate-950">{{ money(segurosCreceBruto) }}</td></tr>
                                <tr class="border-b bg-slate-50/60"><td class="px-5 py-2.5 text-slate-600 font-medium">Seguro CRECE reconocido como ingreso MR Lana (30%)</td><td class="px-5 py-2.5 text-right font-black text-emerald-700">{{ money(segurosCrece30) }}</td></tr>
                                <tr class="border-b"><td class="px-5 py-2.5 text-slate-600 font-medium">Seguro CRECE canalizado a aseguradora (70%)</td><td class="px-5 py-2.5 text-right font-black text-slate-950">{{ money(segurosCrece70) }}</td></tr>
                                <tr class="border-b-2 border-indigo-200 bg-indigo-50"><td class="px-5 py-2.5 font-black text-indigo-900">Total canalizado a aseguradora</td><td class="px-5 py-2.5 text-right font-black text-indigo-900">{{ money(segurosPuenteTotal) }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ══════════ NÓMINA ══════════ -->
                <div v-show="activeTab === 'nomina'" class="space-y-5">
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <KpiCard label="Nómina y Capital Humano" :value="money(kpiNomina)" :icon="Wallet" tone="blue" />
                        <KpiCard label="Sueldos" :value="money(nomNomina)" tone="teal" />
                        <KpiCard label="Comisiones" :value="money(nomComis)" tone="teal" />
                        <KpiCard label="Bonos" :value="money(nomBonos)" tone="teal" />
                    </div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <KpiCard label="Percepciones" :value="money(noiPercepciones)" tone="teal" />
                        <KpiCard label="Deducciones" :value="money(noiDeducciones)" tone="amber" />
                        <KpiCard label="Neto pagado a trabajadores" :value="money(noiNetoPagado)" tone="blue" />
                    </div>
                    <ChartCard title="Nómina por sucursal" :series="nominaPorSucursalSeries" :options="nominaPorSucursalOptions" type="donut" :height="320" />

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
                                            <td class="px-8 py-1.5 text-slate-600">
                                                {{ c.concepto }}
                                                <span v-if="NOI_DEDUCTION_LABELS.has(c.concepto)" class="ml-1.5 text-[10px] uppercase tracking-wide text-slate-400">(informativo, no afecta)</span>
                                            </td>
                                            <td class="px-5 py-1.5 text-right font-semibold" :class="NOI_DEDUCTION_LABELS.has(c.concepto) ? 'text-slate-500' : 'text-slate-700'">
                                                {{ money(c.total) }}
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
                        <ChartCard title="Mora por bucket" :series="moraBucketSeries" :options="moraBucketOptions" type="donut" :height="260" />
                    </div>
                    <ChartCard title="Top sucursales con más cartera vencida" :series="topVencidaSeries" :options="topVencidaOptions" type="donut" :height="300" />

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

                    <!-- Desglose por componente -->
                    <div class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                        <div class="border-b bg-slate-50 px-5 py-3"><h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Desglose por componente — mora global</h3></div>
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th class="px-4 py-3 text-left">Bucket</th>
                                    <th class="px-4 py-3 text-right">Capital atrasado</th>
                                    <th class="px-4 py-3 text-right">Interés atrasado</th>
                                    <th class="px-4 py-3 text-right">Impuesto atrasado</th>
                                    <th class="px-4 py-3 text-right">S. Interés moratorio</th>
                                    <th class="px-4 py-3 text-right">S. Imp. moratorio</th>
                                    <th class="px-4 py-3 text-right">Total bucket</th>
                                    <th class="px-4 py-3 text-right">% Mora</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="b in moraComponentes" :key="b.key" class="border-t hover:bg-slate-50">
                                    <td class="px-4 py-2.5 font-semibold">{{ b.label }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(b.capital) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(b.interes) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(b.impuesto) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(b.moratorio) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(b.imp_moratorio) }}</td>
                                    <td class="px-4 py-2.5 text-right font-bold text-red-700">{{ money(b.total) }}</td>
                                    <td class="px-4 py-2.5 text-right text-slate-600">{{ b.pct.toFixed(1) }}%</td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-slate-100 font-black text-xs">
                                <tr>
                                    <td class="px-4 py-2.5 uppercase tracking-wider">Total mora</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(moraTotalesComponentes.capital) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(moraTotalesComponentes.interes) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(moraTotalesComponentes.impuesto) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(moraTotalesComponentes.moratorio) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ money(moraTotalesComponentes.imp_moratorio) }}</td>
                                    <td class="px-4 py-2.5 text-right text-red-700">{{ money(moraTotalesComponentes.total) }}</td>
                                    <td class="px-4 py-2.5 text-right">100%</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- ══════════ EFECTIVIDAD DE COBRANZA ══════════ -->
                <div v-show="activeTab === 'cobranza'" class="space-y-5">
                    <template v-if="ecData">
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <KpiCard label="Total cobrado" :value="money(ecTotal.total)" :icon="Banknote" tone="teal" />
                            <KpiCard label="Cobros vigentes" :value="money(ecData?.vigente?.total ?? 0)" :icon="CheckCircle2" tone="green" />
                            <KpiCard label="Cobros en atraso" :value="money((ecData?.atrasado?.total ?? 0) + (ecData?.vencido?.total ?? 0))" :icon="AlertTriangle" tone="amber" />
                            <KpiCard label="Cobros vencidos" :value="money(ecData?.vencido?.total ?? 0)" :icon="AlertTriangle" tone="red" />
                        </div>

                        <div class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                            <div class="border-b bg-slate-50 px-5 py-3"><h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Cobranza por estatus del crédito</h3></div>
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                    <tr>
                                        <th class="px-4 py-3 text-left">Estatus</th>
                                        <th class="px-4 py-3 text-right">Contratos</th>
                                        <th class="px-4 py-3 text-right">Capital</th>
                                        <th class="px-4 py-3 text-right">Interés</th>
                                        <th class="px-4 py-3 text-right">Impuesto</th>
                                        <th class="px-4 py-3 text-right">Moratorios</th>
                                        <th class="px-4 py-3 text-right">Total</th>
                                        <th class="px-4 py-3 text-right">% Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="s in ecStatus" :key="s.key" class="border-t hover:bg-slate-50">
                                        <td class="px-4 py-2.5 font-semibold">{{ s.label }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ num(s.contratos ?? 0) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(s.capital ?? 0) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(s.interes ?? 0) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(s.impuesto ?? 0) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(s.moratorios ?? 0) }}</td>
                                        <td class="px-4 py-2.5 text-right font-bold" :class="s.key === 'vencido' ? 'text-red-700' : s.key === 'vigente' ? 'text-emerald-700' : ''">{{ money(s.total ?? 0) }}</td>
                                        <td class="px-4 py-2.5 text-right text-slate-600">{{ ecTotal.total > 0 ? ((s.total ?? 0) / ecTotal.total * 100).toFixed(1) : '0.0' }}%</td>
                                    </tr>
                                </tbody>
                                <tfoot class="bg-slate-100 font-black text-xs">
                                    <tr>
                                        <td class="px-4 py-2.5 uppercase tracking-wider">Total</td>
                                        <td class="px-4 py-2.5 text-right">{{ num(ecTotal.contratos) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(ecTotal.capital) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(ecTotal.interes) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(ecTotal.impuesto) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(ecTotal.moratorios) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(ecTotal.total) }}</td>
                                        <td class="px-4 py-2.5 text-right">100%</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <div class="rounded-2xl border bg-amber-50 px-5 py-4 text-sm text-amber-800">
                            <strong>Nota metodológica:</strong> Los cobros se clasifican por los días de atraso del crédito al momento del cobro.
                            No incluye seguros ni coberturas canalizadas — mismas reglas que la recuperación total.
                        </div>
                    </template>
                    <EmptyState v-else title="Sin datos de efectividad de cobranza" description="Genera el reporte para ver esta sección." />
                </div>

                <!-- ══════════ PRODUCTOS ══════════ -->
                <div v-show="activeTab === 'productos'" class="space-y-5">
                    <template v-if="productosRows.length">
                        <ChartCard title="Colocación por producto" :series="colocacionProductoSeries" :options="colocacionProductoOptions" type="donut" :height="300" />
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

                <!-- ══════════ FONDEOS / EXCEDENTES ══════════ -->
                <div v-show="activeTab === 'fondeos'" class="space-y-5">
                    <!-- KPIs fondeo -->
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <KpiCard label="Fondeos entre sucursales" :value="money(fondeoDetalleTotal)" :icon="Landmark" tone="blue" />
                        <KpiCard label="Excedente enviado a corporativo" :value="money(excGlobal)" :icon="Banknote" tone="amber" />
                        <KpiCard label="Total movimientos" :value="num(fondeoDetalleRows.length)" :icon="Receipt" tone="neutral" />
                    </div>
                    <p class="rounded-xl bg-blue-50 px-4 py-2.5 text-xs text-blue-700 border border-blue-100">
                        Los fondeos entre sucursales son movimientos de liquidez — <strong>no afectan el EBITDA ni el OPEX</strong>. Los excedentes enviados a corporativo se muestran como dato informativo.
                    </p>
                    <!-- Gráfica fondeos por origen -->
                    <div v-if="fondeosPorOrigen.length" class="grid gap-4 lg:grid-cols-2">
                        <ChartCard title="Fondeos por sucursal origen" :series="fondeosPorOrigenSeries" :options="fondeosPorOrigenOptions" type="donut" :height="280" />
                        <div class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                    <tr><th class="px-4 py-3 text-left">Sucursal origen</th><th class="px-4 py-3 text-right">Total fondeado</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="r in fondeosPorOrigen" :key="r.sucursal" class="border-t hover:bg-slate-50">
                                        <td class="px-4 py-2.5 font-bold">{{ r.sucursal }}</td>
                                        <td class="px-4 py-2.5 text-right font-semibold">{{ money(r.monto) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <!-- Excedentes por sucursal -->
                    <div v-if="corpFundingRows.length" class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                        <div class="flex items-center justify-between px-5 py-3 border-b bg-amber-50/60">
                            <span class="font-bold text-sm text-amber-800">Excedente enviado a corporativo por sucursal</span>
                            <span class="font-black text-sm text-amber-900">{{ money(excGlobal) }}</span>
                        </div>
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                <tr><th class="px-4 py-3 text-left">Sucursal</th><th class="px-4 py-3 text-right">Total enviado</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in corpFundingRows" :key="r.branch" class="border-t hover:bg-slate-50">
                                    <td class="px-4 py-2.5 font-bold">{{ r.branch }}</td>
                                    <td class="px-4 py-2.5 text-right font-semibold text-amber-700">{{ money(r.total) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- Detalle completo fondeos -->
                    <div v-if="fondeoDetalleRows.length" class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                        <div class="flex items-center justify-between px-5 py-3 border-b">
                            <span class="font-bold text-sm">Detalle de fondeos entre sucursales</span>
                            <span class="font-black text-sm">{{ money(fondeoDetalleTotal) }}</span>
                        </div>
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th class="px-4 py-3 text-left">Fecha</th>
                                    <th class="px-4 py-3 text-left">Origen</th>
                                    <th class="px-4 py-3 text-left">Destino</th>
                                    <th class="px-4 py-3 text-left">Responsable</th>
                                    <th class="px-4 py-3 text-right">Monto</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(f, i) in fondeoDetalleRows" :key="i" class="border-t hover:bg-slate-50" :class="i % 2 === 0 ? 'bg-white' : 'bg-slate-50/40'">
                                    <td class="px-4 py-2 text-slate-500 text-xs">{{ f.fecha ?? '—' }}</td>
                                    <td class="px-4 py-2 font-semibold">{{ f.sucursal_origen ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ f.sucursal_destino ?? '—' }}</td>
                                    <td class="px-4 py-2 text-xs text-slate-600">{{ f.responsable ?? '—' }}</td>
                                    <td class="px-4 py-2 text-right font-semibold">{{ money(f.monto) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <EmptyState v-if="!fondeoDetalleRows.length && !corpFundingRows.length" title="Sin fondeos ni excedentes registrados" description="No hay movimientos de fondeo ni envíos a corporativo para este periodo." />
                </div>

                <!-- ══════════ ROTACIÓN DE PERSONAL ══════════ -->
                <div v-show="activeTab === 'rotacion'" class="space-y-5">
                    <template v-if="rotacionData">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-bold text-slate-800">Rotación de personal</h3>
                        </div>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <KpiCard label="Altas del periodo" :value="num(rotacionAltas)" :icon="TrendingUp" tone="green" />
                            <KpiCard label="Bajas del periodo" :value="num(rotacionBajas)" :icon="AlertTriangle" tone="red" />
                            <KpiCard label="Plantilla" :value="num(rotacionPromedio)" :icon="Building2" tone="blue" />
                            <KpiCard label="Índice de rotación" :value="fmtPercent(rotacionIndice)" :icon="Percent" :tone="rotacionIndice > 5 ? 'red' : rotacionIndice > 2 ? 'amber' : 'teal'" />
                        </div>
                        <div v-if="rotacionPorSucursal.length" class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                            <div class="px-5 py-3 border-b">
                                <span class="font-bold text-sm">Rotación de personal por sucursal</span>
                            </div>
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                    <tr>
                                        <th class="px-4 py-3 text-left">Sucursal</th>
                                        <th class="px-4 py-3 text-right">Altas</th>
                                        <th class="px-4 py-3 text-right">Bajas</th>
                                        <th class="px-4 py-3 text-right">Plantilla</th>
                                        <th class="px-4 py-3 text-right">Índice de rotación</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(r, i) in rotacionPorSucursal" :key="i" class="border-t hover:bg-slate-50" :class="i % 2 === 0 ? 'bg-white' : 'bg-slate-50/40'">
                                        <td class="px-4 py-2.5 font-bold">{{ r.sucursal }}</td>
                                        <td class="px-4 py-2.5 text-right text-emerald-700 font-medium">{{ r.altas ?? 0 }}</td>
                                        <td class="px-4 py-2.5 text-right text-red-700 font-medium">{{ r.bajas }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ Number(r.promedio_personal).toFixed(0) }}</td>
                                        <td class="px-4 py-2.5 text-right font-semibold" :class="Number(r.indice_rotacion) > 5 ? 'text-red-700' : Number(r.indice_rotacion) > 2 ? 'text-amber-600' : 'text-emerald-700'">
                                            {{ Number(r.indice_rotacion).toFixed(2) }}%
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="text-sm text-slate-500 italic text-center py-4">Sin desglose por sucursal disponible para este periodo.</p>

                        <div v-if="rotacionDetalleMensual.length" class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                            <div class="px-5 py-3 border-b">
                                <span class="font-bold text-sm">Detalle mensual del periodo consolidado</span>
                            </div>
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                    <tr>
                                        <th class="px-4 py-3 text-left">Mes</th>
                                        <th class="px-4 py-3 text-right">Altas</th>
                                        <th class="px-4 py-3 text-right">Bajas</th>
                                        <th class="px-4 py-3 text-right">Plantilla</th>
                                        <th class="px-4 py-3 text-right">Índice</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(d, i) in rotacionDetalleMensual" :key="i" class="border-t hover:bg-slate-50" :class="i % 2 === 0 ? 'bg-white' : 'bg-slate-50/40'">
                                        <td class="px-4 py-2.5 font-bold">{{ d.mes }}</td>
                                        <td class="px-4 py-2.5 text-right text-emerald-700 font-medium">{{ d.altas }}</td>
                                        <td class="px-4 py-2.5 text-right text-red-700 font-medium">{{ d.bajas }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ Number(d.plantilla).toFixed(0) }}</td>
                                        <td class="px-4 py-2.5 text-right font-semibold">{{ Number(d.indice).toFixed(2) }}%</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="rotacionDetalle" class="rounded-2xl border bg-white shadow-sm">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between px-5 py-3.5"
                                @click="rotacionAuditoriaAbierta = !rotacionAuditoriaAbierta"
                            >
                                <span class="font-bold text-sm text-slate-700">Auditoría de rotación</span>
                                <ChevronDown v-if="!rotacionAuditoriaAbierta" class="size-4 text-slate-400" />
                                <ChevronUp v-else class="size-4 text-slate-400" />
                            </button>
                            <div v-show="rotacionAuditoriaAbierta" class="border-t px-5 py-4 space-y-5">
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div>
                                        <p class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">
                                            Altas ({{ rotacionAltasLista.length }})
                                        </p>
                                        <div class="max-h-64 overflow-y-auto rounded-xl border">
                                            <table class="w-full text-xs">
                                                <tbody>
                                                    <tr v-for="(e, i) in rotacionAltasLista" :key="'alta-'+i" class="border-t first:border-t-0" :class="i % 2 === 0 ? 'bg-white' : 'bg-slate-50/60'">
                                                        <td class="px-3 py-1.5 font-medium text-emerald-700">{{ e.nombre }}</td>
                                                        <td class="px-3 py-1.5 text-right text-slate-500">{{ e.sucursal }}</td>
                                                    </tr>
                                                    <tr v-if="!rotacionAltasLista.length"><td class="px-3 py-3 text-center text-slate-400 italic">Sin altas en el periodo</td></tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div>
                                        <p class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">
                                            Bajas ({{ rotacionBajasLista.length }})
                                        </p>
                                        <div class="max-h-64 overflow-y-auto rounded-xl border">
                                            <table class="w-full text-xs">
                                                <tbody>
                                                    <tr v-for="(e, i) in rotacionBajasLista" :key="'baja-'+i" class="border-t first:border-t-0" :class="i % 2 === 0 ? 'bg-white' : 'bg-slate-50/60'">
                                                        <td class="px-3 py-1.5 font-medium text-red-700">{{ e.nombre }}</td>
                                                        <td class="px-3 py-1.5 text-right text-slate-500">{{ e.sucursal }}</td>
                                                    </tr>
                                                    <tr v-if="!rotacionBajasLista.length"><td class="px-3 py-3 text-center text-slate-400 italic">Sin bajas en el periodo</td></tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div>
                                        <p class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">
                                            Plantilla {{ rotacionMesAnteriorLabel }} ({{ rotacionMesAnteriorLista.length }})
                                        </p>
                                        <div class="max-h-64 overflow-y-auto rounded-xl border">
                                            <table class="w-full text-xs">
                                                <tbody>
                                                    <tr v-for="(e, i) in rotacionMesAnteriorLista" :key="'prev-'+i" class="border-t first:border-t-0" :class="i % 2 === 0 ? 'bg-white' : 'bg-slate-50/60'">
                                                        <td class="px-3 py-1.5">{{ e.nombre }}</td>
                                                        <td class="px-3 py-1.5 text-right text-slate-500">{{ e.sucursal }}</td>
                                                    </tr>
                                                    <tr v-if="!rotacionMesAnteriorLista.length"><td class="px-3 py-3 text-center text-slate-400 italic">Sin datos del periodo anterior</td></tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div>
                                        <p class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">
                                            Plantilla {{ rotacionMesActualLabel }} ({{ rotacionMesActualLista.length }})
                                        </p>
                                        <div class="max-h-64 overflow-y-auto rounded-xl border">
                                            <table class="w-full text-xs">
                                                <tbody>
                                                    <tr v-for="(e, i) in rotacionMesActualLista" :key="'curr-'+i" class="border-t first:border-t-0" :class="i % 2 === 0 ? 'bg-white' : 'bg-slate-50/60'">
                                                        <td class="px-3 py-1.5">{{ e.nombre }}</td>
                                                        <td class="px-3 py-1.5 text-right text-slate-500">{{ e.sucursal }}</td>
                                                    </tr>
                                                    <tr v-if="!rotacionMesActualLista.length"><td class="px-3 py-3 text-center text-slate-400 italic">Sin datos del periodo actual</td></tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                    <EmptyState v-else title="Sin datos de rotación" description="Aún no hay información de rotación para este periodo." />
                </div>

                <!-- ══════════ CATEGORÍA EBITDA ══════════ -->
                <div v-show="activeTab === 'categoria'" class="space-y-5">
                    <template v-if="branchesFull.length">
                        <div class="grid gap-4 lg:grid-cols-3">
                            <ChartCard title="Distribución por categoría" :series="categoriaDonutSeries" :options="categoriaDonutOptions" type="donut" :height="280" class="lg:col-span-1" />
                            <ChartCard title="EBITDA por sucursal" :series="ebitdaPorSucursalSeries" :options="ebitdaPorSucursalOptions" type="donut" :height="280" class="lg:col-span-2" />
                        </div>
                        <div class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                    <tr>
                                        <th class="px-4 py-3 text-left">Sucursal</th>
                                        <th class="px-4 py-3 text-right">Recuperación</th>
                                        <th class="px-4 py-3 text-right">Colocación</th>
                                        <th class="px-4 py-3 text-right">OPEX</th>
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
                                        <td class="px-4 py-2.5 text-right">{{ money(b.gastos) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ money(b.nomina) }}</td>
                                        <td class="px-4 py-2.5 text-right font-black" :class="b.ebitda < 0 ? 'text-red-700' : 'text-emerald-700'">{{ money(b.ebitda) }}</td>
                                        <td class="px-4 py-2.5 text-center"><EbitdaBadge :categoria="b.categoria" /></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="text-xs italic text-slate-400">EBITDA = Utilidad bruta (intereses + impuestos + moratorios + comisión por apertura + cargos adicionales + excedentes + 30% Seguro CRECE) − Gastos Totales (OPEX + Nómina y Capital Humano). No incluye capital recuperado.</p>
                    </template>
                    <EmptyState v-else title="Sin datos para calcular categoría EBITDA" />
                </div>

                <!-- ══════════ GESTORES ══════════ -->
                <div v-show="activeTab === 'gestores'" class="space-y-5">
                    <template v-if="empGest.length">
                        <ChartCard v-if="topGestoresColocacion.length" title="Ranking de gestores por colocación" :series="rankingGestoresSeries" :options="rankingGestoresOptions" type="donut" :height="280" />

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
                                        <td class="px-3 py-2 text-slate-600 whitespace-nowrap"><span :class="e.branch === 'Sin sucursal' ? 'text-amber-600 font-semibold' : ''">{{ e.branch === 'Sin sucursal' ? '—' : e.branch }}</span></td>
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
                    <EmptyState v-else title="Sin datos de gestores" description="Verifica que el archivo de nómina fue procesado para este periodo." />
                </div>

            </div>
        </template>
    </div>
</template>
