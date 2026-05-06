<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import {
    ArrowLeft,
    Download,
    FileBarChart2,
    FileSpreadsheet,
    FileText,
    AlertTriangle,
    Users,
    Building2,
    TrendingUp,
    AlertCircle,
} from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    period: {
        id: number
        label: string
        code: string
        type: string
        start_date: string | null
        end_date: string | null
    }
    summary: {
        id: number
        global_metrics: Record<string, number>
        generated_at: string
        version: number
    } | null
    payrollSummary: {
        total_empleados: number
        pagos: number
        bonos: number
        descuentos: number
        gastos: number
        neto: number
    }
    employees: Array<{
        id: number
        employee_name: string
        branch_name: string | null
        total_payments: number
        total_bonuses: number
        total_discounts: number
        total_expenses: number
        net_amount: number
        included_in_report: boolean
        exclusion_reason: string | null
    }>
    branchSummaries: Array<{
        branch_id: number
        branch_name: string
        metrics: Record<string, number>
    }>
    incidents: Array<{
        id: number
        type: string
        severity: string
        message: string
        context: Record<string, any> | null
    }>
    run: { status: string; started_at: string | null; finished_at: string | null } | null
    hasExcelExport: boolean
    hasPdfExport: boolean
    excelUrl: string
    pdfUrl: string
}>()

const tab = ref<'resumen' | 'empleados' | 'sucursales' | 'incidencias'>('resumen')
const tabs = [
    { k: 'resumen',     l: 'Resumen',     icon: TrendingUp },
    { k: 'empleados',   l: 'Empleados',   icon: Users },
    { k: 'sucursales',  l: 'Sucursales',  icon: Building2 },
    { k: 'incidencias', l: 'Incidencias', icon: AlertCircle },
] as const

const money = (v: number) =>
    new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(v || 0))

const pct = (v: number) => Number(v || 0).toFixed(2) + '%'

const gm = computed(() => props.summary?.global_metrics ?? {})

const summaryCards = computed(() => [
    { label: 'Empleados',      value: props.payrollSummary.total_empleados.toString(),  accent: false },
    { label: 'Recuperación',   value: money(gm.value.recuperacion_total ?? 0),           accent: false },
    { label: 'Colocación',     value: money(gm.value.colocacion_total ?? 0),             accent: false },
    { label: 'Cartera total',  value: money(gm.value.valor_cartera_total ?? 0),          accent: false },
    { label: 'Cartera vencida', value: money(gm.value.cartera_vencida_total ?? 0),       accent: true  },
    { label: 'Índice de mora',  value: pct(gm.value.mora_porcentaje ?? 0),               accent: (gm.value.mora_porcentaje ?? 0) > 25 },
    { label: 'Gastos totales',  value: money(gm.value.gasto_total ?? 0),                 accent: false },
    { label: 'Neto nómina',     value: money(props.payrollSummary.neto ?? 0),            accent: false },
])

const criticalIncidents = computed(() => props.incidents.filter(i => i.severity === 'high').length)
const includedEmployees  = computed(() => props.employees.filter(e => e.included_in_report).length)
</script>

<template>
    <Head :title="`Vista previa — ${period.label}`" />

    <div class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-indigo-50/40">

        <!-- ── Hero header ── -->
        <section class="bg-slate-950 px-6 py-6 text-white shadow-2xl sm:px-8">
            <div class="mx-auto max-w-screen-2xl">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="flex items-center gap-2 text-xs font-black uppercase tracking-widest text-indigo-300">
                            <FileBarChart2 class="size-4" />
                            Radiografía · Vista previa completa
                        </div>
                        <h1 class="mt-2 text-2xl font-black tracking-tight sm:text-3xl">
                            {{ period.label }}
                        </h1>
                        <p class="mt-1 text-sm text-slate-400">
                            Periodo {{ period.code }} &nbsp;·&nbsp; Tipo: Radiografía simple &nbsp;·&nbsp; Alcance: General
                        </p>
                        <p v-if="summary?.generated_at" class="mt-1 text-xs text-slate-500">
                            Generado: {{ summary.generated_at }} &nbsp;·&nbsp; Versión {{ summary.version }}
                        </p>
                    </div>

                    <!-- Action buttons -->
                    <div class="flex flex-wrap items-center gap-2">
                        <a
                            v-if="hasExcelExport"
                            :href="excelUrl"
                            class="inline-flex h-10 items-center gap-2 rounded-2xl bg-emerald-600 px-4 text-sm font-black text-white shadow transition hover:bg-emerald-500"
                        >
                            <FileSpreadsheet class="size-4" />
                            Descargar Excel
                        </a>
                        <a
                            v-if="hasPdfExport"
                            :href="pdfUrl"
                            class="inline-flex h-10 items-center gap-2 rounded-2xl bg-rose-600 px-4 text-sm font-black text-white shadow transition hover:bg-rose-500"
                        >
                            <FileText class="size-4" />
                            Descargar PDF
                        </a>
                        <Link
                            href="/reportes-mensuales"
                            class="inline-flex h-10 items-center gap-2 rounded-2xl border border-white/20 bg-white/10 px-4 text-sm font-black text-white transition hover:bg-white/20"
                        >
                            <ArrowLeft class="size-4" />
                            Reportes mensuales
                        </Link>
                        <Link
                            href="/historico-general"
                            class="inline-flex h-10 items-center gap-2 rounded-2xl border border-white/10 bg-white/5 px-4 text-sm font-black text-slate-300 transition hover:bg-white/10"
                        >
                            Histórico general
                        </Link>
                    </div>
                </div>
            </div>
        </section>

        <div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8 space-y-6">

            <!-- ── No summary state ── -->
            <div
                v-if="!summary"
                class="rounded-[2rem] border border-dashed border-amber-300 bg-amber-50 p-10 text-center"
            >
                <AlertTriangle class="mx-auto size-10 text-amber-500" />
                <h2 class="mt-4 text-lg font-black text-amber-900">Sin radiografía generada</h2>
                <p class="mt-2 text-sm text-amber-700">
                    No existe una radiografía vigente para este periodo. Genera el reporte desde Histórico general.
                </p>
                <Link
                    href="/historico-general"
                    class="mt-5 inline-flex h-10 items-center gap-2 rounded-2xl bg-amber-600 px-5 text-sm font-black text-white transition hover:bg-amber-500"
                >
                    Ir a Histórico general
                </Link>
            </div>

            <template v-else>
                <!-- ── Summary cards ── -->
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div
                        v-for="card in summaryCards"
                        :key="card.label"
                        class="rounded-2xl border bg-white p-4 shadow-sm"
                        :class="card.accent ? 'border-rose-200 bg-rose-50/50' : 'border-slate-200'"
                    >
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-500">{{ card.label }}</p>
                        <p
                            class="mt-2 text-xl font-black"
                            :class="card.accent ? 'text-rose-700' : 'text-slate-950'"
                        >{{ card.value }}</p>
                    </div>
                </div>

                <!-- ── Tabs ── -->
                <div class="overflow-hidden rounded-[2rem] border border-slate-200 bg-white shadow-xl shadow-slate-200/60">

                    <!-- Tab bar -->
                    <div class="flex gap-1 overflow-x-auto border-b border-slate-100 bg-slate-50 p-2">
                        <button
                            v-for="t in tabs"
                            :key="t.k"
                            type="button"
                            class="flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-black transition"
                            :class="tab === t.k ? 'bg-white text-indigo-700 shadow' : 'text-slate-500 hover:bg-white/60 hover:text-slate-900'"
                            @click="tab = t.k"
                        >
                            <component :is="t.icon" class="size-4" />
                            {{ t.l }}
                            <span
                                v-if="t.k === 'incidencias' && criticalIncidents > 0"
                                class="rounded-full bg-rose-100 px-1.5 py-0.5 text-xs text-rose-700"
                            >{{ criticalIncidents }}</span>
                        </button>
                    </div>

                    <!-- Tab content -->
                    <div class="p-5">

                        <!-- ── Resumen ── -->
                        <div v-if="tab === 'resumen'" class="space-y-5">
                            <div class="grid gap-4 md:grid-cols-2">
                                <!-- Financial metrics -->
                                <div>
                                    <h3 class="mb-3 text-sm font-black text-slate-900">Métricas financieras</h3>
                                    <table class="w-full text-sm">
                                        <tbody>
                                            <tr v-for="[label, value] in [
                                                ['Recuperación total', money(gm.recuperacion_total ?? 0)],
                                                ['Colocación total', money(gm.colocacion_total ?? 0)],
                                                ['Valor cartera total', money(gm.valor_cartera_total ?? 0)],
                                                ['Cartera vencida', money(gm.cartera_vencida_total ?? 0)],
                                                ['Índice de mora', pct(gm.mora_porcentaje ?? 0)],
                                                ['Gastos totales', money(gm.gasto_total ?? 0)],
                                            ]" :key="label" class="border-b border-slate-100 last:border-0">
                                                <td class="py-2.5 font-semibold text-slate-700">{{ label }}</td>
                                                <td class="py-2.5 text-right font-black text-slate-950">{{ value }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <!-- Payroll metrics -->
                                <div>
                                    <h3 class="mb-3 text-sm font-black text-slate-900">Nómina / empleados</h3>
                                    <table class="w-full text-sm">
                                        <tbody>
                                            <tr v-for="[label, value] in [
                                                ['Total empleados', payrollSummary.total_empleados.toString()],
                                                ['Total pagos', money(payrollSummary.pagos)],
                                                ['Total bonos', money(payrollSummary.bonos)],
                                                ['Total descuentos', money(payrollSummary.descuentos)],
                                                ['Total gastos', money(payrollSummary.gastos)],
                                                ['Neto acumulado', money(payrollSummary.neto)],
                                            ]" :key="label" class="border-b border-slate-100 last:border-0">
                                                <td class="py-2.5 font-semibold text-slate-700">{{ label }}</td>
                                                <td class="py-2.5 text-right font-black text-slate-950">{{ value }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- ── Empleados ── -->
                        <div v-else-if="tab === 'empleados'">
                            <div v-if="employees.length === 0" class="py-10 text-center text-sm text-slate-500">
                                Sin datos de empleados consolidados para este periodo.
                            </div>
                            <div v-else>
                                <p class="mb-3 text-sm text-slate-600">
                                    {{ includedEmployees }} incluidos · {{ employees.length - includedEmployees }} excluidos
                                </p>
                                <div class="overflow-x-auto rounded-2xl border border-slate-200">
                                    <table class="min-w-full text-sm">
                                        <thead>
                                            <tr class="border-b bg-slate-50 text-left text-slate-500">
                                                <th class="px-4 py-3 font-bold">Empleado</th>
                                                <th class="px-4 py-3 font-bold">Sucursal</th>
                                                <th class="px-4 py-3 text-right font-bold">Pagos</th>
                                                <th class="px-4 py-3 text-right font-bold">Gastos</th>
                                                <th class="px-4 py-3 text-right font-bold">Neto</th>
                                                <th class="px-4 py-3 text-center font-bold">Estado</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr
                                                v-for="emp in employees"
                                                :key="emp.id"
                                                class="border-b border-slate-100 last:border-0"
                                                :class="!emp.included_in_report ? 'opacity-60' : ''"
                                            >
                                                <td class="px-4 py-3 font-semibold text-slate-900">
                                                    {{ emp.employee_name }}
                                                    <p v-if="emp.exclusion_reason" class="mt-0.5 text-xs text-amber-600">{{ emp.exclusion_reason }}</p>
                                                </td>
                                                <td class="px-4 py-3 text-slate-600">{{ emp.branch_name ?? '—' }}</td>
                                                <td class="px-4 py-3 text-right">{{ money(emp.total_payments) }}</td>
                                                <td class="px-4 py-3 text-right">{{ money(emp.total_expenses) }}</td>
                                                <td class="px-4 py-3 text-right font-black">{{ money(emp.net_amount) }}</td>
                                                <td class="px-4 py-3 text-center">
                                                    <span
                                                        class="rounded-full px-2.5 py-1 text-xs font-black"
                                                        :class="emp.included_in_report ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'"
                                                    >{{ emp.included_in_report ? 'Incluido' : 'Excluido' }}</span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- ── Sucursales ── -->
                        <div v-else-if="tab === 'sucursales'">
                            <div v-if="branchSummaries.length === 0" class="py-10 text-center text-sm text-slate-500">
                                Sin datos de sucursales para este periodo.
                            </div>
                            <div v-else class="overflow-x-auto rounded-2xl border border-slate-200">
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr class="border-b bg-slate-50 text-left text-slate-500">
                                            <th class="px-4 py-3 font-bold">Sucursal</th>
                                            <th class="px-4 py-3 text-right font-bold">Recuperación</th>
                                            <th class="px-4 py-3 text-right font-bold">Colocación</th>
                                            <th class="px-4 py-3 text-right font-bold">Cartera</th>
                                            <th class="px-4 py-3 text-right font-bold">Mora %</th>
                                            <th class="px-4 py-3 text-right font-bold">Gastos</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="bs in branchSummaries"
                                            :key="bs.branch_id"
                                            class="border-b border-slate-100 last:border-0"
                                        >
                                            <td class="px-4 py-3 font-semibold text-slate-900">{{ bs.branch_name }}</td>
                                            <td class="px-4 py-3 text-right">{{ money(bs.metrics.recuperacion_total ?? 0) }}</td>
                                            <td class="px-4 py-3 text-right">{{ money(bs.metrics.colocacion_total ?? 0) }}</td>
                                            <td class="px-4 py-3 text-right">{{ money(bs.metrics.valor_cartera ?? 0) }}</td>
                                            <td
                                                class="px-4 py-3 text-right font-black"
                                                :class="(bs.metrics.mora_porcentaje ?? 0) > 25 ? 'text-rose-700' : ''"
                                            >{{ pct(bs.metrics.mora_porcentaje ?? 0) }}</td>
                                            <td class="px-4 py-3 text-right">{{ money(bs.metrics.gasto_total ?? 0) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- ── Incidencias ── -->
                        <div v-else-if="tab === 'incidencias'">
                            <div v-if="incidents.length === 0" class="py-10 text-center text-sm text-slate-500">
                                Sin incidencias registradas para este periodo.
                            </div>
                            <div v-else class="space-y-3">
                                <div
                                    v-for="inc in incidents"
                                    :key="inc.id"
                                    class="rounded-2xl border p-4"
                                    :class="{
                                        'border-rose-200 bg-rose-50': inc.severity === 'high',
                                        'border-amber-200 bg-amber-50': inc.severity === 'warning',
                                        'border-emerald-200 bg-emerald-50': inc.severity === 'resolved',
                                    }"
                                >
                                    <div class="flex items-center gap-3">
                                        <span
                                            class="rounded-full px-2.5 py-1 text-xs font-black uppercase"
                                            :class="{
                                                'bg-rose-100 text-rose-800': inc.severity === 'high',
                                                'bg-amber-100 text-amber-800': inc.severity === 'warning',
                                                'bg-emerald-100 text-emerald-800': inc.severity === 'resolved',
                                            }"
                                        >{{ inc.severity }}</span>
                                        <span class="text-xs text-slate-500">{{ inc.type }}</span>
                                    </div>
                                    <p class="mt-2 text-sm font-semibold text-slate-900">{{ inc.message }}</p>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ── Generation info ── -->
                <div v-if="run" class="rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-600">
                    <span class="font-semibold text-slate-900">Generación:</span>
                    {{ run.started_at ?? '—' }} → {{ run.finished_at ?? 'En curso' }}
                    &nbsp;·&nbsp; Estado: <span class="font-semibold text-emerald-700">{{ run.status }}</span>
                </div>

            </template>
        </div>
    </div>
</template>
