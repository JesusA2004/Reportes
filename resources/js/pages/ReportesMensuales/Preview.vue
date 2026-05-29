<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ArrowLeft, AlertTriangle, FileSpreadsheet, FileText, Search, ChevronDown, ChevronUp, Download } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'

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
}>()

// ── Filtered export config ───────────────────────────────────────────────────
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

// Pre-populate filter from URL query params (e.g. from "Ver reporte completo" with scope)
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

type TabKey = 'resumen' | 'radiografia' | 'productos' | 'sucursales' | 'empleados' | 'cartera' | 'gastos' | 'incidencias'
const activeTab = ref<TabKey>('resumen')

const snap    = computed(() => props.snapshot)
const sum     = computed(() => snap.value?.summary ?? {})
const pay     = computed(() => snap.value?.sections?.payroll ?? {})
const inc     = computed(() => snap.value?.sections?.incidents ?? [])
const charts  = computed(() => snap.value?.charts ?? {})
const highInc = computed(() => inc.value.filter((i: any) => i.severity === 'high').length)

// Gastos: nuevo formato expandido
const gastos  = computed(() => snap.value?.sections?.expenses_detail ?? {})
const gastosTotal      = computed(() => gastos.value?.total ?? sum.value?.expenses_total ?? 0)
const gastosByCategory = computed(() => gastos.value?.byCategory ?? [])
const gastosByConcept  = computed(() => gastos.value?.byConcept ?? [])
const gastosByBranch   = computed(() => gastos.value?.byBranch ?? [])
const gastosByEmployee = computed(() => gastos.value?.byEmployee ?? [])
const gastosBySource   = computed(() => gastos.value?.bySource ?? [])

// Branch radiography — calculator-sourced, same source as Excel
const branchRadiography = computed(() => snap.value?.branch_radiography ?? null)
const brGlobal = computed(() => branchRadiography.value?.global ?? null)

// ── Sección 2: Ingresos ──────────────────────────────────────────────────────
const ingrCapital   = computed(() => Number(brGlobal.value?.capital_recuperado)  || 0)
const ingrInteres   = computed(() => Number(brGlobal.value?.interes_recuperado)  || 0)
const ingrImpuesto  = computed(() => Number(brGlobal.value?.impuesto_recuperado) || 0)
const ingrMultas    = computed(() => Number(brGlobal.value?.charges)             || 0)
const ingrCargosIni = computed(() => Number(brGlobal.value?.cargos_inicio)       || 0)
const ingrComAper   = computed(() => Number(brGlobal.value?.comision_apertura)   || 0)
const ingrTotal     = computed(() => ingrCapital.value + ingrInteres.value + ingrImpuesto.value + ingrMultas.value + ingrCargosIni.value + ingrComAper.value)

// ── Sección 4: Nómina ────────────────────────────────────────────────────────
const nomNomina   = computed(() => Number(brGlobal.value?.nomina_total)    || 0)
const nomComis    = computed(() => Number(brGlobal.value?.comisiones)      || 0)
const nomVac      = computed(() => Number(brGlobal.value?.vacaciones)      || 0)
const nomPrimaVac = computed(() => Number(brGlobal.value?.prima_vacacional)|| 0)
const nomBonos    = computed(() => Number(brGlobal.value?.bonos)           || 0)

const NOM_DETALLE_ORDER = ['IMSS','Descuentos Infonavit','Finiquito','Gastos médicos','Gasolina','Financiamiento de Motos','Financiamiento de Motos (desc.)','Descuento Servicios Moto','Financiamiento Celular','Cascos','Descuento de uniformes','Pensión Alimenticia','Préstamo Personal','Anticipo de nómina','Otros conceptos nómina','Otros descuentos NOI']

const nomDetalle = computed<{ label: string; value: number }[]>(() => {
    const det = brGlobal.value?.nomina_detalle as Record<string, number> | undefined
    if (!det) return []
    const rows: { label: string; value: number }[] = []
    const seen = new Set<string>()
    for (const label of NOM_DETALLE_ORDER) {
        const v = Number(det[label]) || 0
        if (v > 0) { rows.push({ label, value: v }); seen.add(label) }
    }
    for (const [label, raw] of Object.entries(det)) {
        if (!seen.has(label) && Number(raw) > 0) rows.push({ label, value: Number(raw) })
    }
    return rows
})

const nomTotal = computed(() => {
    const base = nomNomina.value + nomComis.value + nomVac.value + nomPrimaVac.value + nomBonos.value
    return base + nomDetalle.value.reduce((s, r) => s + r.value, 0)
})

// ── Sección 5: Préstamos intersucursales ─────────────────────────────────────
const fondeoGlobal = computed(() => Number(brGlobal.value?.prestamos_fondea) || 0)

// ── Sección 7: Análisis ──────────────────────────────────────────────────────
const recGlobal   = computed(() => Number(brGlobal.value?.recuperacion_total) || 0)
const colGlobal   = computed(() => Number(brGlobal.value?.colocacion)         || 0)
const carteraGlobal = computed(() => Number(brGlobal.value?.valor_cartera)    || 0)
const excGlobal   = computed(() => Number(brGlobal.value?.excedentes)         || 0)
const mora0_30g   = computed(() => Number(brGlobal.value?.mora_0_30)          || 0)
const mora31_60g  = computed(() => Number(brGlobal.value?.mora_31_60)         || 0)
const mora61_90g  = computed(() => Number(brGlobal.value?.mora_61_90)         || 0)
const mora91_120g = computed(() => Number(brGlobal.value?.mora_91_120)        || 0)

const mora120plusG  = computed(() => Number(brGlobal.value?.mora_120_plus)       || 0)
const moraTotalGlobal = computed(() => mora0_30g.value + mora31_60g.value + mora61_90g.value + mora91_120g.value + mora120plusG.value)

// ── Sección 8: Utilidad ──────────────────────────────────────────────────────
const utilidadGlobal = computed(() => recGlobal.value - brGlobalGastosTotal.value - nomTotal.value)

// ── KPI primario (branch_radiography → fallback summary) ─────────────────────
const kpiRec     = computed(() => brGlobal.value ? recGlobal.value     : Number(snap.value?.summary?.recovery_total ?? 0))
const kpiCol     = computed(() => brGlobal.value ? colGlobal.value     : Number(snap.value?.summary?.placement_total ?? 0))
const kpiCartera = computed(() => brGlobal.value ? carteraGlobal.value : Number(snap.value?.summary?.portfolio_total ?? 0))
const kpiMora    = computed(() => brGlobal.value ? moraTotalGlobal.value : Number(snap.value?.summary?.overdue_portfolio ?? 0))
const kpiMoraPct = computed(() => kpiCartera.value > 0 ? kpiMora.value / kpiCartera.value * 100 : Number(snap.value?.summary?.mora_index ?? 0))
const kpiGastos  = computed(() => brGlobal.value ? brGlobalGastosTotal.value : Number(snap.value?.summary?.expenses_total ?? 0))
const kpiNomina  = computed(() => nomTotal.value || Number(snap.value?.summary?.net_payroll ?? 0))
const kpiUtil    = computed(() => brGlobal.value ? utilidadGlobal.value : 0)
const kpiEmp     = computed(() => Number(snap.value?.summary?.employees_count ?? 0))

const sucursalesRows = computed(() => {
    const branches = branchRadiography.value?.branches
    if (branches?.length) {
        return (branches as any[]).map(b => {
            const moraSum = (Number(b.mora_0_30)||0) + (Number(b.mora_31_60)||0) + (Number(b.mora_61_90)||0) + (Number(b.mora_91_120)||0) + (Number(b.mora_120_plus)||0)
            const cartVal = Number(b.valor_cartera) || 0
            return {
                nombre: b.sucursal,
                recuperacion: b.recuperacion_total,
                colocacion: b.colocacion,
                cartera: cartVal,
                vencida: moraSum,
                mora: cartVal > 0 ? moraSum / cartVal * 100 : 0,
                gastos: b.gastos_operativos,
                nomina: (Number(b.nomina_total)||0) + (Number(b.comisiones)||0) + (Number(b.bonos)||0),
            }
        })
    }
    return (snap.value?.sections?.branches ?? []) as any[]
})

const brGlobalGastos = computed(() => {
    const det = branchRadiography.value?.global?.gastos_detalle as Record<string, number> | undefined
    if (!det) return []
    return Object.entries(det)
        .map(([concepto, total]) => ({ concepto, total: Number(total) }))
        .filter(c => c.total > 0)
        .sort((a, b) => b.total - a.total)
})
const brGlobalGastosTotal = computed(() => Number(branchRadiography.value?.global?.gastos_operativos) || 0)

// Empleados/Gestores fusionados
const empGest = computed(() => snap.value?.sections?.employees_gestores ?? [])

// Filtros empleados
const searchEmp     = ref('')
const filterBranch  = ref('')
const branchOptions = computed(() => props.branches.map(b => b.name).sort())
const filteredEmp = computed(() => {
    let rows = empGest.value
    if (searchEmp.value.trim()) {
        const q = searchEmp.value.trim().toLowerCase()
        rows = rows.filter((r: any) =>
            (r.name ?? '').toLowerCase().includes(q) ||
            (r.branch ?? '').toLowerCase().includes(q)
        )
    }
    if (filterBranch.value) {
        rows = rows.filter((r: any) => r.branch === filterBranch.value)
    }
    return rows
})
const showAllEmp = ref(false)
const empVisible = computed(() => showAllEmp.value ? filteredEmp.value : filteredEmp.value.slice(0, 15))

const money = (v: number) =>
    new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', maximumFractionDigits: 0 }).format(Number(v || 0))
const moneyFull = (v: number) =>
    new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(v || 0))
const pct  = (v: number) => Number(v || 0).toFixed(2) + '%'
const num  = (v: number) => new Intl.NumberFormat('es-MX').format(Number(v || 0))

const tabs: { key: TabKey; label: string; badge?: string }[] = [
    { key: 'resumen',      label: 'Resumen' },
    { key: 'radiografia',  label: 'Radiografía' },
    { key: 'productos',    label: 'Productos' },
    { key: 'sucursales',   label: 'Sucursales' },
    { key: 'empleados',    label: 'Empleados / Gestores' },
    { key: 'cartera',      label: 'Cartera y mora' },
    { key: 'gastos',       label: 'Gastos' },
    { key: 'incidencias',  label: 'Incidencias' },
]
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
        <div v-if="!snap" class="mx-auto max-w-screen-2xl px-6 py-20 text-center">
            <AlertTriangle class="mx-auto mb-4 size-12 text-amber-500" />
            <h2 class="text-xl font-black text-slate-800">Sin radiografía generada</h2>
            <p class="mt-2 text-sm text-slate-500">Genera la radiografía en Histórico General para ver el reporte completo.</p>
        </div>

        <template v-else>
            <!-- KPI CARDS -->
            <div class="mx-auto max-w-screen-2xl px-6 py-5">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
                    <div class="rounded-2xl border bg-white p-4 shadow-sm hover:shadow-md transition">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Empleados</p>
                        <p class="mt-1 text-xl font-black text-slate-950">{{ num(kpiEmp) }}</p>
                    </div>
                    <div class="rounded-2xl border bg-white p-4 shadow-sm hover:shadow-md transition">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Recuperación</p>
                        <p class="mt-1 text-xl font-black text-slate-950">{{ money(kpiRec) }}</p>
                    </div>
                    <div class="rounded-2xl border bg-white p-4 shadow-sm hover:shadow-md transition">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Colocación</p>
                        <p class="mt-1 text-xl font-black text-slate-950">{{ money(kpiCol) }}</p>
                    </div>
                    <div class="rounded-2xl border bg-white p-4 shadow-sm hover:shadow-md transition"
                         :class="kpiMoraPct > 25 ? 'border-red-200 bg-red-50' : ''">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Mora %</p>
                        <p class="mt-1 text-xl font-black" :class="kpiMoraPct > 25 ? 'text-red-700' : 'text-slate-950'">{{ pct(kpiMoraPct) }}</p>
                    </div>
                    <div class="rounded-2xl border bg-white p-4 shadow-sm hover:shadow-md transition">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Cartera</p>
                        <p class="mt-1 text-xl font-black text-slate-950">{{ money(kpiCartera) }}</p>
                    </div>
                    <div class="rounded-2xl border bg-white p-4 shadow-sm hover:shadow-md transition"
                         :class="kpiMora > 0 ? 'border-red-200 bg-red-50' : ''">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Mora total</p>
                        <p class="mt-1 text-xl font-black" :class="kpiMora > 0 ? 'text-red-700' : 'text-slate-950'">{{ money(kpiMora) }}</p>
                    </div>
                    <div class="rounded-2xl border bg-white p-4 shadow-sm hover:shadow-md transition">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Nómina</p>
                        <p class="mt-1 text-xl font-black text-slate-950">{{ money(kpiNomina) }}</p>
                    </div>
                    <div class="rounded-2xl border bg-white p-4 shadow-sm hover:shadow-md transition"
                         :class="kpiUtil < 0 ? 'border-red-200 bg-red-50' : 'border-emerald-200 bg-emerald-50'">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">EBITDA</p>
                        <p class="mt-1 text-xl font-black" :class="kpiUtil < 0 ? 'text-red-700' : 'text-emerald-800'">{{ money(kpiUtil) }}</p>
                    </div>
                </div>
            </div>

            <!-- FILTERED EXPORT PANEL -->
            <div class="mx-auto max-w-screen-2xl px-6 pb-2">
                <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                    <button @click="showFilteredPanel = !showFilteredPanel"
                            class="flex w-full items-center justify-between px-5 py-3.5 text-sm font-bold text-slate-700 hover:bg-slate-50 transition">
                        <span class="flex items-center gap-2">
                            <Download class="size-4 text-indigo-500" />
                            Reportes filtrados (por sucursal, gestor o comparativo)
                        </span>
                        <ChevronDown v-if="!showFilteredPanel" class="size-4 text-slate-400" />
                        <ChevronUp v-else class="size-4 text-slate-400" />
                    </button>

                    <div v-if="showFilteredPanel" class="border-t px-5 py-4 space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">

                            <!-- Tipo de reporte -->
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Tipo de reporte</label>
                                <select v-model="filteredType"
                                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100">
                                    <option value="simple">Simple</option>
                                    <option value="month_vs_month">Mes vs Mes</option>
                                    <option value="bimester_vs_bimester">Bimestre vs Bimestre</option>
                                    <option value="quarter_vs_quarter">Trimestre vs Trimestre</option>
                                </select>
                            </div>

                            <!-- Alcance (solo simple) -->
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Alcance</label>
                                <select v-model="filteredScope"
                                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100">
                                    <option value="general">General</option>
                                    <option value="branch">Por sucursal</option>
                                    <option value="employee">Por gestor</option>
                                </select>
                            </div>

                            <!-- Periodo a comparar (comparativos) -->
                            <div v-if="isComparative">
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Periodo a comparar</label>
                                <select v-model="filteredComparePeriodId"
                                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100">
                                    <option :value="null">— Seleccionar —</option>
                                    <option v-for="p in comparePeriodOptions" :key="p.id" :value="p.id" :disabled="!p.has_snapshot">
                                        {{ p.label }}{{ !p.has_snapshot ? ' (sin radiografía)' : '' }}
                                    </option>
                                </select>
                                <p v-if="comparePeriodOptions.length === 0" class="mt-1 text-xs text-amber-600">
                                    No hay periodos del mismo tipo disponibles para comparar.
                                </p>
                            </div>

                            <!-- Sucursal -->
                            <div v-if="filteredScope === 'branch'">
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Sucursal</label>
                                <select v-model="filteredBranchId"
                                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100">
                                    <option :value="null">— Seleccionar —</option>
                                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                                </select>
                            </div>

                            <!-- Gestor -->
                            <div v-if="filteredScope === 'employee'">
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Gestor / Empleado</label>
                                <select v-model="filteredEmployeeId"
                                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100">
                                    <option :value="null">— Seleccionar —</option>
                                    <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
                                </select>
                            </div>

                        </div>

                        <!-- Gasto extra (solo empleado) -->
                        <div v-if="filteredScope === 'employee'" class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Gasto general asignado ($)</label>
                                <input v-model="filteredExtraAmount" type="number" min="0" step="0.01"
                                       placeholder="0.00"
                                       class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Notas del gasto</label>
                                <input v-model="filteredExtraNotes" type="text" placeholder="Descripción del gasto asignado…"
                                       class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100" />
                            </div>
                        </div>

                        <!-- Buttons -->
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
                                <span v-else-if="isComparative && filteredComparePeriodId && !allPeriods.find((p: any) => p.id === filteredComparePeriodId)?.has_snapshot">El periodo seleccionado no tiene radiografía generada.</span>
                                <span v-else-if="filteredScope === 'branch'">Selecciona una sucursal.</span>
                                <span v-else-if="filteredScope === 'employee'">Selecciona un gestor.</span>
                            </p>
                        </div>

                        <!-- Filtered preview panel -->
                        <div v-if="filteredLoading" class="mt-3 text-xs text-slate-500 italic">Cargando datos…</div>
                        <div v-else-if="filteredFetchError" class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                            {{ filteredFetchError }}
                        </div>
                        <div v-else-if="filteredPreview?.data" class="mt-3 rounded-xl border bg-slate-50 px-4 py-3">
                            <p class="mb-2 text-xs font-black uppercase tracking-wider text-slate-500">
                                {{ filteredPreview.scope === 'employee' ? 'Gestor' : 'Sucursal' }}:
                                {{ filteredPreview.label }}
                                <span v-if="filteredPreview.data.branch" class="font-normal text-slate-400"> · {{ filteredPreview.data.branch }}</span>
                                <span v-if="filteredPreview.data.route" class="font-normal text-slate-400"> · {{ filteredPreview.data.route }}</span>
                            </p>
                            <div class="grid grid-cols-2 gap-x-8 gap-y-1 text-sm sm:grid-cols-3 lg:grid-cols-6">
                                <div v-for="(row, i) in (filteredPreview.scope === 'employee' ? [
                                    ['Recuperación',   money(filteredPreview.data.recuperacion)],
                                    ['Colocación',     money(filteredPreview.data.colocacion)],
                                    ['Operaciones',    num(filteredPreview.data.operaciones ?? 0)],
                                    ['Cartera',        money(filteredPreview.data.cartera)],
                                    ['Mora total',     money(filteredPreview.data.mora_total)],
                                    ['Mora %',         pct(filteredPreview.data.mora_pct)],
                                    ['Nómina (pagos)', money(filteredPreview.data.pagos)],
                                    ['Bonos',          money(filteredPreview.data.bonos)],
                                    ['Descuentos',     money(filteredPreview.data.descuentos)],
                                    ['Neto nómina',    money(filteredPreview.data.neto)],
                                ] : [
                                    ['Recuperación',   money(filteredPreview.data.recuperacion)],
                                    ['Colocación',     money(filteredPreview.data.colocacion)],
                                    ['Cartera',        money(filteredPreview.data.cartera)],
                                    ['Mora total',     money(filteredPreview.data.mora_total)],
                                    ['Mora %',         pct(filteredPreview.data.mora_pct)],
                                    ['Gastos Op.',     money(filteredPreview.data.gastos)],
                                    ['Nómina',         money(filteredPreview.data.nomina)],
                                ])" :key="i">
                                    <div class="text-xs text-slate-500">{{ row[0] }}</div>
                                    <div class="font-black text-slate-900">{{ row[1] }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TABS + CONTENT -->
            <div class="mx-auto max-w-screen-2xl px-6 pb-12">

                <!-- Tab bar -->
                <div class="mb-5 flex flex-wrap gap-1 border-b border-slate-200">
                    <button v-for="t in tabs" :key="t.key" @click="activeTab = t.key"
                        class="relative px-4 py-2.5 text-sm font-bold transition border-b-2"
                        :class="activeTab === t.key ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-800'">
                        {{ t.label }}
                        <span v-if="t.key === 'incidencias' && highInc > 0"
                              class="ml-1.5 rounded-full bg-red-100 px-1.5 py-0.5 text-xs font-black text-red-700">{{ highInc }}</span>
                    </button>
                </div>

                <!-- ══════════ RESUMEN ══════════ -->
                <div v-show="activeTab === 'resumen'" class="space-y-5">

                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="rounded-2xl border bg-white p-5 shadow-sm">
                            <h3 class="mb-3 text-xs font-black uppercase tracking-wider text-slate-500">Métricas financieras</h3>
                            <table class="w-full text-sm">
                                <tr v-for="row in [
                                    ['Recuperación total',   money(kpiRec)],
                                    ['Colocación total',     money(kpiCol)],
                                    ['Cartera total',        money(kpiCartera)],
                                    ['Mora total',           money(kpiMora)],
                                    ['Índice de mora',       pct(kpiMoraPct)],
                                    ['Gastos operativos',    money(kpiGastos)],
                                    ['EBITDA del periodo', money(kpiUtil)],
                                ]" :key="row[0]" class="border-b last:border-0">
                                    <td class="py-2 text-slate-600 font-medium">{{ row[0] }}</td>
                                    <td class="py-2 text-right font-black text-slate-950">{{ row[1] }}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="rounded-2xl border bg-white p-5 shadow-sm">
                            <h3 class="mb-3 text-xs font-black uppercase tracking-wider text-slate-500">Nómina y capital humano</h3>
                            <table class="w-full text-sm">
                                <tr v-for="row in [
                                    ['Total empleados',       num(kpiEmp)],
                                    ['Nómina (sueldos)',      money(nomNomina)],
                                    ['Comisiones',            money(nomComis)],
                                    ['Vacaciones',            money(nomVac)],
                                    ['Prima vacacional',      money(nomPrimaVac)],
                                    ['Bonos',                 money(nomBonos)],
                                    ['Total nómina completa', money(nomTotal)],
                                ]" :key="row[0]" class="border-b last:border-0">
                                    <td class="py-2 text-slate-600 font-medium">{{ row[0] }}</td>
                                    <td class="py-2 text-right font-black text-slate-950">{{ row[1] }}</td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Gráficas resumen -->
                    <div class="grid gap-4 lg:grid-cols-2">

                        <!-- Colocación por producto -->
                        <div v-if="charts.placement_by_product?.length" class="rounded-2xl border bg-white p-5 shadow-sm">
                            <h3 class="mb-4 text-xs font-black uppercase tracking-wider text-slate-500">Colocación por producto</h3>
                            <div class="space-y-2">
                                <div v-for="bar in charts.placement_by_product" :key="bar.label" class="flex items-center gap-3 text-sm">
                                    <div class="w-24 shrink-0 truncate text-xs font-semibold text-slate-600">{{ bar.label }}</div>
                                    <div class="flex-1 rounded-full bg-slate-100 h-4 overflow-hidden">
                                        <div class="h-full rounded-full bg-indigo-500 transition-all" :style="{ width: bar.pct + '%' }"></div>
                                    </div>
                                    <div class="w-24 shrink-0 text-right text-xs font-black text-slate-800">{{ money(bar.value) }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- Top gestores por colocación -->
                        <div v-if="charts.top_promoters_placement?.length" class="rounded-2xl border bg-white p-5 shadow-sm">
                            <h3 class="mb-4 text-xs font-black uppercase tracking-wider text-slate-500">Top gestores · Colocación</h3>
                            <div class="space-y-2">
                                <div v-for="bar in charts.top_promoters_placement" :key="bar.label" class="flex items-center gap-3 text-sm">
                                    <div class="w-28 shrink-0 truncate text-xs font-semibold text-slate-600">{{ bar.label }}</div>
                                    <div class="flex-1 rounded-full bg-slate-100 h-4 overflow-hidden">
                                        <div class="h-full rounded-full bg-emerald-500 transition-all" :style="{ width: bar.pct + '%' }"></div>
                                    </div>
                                    <div class="w-24 shrink-0 text-right text-xs font-black text-slate-800">{{ money(bar.value) }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- Cartera vencida por sucursal -->
                        <div v-if="charts.portfolio_by_branch?.length" class="rounded-2xl border bg-white p-5 shadow-sm">
                            <h3 class="mb-4 text-xs font-black uppercase tracking-wider text-slate-500">Cartera por sucursal</h3>
                            <div class="space-y-2">
                                <div v-for="bar in charts.portfolio_by_branch" :key="bar.label" class="flex items-center gap-3 text-sm">
                                    <div class="w-24 shrink-0 truncate text-xs font-semibold text-slate-600">{{ bar.label }}</div>
                                    <div class="flex-1 rounded-full bg-slate-100 h-4 overflow-hidden">
                                        <div class="h-full rounded-full bg-sky-500 transition-all" :style="{ width: bar.pct + '%' }"></div>
                                    </div>
                                    <div class="w-24 shrink-0 text-right text-xs font-black text-slate-800">{{ money(bar.value) }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- Mora por bucket -->
                        <div v-if="charts.mora_by_bucket?.length" class="rounded-2xl border bg-white p-5 shadow-sm">
                            <h3 class="mb-4 text-xs font-black uppercase tracking-wider text-slate-500">Mora por días vencidos</h3>
                            <div class="space-y-2">
                                <div v-for="bar in charts.mora_by_bucket" :key="bar.label" class="flex items-center gap-3 text-sm">
                                    <div class="w-24 shrink-0 truncate text-xs font-semibold text-slate-600">{{ bar.label }}</div>
                                    <div class="flex-1 rounded-full bg-slate-100 h-4 overflow-hidden">
                                        <div class="h-full rounded-full transition-all"
                                             :class="bar.label === 'Al corriente' ? 'bg-emerald-400' : 'bg-red-500'"
                                             :style="{ width: bar.pct + '%' }"></div>
                                    </div>
                                    <div class="w-24 shrink-0 text-right text-xs font-black text-slate-800">{{ money(bar.value) }}</div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ══════════ RADIOGRAFÍA ══════════ -->
                <div v-show="activeTab === 'radiografia'" class="space-y-5">

                    <div v-if="!brGlobal" class="rounded-2xl border bg-amber-50 p-6 text-sm text-amber-700">
                        Sin datos de radiografía. Genera el informe primero.
                    </div>

                    <template v-else>

                        <!-- 1. MÉTRICAS GENERALES -->
                        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                            <div class="bg-indigo-700 px-5 py-2.5">
                                <span class="text-xs font-black uppercase tracking-wider text-white">1. Métricas Generales</span>
                            </div>
                            <table class="w-full text-sm">
                                <tbody>
                                    <tr v-for="(row, i) in [
                                        ['Valor de cartera',               moneyFull(carteraGlobal)],
                                        ['Otorgamientos',                   moneyFull(colGlobal)],
                                        ['Recuperación total',              moneyFull(recGlobal)],
                                        ['Mora 0 – 30 días',               moneyFull(mora0_30g)],
                                        ['Mora 31 – 60 días',              moneyFull(mora31_60g)],
                                        ['Mora 61 – 90 días',              moneyFull(mora61_90g)],
                                        ['Mora 91 – 120 días',             moneyFull(mora91_120g)],
                                        ['Mora total',                     moneyFull(mora0_30g + mora31_60g + mora61_90g + mora91_120g)],
                                        ['Envío de utilidad a corporativo', moneyFull(excGlobal)],
                                    ]" :key="row[0]"
                                        class="border-b last:border-0"
                                        :class="i % 2 === 0 ? 'bg-white' : 'bg-slate-50'">
                                        <td class="px-5 py-2 text-slate-600 font-medium">{{ row[0] }}</td>
                                        <td class="px-5 py-2 text-right font-black text-slate-950">{{ row[1] }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- 2. INGRESOS -->
                        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                            <div class="bg-indigo-700 px-5 py-2.5">
                                <span class="text-xs font-black uppercase tracking-wider text-white">2. Ingresos</span>
                            </div>
                            <table class="w-full text-sm">
                                <tbody>
                                    <tr v-for="(row, i) in ([
                                        ['Capital recuperado',     ingrCapital,   ingrCapital > 0],
                                        ['Intereses recuperados',  ingrInteres,   ingrInteres > 0],
                                        ['Impuestos recuperados',  ingrImpuesto,  ingrImpuesto > 0],
                                        ['Multas / cargos',        ingrMultas,    ingrMultas > 0],
                                        ['Cargos al inicio',       ingrCargosIni, ingrCargosIni > 0],
                                        ['Comisión apertura',      ingrComAper,   ingrComAper > 0],
                                    ] as [string, number, boolean][]).filter(r => r[2])"
                                        :key="row[0]"
                                        class="border-b last:border-0"
                                        :class="i % 2 === 0 ? 'bg-white' : 'bg-slate-50'">
                                        <td class="px-5 py-2 text-slate-600 font-medium">{{ row[0] }}</td>
                                        <td class="px-5 py-2 text-right font-black text-slate-950">{{ moneyFull(row[1]) }}</td>
                                    </tr>
                                    <tr class="bg-indigo-50 border-t-2 border-indigo-200">
                                        <td class="px-5 py-2 font-black text-indigo-900">Total ingresos</td>
                                        <td class="px-5 py-2 text-right font-black text-indigo-900">{{ moneyFull(ingrTotal) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- 3. GASTOS OPERATIVOS -->
                        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                            <div class="bg-indigo-700 px-5 py-2.5">
                                <span class="text-xs font-black uppercase tracking-wider text-white">3. Gastos Operativos</span>
                            </div>
                            <div v-if="!brGlobalGastos.length" class="px-5 py-4 text-sm text-slate-400">Sin gastos registrados.</div>
                            <table v-else class="w-full text-sm">
                                <tbody>
                                    <tr v-for="(c, i) in brGlobalGastos" :key="c.concepto"
                                        class="border-b last:border-0"
                                        :class="i % 2 === 0 ? 'bg-white' : 'bg-slate-50'">
                                        <td class="px-5 py-2 text-slate-600 font-medium">{{ c.concepto }}</td>
                                        <td class="px-5 py-2 text-right font-black text-slate-950">{{ moneyFull(c.total) }}</td>
                                    </tr>
                                    <tr class="bg-indigo-50 border-t-2 border-indigo-200">
                                        <td class="px-5 py-2 font-black text-indigo-900">Total gastos operativos</td>
                                        <td class="px-5 py-2 text-right font-black text-indigo-900">{{ moneyFull(brGlobalGastosTotal) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- 4. NÓMINA Y CAPITAL HUMANO -->
                        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                            <div class="bg-indigo-700 px-5 py-2.5">
                                <span class="text-xs font-black uppercase tracking-wider text-white">4. Nómina y Capital Humano</span>
                            </div>
                            <table class="w-full text-sm">
                                <tbody>
                                    <tr v-for="(row, i) in ([
                                        ['Nómina',           nomNomina,   nomNomina > 0],
                                        ['Comisiones',       nomComis,    nomComis > 0],
                                        ['Vacaciones',       nomVac,      nomVac > 0],
                                        ['Prima vacacional', nomPrimaVac, nomPrimaVac > 0],
                                        ['Bonos',            nomBonos,    nomBonos > 0],
                                    ] as [string, number, boolean][]).filter(r => r[2])"
                                        :key="row[0]"
                                        class="border-b last:border-0"
                                        :class="i % 2 === 0 ? 'bg-white' : 'bg-slate-50'">
                                        <td class="px-5 py-2 text-slate-600 font-medium">{{ row[0] }}</td>
                                        <td class="px-5 py-2 text-right font-black text-slate-950">{{ moneyFull(row[1]) }}</td>
                                    </tr>
                                    <tr v-for="(d, j) in nomDetalle" :key="d.label"
                                        class="border-b last:border-0"
                                        :class="(j + 5) % 2 === 0 ? 'bg-white' : 'bg-slate-50'">
                                        <td class="px-5 py-2 text-slate-600 font-medium pl-8">{{ d.label }}</td>
                                        <td class="px-5 py-2 text-right font-black text-slate-950">{{ moneyFull(d.value) }}</td>
                                    </tr>
                                    <tr class="bg-indigo-50 border-t-2 border-indigo-200">
                                        <td class="px-5 py-2 font-black text-indigo-900">Total nómina y capital humano</td>
                                        <td class="px-5 py-2 text-right font-black text-indigo-900">{{ moneyFull(nomTotal) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- 5. PRÉSTAMOS INTERSUCURSALES -->
                        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                            <div class="bg-indigo-700 px-5 py-2.5">
                                <span class="text-xs font-black uppercase tracking-wider text-white">5. Préstamos Intersucursales</span>
                            </div>
                            <table class="w-full text-sm">
                                <tbody>
                                    <tr v-for="(row, i) in [
                                        ['Activos (fondea)',  moneyFull(fondeoGlobal)],
                                        ['Pasivos (recibe)', moneyFull(fondeoGlobal)],
                                        ['Total',            moneyFull(fondeoGlobal)],
                                    ]" :key="row[0]"
                                        class="border-b last:border-0"
                                        :class="i % 2 === 0 ? 'bg-white' : 'bg-slate-50'">
                                        <td class="px-5 py-2 text-slate-600 font-medium">{{ row[0] }}</td>
                                        <td class="px-5 py-2 text-right font-black text-slate-950">{{ row[1] }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- 6. ÍNDICE DE ROTACIÓN -->
                        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                            <div class="bg-indigo-700 px-5 py-2.5">
                                <span class="text-xs font-black uppercase tracking-wider text-white">6. Índice de Rotación de Personal</span>
                            </div>
                            <table class="w-full text-sm">
                                <tbody>
                                    <tr v-for="(row, i) in [
                                        ['N° de personas que dejaron la empresa', '—'],
                                        ['Promedio de personas en el periodo',    num(snap?.summary?.employees_count ?? 0)],
                                        ['Índice de rotación',                   '—'],
                                    ]" :key="row[0]"
                                        class="border-b last:border-0"
                                        :class="i % 2 === 0 ? 'bg-white' : 'bg-slate-50'">
                                        <td class="px-5 py-2 text-slate-600 font-medium">{{ row[0] }}</td>
                                        <td class="px-5 py-2 text-right font-black text-slate-950">{{ row[1] }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- 7. ANÁLISIS DE TENDENCIAS -->
                        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                            <div class="bg-indigo-700 px-5 py-2.5">
                                <span class="text-xs font-black uppercase tracking-wider text-white">7. Análisis de Tendencias y Proyecciones</span>
                            </div>
                            <table class="w-full text-sm">
                                <tbody>
                                    <tr v-for="(row, i) in [
                                        ['Saldo inicial en caja',             moneyFull(0)],
                                        ['Ingresos totales',                  moneyFull(recGlobal)],
                                        ['Otorgamientos',                     moneyFull(colGlobal)],
                                        ['Gastos totales',                    moneyFull(brGlobalGastosTotal)],
                                        ['EBITDA',                            moneyFull(utilidadGlobal)],
                                        ['Saldo final en caja',               moneyFull(0)],
                                        ['Préstamos inter sucursales',        moneyFull(fondeoGlobal)],
                                        ['Envío de utilidad a corporativo',   moneyFull(excGlobal)],
                                        ['Diferencia',                        moneyFull(0)],
                                        ['Mora de 0 a 30 días',               moneyFull(mora0_30g)],
                                        ['Mora de 31 a 60 días',              moneyFull(mora31_60g)],
                                        ['Mora de 61 a 90 días',              moneyFull(mora61_90g)],
                                        ['Mora de 91 a 120 días',             moneyFull(mora91_120g)],
                                        ['Mora 120+ días',                    moneyFull(mora120plusG)],
                                        ['Valor cartera',                     moneyFull(carteraGlobal)],
                                    ]" :key="row[0]"
                                        class="border-b last:border-0"
                                        :class="i % 2 === 0 ? 'bg-white' : 'bg-slate-50'">
                                        <td class="px-5 py-2 text-slate-600 font-medium">{{ row[0] }}</td>
                                        <td class="px-5 py-2 text-right font-black text-slate-950">{{ row[1] }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- 8. EBITDA -->
                        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                            <div class="bg-indigo-700 px-5 py-2.5">
                                <span class="text-xs font-black uppercase tracking-wider text-white">8. EBITDA</span>
                            </div>
                            <table class="w-full text-sm">
                                <tbody>
                                    <tr class="bg-white border-b">
                                        <td class="px-5 py-2 text-slate-600 font-medium">Recuperación total</td>
                                        <td class="px-5 py-2 text-right font-black text-slate-950">{{ moneyFull(recGlobal) }}</td>
                                    </tr>
                                    <tr class="bg-slate-50 border-b">
                                        <td class="px-5 py-2 text-slate-600 font-medium">Menos: Gastos operativos</td>
                                        <td class="px-5 py-2 text-right font-black text-slate-950">{{ moneyFull(brGlobalGastosTotal) }}</td>
                                    </tr>
                                    <tr class="bg-white border-b">
                                        <td class="px-5 py-2 text-slate-600 font-medium">Menos: Nómina y capital humano</td>
                                        <td class="px-5 py-2 text-right font-black text-slate-950">{{ moneyFull(nomTotal) }}</td>
                                    </tr>
                                    <tr :class="utilidadGlobal >= 0 ? 'bg-emerald-50 border-t-2 border-emerald-300' : 'bg-red-50 border-t-2 border-red-300'">
                                        <td class="px-5 py-2 font-black" :class="utilidadGlobal >= 0 ? 'text-emerald-900' : 'text-red-900'">EBITDA del periodo</td>
                                        <td class="px-5 py-2 text-right font-black text-xl" :class="utilidadGlobal >= 0 ? 'text-emerald-900' : 'text-red-900'">{{ moneyFull(utilidadGlobal) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- 9. SALDO TOTAL ACUMULADO -->
                        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                            <div class="bg-indigo-700 px-5 py-2.5">
                                <span class="text-xs font-black uppercase tracking-wider text-white">9. Saldo Total Acumulado Cuentas</span>
                            </div>
                            <div class="px-5 py-4 text-sm text-slate-400 italic">Sin fuente de caja en BD — dato a llenar manualmente.</div>
                        </div>

                        <!-- 10. OBSERVACIONES -->
                        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                            <div class="bg-indigo-700 px-5 py-2.5">
                                <span class="text-xs font-black uppercase tracking-wider text-white">10. Observaciones y Notas</span>
                            </div>
                            <div class="px-5 py-4 space-y-3">
                                <div class="h-8 rounded border border-dashed border-slate-200 bg-slate-50"></div>
                                <div class="h-8 rounded border border-dashed border-slate-200 bg-slate-50"></div>
                                <div class="h-8 rounded border border-dashed border-slate-200 bg-slate-50"></div>
                            </div>
                        </div>

                    </template>
                </div>

                <!-- ══════════ PRODUCTOS ══════════ -->
                <div v-show="activeTab === 'productos'">
                    <div v-if="!snap.sections?.products?.length" class="rounded-2xl border bg-white p-10 text-center text-sm text-slate-400">
                        Sin datos de producto. Verifica que el archivo de ministraciones incluya la columna de producto financiero.
                    </div>
                    <div v-else class="space-y-4">
                        <!-- Barras de colocación por producto -->
                        <div class="rounded-2xl border bg-white p-5 shadow-sm">
                            <h3 class="mb-4 text-xs font-black uppercase tracking-wider text-slate-500">Colocación por producto</h3>
                            <div class="space-y-3">
                                <div v-for="p in snap.sections.products" :key="p.producto" class="grid grid-cols-[1fr_auto] items-center gap-3">
                                    <div>
                                        <div class="mb-1 flex items-center justify-between text-xs">
                                            <span class="font-bold text-slate-800">{{ p.producto }}</span>
                                            <span class="text-slate-500">{{ p.pct }}% · {{ num(p.operaciones) }} ops</span>
                                        </div>
                                        <div class="h-3 w-full overflow-hidden rounded-full bg-slate-100">
                                            <div class="h-full rounded-full bg-indigo-500 transition-all" :style="{ width: p.pct + '%' }"></div>
                                        </div>
                                    </div>
                                    <div class="text-right text-sm font-black text-slate-950 w-28">{{ money(p.colocacion) }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- Tabla detalle -->
                        <div class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                    <tr>
                                        <th class="px-4 py-3 text-left">Producto</th>
                                        <th class="px-4 py-3 text-right">Operaciones</th>
                                        <th class="px-4 py-3 text-right">Colocación</th>
                                        <th class="px-4 py-3 text-right">Recuperación</th>
                                        <th class="px-4 py-3 text-right">Cartera</th>
                                        <th class="px-4 py-3 text-right">Contratos</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="p in snap.sections.products" :key="p.producto" class="border-t hover:bg-slate-50">
                                        <td class="px-4 py-2.5 font-bold">{{ p.producto }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ num(p.operaciones) }}</td>
                                        <td class="px-4 py-2.5 text-right font-black">{{ moneyFull(p.colocacion) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ moneyFull(p.recuperacion) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ moneyFull(p.cartera) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ num(p.contratos) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ══════════ SUCURSALES ══════════ -->
                <div v-show="activeTab === 'sucursales'">
                    <div v-if="!sucursalesRows.length" class="rounded-2xl border bg-white p-10 text-center text-sm text-slate-400">Sin datos por sucursal.</div>
                    <div v-else class="space-y-4">
                        <!-- Gráfica cartera por sucursal -->
                        <div class="rounded-2xl border bg-white p-5 shadow-sm">
                            <h3 class="mb-4 text-xs font-black uppercase tracking-wider text-slate-500">Cartera por sucursal</h3>
                            <div class="space-y-2">
                                <div v-for="b in sucursalesRows" :key="b.nombre" class="flex items-center gap-3">
                                    <div class="w-28 shrink-0 truncate text-xs font-semibold text-slate-700">{{ b.nombre }}</div>
                                    <div class="flex-1 rounded-full bg-slate-100 h-4 overflow-hidden">
                                        <div class="h-full rounded-full bg-sky-500 transition-all"
                                             :style="{ width: (sucursalesRows[0]?.cartera > 0 ? Math.min(100, b.cartera / sucursalesRows[0].cartera * 100) : 0) + '%' }"></div>
                                    </div>
                                    <div class="w-24 shrink-0 text-right text-xs font-black text-slate-800">{{ money(b.cartera) }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                    <tr>
                                        <th class="px-4 py-3 text-left">Sucursal</th>
                                        <th class="px-4 py-3 text-right">Recuperación</th>
                                        <th class="px-4 py-3 text-right">Colocación</th>
                                        <th class="px-4 py-3 text-right">Cartera</th>
                                        <th class="px-4 py-3 text-right">Mora $</th>
                                        <th class="px-4 py-3 text-right">Mora %</th>
                                        <th class="px-4 py-3 text-right">Gastos Op.</th>
                                        <th class="px-4 py-3 text-right">Nómina</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="b in sucursalesRows" :key="b.nombre" class="border-t hover:bg-slate-50">
                                        <td class="px-4 py-2.5 font-bold">{{ b.nombre }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ moneyFull(b.recuperacion) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ moneyFull(b.colocacion) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ moneyFull(b.cartera) }}</td>
                                        <td class="px-4 py-2.5 text-right font-bold" :class="b.vencida > 0 ? 'text-red-700' : ''">{{ moneyFull(b.vencida) }}</td>
                                        <td class="px-4 py-2.5 text-right font-bold" :class="b.mora > 25 ? 'text-red-700' : ''">{{ pct(b.mora) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ moneyFull(b.gastos) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ b.nomina != null ? moneyFull(b.nomina) : '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ══════════ EMPLEADOS / GESTORES ══════════ -->
                <div v-show="activeTab === 'empleados'">

                    <!-- Filtros -->
                    <div class="mb-4 flex flex-wrap gap-3">
                        <div class="relative flex-1 min-w-52">
                            <Search class="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-slate-400" />
                            <input v-model="searchEmp" type="text" placeholder="Buscar por nombre, código o sucursal…"
                                   class="w-full rounded-xl border border-slate-200 bg-white py-2 pl-9 pr-4 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100" />
                        </div>
                        <select v-model="filterBranch"
                                class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100">
                            <option value="">Todas las sucursales</option>
                            <option v-for="b in branchOptions" :key="b" :value="b">{{ b }}</option>
                        </select>
                    </div>

                    <div v-if="!empGest.length" class="rounded-2xl border bg-amber-50 p-6 text-sm text-amber-700">
                        Sin datos de empleados/gestores para este periodo. Verifica que el archivo NOI fue procesado y que hay colocación o cartera en el periodo.
                    </div>
                    <div v-else>
                        <div class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                            <table class="w-full text-xs">
                                <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3 text-left sticky left-0 bg-slate-50">Empleado / Gestor</th>
                                        <th class="px-3 py-3 text-left">Sucursal</th>
                                        <th class="px-3 py-3 text-right">Pagos</th>
                                        <th class="px-3 py-3 text-right">Bonos</th>
                                        <th class="px-3 py-3 text-right">Descuentos</th>
                                        <th class="px-3 py-3 text-right">Neto nómina</th>
                                        <th class="px-3 py-3 text-right">Colocación</th>
                                        <th class="px-3 py-3 text-right">Ops</th>
                                        <th class="px-3 py-3 text-right">Recuperación</th>
                                        <th class="px-3 py-3 text-right">Cartera</th>
                                        <th class="px-3 py-3 text-right">C. Vencida</th>
                                        <th class="px-3 py-3 text-right">Mora %</th>
                                        <th class="px-3 py-3 text-right">Gastos</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="e in empVisible" :key="e.name + e.branch" class="border-t hover:bg-slate-50">
                                        <td class="px-3 py-2 font-bold sticky left-0 bg-white whitespace-nowrap">{{ e.name }}</td>
                                        <td class="px-3 py-2 text-slate-600 whitespace-nowrap">
                                            <span :class="e.branch === 'Sin sucursal' ? 'text-amber-600 font-semibold' : ''">{{ e.branch }}</span>
                                        </td>
                                        <td class="px-3 py-2 text-right">{{ e.pagos > 0 ? moneyFull(e.pagos) : '—' }}</td>
                                        <td class="px-3 py-2 text-right">{{ e.bonos > 0 ? moneyFull(e.bonos) : '—' }}</td>
                                        <td class="px-3 py-2 text-right">{{ e.descuentos > 0 ? moneyFull(e.descuentos) : '—' }}</td>
                                        <td class="px-3 py-2 text-right font-bold">{{ e.neto > 0 ? moneyFull(e.neto) : '—' }}</td>
                                        <td class="px-3 py-2 text-right font-bold text-indigo-700">{{ e.colocacion > 0 ? moneyFull(e.colocacion) : '—' }}</td>
                                        <td class="px-3 py-2 text-right">{{ e.operaciones > 0 ? num(e.operaciones) : '—' }}</td>
                                        <td class="px-3 py-2 text-right">{{ e.recuperacion > 0 ? moneyFull(e.recuperacion) : '—' }}</td>
                                        <td class="px-3 py-2 text-right">{{ e.cartera > 0 ? moneyFull(e.cartera) : '—' }}</td>
                                        <td class="px-3 py-2 text-right" :class="e.vencida > 0 ? 'font-bold text-red-700' : ''">{{ e.vencida > 0 ? moneyFull(e.vencida) : '—' }}</td>
                                        <td class="px-3 py-2 text-right" :class="e.mora > 25 ? 'font-bold text-red-700' : ''">{{ e.cartera > 0 ? pct(e.mora) : '—' }}</td>
                                        <td class="px-3 py-2 text-right">{{ e.gastos > 0 ? moneyFull(e.gastos) : '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="filteredEmp.length > 15" class="mt-3 text-center">
                            <button @click="showAllEmp = !showAllEmp"
                                    class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-2 text-sm font-bold text-slate-600 shadow-sm hover:bg-slate-50 transition">
                                {{ showAllEmp ? 'Ver menos' : `Ver todos (${filteredEmp.length})` }}
                            </button>
                        </div>
                        <p class="mt-2 text-xs text-slate-400">
                            Mostrando {{ empVisible.length }} de {{ filteredEmp.length }} registros.
                            Un gestor y un empleado son la misma persona si comparten nombre.
                        </p>
                    </div>
                </div>

                <!-- ══════════ CARTERA Y MORA ══════════ -->
                <div v-show="activeTab === 'cartera'" class="space-y-4">
                    <div class="grid grid-cols-3 gap-3">
                        <div class="rounded-2xl border bg-white p-4 shadow-sm">
                            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Cartera total</p>
                            <p class="mt-1 text-xl font-black">{{ moneyFull(kpiCartera) }}</p>
                        </div>
                        <div class="rounded-2xl border bg-white p-4 shadow-sm" :class="kpiMora > 0 ? 'border-red-200' : ''">
                            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Mora total</p>
                            <p class="mt-1 text-xl font-black" :class="kpiMora > 0 ? 'text-red-700' : ''">{{ moneyFull(kpiMora) }}</p>
                        </div>
                        <div class="rounded-2xl border bg-white p-4 shadow-sm" :class="kpiMoraPct > 25 ? 'border-red-200' : ''">
                            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Índice mora</p>
                            <p class="mt-1 text-xl font-black" :class="kpiMoraPct > 25 ? 'text-red-700' : ''">{{ pct(kpiMoraPct) }}</p>
                        </div>
                    </div>

                    <!-- Gráfica mora por bucket -->
                    <div v-if="charts.mora_by_bucket?.length" class="rounded-2xl border bg-white p-5 shadow-sm">
                        <h3 class="mb-4 text-xs font-black uppercase tracking-wider text-slate-500">Vencido por bucket</h3>
                        <div class="space-y-2">
                            <div v-for="bar in charts.mora_by_bucket" :key="bar.label" class="flex items-center gap-3">
                                <div class="w-28 shrink-0 text-xs font-semibold text-slate-600">{{ bar.label }}</div>
                                <div class="flex-1 rounded-full bg-slate-100 h-5 overflow-hidden">
                                    <div class="h-full rounded-full transition-all flex items-center pl-2"
                                         :class="bar.label === 'Al corriente' ? 'bg-emerald-400' : 'bg-red-500'"
                                         :style="{ width: Math.max(bar.pct, 1) + '%' }">
                                    </div>
                                </div>
                                <div class="w-28 shrink-0 text-right text-xs font-black text-slate-800">{{ moneyFull(bar.value) }}</div>
                            </div>
                        </div>
                    </div>

                    <div v-if="!snap.sections?.portfolio_buckets?.length" class="rounded-2xl border bg-amber-50 p-4 text-sm text-amber-700">
                        Sin datos de días vencidos. Verifica que el archivo "Lendus Saldos por Cliente" incluya la columna "días_mora" o "días_vencidos".
                    </div>
                    <div v-else class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th class="px-4 py-3 text-left">Bucket</th>
                                    <th class="px-4 py-3 text-right">Contratos</th>
                                    <th class="px-4 py-3 text-right">Balance</th>
                                    <th class="px-4 py-3 text-right">Vencido</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="b in snap.sections.portfolio_buckets" :key="b.label" class="border-t hover:bg-slate-50">
                                    <td class="px-4 py-2.5 font-semibold">{{ b.label }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ num(b.contratos) }}</td>
                                    <td class="px-4 py-2.5 text-right">{{ moneyFull(b.balance) }}</td>
                                    <td class="px-4 py-2.5 text-right font-bold" :class="b.vencida > 0 && b.label !== 'Al corriente' ? 'text-red-700' : ''">{{ moneyFull(b.vencida) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ══════════ GASTOS ══════════ -->
                <div v-show="activeTab === 'gastos'" class="space-y-4">

                    <!-- KPI total -->
                    <div class="rounded-2xl border bg-white p-5 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Gastos operativos del periodo</p>
                                <p class="mt-1 text-3xl font-black text-slate-950">{{ moneyFull(kpiGastos) }}</p>
                            </div>
                            <div class="flex gap-3">
                                <div v-for="s in gastosBySource" :key="s.fuente" class="rounded-xl bg-slate-50 border px-4 py-2 text-center">
                                    <p class="text-xs text-slate-500 font-semibold">{{ s.fuente }}</p>
                                    <p class="text-sm font-black text-slate-900">{{ moneyFull(s.total) }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="!gastosByCategory.length && !brGlobalGastos.length" class="rounded-2xl border bg-white p-10 text-center text-sm text-slate-400">Sin gastos registrados para este periodo.</div>

                    <!-- Gastos operativos canónicos (fuente: branch_radiography) -->
                    <div v-if="brGlobalGastos.length" class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                        <div class="bg-slate-50 border-b px-4 py-2 flex items-center justify-between">
                            <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Gastos operativos por concepto</span>
                            <span class="text-xs font-black text-slate-800">Total: {{ moneyFull(brGlobalGastosTotal) }}</span>
                        </div>
                        <table class="w-full text-xs">
                            <tbody>
                                <tr v-for="c in brGlobalGastos" :key="c.concepto" class="border-b last:border-0 hover:bg-slate-50">
                                    <td class="px-4 py-2 font-semibold text-slate-700">{{ c.concepto }}</td>
                                    <td class="px-4 py-2 text-right font-black">{{ moneyFull(c.total) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="gastosByCategory.length" class="grid gap-4 lg:grid-cols-2">

                        <!-- Por categoría -->
                        <div class="rounded-2xl border bg-white p-5 shadow-sm">
                            <h3 class="mb-4 text-xs font-black uppercase tracking-wider text-slate-500">Por categoría (fuente bruta)</h3>
                            <div class="space-y-2">
                                <div v-for="c in gastosByCategory" :key="c.categoria" class="flex items-center gap-3">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex justify-between text-xs mb-1">
                                            <span class="font-semibold text-slate-700 truncate">{{ c.categoria }}</span>
                                            <span class="text-slate-400 ml-2">{{ c.count }} regs</span>
                                        </div>
                                        <div class="h-3 w-full overflow-hidden rounded-full bg-slate-100">
                                            <div class="h-full rounded-full bg-violet-500 transition-all"
                                                 :style="{ width: (gastosTotal > 0 ? Math.min(100, c.total / gastosTotal * 100) : 0) + '%' }"></div>
                                        </div>
                                    </div>
                                    <div class="w-24 shrink-0 text-right text-xs font-black text-slate-800">{{ moneyFull(c.total) }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- Por sucursal -->
                        <div class="rounded-2xl border bg-white p-5 shadow-sm">
                            <h3 class="mb-4 text-xs font-black uppercase tracking-wider text-slate-500">Por sucursal</h3>
                            <div class="space-y-2">
                                <div v-for="b in gastosByBranch" :key="b.sucursal" class="flex items-center gap-3">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex justify-between text-xs mb-1">
                                            <span class="font-semibold text-slate-700 truncate">{{ b.sucursal }}</span>
                                            <span class="text-slate-400 ml-2">{{ b.count }} regs</span>
                                        </div>
                                        <div class="h-3 w-full overflow-hidden rounded-full bg-slate-100">
                                            <div class="h-full rounded-full bg-amber-500 transition-all"
                                                 :style="{ width: (gastosTotal > 0 ? Math.min(100, b.total / gastosTotal * 100) : 0) + '%' }"></div>
                                        </div>
                                    </div>
                                    <div class="w-24 shrink-0 text-right text-xs font-black text-slate-800">{{ moneyFull(b.total) }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- Por concepto -->
                        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                            <div class="bg-slate-50 border-b px-4 py-2 text-xs font-bold uppercase tracking-wider text-slate-500">Por concepto</div>
                            <table class="w-full text-xs">
                                <tbody>
                                    <tr v-for="c in gastosByConcept" :key="c.concepto" class="border-b last:border-0 hover:bg-slate-50">
                                        <td class="px-4 py-2 font-semibold text-slate-700">{{ c.concepto }}</td>
                                        <td class="px-4 py-2 text-right text-slate-500">{{ c.count }} regs</td>
                                        <td class="px-4 py-2 text-right font-black">{{ moneyFull(c.total) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Por empleado -->
                        <div v-if="gastosByEmployee.length" class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                            <div class="bg-slate-50 border-b px-4 py-2 text-xs font-bold uppercase tracking-wider text-slate-500">Por empleado / gestor</div>
                            <table class="w-full text-xs">
                                <tbody>
                                    <tr v-for="e in gastosByEmployee" :key="e.empleado" class="border-b last:border-0 hover:bg-slate-50">
                                        <td class="px-4 py-2 font-semibold text-slate-700">{{ e.empleado }}</td>
                                        <td class="px-4 py-2 text-right font-black">{{ moneyFull(e.total) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                    </div>
                </div>

                <!-- ══════════ INCIDENCIAS ══════════ -->
                <div v-show="activeTab === 'incidencias'">
                    <div v-if="!inc.length" class="rounded-2xl border bg-white p-10 text-center text-sm text-slate-400">Sin incidencias registradas.</div>
                    <div v-else class="space-y-3">
                        <div v-for="i in inc" :key="i.type + i.message"
                             class="rounded-2xl border p-4"
                             :class="i.severity === 'high' ? 'border-red-200 bg-red-50' : 'border-amber-200 bg-amber-50'">
                            <div class="flex items-center gap-2">
                                <span class="rounded-full px-2 py-0.5 text-xs font-black uppercase"
                                      :class="i.severity === 'high' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'">{{ i.severity }}</span>
                                <span class="text-xs font-bold text-slate-500">{{ i.type }}</span>
                            </div>
                            <p class="mt-1.5 text-sm font-semibold" :class="i.severity === 'high' ? 'text-red-800' : 'text-amber-800'">{{ i.message }}</p>
                        </div>
                    </div>
                </div>

            </div>
        </template>
    </div>
</template>
