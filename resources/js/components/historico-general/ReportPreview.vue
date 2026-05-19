<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ExternalLink } from 'lucide-vue-next'
import EmptyState from './EmptyState.vue'
import SectionHeader from './SectionHeader.vue'

const props = defineProps<{ period: any; preview: any | null; config: any }>()

const money = (v: number) =>
    new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(v || 0))

// ── Filtered data fetch ──────────────────────────────────────────────────────
const filteredData    = ref<any>(null)
const filteredLoading = ref(false)

watch(
    () => [props.config?.scope, props.config?.branch_id, props.config?.employee_id, props.period?.id, props.period?.radiography_ready] as const,
    async ([scope, branchId, employeeId, periodId, ready]) => {
        if (!ready || !periodId || (scope !== 'branch' && scope !== 'employee')) {
            filteredData.value = null
            return
        }
        if (scope === 'branch' && !branchId) { filteredData.value = null; return }
        if (scope === 'employee' && !employeeId) { filteredData.value = null; return }

        filteredLoading.value = true
        try {
            const p = new URLSearchParams({ scope: String(scope) })
            if (scope === 'branch')   p.set('branch_id',   String(branchId))
            if (scope === 'employee') p.set('employee_id', String(employeeId))
            const res = await fetch(`/reportes-mensuales/${periodId}/filtrado-datos?${p}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
            filteredData.value = res.ok ? await res.json() : null
        } catch { filteredData.value = null }
        finally { filteredLoading.value = false }
    },
    { immediate: true },
)

// ── Labels ───────────────────────────────────────────────────────────────────
const scopeLabel = computed(() => {
    const s = props.config?.scope
    if (s === 'branch')   return filteredData.value?.label ? `Sucursal: ${filteredData.value.label}` : 'Por sucursal'
    if (s === 'employee') return filteredData.value?.label ? `Gestor: ${filteredData.value.label}`   : 'Por gestor'
    return 'General'
})

// ── Metric cards ─────────────────────────────────────────────────────────────
const metricCards = computed(() => {
    const d = filteredData.value?.data
    if (d) {
        const cards: { l: string; v: any }[] = [
            { l: 'Recuperación', v: money(d.recuperacion ?? 0) },
            { l: 'Colocación',   v: money(d.colocacion   ?? 0) },
            { l: 'Cartera',      v: money(d.cartera       ?? 0) },
            { l: 'Mora %',       v: (d.mora_pct ?? 0).toFixed(2) + '%' },
        ]
        if (d.gastos      !== undefined) cards.push({ l: 'Gastos Op.',  v: money(d.gastos) })
        if (d.nomina      !== undefined) cards.push({ l: 'Nómina',      v: money(d.nomina) })
        if (d.operaciones !== undefined) cards.push({ l: 'Operaciones', v: d.operaciones })
        if (d.pagos       !== undefined) cards.push({ l: 'Pagos',       v: money(d.pagos) })
        if (d.neto        !== undefined) cards.push({ l: 'Neto nómina', v: money(d.neto) })
        return cards
    }
    const gm = props.period?.preview_summary?.global_metrics ?? props.preview?.metrics ?? {}
    return [
        { l: 'Empleados',      v: (gm.total_empleados ?? 0) },
        { l: 'Pagos',          v: money(gm.pagos_total ?? 0) },
        { l: 'Recuperación',   v: money(gm.recuperacion_total ?? 0) },
        { l: 'Colocación',     v: money(gm.colocacion_total ?? 0) },
        { l: 'Cartera',        v: money(gm.valor_cartera_total ?? 0) },
        { l: 'Mora %',         v: (gm.mora_porcentaje ?? 0).toFixed(2) + '%' },
        { l: 'Gastos totales', v: money(gm.gasto_total ?? 0) },
        { l: 'Neto nómina',    v: money(gm.neto_total ?? 0) },
    ]
})

// ── "Ver reporte completo" URL — preserva el filtro aplicado ─────────────────
const previewUrl = computed(() => {
    if (!props.period?.radiography_ready) return null
    const base = `/reportes-mensuales/${props.period.id}/preview`
    if (props.config?.scope === 'branch' && props.config?.branch_id)
        return `${base}?scope=branch&branch_id=${props.config.branch_id}`
    if (props.config?.scope === 'employee' && props.config?.employee_id)
        return `${base}?scope=employee&employee_id=${props.config.employee_id}`
    return base
})
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
                    Alcance: {{ scopeLabel }}
                    <span v-if="period.preview_summary?.generated_at">
                        &nbsp;·&nbsp; {{ period.preview_summary.generated_at }}
                    </span>
                </p>
            </div>

            <!-- Metric cards (filtradas si scope=branch/employee) -->
            <div v-if="filteredLoading" class="flex items-center gap-2 text-sm text-slate-500">
                <svg class="size-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                Cargando datos filtrados…
            </div>
            <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-4">
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
