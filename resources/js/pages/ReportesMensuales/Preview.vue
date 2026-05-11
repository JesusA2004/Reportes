<script setup lang="ts">
import { computed, ref } from 'vue'
import { ArrowLeft, AlertTriangle, FileSpreadsheet, FileText, Search } from 'lucide-vue-next'
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
}>()

type TabKey = 'resumen' | 'productos' | 'sucursales' | 'empleados' | 'cartera' | 'gastos' | 'incidencias'
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

// Empleados/Gestores fusionados
const empGest = computed(() => snap.value?.sections?.employees_gestores ?? [])

// Filtros empleados
const searchEmp     = ref('')
const filterBranch  = ref('')
const branchOptions = computed(() => {
    const set = new Set<string>()
    empGest.value.forEach((r: any) => { if (r.branch) set.add(r.branch) })
    return Array.from(set).sort()
})
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
    { key: 'resumen',     label: 'Resumen' },
    { key: 'productos',   label: 'Productos' },
    { key: 'sucursales',  label: 'Sucursales' },
    { key: 'empleados',   label: 'Empleados / Gestores' },
    { key: 'cartera',     label: 'Cartera y mora' },
    { key: 'gastos',      label: 'Gastos' },
    { key: 'incidencias', label: 'Incidencias' },
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
                    <div class="rounded-2xl border border-white/70 bg-white p-4 shadow-sm hover:shadow-md transition">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Empleados</p>
                        <p class="mt-1 text-xl font-black text-slate-950">{{ num(sum.employees_count) }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/70 bg-white p-4 shadow-sm hover:shadow-md transition">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Recuperación</p>
                        <p class="mt-1 text-xl font-black text-slate-950">{{ money(sum.recovery_total) }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/70 bg-white p-4 shadow-sm hover:shadow-md transition">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Colocación</p>
                        <p class="mt-1 text-xl font-black text-slate-950">{{ money(sum.placement_total) }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/70 bg-white p-4 shadow-sm hover:shadow-md transition"
                         :class="sum.mora_index > 25 ? 'border-red-200 bg-red-50' : ''">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Mora</p>
                        <p class="mt-1 text-xl font-black" :class="sum.mora_index > 25 ? 'text-red-700' : 'text-slate-950'">{{ pct(sum.mora_index) }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/70 bg-white p-4 shadow-sm hover:shadow-md transition">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Cartera</p>
                        <p class="mt-1 text-xl font-black text-slate-950">{{ money(sum.portfolio_total) }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/70 bg-white p-4 shadow-sm hover:shadow-md transition"
                         :class="sum.overdue_portfolio > 0 ? 'border-red-200 bg-red-50' : ''">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">C. Vencida</p>
                        <p class="mt-1 text-xl font-black" :class="sum.overdue_portfolio > 0 ? 'text-red-700' : 'text-slate-950'">{{ money(sum.overdue_portfolio) }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/70 bg-white p-4 shadow-sm hover:shadow-md transition">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Neto nómina</p>
                        <p class="mt-1 text-xl font-black text-slate-950">{{ money(sum.net_payroll) }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/70 bg-white p-4 shadow-sm hover:shadow-md transition">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Gastos</p>
                        <p class="mt-1 text-xl font-black text-slate-950">{{ money(sum.expenses_total) }}</p>
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
                                    ['Recuperación total',  money(sum.recovery_total)],
                                    ['Colocación total',    money(sum.placement_total)],
                                    ['Cartera total',       money(sum.portfolio_total)],
                                    ['Cartera vencida',     money(sum.overdue_portfolio)],
                                    ['Índice de mora',      pct(sum.mora_index)],
                                    ['Gastos totales',      money(sum.expenses_total)],
                                ]" :key="row[0]" class="border-b last:border-0">
                                    <td class="py-2 text-slate-600 font-medium">{{ row[0] }}</td>
                                    <td class="py-2 text-right font-black text-slate-950">{{ row[1] }}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="rounded-2xl border bg-white p-5 shadow-sm">
                            <h3 class="mb-3 text-xs font-black uppercase tracking-wider text-slate-500">Nómina / empleados</h3>
                            <table class="w-full text-sm">
                                <tr v-for="row in [
                                    ['Total empleados',  num(pay.total_empleados)],
                                    ['Pagos',            money(pay.pagos)],
                                    ['Bonos',            money(pay.bonos)],
                                    ['Descuentos',       money(pay.descuentos)],
                                    ['Gastos empleados', money(pay.gastos)],
                                    ['Neto acumulado',   money(pay.neto)],
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
                    <div v-if="!snap.sections?.branches?.length" class="rounded-2xl border bg-white p-10 text-center text-sm text-slate-400">Sin datos por sucursal.</div>
                    <div v-else class="space-y-4">
                        <!-- Gráfica cartera por sucursal -->
                        <div class="rounded-2xl border bg-white p-5 shadow-sm">
                            <h3 class="mb-4 text-xs font-black uppercase tracking-wider text-slate-500">Cartera por sucursal</h3>
                            <div class="space-y-2">
                                <div v-for="b in snap.sections.branches" :key="b.branch_id" class="flex items-center gap-3">
                                    <div class="w-28 shrink-0 truncate text-xs font-semibold text-slate-700">{{ b.nombre }}</div>
                                    <div class="flex-1 rounded-full bg-slate-100 h-4 overflow-hidden">
                                        <div class="h-full rounded-full bg-sky-500 transition-all"
                                             :style="{ width: (snap.sections.branches[0]?.cartera > 0 ? Math.min(100, b.cartera / snap.sections.branches[0].cartera * 100) : 0) + '%' }"></div>
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
                                        <th class="px-4 py-3 text-right">Vencida</th>
                                        <th class="px-4 py-3 text-right">Mora %</th>
                                        <th class="px-4 py-3 text-right">Gastos</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="b in snap.sections.branches" :key="b.branch_id" class="border-t hover:bg-slate-50">
                                        <td class="px-4 py-2.5 font-bold">{{ b.nombre }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ moneyFull(b.recuperacion) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ moneyFull(b.colocacion) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ moneyFull(b.cartera) }}</td>
                                        <td class="px-4 py-2.5 text-right font-bold" :class="b.vencida > 0 ? 'text-red-700' : ''">{{ moneyFull(b.vencida) }}</td>
                                        <td class="px-4 py-2.5 text-right font-bold" :class="b.mora > 25 ? 'text-red-700' : ''">{{ pct(b.mora) }}</td>
                                        <td class="px-4 py-2.5 text-right">{{ moneyFull(b.gastos) }}</td>
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
                            <p class="mt-1 text-xl font-black">{{ moneyFull(sum.portfolio_total) }}</p>
                        </div>
                        <div class="rounded-2xl border bg-white p-4 shadow-sm" :class="sum.overdue_portfolio > 0 ? 'border-red-200' : ''">
                            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Cartera vencida</p>
                            <p class="mt-1 text-xl font-black" :class="sum.overdue_portfolio > 0 ? 'text-red-700' : ''">{{ moneyFull(sum.overdue_portfolio) }}</p>
                        </div>
                        <div class="rounded-2xl border bg-white p-4 shadow-sm" :class="sum.mora_index > 25 ? 'border-red-200' : ''">
                            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Índice mora</p>
                            <p class="mt-1 text-xl font-black" :class="sum.mora_index > 25 ? 'text-red-700' : ''">{{ pct(sum.mora_index) }}</p>
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
                                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Gastos totales del periodo</p>
                                <p class="mt-1 text-3xl font-black text-slate-950">{{ moneyFull(gastosTotal) }}</p>
                            </div>
                            <div class="flex gap-3">
                                <div v-for="s in gastosBySource" :key="s.fuente" class="rounded-xl bg-slate-50 border px-4 py-2 text-center">
                                    <p class="text-xs text-slate-500 font-semibold">{{ s.fuente }}</p>
                                    <p class="text-sm font-black text-slate-900">{{ moneyFull(s.total) }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="!gastosByCategory.length" class="rounded-2xl border bg-white p-10 text-center text-sm text-slate-400">Sin gastos registrados para este periodo.</div>

                    <div v-else class="grid gap-4 lg:grid-cols-2">

                        <!-- Por categoría -->
                        <div class="rounded-2xl border bg-white p-5 shadow-sm">
                            <h3 class="mb-4 text-xs font-black uppercase tracking-wider text-slate-500">Por categoría</h3>
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
