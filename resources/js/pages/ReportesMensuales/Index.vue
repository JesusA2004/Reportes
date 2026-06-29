<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import {
    Calculator,
    CheckCircle2,
    Download,
    FileBarChart2,
    FolderSync,
    Search,
    Users,
    XCircle,
} from 'lucide-vue-next'

import AppLayout from '@/layouts/AppLayout.vue'

type PeriodItem = {
    id: number
    label: string
    code: string
    type: string
    start_date: string | null
    end_date: string | null
    is_closed: boolean
}

type GeneratedReport = {
    id: number
    name: string
    period_id: number
    period: string
    period_code: string
    type: string
    scope: string
    generated_at: string
    generated_by?: number | null
    status: string
    excel_url: string
    pdf_url: string
    preview_url: string
}

type SummaryRow = {
    id: number
    employee_name?: string | null
    branch_name?: string | null
    total_payments: number
    total_bonuses: number
    total_discounts: number
    total_expenses: number
    net_amount: number
    has_useful_movement: boolean
    included_in_report: boolean
    exclusion_reason?: string | null
}

const props = withDefaults(
    defineProps<{
        periods: PeriodItem[]
        selectedPeriodId?: number | null
        summaryRows?: SummaryRow[]
        message: string
        generatedReports?: GeneratedReport[]
    }>(),
    {
        periods: () => [],
        selectedPeriodId: null,
        summaryRows: () => [],
        generatedReports: () => [],
    },
)

defineOptions({
    layout: AppLayout,
})

const selectPeriod = (periodId: number) => {
    router.get(
        '/reportes-mensuales',
        { period: periodId },
        { preserveScroll: true, preserveState: true },
    )
}

const consolidateForm = useForm({})

const consolidate = () => {
    if (!props.selectedPeriodId) return

    consolidateForm.post(`/reportes-mensuales/${props.selectedPeriodId}/consolidar`, {
        preserveScroll: true,
    })
}

const exportSummary = () => {
    if (!props.selectedPeriodId) return
    window.location.href = `/reportes-mensuales/${props.selectedPeriodId}/consolidado.csv`
}

const includedCount = props.summaryRows.filter((row) => row.included_in_report).length
const excludedCount = props.summaryRows.filter((row) => !row.included_in_report).length
const totalNet = props.summaryRows.reduce((sum, row) => sum + Number(row.net_amount || 0), 0)
const totalExpenses = props.summaryRows.reduce((sum, row) => sum + Number(row.total_expenses || 0), 0)

const money = (value: number) =>
    new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    }).format(Number(value || 0))

const STATUS_LABELS: Record<string, string> = {
    generated:  'Generado',
    processing: 'Procesando',
    pending:    'Pendiente',
    failed:     'Error',
    cancelled:  'Cancelado',
    expired:    'Expirado',
}
const STATUS_COLORS: Record<string, string> = {
    generated:  'bg-emerald-50 text-emerald-700',
    processing: 'bg-blue-50 text-blue-700',
    pending:    'bg-amber-50 text-amber-700',
    failed:     'bg-red-50 text-red-700',
    cancelled:  'bg-slate-100 text-slate-500',
    expired:    'bg-slate-50 text-slate-400',
}
const statusLabel  = (s: string) => STATUS_LABELS[s?.toLowerCase()] ?? s
const statusColor  = (s: string) => STATUS_COLORS[s?.toLowerCase()] ?? 'bg-slate-100 text-slate-600'

const SCOPE_LABELS: Record<string, string> = {
    general:  'General',
    branch:   'Por sucursal',
    employee: 'Por gestor',
}
const scopeLabel = (s: string) => SCOPE_LABELS[s?.toLowerCase()] ?? s

import { ref, computed } from 'vue'

const searchQuery   = ref('')
const filterStatus  = ref('')

const filteredReports = computed(() => {
    const q = searchQuery.value.trim().toLowerCase()
    const s = filterStatus.value.toLowerCase()
    return props.generatedReports.filter(r => {
        const matchSearch = !q
            || r.name?.toLowerCase().includes(q)
            || r.period?.toLowerCase().includes(q)
            || r.type?.toLowerCase().includes(q)
        const matchStatus = !s || r.status?.toLowerCase() === s
        return matchSearch && matchStatus
    })
})
</script>

<template>
    <Head title="Reportes por periodo" />

    <div class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-indigo-50/40 p-4 sm:p-6 lg:p-8">
        <div class="mx-auto max-w-screen-2xl space-y-6">
            <section class="overflow-hidden rounded-[2rem] bg-slate-950 p-6 text-white shadow-2xl shadow-slate-300 sm:p-8">
                <div class="flex items-center gap-3">
                    <div class="flex size-10 items-center justify-center rounded-2xl bg-emerald-500">
                        <FileBarChart2 class="size-5 text-white" />
                    </div>
                    <p class="text-xs font-black uppercase tracking-[0.28em] text-emerald-300">Reportes mensuales</p>
                </div>
                <h1 class="mt-3 text-3xl font-black tracking-tight sm:text-4xl">Reportes por periodo</h1>
                <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-300">{{ message }}</p>
                <div class="mt-5 flex flex-wrap gap-3">
                    <button
                        type="button"
                        class="inline-flex h-11 items-center gap-2 rounded-2xl border border-white/20 bg-white/10 px-5 text-sm font-black text-white transition hover:bg-white/20 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="!selectedPeriodId || consolidateForm.processing"
                        @click="consolidate"
                    >
                        <FolderSync class="size-4" />
                        {{ consolidateForm.processing ? 'Consolidando...' : 'Consolidar periodo' }}
                    </button>
                    <button
                        type="button"
                        class="inline-flex h-11 items-center gap-2 rounded-2xl bg-emerald-500 px-5 text-sm font-black text-white transition hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="!selectedPeriodId || !summaryRows.length"
                        @click="exportSummary"
                    >
                        <Download class="size-4" />
                        Exportar consolidado
                    </button>
                </div>
            </section>

            <section class="app-card p-5 shadow-xl shadow-slate-200/60 sm:p-6">
                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="text-lg font-black tracking-tight">Reportes generados</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Consulta, previsualiza y descarga los Excel/PDF ya guardados sin recalcular todo.</p>
                    </div>
                    <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-black text-emerald-700">{{ filteredReports.length }} de {{ generatedReports.length }} disponibles</span>
                </div>

                <!-- Filtros -->
                <div v-if="generatedReports.length" class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center">
                    <div class="relative flex-1">
                        <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                        <input v-model="searchQuery" type="search" placeholder="Buscar por nombre, periodo o tipo…"
                            class="w-full rounded-xl border border-slate-200 bg-white py-2 pl-9 pr-4 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100" />
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <button @click="filterStatus = ''" :class="filterStatus === '' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                            class="rounded-xl px-3 py-1.5 text-xs font-bold transition">Todos</button>
                        <button v-for="(label, key) in STATUS_LABELS" :key="key"
                            @click="filterStatus = key"
                            :class="filterStatus === key ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                            class="rounded-xl px-3 py-1.5 text-xs font-bold transition">{{ label }}</button>
                    </div>
                </div>

                <!-- Cards en móvil -->
                <div v-if="filteredReports.length" class="sm:hidden space-y-3">
                    <div v-for="report in filteredReports" :key="report.id"
                        class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="flex items-start justify-between gap-2">
                            <p class="font-black text-sm leading-snug text-slate-900">{{ report.name }}</p>
                            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-black" :class="statusColor(report.status)">{{ statusLabel(report.status) }}</span>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                            <span>{{ report.period }}</span>
                            <span>{{ report.type }} · {{ scopeLabel(report.scope) }}</span>
                            <span>{{ report.generated_at ?? '—' }}</span>
                        </div>
                        <div class="mt-3 flex gap-2">
                            <a class="flex-1 rounded-xl border border-slate-200 py-2 text-center text-xs font-bold hover:bg-slate-50 transition-colors" :href="report.preview_url">Ver</a>
                            <a class="flex-1 rounded-xl border border-slate-200 py-2 text-center text-xs font-bold hover:bg-slate-50 transition-colors" :href="report.excel_url">Excel</a>
                            <a class="flex-1 rounded-xl border border-slate-200 py-2 text-center text-xs font-bold hover:bg-slate-50 transition-colors" :href="report.pdf_url">PDF</a>
                        </div>
                    </div>
                </div>

                <!-- Tabla en desktop -->
                <div v-if="filteredReports.length" class="hidden sm:block overflow-x-auto rounded-2xl border border-border/70">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40 text-left text-muted-foreground">
                                <th class="px-4 py-3 font-bold">Reporte</th>
                                <th class="px-4 py-3 font-bold">Periodo</th>
                                <th class="px-4 py-3 font-bold">Tipo / alcance</th>
                                <th class="px-4 py-3 font-bold">Generado</th>
                                <th class="px-4 py-3 font-bold">Estado</th>
                                <th class="px-4 py-3 text-right font-bold">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="report in filteredReports" :key="report.id" class="border-b last:border-b-0 hover:bg-slate-50/60 transition-colors">
                                <td class="px-4 py-3 font-black max-w-[200px] truncate" :title="report.name">{{ report.name }}</td>
                                <td class="px-4 py-3">
                                    <span class="font-semibold">{{ report.period }}</span>
                                    <p class="text-xs text-muted-foreground">{{ report.period_code }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="font-medium">{{ report.type }}</span>
                                    <p class="text-xs text-muted-foreground">{{ scopeLabel(report.scope) }}</p>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-600">{{ report.generated_at ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2.5 py-1 text-xs font-black" :class="statusColor(report.status)">{{ statusLabel(report.status) }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        <a class="rounded-xl border px-3 py-2 text-xs font-bold hover:bg-muted transition-colors" :href="report.preview_url">Ver</a>
                                        <a class="rounded-xl border px-3 py-2 text-xs font-bold hover:bg-muted transition-colors" :href="report.excel_url">Excel</a>
                                        <a class="rounded-xl border px-3 py-2 text-xs font-bold hover:bg-muted transition-colors" :href="report.pdf_url">PDF</a>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="!generatedReports.length" class="rounded-2xl border border-dashed p-8 text-center text-sm text-muted-foreground">Aún no hay reportes generados. Genéralos desde Histórico general.</div>
                <div v-else-if="!filteredReports.length" class="rounded-2xl border border-dashed p-6 text-center text-sm text-muted-foreground">No hay reportes que coincidan con los filtros aplicados.</div>
            </section>

            <section class="app-card p-5 sm:p-6">
                <div class="mb-4">
                    <h2 class="text-lg font-bold tracking-tight">Seleccionar periodo</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Elige un periodo para ver su resumen consolidado.
                    </p>
                </div>

                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <button
                        v-for="period in periods"
                        :key="period.id"
                        type="button"
                        class="rounded-2xl border border-border/70 bg-background p-4 text-left transition hover:-translate-y-0.5 hover:shadow"
                        :class="selectedPeriodId === period.id ? 'ring-2 ring-primary/35' : ''"
                        @click="selectPeriod(period.id)"
                    >
                        <p class="text-sm font-bold">{{ period.label }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ period.code }} • {{ period.type }}
                        </p>
                        <p class="mt-2 text-xs text-muted-foreground">
                            {{ period.start_date }} → {{ period.end_date }}
                        </p>
                        <p
                            class="mt-2 text-xs"
                            :class="period.is_closed ? 'text-slate-500' : 'text-emerald-600'"
                        >
                            {{ period.is_closed ? 'Cerrado' : 'Abierto' }}
                        </p>
                    </button>
                </div>
            </section>

            <section v-if="selectedPeriodId" class="space-y-6">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div class="app-card p-5">
                        <div class="flex items-center gap-2 text-xs text-muted-foreground">
                            <Users class="size-4" />
                            Filas consolidadas
                        </div>
                        <p class="mt-2 text-2xl font-extrabold">{{ summaryRows.length }}</p>
                    </div>

                    <div class="app-card p-5">
                        <div class="flex items-center gap-2 text-xs text-muted-foreground">
                            <CheckCircle2 class="size-4" />
                            Incluidos
                        </div>
                        <p class="mt-2 text-2xl font-extrabold">{{ includedCount }}</p>
                    </div>

                    <div class="app-card p-5">
                        <div class="flex items-center gap-2 text-xs text-muted-foreground">
                            <XCircle class="size-4" />
                            Excluidos
                        </div>
                        <p class="mt-2 text-2xl font-extrabold">{{ excludedCount }}</p>
                    </div>

                    <div class="app-card p-5">
                        <div class="flex items-center gap-2 text-xs text-muted-foreground">
                            <Calculator class="size-4" />
                            Neto acumulado
                        </div>
                        <p class="mt-2 text-2xl font-extrabold">{{ money(totalNet) }}</p>
                    </div>
                </div>

                <div class="app-card p-5 sm:p-6">
                    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 class="text-lg font-bold tracking-tight">Resumen consolidado</h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Pagos, bonos, descuentos, gastos y neto por empleado.
                            </p>
                        </div>

                        <p class="text-sm text-muted-foreground">
                            Gastos acumulados:
                            <span class="font-semibold text-foreground">{{ money(totalExpenses) }}</span>
                        </p>
                    </div>

                    <div v-if="summaryRows.length" class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-muted-foreground">
                                    <th class="px-3 py-3 font-semibold">Empleado</th>
                                    <th class="px-3 py-3 font-semibold">Sucursal</th>
                                    <th class="px-3 py-3 font-semibold">Pagos</th>
                                    <th class="px-3 py-3 font-semibold">Bonos</th>
                                    <th class="px-3 py-3 font-semibold">Descuentos</th>
                                    <th class="px-3 py-3 font-semibold">Gastos</th>
                                    <th class="px-3 py-3 font-semibold">Neto</th>
                                    <th class="px-3 py-3 font-semibold">Estado</th>
                                </tr>
                            </thead>

                            <tbody>
                                <tr
                                    v-for="row in summaryRows"
                                    :key="row.id"
                                    class="border-b border-border/60 align-top"
                                >
                                    <td class="px-3 py-3">
                                        <p class="font-semibold text-foreground">
                                            {{ row.employee_name || 'Sin empleado' }}
                                        </p>
                                        <p
                                            v-if="!row.included_in_report && row.exclusion_reason"
                                            class="mt-1 text-xs text-amber-600"
                                        >
                                            {{ row.exclusion_reason }}
                                        </p>
                                    </td>

                                    <td class="px-3 py-3">
                                        {{ row.branch_name || 'Sin sucursal' }}
                                    </td>

                                    <td class="px-3 py-3">{{ money(row.total_payments) }}</td>
                                    <td class="px-3 py-3">{{ money(row.total_bonuses) }}</td>
                                    <td class="px-3 py-3">{{ money(row.total_discounts) }}</td>
                                    <td class="px-3 py-3">{{ money(row.total_expenses) }}</td>

                                    <td class="px-3 py-3 font-semibold">
                                        {{ money(row.net_amount) }}
                                    </td>

                                    <td class="px-3 py-3">
                                        <span
                                            class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                            :class="
                                                row.included_in_report
                                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'
                                                    : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'
                                            "
                                        >
                                            {{ row.included_in_report ? 'Incluido' : 'Excluido' }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-else class="py-10 text-center">
                        <p class="text-sm font-semibold">Todavía no hay resumen consolidado</p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Selecciona el periodo y presiona <span class="font-medium">Consolidar periodo</span>.
                        </p>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
