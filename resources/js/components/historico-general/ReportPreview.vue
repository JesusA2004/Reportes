<script setup lang="ts">
import { computed } from 'vue'
import { ExternalLink, TrendingUp } from 'lucide-vue-next'
import EmptyState from './EmptyState.vue'
import SectionHeader from './SectionHeader.vue'

const props = defineProps<{ period: any; preview: any | null; config: any }>()

const tabs = [{ k: 'metricas', l: 'Métricas' }, { k: 'empleados', l: 'Empleados' }] as const

const money = (value: number) =>
    new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(value || 0))

// Prefer PeriodSummary.global_metrics embedded in period (populated after generation)
const gm = computed(() => props.period?.preview_summary?.global_metrics ?? props.preview?.metrics ?? {})
const previewUrl = computed(() =>
    props.period?.radiography_ready ? `/reportes-mensuales/${props.period.id}/preview` : null
)

// Payroll from preview prop (MonthlyEmployeeSummary aggregates)
const payroll = computed(() => ({
    total_empleados: props.preview?.metrics?.total_empleados ?? 0,
    pagos_total:     props.preview?.metrics?.pagos_total ?? 0,
    gasto_total:     props.gm?.gasto_total ?? props.preview?.metrics?.gasto_total ?? 0,
    neto_total:      props.preview?.metrics?.neto_total ?? 0,
}))

const metricCards = computed(() => [
    { l: 'Empleados',      v: payroll.value.total_empleados },
    { l: 'Pagos',          v: money(payroll.value.pagos_total) },
    { l: 'Recuperación',   v: money(gm.value.recuperacion_total ?? 0) },
    { l: 'Colocación',     v: money(gm.value.colocacion_total ?? 0) },
    { l: 'Cartera',        v: money(gm.value.valor_cartera_total ?? 0) },
    { l: 'Mora %',         v: (gm.value.mora_porcentaje ?? 0).toFixed(2) + '%' },
    { l: 'Gastos totales', v: money(gm.value.gasto_total ?? 0) },
    { l: 'Neto nómina',    v: money(payroll.value.neto_total) },
])
</script>

<template>
    <section class="rounded-[2rem] border border-white/70 bg-white p-6 shadow-xl shadow-slate-200/70">
        <SectionHeader
            eyebrow="Etapa 5"
            title="Vista previa web"
            description="Resumen del reporte generado. Usa Ver reporte para la vista completa con empleados, sucursales e incidencias."
        />

        <EmptyState
            v-if="!period?.radiography_ready"
            class="mt-6"
            title="Aún no hay reporte generado"
            description="Genera la Radiografía en la etapa anterior para habilitar la vista previa y las exportaciones."
        />

        <div v-else class="mt-6 space-y-5">
            <!-- Header band -->
            <div class="overflow-hidden rounded-2xl bg-slate-950 p-5 text-white">
                <p class="text-xs font-black uppercase tracking-widest text-indigo-200">Radiografía generada</p>
                <h3 class="mt-1 text-xl font-black">{{ period.label }}</h3>
                <p class="mt-1 text-xs text-slate-400">
                    Tipo: {{ config?.report_type === 'simple' ? 'Radiografía simple' : 'Comparativo' }}
                    &nbsp;·&nbsp;
                    Alcance: {{ config?.scope ?? 'general' }}
                    <span v-if="period.preview_summary?.generated_at">
                        &nbsp;·&nbsp; {{ period.preview_summary.generated_at }}
                    </span>
                </p>
            </div>

            <!-- Metric cards -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div
                    v-for="card in metricCards"
                    :key="card.l"
                    class="rounded-2xl border border-slate-200 bg-slate-50 p-3"
                >
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">{{ card.l }}</p>
                    <p class="mt-1 text-base font-black text-slate-950">{{ card.v }}</p>
                </div>
            </div>

            <!-- Employees mini-table (from preview prop) -->
            <div v-if="preview?.employees?.length" class="overflow-hidden rounded-2xl border border-slate-200">
                <div class="border-b border-slate-100 bg-slate-50 px-4 py-2.5">
                    <p class="text-xs font-black text-slate-700">Empleados (primeros {{ preview.employees.length }})</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs">
                        <thead>
                            <tr class="border-b text-left text-slate-400">
                                <th class="px-3 py-2">Empleado</th>
                                <th class="px-3 py-2">Sucursal</th>
                                <th class="px-3 py-2 text-right">Pagos</th>
                                <th class="px-3 py-2 text-right">Gastos</th>
                                <th class="px-3 py-2 text-right">Neto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in preview.employees.slice(0, 10)" :key="row.id" class="border-b last:border-0">
                                <td class="px-3 py-2 font-semibold">{{ row.employee_name }}</td>
                                <td class="px-3 py-2 text-slate-500">{{ row.branch_name ?? '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ money(row.total_payments) }}</td>
                                <td class="px-3 py-2 text-right">{{ money(row.total_expenses) }}</td>
                                <td class="px-3 py-2 text-right font-black">{{ money(row.net_amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- CTA: full preview page -->
            <div v-if="previewUrl" class="flex justify-end">
                <a
                    :href="previewUrl"
                    class="inline-flex h-11 items-center gap-2 rounded-2xl bg-indigo-600 px-5 text-sm font-black text-white shadow transition hover:bg-indigo-500"
                >
                    <ExternalLink class="size-4" />
                    Ver reporte completo
                </a>
            </div>
        </div>
    </section>
</template>
