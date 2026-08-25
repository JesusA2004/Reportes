<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import {
    AlertTriangle, Ban, CheckCircle, Clock, DatabaseZap, Download,
    FileSpreadsheet, FileText, LoaderCircle, Play, RefreshCw, ShieldCheck,
    TriangleAlert, XCircle,
} from 'lucide-vue-next'
import SectionHeader from './SectionHeader.vue'
import StatusBadge from './StatusBadge.vue'

const props = defineProps<{
    period: any
    reportConfig: any
    canGenerate: boolean
    isSubmitting?: boolean
}>()

const emit = defineEmits<{
    (e: 'generate'): void
    (e: 'cancel'): void
    (e: 'refresh'): void
    (e: 'process-now'): void
}>()

// ── Live state — synced from Inertia props, kept fresh by polling ─────
const liveStatus     = ref<string | null>(null)
const liveLog        = ref<string | null>(null)
const liveError      = ref<string | null>(null)
const liveQueuedAt   = ref<string | null>(null)
const liveStartedAt  = ref<string | null>(null)
const liveFinishedAt = ref<string | null>(null)
const liveElapsed    = ref<number | null>(null)
const liveStuck      = ref(false)
const liveMeta       = ref<any>(null)
const liveExcelUrl   = ref<string | null>(null)
const livePdfUrl     = ref<string | null>(null)
const liveCanProcessNow = ref(false)

const syncFromProps = () => {
    liveStatus.value        = props.period?.radiography_run_status ?? null
    liveLog.value           = props.period?.radiography_run_log ?? null
    liveError.value         = props.period?.radiography_run_error ?? null
    liveQueuedAt.value      = props.period?.radiography_run_queued_at ?? null
    liveStartedAt.value     = props.period?.radiography_run_started_at ?? null
    liveFinishedAt.value    = props.period?.radiography_run_finished_at ?? null
    liveElapsed.value       = null
    liveStuck.value         = false
    liveMeta.value          = props.period?.radiography_run_metadata ?? null
    // No resetear liveExcelUrl/livePdfUrl aquí: al terminar la generación, el padre
    // recarga la página (Inertia) y este watcher se dispara de nuevo — si limpiara
    // las URLs, el botón "Descargar" caería al fallback plano de abajo aunque el run
    // real fuera un comparativo/por sucursal, descargando el reporte equivocado.
    liveCanProcessNow.value = !!props.period?.radiography_can_process_now
}

watch(() => props.period?.radiography_run_status, syncFromProps, { immediate: true })

// ── Dedicated polling — independent of Inertia page reload ───────────
let pollTimer: ReturnType<typeof setInterval> | null = null
const clearPoll = () => {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null }
}

const pollProgress = async () => {
    if (!props.period?.id) return
    try {
        const res = await fetch(`/historico-general/${props.period.id}/generar-reporte/progreso`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        })
        if (!res.ok) return
        const data = await res.json()

        liveStatus.value        = data.status
        liveLog.value           = data.log
        liveError.value         = data.error_message
        liveQueuedAt.value      = data.queued_at
        liveStartedAt.value     = data.started_at
        liveFinishedAt.value    = data.finished_at
        liveElapsed.value       = typeof data.elapsed_seconds === 'number' ? data.elapsed_seconds : null
        liveStuck.value         = !!data.stuck_warning
        liveMeta.value          = data.metadata ?? null
        liveExcelUrl.value      = data.excel_url ?? null
        livePdfUrl.value        = data.pdf_url ?? null
        liveCanProcessNow.value = !!data.can_process_now

        // Stop polling once terminal — let parent reload Inertia
        if (!['queued', 'running'].includes(data.status ?? '')) {
            clearPoll()
            emit('refresh')
        }
    } catch {
        // silent — retry next tick
    }
}

watch(
    () => liveStatus.value,
    (status) => {
        clearPoll()
        if (status === 'queued' || status === 'running') {
            pollTimer = setInterval(pollProgress, 3000)
        }
    },
    { immediate: true },
)

// ── Live elapsed ticker ───────────────────────────────────────────────
const liveSeconds = ref<number | null>(null)
let ticker: ReturnType<typeof setInterval> | null = null
const clearTicker = () => { if (ticker) { clearInterval(ticker); ticker = null } }

watch(
    () => [liveElapsed.value, liveStatus.value] as const,
    ([secs, status]) => {
        clearTicker()
        liveSeconds.value = typeof secs === 'number' ? secs : null
        if (status === 'queued' || status === 'running') {
            ticker = setInterval(() => {
                if (liveSeconds.value !== null) liveSeconds.value++
            }, 1000)
        }
    },
    { immediate: true },
)

onMounted(() => {
    syncFromProps()
    // Si el reporte ya estaba generado al montar (isDone desde props), pide de una
    // vez las URLs reales al backend — nunca confiar en un fallback plano adivinado,
    // que podría apuntar al reporte simple aunque lo generado fuera un comparativo.
    if (props.period?.radiography_ready) pollProgress()
})
onUnmounted(() => { clearTicker(); clearPoll() })

// ── Computed state ────────────────────────────────────────────────────
const isQueued    = computed(() => liveStatus.value === 'queued')
const isRunning   = computed(() => ['queued', 'running'].includes(liveStatus.value ?? ''))
const isFailed    = computed(() => liveStatus.value === 'failed')
const isCancelled = computed(() => liveStatus.value === 'cancelled')
const isDone              = computed(() => props.period?.radiography_ready)
const hasPreviousReport   = computed(() => props.period?.has_previous_radiography)
const previousReportAt    = computed(() => props.period?.previous_radiography_at ?? null)

const elapsedFormatted = computed(() => {
    if (liveSeconds.value === null) return null
    const s = liveSeconds.value
    const mins = Math.floor(s / 60)
    const secs = s % 60
    if (mins === 0) return `${secs} seg`
    return `${mins} min ${String(secs).padStart(2, '0')} seg`
})

const runMeta     = computed(() => liveMeta.value)
const progress    = computed(() => runMeta.value?.progress_percent ?? null)
const currentStep = computed(() => runMeta.value?.current_step ?? null)

// ── Config summary labels ─────────────────────────────────────────────
const reportTypeLabel = computed(() => {
    switch (props.reportConfig?.report_type) {
        case 'simple':                return 'Radiografía simple'
        case 'month_vs_month':        return 'Comparativo mes vs mes'
        case 'bimester_vs_bimester':  return 'Comparativo bimestre vs bimestre'
        default:                      return 'Comparativo trimestre vs trimestre'
    }
})
const scopeLabel = computed(() => {
    switch (props.reportConfig?.scope) {
        case 'general': return 'General (todas las sucursales)'
        case 'branch':  return 'Por sucursal'
        default:        return 'Por empleado / gestor'
    }
})

// ── Status card config ────────────────────────────────────────────────
// IMPORTANTE: isFailed/isCancelled (derivados del run EN VIVO vía polling) se
// comprueban ANTES que isDone (derivado de radiography_ready, un prop de
// Inertia que puede tardar un ciclo en refrescarse). Nunca debe poder mostrarse
// la tarjeta verde "Reporte generado" al mismo tiempo que el bloque de error —
// ver el bug de julio: un run fallido dejaba radiography_ready=true porque el
// PeriodSummary ya se había marcado 'generated' antes del paso que reventó.
const statusConfig = computed(() => {
    if (isQueued.value)    return { color: 'bg-violet-50 border-violet-200',   text: 'text-violet-700',   label: 'En cola…',               icon: LoaderCircle,   iconClass: 'text-violet-600 animate-spin' }
    if (isRunning.value)   return { color: 'bg-indigo-50 border-indigo-200',   text: 'text-indigo-700',   label: 'Generando…',             icon: LoaderCircle,   iconClass: 'text-indigo-600 animate-spin' }
    if (isFailed.value)    return { color: 'bg-rose-50 border-rose-200',       text: 'text-rose-700',     label: 'La generación falló',    icon: XCircle,        iconClass: 'text-rose-600' }
    if (isCancelled.value) return { color: 'bg-slate-100 border-slate-300',    text: 'text-slate-600',    label: 'Generación cancelada',   icon: Ban,            iconClass: 'text-slate-500' }
    if (isDone.value)      return { color: 'bg-emerald-50 border-emerald-200', text: 'text-emerald-700',  label: 'Reporte generado',       icon: CheckCircle,    iconClass: 'text-emerald-600' }
    if (props.canGenerate) return { color: 'bg-slate-50 border-slate-200',     text: 'text-slate-600',    label: 'Lista para generar',     icon: ShieldCheck,    iconClass: 'text-slate-500' }
    return                  { color: 'bg-amber-50 border-amber-200',           text: 'text-amber-700',    label: 'Bloqueado',              icon: TriangleAlert,  iconClass: 'text-amber-600' }
})
</script>

<template>
    <section class="rounded-[2rem] border border-white/70 bg-white p-6 shadow-xl shadow-slate-200/70">
        <SectionHeader
            eyebrow="Etapa 5"
            title="Generar reporte"
            description="Calcula métricas, consolida la nómina y genera los archivos Excel y PDF. El proceso corre en segundo plano; recibirás un correo cuando termine."
        />

        <div class="mt-6 space-y-4">

            <!-- Fila superior: config + estado -->
            <div class="grid gap-4 lg:grid-cols-[1fr_0.9fr]">

                <!-- Configuración seleccionada -->
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                    <div class="flex items-center gap-3">
                        <FileText class="size-6 shrink-0 text-indigo-600" />
                        <div>
                            <p class="font-black text-slate-950">Configuración del reporte</p>
                            <p class="text-sm text-slate-500">Tipo y alcance seleccionados en la etapa anterior</p>
                        </div>
                    </div>
                    <div class="mt-4 space-y-2">
                        <div class="flex items-center justify-between rounded-xl bg-white px-4 py-2.5 shadow-sm ring-1 ring-slate-200">
                            <span class="text-xs font-bold text-slate-500 uppercase tracking-wide">Tipo</span>
                            <span class="text-sm font-black text-slate-900">{{ reportTypeLabel }}</span>
                        </div>
                        <div class="flex items-center justify-between rounded-xl bg-white px-4 py-2.5 shadow-sm ring-1 ring-slate-200">
                            <span class="text-xs font-bold text-slate-500 uppercase tracking-wide">Alcance</span>
                            <span class="text-sm font-black text-slate-900">{{ scopeLabel }}</span>
                        </div>
                        <div v-if="period?.blocking_reasons?.length" class="mt-3 rounded-2xl border border-amber-200 bg-amber-50 p-3">
                            <p class="text-xs font-black text-amber-800">Bloqueado — razones:</p>
                            <ul class="mt-1.5 list-disc pl-4 space-y-0.5 text-xs text-amber-700">
                                <li v-for="reason in period.blocking_reasons" :key="reason">{{ reason }}</li>
                            </ul>
                        </div>
                        <!-- Reporte anterior disponible aunque el flujo esté bloqueado -->
                        <div v-if="hasPreviousReport && !isDone" class="mt-3 rounded-2xl border border-sky-200 bg-sky-50 p-3">
                            <p class="text-xs font-black text-sky-800">Reporte anterior disponible</p>
                            <p class="text-xs text-sky-700 mt-1">Generado el {{ previousReportAt }}. Puedes descargarlo mientras se completa la nueva actualización.</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <a
                                    :href="`/reportes-mensuales/${period?.id}/radiografia.xlsx`"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-sky-300 bg-white px-3 py-1.5 text-xs font-bold text-sky-700 shadow-sm transition hover:bg-sky-50"
                                >
                                    <FileSpreadsheet class="size-3.5" />Excel anterior
                                </a>
                                <a
                                    :href="`/reportes-mensuales/${period?.id}/radiografia.pdf`"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-sky-300 bg-white px-3 py-1.5 text-xs font-bold text-sky-700 shadow-sm transition hover:bg-sky-50"
                                >
                                    <FileText class="size-3.5" />PDF anterior
                                </a>
                                <a
                                    :href="`/reportes-mensuales/${period?.id}/preview`"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-sky-300 bg-white px-3 py-1.5 text-xs font-bold text-sky-700 shadow-sm transition hover:bg-sky-50"
                                >
                                    <Download class="size-3.5" />Vista previa
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Estado y acciones -->
                <div class="space-y-4">

                    <!-- Card de estado -->
                    <div class="rounded-2xl border p-5 transition-all duration-300" :class="statusConfig.color">
                        <div class="flex items-center gap-3">
                            <component :is="statusConfig.icon" class="size-6 shrink-0" :class="statusConfig.iconClass" />
                            <div class="min-w-0 flex-1">
                                <p class="font-black text-slate-950">{{ statusConfig.label }}</p>
                                <p v-if="liveLog" class="mt-0.5 truncate text-xs leading-5" :class="statusConfig.text">{{ liveLog }}</p>
                            </div>
                        </div>

                        <!-- Barra de progreso -->
                        <div v-if="isRunning && progress !== null" class="mt-4">
                            <div class="mb-1.5 flex items-center justify-between text-xs font-medium text-slate-600">
                                <span class="truncate pr-3">
                                    <span v-if="currentStep" class="font-bold text-indigo-700">{{ currentStep }}</span>
                                    <span v-else>Procesando…</span>
                                </span>
                                <span class="shrink-0 font-black text-indigo-700">{{ progress }}%</span>
                            </div>
                            <div class="h-2.5 w-full overflow-hidden rounded-full bg-slate-200">
                                <div
                                    class="h-full rounded-full bg-indigo-500 transition-all duration-700"
                                    :style="{ width: `${progress}%` }"
                                />
                            </div>
                        </div>

                        <!-- Timestamps y tiempo transcurrido -->
                        <div v-if="liveQueuedAt || liveStartedAt || liveFinishedAt || elapsedFormatted" class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                            <span v-if="liveQueuedAt && !liveStartedAt">En cola: <strong>{{ liveQueuedAt }}</strong></span>
                            <span v-if="liveStartedAt">Inicio: <strong>{{ liveStartedAt }}</strong></span>
                            <span v-if="liveFinishedAt">Fin: <strong>{{ liveFinishedAt }}</strong></span>
                            <span v-if="elapsedFormatted" class="flex items-center gap-1">
                                <Clock class="size-3 shrink-0" /> Transcurrido: <strong>{{ elapsedFormatted }}</strong>
                            </span>
                        </div>

                        <!-- Error detail -->
                        <div v-if="isFailed && liveError" class="mt-3 break-all rounded-xl bg-rose-100 p-3 font-mono text-xs leading-5 text-rose-800">{{ liveError }}</div>

                        <!-- Descarga (éxito con archivos listos) — nunca se adivina la URL: si
                             isDone pero aún no llegó la respuesta real del backend, el botón se
                             muestra deshabilitado en vez de apuntar a un archivo posiblemente
                             equivocado (p. ej. el simple cuando lo generado fue un comparativo). -->
                        <div v-if="!isFailed && (isDone || liveExcelUrl || livePdfUrl)" class="mt-4 flex flex-wrap gap-2">
                            <a
                                v-if="liveExcelUrl"
                                :href="liveExcelUrl"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-emerald-300 bg-white px-3 py-2 text-xs font-bold text-emerald-700 shadow-sm transition hover:bg-emerald-50"
                            >
                                <FileSpreadsheet class="size-3.5" />Descargar Excel
                            </a>
                            <span v-else-if="isDone" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-400">
                                <LoaderCircle class="size-3.5 animate-spin" />Descargar Excel
                            </span>
                            <a
                                v-if="livePdfUrl"
                                :href="livePdfUrl"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-rose-300 bg-white px-3 py-2 text-xs font-bold text-rose-700 shadow-sm transition hover:bg-rose-50"
                            >
                                <FileText class="size-3.5" />Descargar PDF
                            </a>
                            <span v-else-if="isDone" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-400">
                                <LoaderCircle class="size-3.5 animate-spin" />Descargar PDF
                            </span>
                        </div>
                    </div>

                    <!-- Alerta de proceso atascado -->
                    <div v-if="liveStuck" class="rounded-2xl border border-amber-300 bg-amber-50 p-4">
                        <div class="flex items-start gap-2.5">
                            <AlertTriangle class="mt-0.5 size-4 shrink-0 text-amber-600" />
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-bold text-amber-800">El proceso lleva más tiempo del esperado</p>
                                <p class="mt-1 text-xs leading-5 text-amber-700">
                                    {{ isQueued ? 'Lleva más de 5 min en cola sin iniciar.' : 'Lleva más de 30 min ejecutando.' }}
                                    Verifica que el worker esté activo:
                                </p>
                                <code class="mt-2 block break-all rounded-xl bg-amber-100 px-3 py-2 font-mono text-[11px] leading-5 text-amber-900">
                                    php -d memory_limit=1024M artisan queue:work database --queue=default --tries=1 --timeout=1800 --memory=1024 --sleep=3 -vvv
                                </code>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <button type="button" class="inline-flex items-center gap-1.5 rounded-xl border border-amber-300 bg-white px-3 py-2 text-xs font-bold text-amber-700 transition hover:bg-amber-50" @click="emit('refresh')">
                                        <RefreshCw class="size-3.5" />Verificar estado
                                    </button>
                                    <button type="button" class="inline-flex items-center gap-1.5 rounded-xl border border-rose-300 bg-white px-3 py-2 text-xs font-bold text-rose-700 transition hover:bg-rose-50" @click="emit('cancel')">
                                        <Ban class="size-3.5" />Cancelar proceso
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Aviso corriendo (sin stuck) -->
                    <div v-if="isRunning && !liveStuck" class="space-y-3">

                        <!-- En cola sin worker -->
                        <div v-if="isQueued" class="rounded-2xl border border-violet-200 bg-violet-50 p-4">
                            <div class="flex items-start gap-2.5">
                                <LoaderCircle class="mt-0.5 size-4 shrink-0 animate-spin text-violet-600" />
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-bold text-violet-800">Job en cola — esperando worker</p>
                                    <p class="mt-1 text-xs leading-5 text-violet-700">La generación está encolada correctamente. Inicia el worker para procesarla. Puedes cerrar esta ventana; recibirás correo cuando termine.</p>
                                    <code class="mt-2 block rounded-xl bg-violet-100 px-3 py-2 font-mono text-[11px] leading-5 text-violet-900">php artisan queue:work --tries=1 --timeout=0 -vvv</code>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <button type="button" class="inline-flex items-center gap-1.5 rounded-xl border border-violet-300 bg-white px-3 py-2 text-xs font-bold text-violet-700 transition hover:bg-violet-50" @click="emit('refresh')">
                                            <RefreshCw class="size-3.5" />Verificar estado
                                        </button>
                                        <button v-if="liveCanProcessNow" type="button" class="inline-flex items-center gap-1.5 rounded-xl border border-indigo-300 bg-white px-3 py-2 text-xs font-bold text-indigo-700 transition hover:bg-indigo-50" @click="emit('process-now')">
                                            <DatabaseZap class="size-3.5" />Procesar ahora (local)
                                        </button>
                                        <button type="button" class="inline-flex items-center gap-1.5 rounded-xl border border-rose-200 bg-white px-3 py-2 text-xs font-bold text-rose-600 transition hover:bg-rose-50" @click="emit('cancel')">
                                            <Ban class="size-3.5" />Cancelar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Running normalmente -->
                        <div v-else class="rounded-2xl border border-indigo-200 bg-indigo-50 p-4">
                            <p class="text-sm font-bold text-indigo-800">La generación corre en segundo plano.</p>
                            <p class="mt-1 text-xs text-indigo-600">Puedes cerrar esta ventana. Te avisaremos por correo cuando Excel y PDF estén listos.</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button type="button" class="inline-flex items-center gap-1.5 rounded-xl border border-indigo-300 bg-white px-3 py-2 text-xs font-bold text-indigo-700 transition hover:bg-indigo-50" @click="emit('refresh')">
                                    <RefreshCw class="size-3.5" />Actualizar estado
                                </button>
                                <button type="button" class="inline-flex items-center gap-1.5 rounded-xl border border-rose-200 bg-white px-3 py-2 text-xs font-bold text-rose-600 transition hover:bg-rose-50" @click="emit('cancel')">
                                    <Ban class="size-3.5" />Cancelar proceso
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Botón acción principal -->
                    <button
                        v-if="!isRunning"
                        type="button"
                        class="inline-flex h-12 w-full items-center justify-center gap-2 rounded-2xl px-5 text-sm font-black transition focus:outline-none focus:ring-4 disabled:cursor-not-allowed disabled:opacity-50"
                        :class="isFailed
                            ? 'bg-rose-600 text-white shadow-lg shadow-rose-200 hover:bg-rose-700 focus:ring-rose-100'
                            : isCancelled
                                ? 'bg-slate-700 text-white shadow-lg shadow-slate-200 hover:bg-slate-800 focus:ring-slate-100'
                                : isDone
                                    ? 'bg-slate-700 text-white shadow-lg shadow-slate-200 hover:bg-slate-800 focus:ring-slate-100'
                                    : 'bg-slate-950 text-white shadow-xl shadow-slate-200 hover:bg-slate-800 focus:ring-slate-100'"
                        :disabled="(!canGenerate && !isFailed && !isCancelled) || isSubmitting"
                        @click="emit('generate')"
                    >
                        <Play class="size-4" />
                        <span v-if="isFailed">Reintentar generación</span>
                        <span v-else-if="isCancelled">Reiniciar generación</span>
                        <span v-else-if="isDone">Volver a generar</span>
                        <span v-else-if="hasPreviousReport">Regenerar reporte</span>
                        <span v-else>Generar reporte</span>
                    </button>

                </div>
            </div>

        </div>
    </section>
</template>
