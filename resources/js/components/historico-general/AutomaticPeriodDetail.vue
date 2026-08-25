<script setup lang="ts">
import { computed } from 'vue'
import {
    Ban, CalendarDays, CheckCircle2, Clock, FileSpreadsheet,
    FileText, Info, Layers3, LoaderCircle, Play, XCircle,
} from 'lucide-vue-next'
import StatusBadge from './StatusBadge.vue'

const props = defineProps<{ period: any }>()
const emit = defineEmits<{ (e: 'generate'): void }>()

const isConsolidated  = computed(() => !!props.period?.radiography_ready)
const isRunning       = computed(() => ['queued', 'running'].includes(props.period?.radiography_run_status ?? ''))
const isQueued        = computed(() => props.period?.radiography_run_status === 'queued')
const isFailed        = computed(() => props.period?.radiography_run_status === 'failed')
const isCancelled     = computed(() => props.period?.radiography_run_status === 'cancelled')
const canConsolidate  = computed(() => !!props.period?.can_generate_automatic && !isRunning.value)
const isWaiting       = computed(() => !isConsolidated.value && !canConsolidate.value && !isRunning.value && !isFailed.value && !isCancelled.value)

const overallStatus = computed(() => {
    if (isConsolidated.value) return 'consolidated'
    if (canConsolidate.value || isRunning.value) return 'ready_to_consolidate'
    return 'waiting'
})

const overallStatusLabel = computed(() => ({
    consolidated: 'Consolidado',
    ready_to_consolidate: 'Listo para consolidar',
    waiting: 'Esperando meses',
}[overallStatus.value]))

const typeLabels: Record<string, string> = {
    bimonthly: 'Bimestre',
    quarterly: 'Trimestre',
    semiannual: 'Semestre',
    annual: 'Año',
}
const typeLabel = computed(() => typeLabels[props.period?.type ?? ''] ?? 'Periodo compuesto')

const requiredCounts: Record<string, number> = {
    bimonthly: 2, quarterly: 3, semiannual: 6, annual: 12,
}
const requiredCount = computed(() => requiredCounts[props.period?.type ?? ''] ?? 0)

const excelUrl = computed(() => `/reportes-mensuales/${props.period?.id}/radiografia.xlsx`)
const pdfUrl   = computed(() => `/reportes-mensuales/${props.period?.id}/radiografia.pdf`)

const runLog = computed(() => props.period?.radiography_run_log ?? null)
const runError = computed(() => props.period?.radiography_run_error ?? null)
</script>

<template>
    <div class="space-y-5">

        <!-- ── Cabecera informativa ── -->
        <section class="overflow-hidden rounded-[2rem] border border-violet-100 bg-gradient-to-br from-violet-50 via-white to-indigo-50 p-6 shadow-xl shadow-slate-200/70">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="flex gap-4">
                    <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-violet-600 text-white shadow-lg shadow-violet-200">
                        <Layers3 class="size-6" />
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <p class="text-xs font-black uppercase tracking-[0.22em] text-violet-700">{{ typeLabel }} automático</p>
                            <span class="group relative cursor-help">
                                <Info class="size-3.5 text-violet-400 hover:text-violet-600 transition-colors" />
                                <div class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-2 w-72 -translate-x-1/2 rounded-2xl bg-slate-900 px-4 py-3 text-xs leading-5 text-white opacity-0 shadow-xl transition-opacity group-hover:opacity-100">
                                    Bimestres, trimestres, semestres y años se generan automáticamente a partir de meses operativos. No aceptan carga directa de archivos.
                                </div>
                            </span>
                        </div>
                        <h3 class="mt-1 text-xl font-black text-slate-950">{{ period.label }}</h3>
                        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                            Este periodo es automático. No requiere carga directa de archivos.
                        </p>
                        <p class="mt-1 text-sm leading-6 text-slate-500">
                            Se construye con la información procesada de sus meses operativos.
                        </p>
                    </div>
                </div>
                <StatusBadge :status="overallStatus" :label="overallStatusLabel" />
            </div>

            <!-- Fechas -->
            <div v-if="period.start_date" class="mt-4 flex gap-2 text-xs text-violet-600">
                <CalendarDays class="size-3.5 shrink-0" />
                <span>{{ period.start_date }} → {{ period.end_date }}</span>
            </div>
        </section>

        <!-- ── Meses operativos componentes ── -->
        <section class="rounded-[2rem] border border-white/70 bg-white p-6 shadow-xl shadow-slate-200/70">
            <div class="mb-5 flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="flex size-9 items-center justify-center rounded-xl bg-slate-100">
                        <CalendarDays class="size-4 text-slate-600" />
                    </div>
                    <div>
                        <p class="font-black text-slate-950">Meses operativos componentes</p>
                        <p class="text-xs text-slate-500">
                            Se requieren {{ requiredCount }} reporte(s) mensual(es) generados para consolidar este {{ typeLabel.toLowerCase() }}.
                            <span class="group relative ml-1 inline-flex cursor-help">
                                <Info class="size-3 text-slate-400 hover:text-slate-600 transition-colors" />
                                <span class="pointer-events-none absolute bottom-full left-0 z-20 mb-1 w-64 rounded-xl bg-slate-900 px-3 py-2 text-xs leading-4 text-white opacity-0 shadow-lg transition-opacity group-hover:opacity-100">
                                    Las fuentes se cargan en los meses operativos. Este periodo usa los datos ya procesados de sus componentes.
                                </span>
                            </span>
                        </p>
                    </div>
                </div>
                <span class="text-xs text-slate-400">
                    {{ (period.automatic_components ?? []).filter((c: any) => c.has_report).length }}/{{ period.automatic_components?.length ?? 0 }} listos
                </span>
            </div>

            <!-- Sin componentes configurados -->
            <div v-if="!period.automatic_components?.length" class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-center">
                <p class="text-sm font-bold text-amber-800">Sin meses operativos configurados</p>
                <p class="mt-1 text-xs text-amber-700">Configura los meses que componen este periodo en la sección de Periodos.</p>
            </div>

            <!-- Grid de componentes -->
            <div v-else class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="comp in period.automatic_components"
                    :key="comp.id"
                    class="rounded-2xl border p-4 shadow-sm transition"
                    :class="comp.has_report
                        ? 'border-emerald-200 bg-emerald-50/40'
                        : comp.report_status === 'database_updated'
                            ? 'border-sky-200 bg-sky-50/40'
                            : 'border-amber-200 bg-amber-50/30'"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-2.5">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-xl"
                                :class="comp.has_report ? 'bg-emerald-100' : comp.report_status === 'database_updated' ? 'bg-sky-100' : 'bg-amber-100'"
                            >
                                <CheckCircle2 v-if="comp.has_report" class="size-4 text-emerald-600" />
                                <Clock v-else-if="comp.report_status === 'database_updated'" class="size-4 text-sky-600" />
                                <Clock v-else class="size-4 text-amber-500" />
                            </div>
                            <div>
                                <p class="text-sm font-black text-slate-950">{{ comp.label }}</p>
                                <p class="text-xs text-slate-500">Mes operativo</p>
                            </div>
                        </div>
                        <StatusBadge
                            :status="comp.has_report ? 'generated' : comp.report_status === 'database_updated' ? 'ready' : 'pending'"
                            :label="comp.has_report ? 'Reporte generado' : comp.report_status === 'database_updated' ? 'BD cargada' : 'Sin reporte'"
                        />
                    </div>
                </div>
            </div>

            <!-- Faltan meses -->
            <div v-if="period.missing_component_months?.length" class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                <p class="text-sm font-bold text-amber-800">
                    No se puede generar este {{ typeLabel.toLowerCase() }} todavía.
                </p>
                <p class="mt-1 text-sm text-amber-700">
                    Primero debes generar los reportes mensuales de:
                    <strong>{{ period.missing_component_months.join(', ') }}</strong>.
                </p>
            </div>

            <!-- Todos listos -->
            <div v-else-if="period.automatic_components?.length && !isConsolidated" class="mt-5 rounded-2xl border border-sky-200 bg-sky-50 p-4">
                <p class="text-sm font-bold text-sky-800">Todos los meses componentes tienen reporte generado.</p>
                <p class="mt-1 text-xs text-sky-600">
                    Este periodo está listo para consolidarse. Se usarán los datos ya procesados de sus meses operativos.
                </p>
            </div>
        </section>

        <!-- ── Generación / Estado ── -->
        <section class="rounded-[2rem] border border-white/70 bg-white p-6 shadow-xl shadow-slate-200/70">
            <div class="mb-5 flex items-center gap-3">
                <p class="font-black text-slate-950">
                    <template v-if="isConsolidated">Reporte consolidado</template>
                    <template v-else>Generar reporte consolidado</template>
                </p>
                <span class="group relative cursor-help">
                    <Info class="size-3.5 text-slate-400 hover:text-slate-600 transition-colors" />
                    <span class="pointer-events-none absolute bottom-full left-0 z-20 mb-1 w-72 rounded-xl bg-slate-900 px-3 py-2 text-xs leading-5 text-white opacity-0 shadow-lg transition-opacity group-hover:opacity-100">
                        <template v-if="!canConsolidate && !isConsolidated">Primero genera los reportes de todos los meses que componen este periodo.</template>
                        <template v-else>El reporte consolidado agrega los resultados de todos los meses operativos. No requiere subir archivos nuevos.</template>
                    </span>
                </span>
            </div>

            <!-- Estado de generación activa -->
            <div v-if="isRunning" class="mb-5 rounded-2xl border p-4" :class="isQueued ? 'border-violet-200 bg-violet-50' : 'border-indigo-200 bg-indigo-50'">
                <div class="flex items-center gap-2.5">
                    <LoaderCircle class="size-4 shrink-0 animate-spin" :class="isQueued ? 'text-violet-600' : 'text-indigo-600'" />
                    <div>
                        <p class="text-sm font-bold" :class="isQueued ? 'text-violet-800' : 'text-indigo-800'">
                            {{ isQueued ? 'En cola — esperando worker' : 'Generando consolidado…' }}
                        </p>
                        <p v-if="runLog" class="mt-0.5 truncate text-xs" :class="isQueued ? 'text-violet-600' : 'text-indigo-600'">
                            {{ runLog }}
                        </p>
                    </div>
                </div>
                <p class="mt-2 text-xs" :class="isQueued ? 'text-violet-600' : 'text-indigo-600'">
                    Puedes cerrar esta ventana. Recibirás un correo cuando el reporte esté listo.
                </p>
            </div>

            <!-- Error — solo si el periodo NO quedó consolidado. Un run puede marcar 'failed'
                 en un paso posterior (ej. resolver de gastos) mientras el consolidado en sí ya
                 se generó correctamente (isConsolidated=true); en ese caso no es un error real
                 para el usuario, el Excel/PDF de abajo ya está disponible y correcto. -->
            <div v-if="isFailed && runError && !isConsolidated" class="mb-5 rounded-2xl border border-rose-200 bg-rose-50 p-4">
                <div class="flex items-center gap-2.5">
                    <XCircle class="size-4 shrink-0 text-rose-600" />
                    <p class="text-sm font-bold text-rose-800">La generación falló</p>
                </div>
                <p class="mt-2 break-all rounded-xl bg-rose-100 px-3 py-2 font-mono text-xs leading-5 text-rose-800">{{ runError }}</p>
            </div>

            <!-- Cancelado -->
            <div v-if="isCancelled" class="mb-5 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <div class="flex items-center gap-2.5">
                    <Ban class="size-4 shrink-0 text-slate-500" />
                    <p class="text-sm font-bold text-slate-700">Generación cancelada</p>
                </div>
                <p class="mt-1 text-xs text-slate-500">Puedes reiniciarla cuando estés listo.</p>
            </div>

            <!-- Descarga (cuando está consolidado) -->
            <div v-if="isConsolidated" class="mb-5 flex flex-wrap gap-3">
                <a
                    :href="excelUrl"
                    class="inline-flex items-center gap-2 rounded-2xl border border-emerald-300 bg-white px-4 py-2.5 text-sm font-bold text-emerald-700 shadow-sm transition hover:bg-emerald-50"
                >
                    <FileSpreadsheet class="size-4" />Descargar Excel
                </a>
                <a
                    :href="pdfUrl"
                    class="inline-flex items-center gap-2 rounded-2xl border border-rose-300 bg-white px-4 py-2.5 text-sm font-bold text-rose-700 shadow-sm transition hover:bg-rose-50"
                >
                    <FileText class="size-4" />Descargar PDF
                </a>
            </div>

            <!-- Botón principal -->
            <button
                v-if="!isRunning"
                type="button"
                class="inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl px-5 text-sm font-black transition focus:outline-none focus:ring-4 disabled:cursor-not-allowed disabled:opacity-40"
                :class="isConsolidated
                    ? 'bg-slate-700 text-white shadow-lg shadow-slate-200 hover:bg-slate-800 focus:ring-slate-100'
                    : canConsolidate || isFailed || isCancelled
                        ? 'bg-violet-600 text-white shadow-lg shadow-violet-200 hover:bg-violet-700 focus:ring-violet-100'
                        : 'bg-slate-200 text-slate-400'"
                :disabled="!canConsolidate && !isFailed && !isCancelled && !isConsolidated"
                @click="emit('generate')"
            >
                <Play class="size-4" />
                <span v-if="isConsolidated">Regenerar consolidado</span>
                <span v-else-if="isFailed || isCancelled">Reintentar generación</span>
                <span v-else-if="canConsolidate">Generar consolidado</span>
                <span v-else>Esperando reportes mensuales</span>
            </button>

            <p v-if="isWaiting && !period.automatic_components?.length" class="mt-3 text-center text-xs text-slate-400">
                Configura los meses operativos en la sección de Periodos para habilitar la consolidación.
            </p>
            <p v-else-if="isWaiting" class="mt-3 text-center text-xs text-slate-400">
                Genera los reportes mensuales faltantes para habilitar este botón.
            </p>
        </section>

    </div>
</template>
