<script setup lang="ts">
import { computed, ref } from 'vue'
import { Clock3, MailCheck, AlertCircle, RefreshCw, ChevronDown } from 'lucide-vue-next'
import StatusBadge from './StatusBadge.vue'

const props = defineProps<{ period: any }>()
const emit = defineEmits<{ (e: 'retry'): void }>()

const showTechDetail = ref(false)

const isFailed = computed(() => props.period?.radiography_run_status === 'failed')
const isRunning = computed(() => props.period?.radiography_running)
const isSuccess = computed(() => props.period?.radiography_ready)

const statusKey = computed(() => {
    if (isSuccess.value) return 'completed'
    if (isRunning.value) return 'running'
    if (isFailed.value) return 'error'
    return 'pending'
})

const publicLog = computed(() => props.period?.radiography_run_log ?? '')
</script>

<template>
    <section class="rounded-[2rem] border border-white/70 bg-white p-6 shadow-xl shadow-slate-200/70">

        <!-- Estado fallido — solo si el reporte no quedó listo realmente -->
        <template v-if="isFailed && !isSuccess">
            <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                <div class="flex gap-4">
                    <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-red-100 text-red-600">
                        <AlertCircle class="size-6" />
                    </div>
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.22em] text-red-600">Estado de generación</p>
                        <h3 class="mt-1 text-lg font-black text-slate-950">La generación falló</h3>
                        <p class="mt-1 text-sm leading-6 text-slate-600">
                            {{ publicLog || 'No se pudo generar la Radiografía. Revisa las fuentes cargadas e inténtalo nuevamente.' }}
                        </p>
                        <p v-if="period?.radiography_run_finished_at" class="mt-1 text-xs text-slate-400">
                            Falló el {{ period.radiography_run_finished_at }}
                        </p>
                    </div>
                </div>
                <StatusBadge status="error"  />
            </div>

            <!-- Acciones -->
            <div class="mt-5 flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    class="inline-flex h-10 items-center gap-2 rounded-2xl bg-indigo-600 px-5 text-sm font-black text-white shadow transition hover:bg-indigo-500"
                    @click="emit('retry')"
                >
                    <RefreshCw class="size-4" />
                    Reintentar generación
                </button>

                <button
                    type="button"
                    class="inline-flex h-10 items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-600 transition hover:border-slate-300 hover:text-slate-900"
                    @click="showTechDetail = !showTechDetail"
                >
                    <ChevronDown class="size-4 transition-transform" :class="{ 'rotate-180': showTechDetail }" />
                    Ver detalle técnico
                </button>
            </div>

            <!-- Detalle técnico colapsable -->
            <div v-if="showTechDetail" class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-xs text-slate-500">
                <p class="font-semibold text-slate-700">Información de referencia</p>
                <p class="mt-1">Código de referencia: Run #{{ period?.radiography_run_id ?? '—' }}</p>
                <p class="mt-1 text-slate-400">El equipo técnico puede consultar el error completo en los logs del sistema usando este código.</p>
            </div>
        </template>

        <!-- Estado normal (en progreso / completado / pendiente) -->
        <template v-else>
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div class="flex gap-4">
                    <div class="flex size-12 items-center justify-center rounded-2xl bg-slate-950 text-white">
                        <Clock3 class="size-6" />
                    </div>
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.22em] text-indigo-600">Estado de generación</p>
                        <h3 class="mt-1 text-lg font-black text-slate-950">{{ publicLog || 'Sin generación en curso' }}</h3>
                        <p class="mt-1 text-sm text-slate-600">Cuando el job termine, el usuario recibirá un correo y el reporte quedará en Reportes mensuales.</p>
                    </div>
                </div>
                <StatusBadge :status="statusKey"  />
            </div>
            <div class="mt-5 flex items-center gap-2 rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-sm text-emerald-800">
                <MailCheck class="size-5" />
                Te avisaremos por correo cuando la Radiografía esté lista o si falla.
            </div>
        </template>

    </section>
</template>
