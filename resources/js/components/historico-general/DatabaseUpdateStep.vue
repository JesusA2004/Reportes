<script setup lang="ts">
import { computed } from 'vue'
import { AlertTriangle, Ban, CheckCircle, Clock, DatabaseZap, LoaderCircle, RefreshCw, ShieldCheck, TriangleAlert, XCircle } from 'lucide-vue-next'
import SectionHeader from './SectionHeader.vue'
import StatusBadge from './StatusBadge.vue'

const props = defineProps<{ period: any; canUpdate: boolean }>()
const emit = defineEmits<{
    (e: 'update'): void
    (e: 'cancel'): void
    (e: 'refresh'): void
}>()

const dbRunStatus   = computed(() => props.period?.database_update_run_status ?? null)
const dbRunLog      = computed(() => props.period?.database_update_run_log ?? null)
const dbRunError    = computed(() => props.period?.database_update_run_error ?? null)
const dbRunStarted  = computed(() => props.period?.database_update_run_started_at ?? null)
const dbRunFinished = computed(() => props.period?.database_update_run_finished_at ?? null)
const dbElapsed     = computed(() => props.period?.database_update_elapsed_minutes ?? null)
const stuckWarning  = computed(() => !!props.period?.database_update_stuck_warning)
const isQueued      = computed(() => dbRunStatus.value === 'queued')
const isRunning     = computed(() => ['queued', 'running'].includes(dbRunStatus.value))
const isFailed      = computed(() => dbRunStatus.value === 'failed')
const dbDone        = computed(() => !!props.period?.database_updated)

const runMeta     = computed(() => props.period?.database_update_run_metadata ?? null)
const progress    = computed(() => runMeta.value?.progress_percent ?? null)
const currentStep = computed(() => runMeta.value?.current_step ?? null)
const stats       = computed(() => runMeta.value?.stats ?? null)

const statusConfig = computed(() => {
    if (dbDone.value)    return { color: 'bg-emerald-50 border-emerald-200', text: 'text-emerald-700', label: 'Completada',          icon: CheckCircle,  iconClass: 'text-emerald-600' }
    if (isQueued.value)  return { color: 'bg-violet-50 border-violet-200',   text: 'text-violet-700',  label: 'En cola…',            icon: LoaderCircle, iconClass: 'text-violet-600 animate-spin' }
    if (isRunning.value) return { color: 'bg-indigo-50 border-indigo-200',   text: 'text-indigo-700',  label: 'Procesando…',         icon: LoaderCircle, iconClass: 'text-indigo-600 animate-spin' }
    if (isFailed.value)  return { color: 'bg-rose-50 border-rose-200',       text: 'text-rose-700',    label: 'Falló',               icon: XCircle,      iconClass: 'text-rose-600' }
    if (props.canUpdate) return { color: 'bg-slate-50 border-slate-200',     text: 'text-slate-600',   label: 'Lista para ejecutar', icon: ShieldCheck,  iconClass: 'text-slate-500' }
    return               { color: 'bg-amber-50 border-amber-200',            text: 'text-amber-700',   label: 'Faltan fuentes',      icon: TriangleAlert, iconClass: 'text-amber-600' }
})
</script>

<template>
    <section class="rounded-[2rem] border border-white/70 bg-white p-6 shadow-xl shadow-slate-200/70">
        <SectionHeader
            eyebrow="Etapa 2"
            title="Actualización de base de datos"
            description="Actualiza la base operativa usando NOI Nómina y Cobranza. El proceso corre en segundo plano; recibirás un correo cuando termine."
        />

        <div class="mt-6 grid gap-4 lg:grid-cols-[1fr_0.9fr]">

            <!-- Fuentes y stats -->
            <div class="space-y-4">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                    <div class="flex items-center gap-3">
                        <DatabaseZap class="size-6 shrink-0 text-indigo-600" />
                        <div>
                            <p class="font-black text-slate-950">Fuentes obligatorias</p>
                            <p class="text-sm text-slate-500">NOI Nómina + Lendus Ingresos Cobranza</p>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <StatusBadge :status="period?.missing_database_sources?.includes('noi_nomina') ? 'blocked' : 'completed'" label="NOI Nómina" />
                        <StatusBadge :status="period?.missing_database_sources?.includes('lendus_ingresos_cobranza') ? 'blocked' : 'completed'" label="Cobranza" />
                    </div>
                    <ul v-if="period?.blocking_reasons?.filter((r: string) => r.includes('BD') || r.includes('NOI') || r.includes('Cobranza') || r.includes('actualiza')).length"
                        class="mt-4 list-disc space-y-1 pl-5 text-sm text-slate-600">
                        <li v-for="reason in period.blocking_reasons.filter((r: string) => r.includes('BD') || r.includes('NOI') || r.includes('Cobranza') || r.includes('actualiza'))" :key="reason">{{ reason }}</li>
                    </ul>
                </div>

                <!-- Stats post-procesamiento -->
                <div v-if="stats" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div v-if="stats.employees_detected !== undefined" class="rounded-2xl border border-indigo-100 bg-indigo-50 p-4 text-center">
                        <p class="text-xs font-bold text-indigo-600">Empleados</p>
                        <p class="mt-1 text-2xl font-black text-indigo-800">{{ stats.employees_detected }}</p>
                    </div>
                    <div v-if="stats.branches_detected !== undefined" class="rounded-2xl border border-sky-100 bg-sky-50 p-4 text-center">
                        <p class="text-xs font-bold text-sky-600">Sucursales</p>
                        <p class="mt-1 text-2xl font-black text-sky-800">{{ stats.branches_detected }}</p>
                    </div>
                    <div v-if="stats.promoters_detected !== undefined" class="rounded-2xl border border-violet-100 bg-violet-50 p-4 text-center">
                        <p class="text-xs font-bold text-violet-600">Promotores</p>
                        <p class="mt-1 text-2xl font-black text-violet-800">{{ stats.promoters_detected }}</p>
                    </div>
                    <div v-if="stats.incidents_created !== undefined" class="rounded-2xl border border-amber-100 bg-amber-50 p-4 text-center">
                        <p class="text-xs font-bold text-amber-600">Incidencias</p>
                        <p class="mt-1 text-2xl font-black text-amber-800">{{ stats.incidents_created }}</p>
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
                            <p v-if="dbRunLog" class="mt-0.5 truncate text-xs leading-5" :class="statusConfig.text">{{ dbRunLog }}</p>
                        </div>
                    </div>

                    <!-- Barra de progreso -->
                    <div v-if="isRunning && progress !== null" class="mt-4">
                        <div class="mb-1.5 flex items-center justify-between text-xs font-medium text-slate-600">
                            <span class="truncate pr-3">{{ currentStep ?? 'Procesando…' }}</span>
                            <span class="shrink-0 font-black text-indigo-700">{{ progress }}%</span>
                        </div>
                        <div class="h-2.5 w-full overflow-hidden rounded-full bg-slate-200">
                            <div
                                class="h-full rounded-full bg-indigo-500 transition-all duration-700"
                                :style="{ width: `${progress}%` }"
                            />
                        </div>
                    </div>

                    <div v-if="dbRunStarted || dbRunFinished || (isRunning && dbElapsed !== null)" class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                        <span v-if="dbRunStarted">Inicio: <strong>{{ dbRunStarted }}</strong></span>
                        <span v-if="dbRunFinished">Fin: <strong>{{ dbRunFinished }}</strong></span>
                        <span v-if="isRunning && dbElapsed !== null" class="flex items-center gap-1">
                            <Clock class="size-3 shrink-0" />Transcurrido: <strong>{{ dbElapsed }} min</strong>
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
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Aviso corriendo (sin stuck) -->
                <div v-if="isRunning && !stuckWarning" class="rounded-2xl border border-indigo-200 bg-indigo-50 p-4">
                    <p class="text-sm font-bold text-indigo-800">
                        {{ isQueued ? 'El proceso está en cola esperando worker.' : 'El proceso corre en segundo plano.' }}
                    </p>
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

                <!-- Botón acción principal -->
                <button v-if="!isRunning" type="button"
                    class="inline-flex h-12 w-full items-center justify-center rounded-2xl px-5 text-sm font-black transition focus:outline-none focus:ring-4 disabled:cursor-not-allowed disabled:opacity-50"
                    :class="isFailed ? 'bg-rose-600 text-white shadow-lg shadow-rose-200 hover:bg-rose-700 focus:ring-rose-100' : 'bg-indigo-600 text-white shadow-lg shadow-indigo-200 hover:bg-indigo-700 focus:ring-indigo-100'"
                    :disabled="!canUpdate && !isFailed"
                    @click="emit('update')"
                >
                    <ShieldCheck class="mr-2 size-5" />
                    <span v-if="isFailed">Reintentar actualización</span>
                    <span v-else-if="dbDone">Re-ejecutar actualización</span>
                    <span v-else>Actualizar base de datos</span>
                </button>

            </div>
        </div>
    </section>
</template>
