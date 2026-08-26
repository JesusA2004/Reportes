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

// ── Polling health — distinto de un error de generación: esto es "no pude
// consultar el estado", no "la generación falló". Ver sección "SI EL POLLING
// FALLA" — nunca debe dejar un spinner infinito sin explicación ni crear un job
// nuevo; solo informa y ofrece reintentar la CONSULTA (no la generación).
const pollFailCount = ref(0)
const pollError      = ref(false)

// props.period.radiography_run_status (y hermanos) SIEMPRE describen la identidad
// SIMPLE/GENERAL del periodo (ver ReportUploadController::index()). Para cualquier
// otro alcance configurado en Etapa 4 (sucursal/gestor/comparativo), ese prop
// pertenece a UN RUN DISTINTO — aplicarlo aquí pisaría el estado real (ya resuelto
// por identidad completa vía pollProgress()/generationProgress()) con el de un run
// ajeno, incluso a mitad de una generación en curso. Ver Problema 2.
const isSimpleGeneralConfig = computed(() =>
    (props.reportConfig?.report_type ?? 'simple') === 'simple' &&
    (props.reportConfig?.scope ?? 'general') === 'general'
)

const syncFromProps = () => {
    if (!isSimpleGeneralConfig.value) return
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
        // Identidad del reporte configurado en Etapa 4 — sin esto el backend resolvía
        // "el último run del periodo sin importar de qué reporte era", y un comparativo/
        // por-sucursal/por-gestor generado después podía pisar el estado que esta
        // tarjeta muestra. Ver ReportUploadController::generationProgress().
        const identityParams = new URLSearchParams({
            report_type: props.reportConfig?.report_type ?? 'simple',
            scope: props.reportConfig?.scope ?? 'general',
        })
        if (props.reportConfig?.branch_id) identityParams.set('branch_id', String(props.reportConfig.branch_id))
        if (props.reportConfig?.employee_id) identityParams.set('employee_id', String(props.reportConfig.employee_id))
        if (props.reportConfig?.compare_period_id) identityParams.set('compare_period_id', String(props.reportConfig.compare_period_id))

        const res = await fetch(`/historico-general/${props.period.id}/generar-reporte/progreso?${identityParams.toString()}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        })
        // Un !res.ok (ej. 401/419 por sesión expirada durante una generación larga,
        // o un 5xx transitorio) o una respuesta que no es JSON (ej. redirect al login
        // devuelto como 200 con HTML) NO deben dejarse pasar en silencio: antes esto
        // simplemente "return"eaba y la tarjeta se quedaba congelada para siempre en
        // el último estado conocido (típicamente "en proceso"), aunque el job ya
        // hubiera terminado y el correo de éxito ya hubiera llegado — el usuario solo
        // lo notaba si hacía F5. Ahora se registra como fallo de POLLING (distinto de
        // un fallo de generación) y se sigue reintentando solo; el job en el backend
        // nunca se toca ni se relanza desde aquí.
        if (!res.ok) throw new Error(`HTTP ${res.status}`)
        const contentType = res.headers.get('content-type') ?? ''
        if (!contentType.includes('application/json')) throw new Error('respuesta no-JSON (¿sesión expirada?)')
        const data = await res.json()

        pollFailCount.value = 0
        pollError.value     = false

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
        // No tocar liveStatus/liveExcelUrl/etc. aquí: un fallo de RED no significa que
        // la generación haya fallado, y pisar el estado con algo distinto podría sacar
        // la tarjeta de "en proceso" sin motivo real. Solo se avisa tras 2 fallos
        // seguidos (~6-9 s) para no mostrar el aviso por un simple parpadeo de red.
        pollFailCount.value++
        if (pollFailCount.value >= 2) pollError.value = true
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
    // BUG REAL (2026-08-25, producción/VPS julio): props.period.* (radiography_ready,
    // radiography_run_status, etc.) SIEMPRE describen la identidad SIMPLE/GENERAL del
    // periodo (ver ReportUploadController::index() — $runsByPeriod se filtra a
    // report_type=simple/scope=general a propósito, para que la lista de periodos no
    // se contamine con runs de otro alcance). Si Etapa 4 configuró un alcance
    // DISTINTO (por sucursal/gestor), esos props pueden pertenecer a un run viejo y
    // completamente ajeno (ej. un GENERAL fallido de hace semanas) — antes, esta
    // tarjeta solo pedía el estado real (pollProgress(), que SÍ resuelve por
    // identidad completa vía generationProgress()) cuando radiography_ready(GENERAL)
    // era true, así que un GENERAL fallido/nunca-generado dejaba la tarjeta mostrando
    // ese run ajeno PARA SIEMPRE (el polling nunca arrancaba porque 'failed' no es
    // queued/running). Ahora siempre se pide el estado real de la identidad activa al
    // montar, sin importar qué diga el prop general.
    pollProgress()
})
onUnmounted(() => { clearTicker(); clearPoll() })

// ── Computed state ────────────────────────────────────────────────────
const isQueued    = computed(() => liveStatus.value === 'queued')
const isRunning   = computed(() => ['queued', 'running'].includes(liveStatus.value ?? ''))
const isFailed    = computed(() => liveStatus.value === 'failed')
const isCancelled = computed(() => liveStatus.value === 'cancelled')
// isDone se basa en el liveStatus de la IDENTIDAD ACTIVA (resuelto por pollProgress()),
// nunca en props.period.radiography_ready — ese prop es siempre sobre el alcance
// simple/general, y para un alcance por sucursal/gestor podía quedar en false aunque
// ESE run sí hubiera terminado en success (mostraba "Bloqueado"/"Lista para generar"
// en vez de "Reporte generado" pese a que el Excel/PDF ya existían).
const isDone              = computed(() => liveStatus.value === 'success')
const hasPreviousReport   = computed(() => props.period?.has_previous_radiography)
const previousReportAt    = computed(() => props.period?.previous_radiography_at ?? null)

// Formato reloj "00:42" — el que pide la UX para "Tiempo transcurrido"/"Tiempo total".
const elapsedClock = computed(() => {
    if (liveSeconds.value === null) return null
    const mins = Math.floor(liveSeconds.value / 60)
    const secs = liveSeconds.value % 60
    return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`
})

const runMeta     = computed(() => liveMeta.value)
const progress    = computed(() => runMeta.value?.progress_percent ?? null)
const currentStep = computed(() => runMeta.value?.current_step ?? null)

// Aviso suave a partir de 5 min corriendo (no en cola — eso ya tiene su propio umbral
// de 5 min vía liveStuck) — "tarda más de lo habitual" NUNCA marca failed ni bloquea,
// solo informa. Se apaga solo si liveStuck ya escaló a la alerta dura (30 min).
const isRunningSlow = computed(() =>
    isRunning.value && !isQueued.value && !liveStuck.value &&
    liveSeconds.value !== null && liveSeconds.value >= 300
)

// Razones de bloqueo REALES para mostrar en UI — nunca incluye "en proceso" (eso es
// processing state, no un blocker de negocio; ver Problema 5). El backend ya manda
// blocking_reasons_display sin ese mensaje; este fallback cubre props viejos en caché.
const displayBlockingReasons = computed(() =>
    (props.period?.blocking_reasons_display ?? props.period?.blocking_reasons ?? [])
        .filter((r: string) => r !== 'La Radiografía está en proceso.')
)

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
// Estados mutuamente excluyentes de Etapa 5 — READY / PROCESSING / SUCCESS / FAILED
// (más 'cancelled', que es su propio estado terminal). "Bloqueado" ya NUNCA se usa
// para describir un run en curso — solo para READY-con-blockers reales (faltan
// fuentes, incidencias, etc.), y esos blockers ya no incluyen "en proceso" (ver
// displayBlockingReasons).
const stage = computed(() => {
    if (isQueued.value)    return 'processing'
    if (isRunning.value)   return 'processing'
    if (isFailed.value)    return 'failed'
    if (isCancelled.value) return 'cancelled'
    if (isDone.value)      return 'success'
    if (props.canGenerate) return 'ready'
    return 'blocked'
})

const statusConfig = computed(() => {
    switch (stage.value) {
        case 'processing': return { color: 'bg-indigo-50 border-indigo-200',   text: 'text-indigo-700',   label: 'Generando reporte',      icon: LoaderCircle,   iconClass: 'text-indigo-600 animate-spin' }
        case 'failed':      return { color: 'bg-rose-50 border-rose-200',       text: 'text-rose-700',     label: 'No se pudo generar',     icon: XCircle,        iconClass: 'text-rose-600' }
        case 'cancelled':   return { color: 'bg-slate-100 border-slate-300',    text: 'text-slate-600',    label: 'Generación cancelada',   icon: Ban,            iconClass: 'text-slate-500' }
        case 'success':     return { color: 'bg-emerald-50 border-emerald-200', text: 'text-emerald-700',  label: 'Reporte generado',       icon: CheckCircle,    iconClass: 'text-emerald-600' }
        case 'ready':       return { color: 'bg-slate-50 border-slate-200',     text: 'text-slate-600',    label: 'Listo para generar',     icon: ShieldCheck,    iconClass: 'text-slate-500' }
        default:            return { color: 'bg-amber-50 border-amber-200',     text: 'text-amber-700',    label: 'Bloqueado',              icon: TriangleAlert,  iconClass: 'text-amber-600' }
    }
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
                        <!-- Solo blockers de NEGOCIO reales (faltan fuentes, incidencias, etc.) —
                             nunca mientras el run activo está procesando: eso tiene su propia
                             tarjeta PROCESSING más abajo (ver Problema 1/5). -->
                        <div v-if="!isRunning && displayBlockingReasons.length" class="mt-3 rounded-2xl border border-amber-200 bg-amber-50 p-3">
                            <p class="text-xs font-black text-amber-800">Bloqueado — razones:</p>
                            <ul class="mt-1.5 list-disc pl-4 space-y-0.5 text-xs text-amber-700">
                                <li v-for="reason in displayBlockingReasons" :key="reason">{{ reason }}</li>
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
                                <!-- PROCESSING: mensaje fijo de espera, nunca "bloqueado" -->
                                <p v-if="isRunning" class="mt-0.5 text-xs leading-5" :class="statusConfig.text">
                                    Estamos calculando la radiografía y generando los archivos Excel y PDF.
                                </p>
                                <p v-else-if="liveLog" class="mt-0.5 truncate text-xs leading-5" :class="statusConfig.text">{{ liveLog }}</p>
                            </div>
                        </div>

                        <!-- Subtexto de espera + paso actual (PROCESSING) -->
                        <div v-if="isRunning" class="mt-2 space-y-1">
                            <p class="text-xs leading-5 text-slate-500">
                                Puedes esperar en esta pantalla. Te enviaremos un correo cuando termine.
                            </p>
                            <p v-if="currentStep" class="text-xs font-bold text-indigo-700">Procesando: {{ currentStep }}</p>
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
                        <div v-if="liveQueuedAt || liveStartedAt || liveFinishedAt || elapsedClock" class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                            <span v-if="liveQueuedAt && !liveStartedAt">En cola: <strong>{{ liveQueuedAt }}</strong></span>
                            <span v-if="liveStartedAt">Inicio: <strong>{{ liveStartedAt }}</strong></span>
                            <span v-if="liveFinishedAt">Fin: <strong>{{ liveFinishedAt }}</strong></span>
                            <span v-if="elapsedClock" class="flex items-center gap-1">
                                <Clock class="size-3 shrink-0" />
                                {{ isRunning ? 'Tiempo transcurrido' : 'Tiempo total' }}: <strong>{{ elapsedClock }}</strong>
                            </span>
                        </div>

                        <!-- Error detail -->
                        <div v-if="isFailed && liveError" class="mt-3 break-all rounded-xl bg-rose-100 p-3 font-mono text-xs leading-5 text-rose-800">{{ liveError }}</div>

                        <!-- Éxito: checklist Excel/PDF + descarga — nunca se adivina la URL: si
                             isDone pero aún no llegó la respuesta real del backend, el botón se
                             muestra deshabilitado en vez de apuntar a un archivo posiblemente
                             equivocado (p. ej. el simple cuando lo generado fue un comparativo). -->
                        <div v-if="isDone" class="mt-3 space-y-1 text-xs font-bold text-emerald-700">
                            <p class="flex items-center gap-1.5"><CheckCircle class="size-3.5" />Excel listo</p>
                            <p class="flex items-center gap-1.5"><CheckCircle class="size-3.5" />PDF listo</p>
                        </div>
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

                    <!-- Aviso: la generación tarda más de lo habitual (>5 min corriendo) — nunca
                         marca failed ni bloquea, solo informa; distinto de la alerta dura de
                         "atascado" (30 min) que sí sugiere revisar el worker. -->
                    <div v-if="isRunningSlow" class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                        <div class="flex items-start gap-2.5">
                            <Clock class="mt-0.5 size-4 shrink-0 text-amber-600" />
                            <p class="text-xs leading-5 text-amber-700">
                                El reporte está tardando más de lo habitual, pero continúa procesándose.
                            </p>
                        </div>
                    </div>

                    <!-- Fallo de POLLING (no de generación) — el job puede seguir corriendo en
                         segundo plano; nunca se relanza otro job desde aquí. -->
                    <div v-if="pollError" class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                        <div class="flex items-start gap-2.5">
                            <AlertTriangle class="mt-0.5 size-4 shrink-0 text-amber-600" />
                            <div class="min-w-0 flex-1">
                                <p class="text-xs leading-5 text-amber-700">
                                    No se pudo actualizar el estado de generación.
                                    El proceso puede seguir ejecutándose en segundo plano.
                                </p>
                                <button type="button" class="mt-2 inline-flex items-center gap-1.5 rounded-xl border border-amber-300 bg-white px-3 py-1.5 text-xs font-bold text-amber-700 transition hover:bg-amber-50" @click="pollProgress">
                                    <RefreshCw class="size-3.5" />Reintentar consulta de estado
                                </button>
                            </div>
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
