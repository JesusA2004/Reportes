<script setup lang="ts">
import { computed, ref, watch, onMounted, onUnmounted } from 'vue'
import { AlertTriangle, Ban, CheckCircle, Clock, DatabaseZap, LoaderCircle, RefreshCw, ShieldCheck, TriangleAlert, XCircle } from 'lucide-vue-next'
import SectionHeader from './SectionHeader.vue'
import StatusBadge from './StatusBadge.vue'

const props = defineProps<{ period: any; canUpdate: boolean }>()
const emit = defineEmits<{
    (e: 'update'): void
    (e: 'cancel'): void
    (e: 'clear-stuck'): void
    (e: 'refresh'): void
    (e: 'process-now'): void
    (e: 'requeue'): void
}>()

// Live state — initially from Inertia props, updated by dedicated polling
const liveStatus     = ref<string | null>(null)
const liveLog        = ref<string | null>(null)
const liveError      = ref<string | null>(null)
const liveQueuedAt   = ref<string | null>(null)
const liveStartedAt  = ref<string | null>(null)
const liveFinishedAt = ref<string | null>(null)
const liveElapsed    = ref<number | null>(null)
const liveStuck      = ref(false)
const liveMeta       = ref<any>(null)
const liveOrphaned   = ref(false)
const liveCanProcessNow = ref(false)

const syncFromProps = () => {
    liveStatus.value        = props.period?.database_update_run_status ?? null
    liveLog.value           = props.period?.database_update_run_log ?? null
    liveError.value         = props.period?.database_update_run_error ?? null
    liveQueuedAt.value      = props.period?.database_update_run_queued_at ?? null
    liveStartedAt.value     = props.period?.database_update_run_started_at ?? null
    liveFinishedAt.value    = props.period?.database_update_run_finished_at ?? null
    liveElapsed.value       = typeof props.period?.database_update_elapsed_seconds === 'number'
        ? props.period.database_update_elapsed_seconds
        : null
    liveStuck.value         = !!props.period?.database_update_stuck_warning
    liveMeta.value          = props.period?.database_update_run_metadata ?? null
    liveOrphaned.value      = !!props.period?.database_update_job_orphaned
    liveCanProcessNow.value = !!props.period?.database_update_can_process_now
}

watch(() => props.period?.database_update_run_status, syncFromProps, { immediate: true })

// Dedicated polling against the JSON endpoint — independent of Inertia page reload
let pollTimer: ReturnType<typeof setInterval> | null = null

const clearPoll = () => { if (pollTimer) { clearInterval(pollTimer); pollTimer = null } }

const pollProgress = async () => {
    if (!props.period?.id) return
    try {
        const res = await fetch(`/historico-general/${props.period.id}/actualizacion-bd/progreso`, {
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
        liveOrphaned.value      = !!data.orphaned
        liveCanProcessNow.value = !!data.can_process_now

        // Stop polling once terminal
        if (!['queued', 'running'].includes(data.status ?? '')) {
            clearPoll()
            emit('refresh')
        }
    } catch {
        // silent — next tick will retry
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
    { immediate: true }
)

// Live elapsed ticker — increments every second while running/queued
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
    { immediate: true }
)

onMounted(syncFromProps)

onUnmounted(() => {
    clearTicker()
    clearPoll()
})

const dbRunStatus    = computed(() => liveStatus.value)
const dbRunLog       = computed(() => liveLog.value)
const dbRunError     = computed(() => liveError.value)
const dbRunQueued    = computed(() => liveQueuedAt.value)
const dbRunStarted   = computed(() => liveStartedAt.value)
const dbRunFinished  = computed(() => liveFinishedAt.value)
const stuckWarning   = computed(() => liveStuck.value)
const jobOrphaned    = computed(() => liveOrphaned.value)
const canProcessNow  = computed(() => liveCanProcessNow.value)

const elapsedFormatted = computed(() => {
    if (liveSeconds.value === null) return null
    const s = liveSeconds.value
    const mins = Math.floor(s / 60)
    const secs = s % 60
    if (mins === 0) return `${secs} seg`
    return `${mins} min ${String(secs).padStart(2, '0')} seg`
})
const isQueued      = computed(() => dbRunStatus.value === 'queued')
const isRunning     = computed(() => ['queued', 'running'].includes(dbRunStatus.value))
const isFailed      = computed(() => dbRunStatus.value === 'failed')
const isCancelled   = computed(() => dbRunStatus.value === 'cancelled')
const dbDone        = computed(() => !!props.period?.database_updated)

const runMeta        = computed(() => liveMeta.value)
const progress       = computed(() => runMeta.value?.progress_percent ?? null)
const currentStep    = computed(() => runMeta.value?.current_step ?? null)
const currentSource  = computed(() => runMeta.value?.current_source ?? null)
const currentFile    = computed(() => runMeta.value?.current_file ?? null)
const processedRows  = computed(() => runMeta.value?.processed_rows ?? runMeta.value?.cobranza_rows_read ?? null)
const totalRows      = computed(() => runMeta.value?.total_rows ?? runMeta.value?.cobranza_total_rows ?? null)
const stats          = computed(() => runMeta.value?.stats ?? null)

// "Personas detectadas" acepta tanto el campo nuevo como el legacy
const personsDetected    = computed(() => stats.value?.persons_detected ?? stats.value?.employees_detected ?? undefined)
const recordsLoaded      = computed(() => stats.value?.records_loaded ?? undefined)
const recordsExcluded    = computed(() => stats.value?.records_excluded ?? undefined)
const branchesIncluded   = computed(() => stats.value?.branches_included ?? undefined)
const branchesExcluded   = computed(() => stats.value?.branches_excluded ?? undefined)
const pendingLocations   = computed(() => stats.value?.pending_locations ?? undefined)
const criticalIncidents  = computed(() => stats.value?.critical_incidents ?? stats.value?.incidents_created ?? undefined)
const warnings           = computed(() => stats.value?.warnings ?? undefined)

// Las 12 sucursales operativas — fuente de verdad fija
const INCLUDED_BRANCHES = [
    'ATLACOMULCO', 'ATLIXCO', 'CORDOBA', 'CUERNAVACA', 'HUAMANTLA',
    'IXTLAHUACA', 'MIACATLAN', 'ORIZABA', 'SAN LUIS POTOSI',
    'TENANGO DEL VALLE', 'TLAXCALA', 'TULA',
]
const EXCLUDED_BRANCHES = [
    { name: 'SAN JUAN DEL RÍO', reason: 'Sucursal cerrada' },
    { name: 'CORPORATIVO',       reason: 'No operativo' },
]

const statusConfig = computed(() => {
    if (dbDone.value)      return { color: 'bg-emerald-50 border-emerald-200', text: 'text-emerald-700', label: 'Registros cargados',  icon: CheckCircle,  iconClass: 'text-emerald-600' }
    if (isQueued.value)    return { color: 'bg-violet-50 border-violet-200',   text: 'text-violet-700',  label: 'En cola…',            icon: LoaderCircle, iconClass: 'text-violet-600 animate-spin' }
    if (isRunning.value)   return { color: 'bg-indigo-50 border-indigo-200',   text: 'text-indigo-700',  label: 'Cargando…',           icon: LoaderCircle, iconClass: 'text-indigo-600 animate-spin' }
    if (isFailed.value)    return { color: 'bg-rose-50 border-rose-200',       text: 'text-rose-700',    label: 'Falló',               icon: XCircle,      iconClass: 'text-rose-600' }
    if (isCancelled.value) return { color: 'bg-slate-100 border-slate-300',    text: 'text-slate-600',   label: 'Carga cancelada',     icon: Ban,          iconClass: 'text-slate-500' }
    if (props.canUpdate)   return { color: 'bg-slate-50 border-slate-200',     text: 'text-slate-600',   label: 'Lista para cargar',   icon: ShieldCheck,  iconClass: 'text-slate-500' }
    return                 { color: 'bg-amber-50 border-amber-200',            text: 'text-amber-700',   label: 'Faltan fuentes',      icon: TriangleAlert, iconClass: 'text-amber-600' }
})
</script>

<template>
    <section class="rounded-[2rem] border border-white/70 bg-white p-6 shadow-xl shadow-slate-200/70">
        <SectionHeader
            eyebrow="Etapa 2"
            title="Cargar registros"
            description="Lee las fuentes cargadas y guarda los registros base en las tablas de hechos. El proceso corre en segundo plano; recibirás un correo cuando termine."
        />

        <div class="mt-6 space-y-4">

            <!-- Fila superior: fuentes + estado -->
            <div class="grid gap-4 lg:grid-cols-[1fr_0.9fr]">

                <!-- Fuentes requeridas -->
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                    <div class="flex items-center gap-3">
                        <DatabaseZap class="size-6 shrink-0 text-indigo-600" />
                        <div>
                            <p class="font-black text-slate-950">Fuentes requeridas</p>
                            <p class="text-sm text-slate-500">NOI Nómina + Lendus Ingresos Cobranza</p>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <StatusBadge :status="period?.missing_database_sources?.includes('noi_nomina') ? 'blocked' : 'completed'" label="NOI Nómina" />
                        <StatusBadge :status="period?.missing_database_sources?.includes('lendus_ingresos_cobranza') ? 'blocked' : 'completed'" label="Cobranza" />
                    </div>
                    <ul v-if="period?.blocking_reasons?.length" class="mt-4 list-disc space-y-1 pl-5 text-sm text-slate-600">
                        <li v-for="reason in period.blocking_reasons" :key="reason">{{ reason }}</li>
                    </ul>
                </div>

                <!-- Estado y acciones -->
                <div class="space-y-4">

                    <!-- Card de estado -->
                    <div class="rounded-2xl border p-5 transition-all duration-300" :class="statusConfig.color">
                        <div class="flex items-center gap-3">
                            <component :is="statusConfig.icon" class="size-6 shrink-0" :class="statusConfig.iconClass" />
                            <div class="min-w-0 flex-1">
                                <p class="font-black text-slate-950">{{ statusConfig.label }}</p>
                                <p v-if="dbRunLog" class="mt-0.5 truncate text-xs leading-5" :class="statusConfig.text">{{ dbRunLog }}</p>
                            </div>
                        </div>

                        <!-- Barra de progreso con detalle -->
                        <div v-if="isRunning && progress !== null" class="mt-4">
                            <div class="mb-1.5 flex items-center justify-between text-xs font-medium text-slate-600">
                                <span class="truncate pr-3">
                                    <span v-if="currentSource" class="font-bold text-indigo-700">{{ currentSource }}</span>
                                    <span v-else-if="currentStep">{{ currentStep }}</span>
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
                            <!-- Detalle de filas si está disponible -->
                            <div v-if="processedRows !== null && totalRows !== null" class="mt-2 text-xs text-slate-500">
                                {{ Number(processedRows).toLocaleString('es-MX') }} / {{ Number(totalRows).toLocaleString('es-MX') }} filas
                                <span v-if="currentFile" class="ml-2 text-slate-400">— {{ currentFile }}</span>
                            </div>
                        </div>

                        <div v-if="dbRunQueued || dbRunStarted || dbRunFinished || elapsedFormatted" class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                            <span v-if="dbRunQueued && !dbRunStarted">En cola: <strong>{{ dbRunQueued }}</strong></span>
                            <span v-if="dbRunStarted">Inicio: <strong>{{ dbRunStarted }}</strong></span>
                            <span v-if="dbRunFinished">Fin: <strong>{{ dbRunFinished }}</strong></span>
                            <span v-if="elapsedFormatted" class="flex items-center gap-1">
                                <Clock class="size-3 shrink-0" />Transcurrido: <strong>{{ elapsedFormatted }}</strong>
                            </span>
                        </div>
                        <div v-if="isFailed && dbRunError" class="mt-3 break-all rounded-xl bg-rose-100 p-3 font-mono text-xs leading-5 text-rose-800">{{ dbRunError }}</div>
                    </div>

                    <!-- Alerta de proceso atascado -->
                    <div v-if="stuckWarning" class="rounded-2xl border border-amber-300 bg-amber-50 p-4">
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
                                    <button type="button" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-600 transition hover:bg-slate-50" @click="emit('clear-stuck')">
                                        <RefreshCw class="size-3.5" />Limpiar estado atascado
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Aviso corriendo (sin stuck) -->
                    <div v-if="isRunning && !stuckWarning" class="space-y-3">

                        <!-- Run en cola sin job → worker inactivo o run huérfano -->
                        <div v-if="isQueued && jobOrphaned" class="rounded-2xl border border-amber-300 bg-amber-50 p-4">
                            <div class="flex items-start gap-2.5">
                                <AlertTriangle class="mt-0.5 size-4 shrink-0 text-amber-600" />
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-bold text-amber-800">Run en cola sin job activo</p>
                                    <p class="mt-1 text-xs leading-5 text-amber-700">El run está marcado como "en cola" pero no hay ningún job encolado para procesarlo. Esto ocurre si el job fue eliminado o nunca se encoló correctamente.</p>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <button type="button" class="inline-flex items-center gap-1.5 rounded-xl border border-amber-300 bg-white px-3 py-2 text-xs font-bold text-amber-700 transition hover:bg-amber-50" @click="emit('requeue')">
                                            <RefreshCw class="size-3.5" />Reencolar job
                                        </button>
                                        <button v-if="canProcessNow" type="button" class="inline-flex items-center gap-1.5 rounded-xl border border-indigo-300 bg-white px-3 py-2 text-xs font-bold text-indigo-700 transition hover:bg-indigo-50" @click="emit('process-now')">
                                            <DatabaseZap class="size-3.5" />Procesar ahora (local)
                                        </button>
                                        <button type="button" class="inline-flex items-center gap-1.5 rounded-xl border border-rose-200 bg-white px-3 py-2 text-xs font-bold text-rose-600 transition hover:bg-rose-50" @click="emit('cancel')">
                                            <Ban class="size-3.5" />Cancelar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Run en cola con job → worker apagado -->
                        <div v-else-if="isQueued && !jobOrphaned" class="rounded-2xl border border-violet-200 bg-violet-50 p-4">
                            <div class="flex items-start gap-2.5">
                                <LoaderCircle class="mt-0.5 size-4 shrink-0 animate-spin text-violet-600" />
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-bold text-violet-800">Job en cola — worker inactivo</p>
                                    <p class="mt-1 text-xs leading-5 text-violet-700">El job está encolado correctamente. Necesitas iniciar el worker para que se procese. Puedes cerrar esta ventana; recibirás correo cuando termine.</p>
                                    <code class="mt-2 block rounded-xl bg-violet-100 px-3 py-2 font-mono text-[11px] leading-5 text-violet-900">php artisan queue:work --tries=1 --timeout=0 -vvv</code>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <button type="button" class="inline-flex items-center gap-1.5 rounded-xl border border-violet-300 bg-white px-3 py-2 text-xs font-bold text-violet-700 transition hover:bg-violet-50" @click="emit('refresh')">
                                            <RefreshCw class="size-3.5" />Verificar estado
                                        </button>
                                        <button v-if="canProcessNow" type="button" class="inline-flex items-center gap-1.5 rounded-xl border border-indigo-300 bg-white px-3 py-2 text-xs font-bold text-indigo-700 transition hover:bg-indigo-50" @click="emit('process-now')">
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
                            <p class="text-sm font-bold text-indigo-800">La carga corre en segundo plano.</p>
                            <p class="mt-1 text-xs text-indigo-600">Puedes cerrar esta ventana. Te avisaremos por correo cuando termine.</p>
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
                    <button v-if="!isRunning" type="button"
                        class="inline-flex h-12 w-full items-center justify-center rounded-2xl px-5 text-sm font-black transition focus:outline-none focus:ring-4 disabled:cursor-not-allowed disabled:opacity-50"
                        :class="isFailed ? 'bg-rose-600 text-white shadow-lg shadow-rose-200 hover:bg-rose-700 focus:ring-rose-100' : isCancelled ? 'bg-slate-700 text-white shadow-lg shadow-slate-200 hover:bg-slate-800 focus:ring-slate-100' : dbDone ? 'bg-slate-700 text-white shadow-lg shadow-slate-200 hover:bg-slate-800 focus:ring-slate-100' : 'bg-indigo-600 text-white shadow-lg shadow-indigo-200 hover:bg-indigo-700 focus:ring-indigo-100'"
                        :disabled="!canUpdate && !isFailed && !isCancelled"
                        @click="emit('update')"
                    >
                        <ShieldCheck class="mr-2 size-5" />
                        <span v-if="isFailed">Reintentar carga de registros</span>
                        <span v-else-if="isCancelled">Reiniciar carga de registros</span>
                        <span v-else-if="dbDone">Volver a cargar desde archivos actuales</span>
                        <span v-else>Cargar registros</span>
                    </button>

                </div>
            </div>

            <!-- Stats post-procesamiento -->
            <div v-if="stats" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                <div v-if="personsDetected !== undefined" class="rounded-2xl border border-indigo-100 bg-indigo-50 p-4 text-center">
                    <p class="text-xs font-bold text-indigo-600">Personas detectadas</p>
                    <p class="mt-1 text-2xl font-black text-indigo-800">{{ personsDetected }}</p>
                </div>
                <div v-if="recordsLoaded !== undefined" class="rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-center">
                    <p class="text-xs font-bold text-emerald-600">Registros cargados</p>
                    <p class="mt-1 text-2xl font-black text-emerald-800">{{ Number(recordsLoaded).toLocaleString('es-MX') }}</p>
                </div>
                <div v-if="recordsExcluded !== undefined" class="rounded-2xl border border-slate-100 bg-slate-50 p-4 text-center">
                    <p class="text-xs font-bold text-slate-500">Registros excluidos</p>
                    <p class="mt-1 text-2xl font-black text-slate-700">{{ Number(recordsExcluded).toLocaleString('es-MX') }}</p>
                </div>
                <div v-if="branchesIncluded !== undefined" class="rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-center">
                    <p class="text-xs font-bold text-emerald-600">Sucursales incluidas</p>
                    <p class="mt-1 text-2xl font-black text-emerald-800">{{ branchesIncluded }}</p>
                </div>
                <div v-if="branchesExcluded !== undefined" class="rounded-2xl border border-rose-100 bg-rose-50 p-4 text-center">
                    <p class="text-xs font-bold text-rose-600">Sucursales excluidas</p>
                    <p class="mt-1 text-2xl font-black text-rose-800">{{ branchesExcluded }}</p>
                </div>
                <div v-if="criticalIncidents !== undefined" class="rounded-2xl border border-amber-100 bg-amber-50 p-4 text-center">
                    <p class="text-xs font-bold text-amber-600">Incidencias críticas</p>
                    <p class="mt-1 text-2xl font-black text-amber-800">{{ criticalIncidents }}</p>
                </div>
                <div v-if="warnings !== undefined" class="rounded-2xl border border-orange-100 bg-orange-50 p-4 text-center">
                    <p class="text-xs font-bold text-orange-600">Advertencias</p>
                    <p class="mt-1 text-2xl font-black text-orange-800">{{ warnings }}</p>
                </div>
                <div v-if="pendingLocations !== undefined && pendingLocations > 0" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-center">
                    <p class="text-xs font-bold text-red-600">Ubicaciones pendientes</p>
                    <p class="mt-1 text-2xl font-black text-red-800">{{ pendingLocations }}</p>
                </div>
            </div>

            <!-- Sucursales tomadas en cuenta para el reporte general -->
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                <p class="mb-4 text-sm font-black text-slate-950">Sucursales tomadas en cuenta para el reporte general</p>

                <!-- Incluidas -->
                <div class="mb-4">
                    <p class="mb-2 text-xs font-bold uppercase tracking-wide text-emerald-700">
                        Incluidas ({{ INCLUDED_BRANCHES.length }})
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="branch in INCLUDED_BRANCHES"
                            :key="branch"
                            class="inline-flex items-center rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-800"
                        >
                            {{ branch }}
                        </span>
                    </div>
                </div>

                <!-- Excluidas -->
                <div class="mb-4">
                    <p class="mb-2 text-xs font-bold uppercase tracking-wide text-rose-700">
                        Excluidas ({{ EXCLUDED_BRANCHES.length }})
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="item in EXCLUDED_BRANCHES"
                            :key="item.name"
                            class="inline-flex items-center gap-1.5 rounded-xl border border-rose-200 bg-rose-50 px-3 py-1 text-xs font-bold text-rose-800"
                        >
                            {{ item.name }}
                            <span class="font-normal text-rose-500">— {{ item.reason }}</span>
                        </span>
                    </div>
                </div>

                <!-- Pendientes: solo si hay -->
                <div v-if="pendingLocations !== undefined && pendingLocations > 0">
                    <p class="mb-2 text-xs font-bold uppercase tracking-wide text-red-700">
                        Ubicaciones pendientes ({{ pendingLocations }})
                    </p>
                    <p class="text-xs text-red-700">
                        Se detectaron {{ pendingLocations }} ubicación(es) no reconocida(s). Revisa las incidencias críticas antes de continuar.
                    </p>
                </div>

                <!-- Totales -->
                <div class="mt-3 flex flex-wrap gap-x-6 gap-y-1 border-t border-slate-200 pt-3 text-xs text-slate-500">
                    <span>Sucursales incluidas: <strong class="text-emerald-700">{{ INCLUDED_BRANCHES.length }}</strong></span>
                    <span>Sucursales excluidas: <strong class="text-rose-700">{{ EXCLUDED_BRANCHES.length }}</strong></span>
                    <span v-if="pendingLocations !== undefined && pendingLocations > 0">
                        Ubicaciones pendientes: <strong class="text-red-700">{{ pendingLocations }}</strong>
                    </span>
                </div>
            </div>

        </div>
    </section>
</template>
