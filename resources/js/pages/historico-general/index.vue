<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import Swal from 'sweetalert2'
import { CalendarDays } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import WorkflowStepper from '@/components/historico-general/WorkflowStepper.vue'
import PeriodSelector from '@/components/historico-general/PeriodSelector.vue'
import UploadSourcesStep from '@/components/historico-general/UploadSourcesStep.vue'
import DatabaseUpdateStep from '@/components/historico-general/DatabaseUpdateStep.vue'
import IncidentsStep from '@/components/historico-general/IncidentsStep.vue'
import ReportConfigurationStep from '@/components/historico-general/ReportConfigurationStep.vue'
import ReportGenerationStatus from '@/components/historico-general/ReportGenerationStatus.vue'
import ReportPreview from '@/components/historico-general/ReportPreview.vue'
import GeneratedReportActions from '@/components/historico-general/GeneratedReportActions.vue'
import { useHistoricWorkflow } from '@/composables/useHistoricWorkflow'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    periods: any[]
    sources: any[]
    groupedUploads: any[]
    currentPeriodId: number | null
    preview: any | null
    branches: Array<{ id: number; name: string }>
    employees: Array<{ id: number; full_name: string; branch_name?: string }>
}>()

const selectedPeriodId = ref<number | null>(props.currentPeriodId ?? null)
const incidents        = ref<any[]>([])
const reportConfig     = ref({
    report_type: 'simple',
    scope: 'general',
    branch_id: null as number | null,
    employee_id: null as number | null,
    compare_period_id: null as number | null,
    extra_employee_expense_amount: 0,
    extra_employee_expense_notes: '',
})

const period  = computed(() => props.periods.find((p) => p.id === selectedPeriodId.value) ?? null)
const grouped = computed(() => props.groupedUploads.find((p) => p.period_id === selectedPeriodId.value) ?? null)
const uploadsBySource = computed(() => {
    const map: Record<string, any> = {}
    for (const u of grouped.value?.uploads ?? []) map[u.source_code] = u
    return map
})

const { currentStep, steps, selectStep } = useHistoricWorkflow(period, incidents)
const form = useForm({ period_id: '', data_source_id: '', file: null as File | null, notes: '', covered_period_ids: [] as number[] })

// ── Carga de incidencias ──────────────────────────────────────────────
async function loadIncidents() {
    if (!selectedPeriodId.value) return
    const res  = await fetch(`/historico-general/${selectedPeriodId.value}/incidencias`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    const data = await res.json()
    incidents.value = data.items ?? []
}

watch(selectedPeriodId, () => {
    incidents.value    = []
    currentStep.value  = 'files'
    // Solo cargar incidencias si la BD ya está actualizada
    if (period.value?.database_updated) loadIncidents()
}, { immediate: true })

// ── Polling ──────────────────────────────────────────────────────────
const isDbUpdating      = computed(() => ['queued', 'running'].includes(period.value?.database_update_run_status))
const isRadioGenerating = computed(() => ['queued', 'running'].includes(period.value?.radiography_run_status))

let pollInterval: ReturnType<typeof setInterval> | null = null

function startPolling() {
    if (pollInterval) return
    pollInterval = setInterval(() => {
        router.reload({ only: ['periods', 'groupedUploads'] })
    }, 7000)
}

function stopPolling() {
    if (pollInterval) { clearInterval(pollInterval); pollInterval = null }
}

watch([isDbUpdating, isRadioGenerating], ([db, radio]) => {
    if (db || radio) startPolling()
    else stopPolling()
}, { immediate: true })

// Notificar cuando BD termina (queued/running → success/failed)
watch(() => period.value?.database_update_run_status, (newStatus, oldStatus) => {
    const wasRunning = ['queued', 'running'].includes(String(oldStatus ?? ''))
    if (!wasRunning) return

    if (newStatus === 'success') {
        loadIncidents().then(() => {
            Swal.fire({
                title: 'Base de datos actualizada',
                html: `La BD del periodo <strong>${period.value?.label ?? ''}</strong> se actualizó correctamente.<br><br>Puedes revisar las incidencias y continuar.`,
                icon: 'success',
                confirmButtonText: 'Revisar incidencias',
                showCancelButton: true,
                cancelButtonText: 'Continuar después',
                reverseButtons: true,
            }).then((r) => {
                if (r.isConfirmed) selectStep('incidents')
            })
        })
    } else if (newStatus === 'failed') {
        Swal.fire({
            title: 'La actualización falló',
            text: period.value?.database_update_run_error ?? 'El proceso terminó con error. Revisa el detalle en la etapa de Actualización BD.',
            icon: 'error',
            confirmButtonText: 'Ver detalle',
        }).then(() => selectStep('bd'))
    }
})

onUnmounted(stopPolling)

// ── Helpers ───────────────────────────────────────────────────────────
const toastError = (title: string, text: string) =>
    Swal.fire({ title, text, icon: 'warning', confirmButtonText: 'Entendido' })

// ── Acciones ──────────────────────────────────────────────────────────
const uploadFile = async ({ sourceId, file }: { sourceId: number; file: File }) => {
    if (!period.value) return toastError('Selecciona periodo', 'Elige un periodo antes de cargar archivos.')
    if (period.value.is_derived || !period.value.can_receive_uploads) return toastError('Periodo automático', 'Este periodo es automático y no recibe archivos directos.')
    form.period_id         = String(selectedPeriodId.value)
    form.data_source_id    = String(sourceId)
    form.file              = file
    form.covered_period_ids = [selectedPeriodId.value as number]
    Swal.fire({ title: 'Subiendo archivo', text: 'Validando formato y guardando fuente.', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() })
    form.post('/historico-general', { forceFormData: true, preserveScroll: true, onSuccess: () => Swal.fire('Archivo subido', 'La fuente quedó registrada para este periodo.', 'success'), onError: () => Swal.fire('Error de carga', 'Revisa formato, periodo y fuente seleccionada.', 'error') })
}

const deleteUpload = async (id: number) => {
    const result = await Swal.fire({ title: '¿Eliminar archivo?', text: 'Se quitará esta fuente del periodo.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar', reverseButtons: true })
    if (!result.isConfirmed) return
    router.delete(`/historico-general/${id}`, { preserveScroll: true, onSuccess: () => Swal.fire('Archivo eliminado', 'La fuente fue retirada correctamente.', 'success'), onError: () => Swal.fire('No se pudo eliminar', 'Intenta nuevamente.', 'error') })
}

const reprocessUpload = async (id: number) => {
    const result = await Swal.fire({ title: '¿Reprocesar archivo?', text: 'Se volverá a analizar la fuente. Los datos anteriores serán reemplazados.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, reprocesar', cancelButtonText: 'Cancelar', reverseButtons: true })
    if (!result.isConfirmed) return
    Swal.fire({ title: 'Reprocesando…', text: 'El archivo se está analizando.', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() })
    router.post(`/historico-general/${id}/analizar`, {}, { preserveScroll: true, onSuccess: () => Swal.fire('Reprocesado', 'La fuente fue analizada nuevamente.', 'success'), onError: () => Swal.fire('Error al reprocesar', 'Revisa el archivo fuente.', 'error') })
}

const updateDatabase = async () => {
    if (!selectedPeriodId.value) return
    const result = await Swal.fire({ title: 'Actualizar base de datos', text: 'Se enviará el proceso a cola. Recibirás un correo cuando termine.', icon: 'info', showCancelButton: true, confirmButtonText: 'Enviar a cola', cancelButtonText: 'Cancelar', reverseButtons: true })
    if (!result.isConfirmed) return
    router.post(`/historico-general/${selectedPeriodId.value}/actualizar-bd`, {}, {
        preserveScroll: true,
        onSuccess: () => Swal.fire({ title: 'Proceso en cola', text: 'Puedes cerrar esta ventana. Te avisaremos por correo cuando la BD termine de actualizarse.', icon: 'success', confirmButtonText: 'Entendido' }),
        onError:   () => Swal.fire('No se pudo iniciar', 'Faltan fuentes o ya hay un proceso en ejecución.', 'error'),
    })
}

const cancelDatabaseUpdate = async () => {
    if (!selectedPeriodId.value) return
    const result = await Swal.fire({
        title: '¿Cancelar la actualización?',
        text: 'El proceso se marcará como fallido. Podrás reintentarlo desde la etapa de Actualización BD.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, cancelar proceso',
        cancelButtonText: 'No, dejar corriendo',
        reverseButtons: true,
        confirmButtonColor: '#ef4444',
    })
    if (!result.isConfirmed) return
    router.post(`/historico-general/${selectedPeriodId.value}/actualizacion-bd/cancelar`, {}, {
        preserveScroll: true,
        onSuccess: () => Swal.fire({ title: 'Proceso cancelado', text: 'Puedes reintentar la actualización cuando estés listo.', icon: 'info', confirmButtonText: 'Entendido' }),
        onError:   () => Swal.fire('No se pudo cancelar', 'El proceso ya terminó o no existe.', 'error'),
    })
}

const resolveIncident = async (id: number) => {
    const result = await Swal.fire({ title: 'Resolver incidencia', input: 'textarea', inputLabel: 'Nota de resolución', inputPlaceholder: 'Describe cómo quedó resuelta…', showCancelButton: true, confirmButtonText: 'Guardar resolución', cancelButtonText: 'Cancelar', inputValidator: (value) => !value ? 'Captura una nota de resolución.' : undefined })
    if (!result.isConfirmed || !selectedPeriodId.value) return
    router.post(`/historico-general/${selectedPeriodId.value}/incidencias/${id}/resolver`, { resolution_note: result.value }, {
        preserveScroll: true,
        onSuccess: () => { Swal.fire('Incidencia resuelta', 'El estado del flujo se actualizó.', 'success'); loadIncidents() },
        onError:   () => Swal.fire('No se pudo resolver', 'Intenta nuevamente.', 'error'),
    })
}

const generateReport = () => {
    if (!period.value?.can_generate_radiography) return toastError('Generación bloqueada', period.value?.blocking_reasons?.join(' ') || 'Completa las etapas previas.')
    if (reportConfig.value.report_type !== 'simple' && !reportConfig.value.compare_period_id) return toastError('Falta periodo comparable', 'Selecciona explícitamente el periodo a comparar.')
    Swal.fire({ title: 'Reporte enviado a procesamiento', text: 'El reporte se genera en segundo plano. Puedes cerrar esta ventana; te avisaremos por correo cuando el Excel y PDF estén listos.', icon: 'info', confirmButtonText: 'Entendido' })
    router.post(`/historico-general/${selectedPeriodId.value}/generar-radiografia`, { config: reportConfig.value }, { preserveScroll: true })
}
</script>

<template>
    <Head title="Histórico general" />
    <main class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-indigo-50/40 p-4 sm:p-6 lg:p-8">
        <div class="mx-auto max-w-screen-2xl space-y-6">

            <!-- Hero header compacto -->
            <section class="overflow-hidden rounded-[2rem] bg-slate-950 px-6 py-5 text-white shadow-2xl shadow-slate-300 sm:px-8 sm:py-6">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.28em] text-indigo-300">Histórico general</p>
                        <h1 class="mt-1 text-2xl font-black tracking-tight sm:text-3xl">
                            Flujo guiado · Carga, análisis y Radiografía
                        </h1>
                    </div>
                    <p class="max-w-sm text-xs leading-5 text-slate-400 sm:text-right">
                        Selecciona el periodo, carga las 5 fuentes, actualiza la BD,<br class="hidden sm:block" />
                        revisa incidencias y genera reportes Excel y PDF.
                    </p>
                </div>
            </section>

            <!-- ① Selección de periodo — siempre visible y primero -->
            <PeriodSelector v-model="selectedPeriodId" :periods="periods" />

            <!-- Sin periodo: empty state guiado -->
            <div
                v-if="!selectedPeriodId"
                class="flex flex-col items-center justify-center rounded-[2rem] border border-dashed border-slate-300 bg-white/60 py-20 text-center shadow-sm"
            >
                <div class="flex size-16 items-center justify-center rounded-3xl bg-indigo-100 shadow-lg shadow-indigo-100">
                    <CalendarDays class="size-8 text-indigo-500" />
                </div>
                <h2 class="mt-5 text-xl font-black text-slate-800">Selecciona un periodo para iniciar</h2>
                <p class="mt-2 max-w-sm text-sm leading-6 text-slate-500">
                    Usa el selector de arriba para elegir una semana base o periodo automático.<br />
                    El flujo guiado se desplegará aquí.
                </p>
            </div>

            <!-- Con periodo: stepper + contenido de la etapa -->
            <template v-else>

                <!-- ② Stepper del flujo -->
                <WorkflowStepper :steps="steps" :current="currentStep" @select="selectStep" />

                <!-- ③ Contenido de la etapa actual -->
                <transition name="fade" mode="out-in">
                    <UploadSourcesStep
                        v-if="currentStep === 'files'"
                        :key="`files-${selectedPeriodId}`"
                        :period="period"
                        :sources="sources"
                        :uploads-by-source="uploadsBySource"
                        :selected-period-id="selectedPeriodId"
                        @upload="uploadFile"
                        @delete="deleteUpload"
                        @reprocess="reprocessUpload"
                    />
                    <DatabaseUpdateStep
                        v-else-if="currentStep === 'bd'"
                        :period="period"
                        :can-update="Boolean(period?.can_update_database)"
                        @update="updateDatabase"
                        @cancel="cancelDatabaseUpdate"
                        @refresh="() => router.reload({ only: ['periods', 'groupedUploads'] })"
                    />
                    <IncidentsStep
                        v-else-if="currentStep === 'incidents'"
                        :period="period"
                        :incidents="incidents"
                        @resolve="resolveIncident"
                        @refresh="loadIncidents"
                    />
                    <div v-else-if="currentStep === 'config'" class="space-y-5">
                        <ReportConfigurationStep
                            v-model="reportConfig"
                            :period="period"
                            :periods="periods"
                            :branches="branches"
                            :employees="employees"
                            :can-generate="Boolean(period?.can_generate_radiography)"
                            @generate="generateReport"
                        />
                        <ReportGenerationStatus :period="period" />
                    </div>
                    <ReportPreview
                        v-else-if="currentStep === 'preview'"
                        :period="period"
                        :preview="preview"
                        :config="reportConfig"
                    />
                    <GeneratedReportActions v-else :period="period" />
                </transition>

            </template>

        </div>
    </main>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: all 220ms ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; transform: translateY(8px); }
</style>
