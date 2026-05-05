<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import {
    Calculator,
    CheckCircle2,
    Download,
    FileBarChart2,
    FolderSync,
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
</script>

<template>
    <Head title="Reportes por periodo" />

    <div class="app-page px-4 py-4 sm:px-6">
        <div class="space-y-6">
            <section class="app-card p-5 sm:p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="space-y-3">
                        <div
                            class="inline-flex w-fit items-center gap-2 rounded-full border border-primary/15 bg-primary/5 px-3 py-1.5 text-xs font-semibold text-primary"
                        >
                            <FileBarChart2 class="size-3.5" />
                            Consolidado operativo por periodo
                        </div>

                        <div>
                            <h1 class="text-2xl font-extrabold tracking-tight sm:text-3xl">
                                Reportes por periodo
                            </h1>
                            <p class="mt-2 max-w-3xl text-sm text-muted-foreground">
                                {{ message }}
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row">
                        <button
                            type="button"
                            class="app-btn app-btn-secondary h-11 px-5"
                            :disabled="!selectedPeriodId || consolidateForm.processing"
                            @click="consolidate"
                        >
                            <FolderSync class="mr-2 size-4" />
                            {{ consolidateForm.processing ? 'Consolidando...' : 'Consolidar periodo' }}
                        </button>

                        <button
                            type="button"
                            class="app-btn h-11 px-5"
                            :disabled="!selectedPeriodId || !summaryRows.length"
                            @click="exportSummary"
                        >
                            <Download class="mr-2 size-4" />
                            Exportar consolidado
                        </button>
                    </div>
                </div>
            </section>

            <section class="app-card p-5 shadow-xl shadow-slate-200/60 sm:p-6">
                <div class="mb-5 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="text-lg font-black tracking-tight">Reportes generados</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Consulta, previsualiza y descarga los Excel/PDF ya guardados sin recalcular todo.</p>
                    </div>
                    <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-black text-emerald-700">{{ generatedReports.length }} disponibles</span>
                </div>
                <div v-if="generatedReports.length" class="overflow-x-auto rounded-2xl border border-border/70">
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
                            <tr v-for="report in generatedReports" :key="report.id" class="border-b last:border-b-0">
                                <td class="px-4 py-3 font-black">{{ report.name }}</td>
                                <td class="px-4 py-3">{{ report.period }}<p class="text-xs text-muted-foreground">{{ report.period_code }}</p></td>
                                <td class="px-4 py-3">{{ report.type }}<p class="text-xs text-muted-foreground">{{ report.scope }}</p></td>
                                <td class="px-4 py-3">{{ report.generated_at ?? '—' }}</td>
                                <td class="px-4 py-3"><span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-black text-emerald-700">{{ report.status }}</span></td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        <a class="rounded-xl border px-3 py-2 text-xs font-bold hover:bg-muted" :href="report.preview_url">Ver</a>
                                        <a class="rounded-xl border px-3 py-2 text-xs font-bold hover:bg-muted" :href="report.excel_url">Excel</a>
                                        <a class="rounded-xl border px-3 py-2 text-xs font-bold hover:bg-muted" :href="report.pdf_url">PDF</a>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="rounded-2xl border border-dashed p-8 text-center text-sm text-muted-foreground">Aún no hay reportes generados. Genéralos desde Histórico general.</div>
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
