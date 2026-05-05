<script setup lang="ts">
import { computed } from 'vue'
import { CheckCircle, DatabaseZap, LoaderCircle, RefreshCw, ShieldCheck, TriangleAlert, XCircle } from 'lucide-vue-next'
import SectionHeader from './SectionHeader.vue'
import StatusBadge from './StatusBadge.vue'

const props = defineProps<{ period: any; canUpdate: boolean }>()
const emit = defineEmits<{
    (e: 'update'): void
    (e: 'refresh'): void
}>()

const dbRunStatus   = computed(() => props.period?.database_update_run_status ?? null)
const dbRunLog      = computed(() => props.period?.database_update_run_log ?? null)
const dbRunError    = computed(() => props.period?.database_update_run_error ?? null)
const dbRunStarted  = computed(() => props.period?.database_update_run_started_at ?? null)
const dbRunFinished = computed(() => props.period?.database_update_run_finished_at ?? null)
const isRunning     = computed(() => ['queued', 'running'].includes(dbRunStatus.value))
const isFailed      = computed(() => dbRunStatus.value === 'failed')
const dbDone        = computed(() => !!props.period?.database_updated)

const statusConfig = computed(() => {
    if (dbDone.value)    return { color: 'bg-emerald-50 border-emerald-200', text: 'text-emerald-700', label: 'Completada', icon: CheckCircle, iconClass: 'text-emerald-600' }
    if (isRunning.value) return { color: 'bg-indigo-50 border-indigo-200', text: 'text-indigo-700', label: dbRunStatus.value === 'queued' ? 'En cola…' : 'Procesando…', icon: LoaderCircle, iconClass: 'text-indigo-600 animate-spin' }
    if (isFailed.value)  return { color: 'bg-rose-50 border-rose-200', text: 'text-rose-700', label: 'Falló', icon: XCircle, iconClass: 'text-rose-600' }
    if (props.canUpdate) return { color: 'bg-slate-50 border-slate-200', text: 'text-slate-600', label: 'Lista para ejecutar', icon: ShieldCheck, iconClass: 'text-slate-500' }
    return { color: 'bg-amber-50 border-amber-200', text: 'text-amber-700', label: 'Faltan fuentes', icon: TriangleAlert, iconClass: 'text-amber-600' }
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

            <!-- Fuentes requeridas -->
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
                    <div v-if="dbRunStarted || dbRunFinished" class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                        <span v-if="dbRunStarted">Inicio: <strong>{{ dbRunStarted }}</strong></span>
                        <span v-if="dbRunFinished">Fin: <strong>{{ dbRunFinished }}</strong></span>
                    </div>
                    <div v-if="isFailed && dbRunError" class="mt-3 break-all rounded-xl bg-rose-100 p-3 font-mono text-xs leading-5 text-rose-800">{{ dbRunError }}</div>
                </div>

                <!-- Aviso mientras corre -->
                <div v-if="isRunning" class="rounded-2xl border border-indigo-200 bg-indigo-50 p-4">
                    <p class="text-sm font-bold text-indigo-800">El proceso corre en segundo plano.</p>
                    <p class="mt-1 text-xs text-indigo-600">Puedes cerrar esta ventana. Te avisaremos por correo cuando termine.</p>
                    <button type="button" class="mt-3 inline-flex items-center gap-1.5 rounded-xl border border-indigo-300 bg-white px-3 py-2 text-xs font-bold text-indigo-700 transition hover:bg-indigo-50" @click="emit('refresh')">
                        <RefreshCw class="size-3.5" /> Actualizar estado
                    </button>
                </div>

                <!-- Botón acción -->
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
