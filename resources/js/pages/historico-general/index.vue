<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import Swal from 'sweetalert2'
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
const props = defineProps<{ periods:any[]; sources:any[]; groupedUploads:any[]; currentPeriodId:number|null; preview:any|null }>()
const selectedPeriodId = ref<number|null>(props.currentPeriodId ?? null)
const incidents = ref<any[]>([])
const reportConfig = ref({ report_type: 'simple', scope: 'general', branch: '', employee: '', compare_period_id: null, sections: [], employee_expenses: { gasolina: 0, recargas: 0, nomina_indirecta: 0, otros: 0 } })
const period = computed(() => props.periods.find((p) => p.id === selectedPeriodId.value) ?? null)
const grouped = computed(() => props.groupedUploads.find((p) => p.period_id === selectedPeriodId.value) ?? null)
const uploadsBySource = computed(() => {
    const map:Record<string, any> = {}
    for (const u of grouped.value?.uploads ?? []) map[u.source_code] = u
    return map
})
const { currentStep, steps, selectStep } = useHistoricWorkflow(period, incidents)
const form = useForm({ period_id:'', data_source_id:'', file:null as File | null, notes:'', covered_period_ids:[] as number[] })

async function loadIncidents() {
    if (!selectedPeriodId.value) return
    const response = await fetch(`/historico-general/${selectedPeriodId.value}/incidencias`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    const data = await response.json()
    incidents.value = data.items ?? []
}

watch(selectedPeriodId, () => { incidents.value = []; currentStep.value = 'files'; loadIncidents() }, { immediate: true })

const toastError = (title: string, text: string) => Swal.fire({ title, text, icon: 'warning', confirmButtonText: 'Entendido' })
const uploadFile = async ({ sourceId, file }:{ sourceId:number; file:File }) => {
    if (!period.value) return toastError('Selecciona periodo', 'Elige un periodo antes de cargar archivos.')
    if (period.value.is_derived || !period.value.can_receive_uploads) return toastError('Periodo automático', 'Este periodo es automático y no recibe archivos directos.')
    form.period_id = String(selectedPeriodId.value)
    form.data_source_id = String(sourceId)
    form.file = file
    form.covered_period_ids = [selectedPeriodId.value as number]
    Swal.fire({ title:'Subiendo archivo', text:'Validando formato y guardando fuente.', allowOutsideClick:false, showConfirmButton:false, didOpen:()=>Swal.showLoading() })
    form.post('/historico-general', { forceFormData:true, preserveScroll:true, onSuccess:()=>Swal.fire('Archivo subido correctamente','La fuente quedó registrada para este periodo.','success'), onError:()=>Swal.fire('Error de carga','Revisa formato, periodo y fuente seleccionada.','error') })
}
const deleteUpload = async (id:number) => {
    const result = await Swal.fire({ title:'¿Eliminar archivo?', text:'Se quitará esta fuente del periodo.', icon:'warning', showCancelButton:true, confirmButtonText:'Sí, eliminar', cancelButtonText:'Cancelar', reverseButtons:true })
    if (!result.isConfirmed) return
    router.delete(`/historico-general/${id}`, { preserveScroll:true, onSuccess:()=>Swal.fire('Archivo eliminado','La fuente fue retirada correctamente.','success'), onError:()=>Swal.fire('No se pudo eliminar','Intenta nuevamente.','error') })
}
const reprocessUpload = (id:number) => router.post(`/historico-general/${id}/analizar`, {}, { preserveScroll:true, onSuccess:()=>Swal.fire('Reprocesamiento iniciado','La fuente se volvió a analizar.','success'), onError:()=>Swal.fire('No se pudo reprocesar','Revisa el archivo fuente.','error') })
const updateDatabase = () => {
    if (!selectedPeriodId.value) return
    Swal.fire({ title:'Actualizando base de datos', html:'<p>Validando archivos obligatorios...</p><p>Leyendo NOI y Cobranza...</p><p>Registrando incidencias...</p>', allowOutsideClick:false, showConfirmButton:false, didOpen:()=>Swal.showLoading() })
    router.post(`/historico-general/${selectedPeriodId.value}/actualizar-bd`, {}, { preserveScroll:true, onSuccess:()=>{ Swal.fire('BD actualizada','Revisa incidencias pendientes antes de continuar.','success'); currentStep.value='incidents'; loadIncidents() }, onError:()=>Swal.fire('No se pudo actualizar','Faltan fuentes o alguna fuente tiene error.','error') })
}
const resolveIncident = async (id:number) => {
    const result = await Swal.fire({ title:'Resolver incidencia', input:'textarea', inputLabel:'Nota de resolución', inputPlaceholder:'Describe cómo quedó resuelta...', showCancelButton:true, confirmButtonText:'Guardar resolución', cancelButtonText:'Cancelar', inputValidator:(value)=> !value ? 'Captura una nota de resolución.' : undefined })
    if (!result.isConfirmed || !selectedPeriodId.value) return
    router.post(`/historico-general/${selectedPeriodId.value}/incidencias/${id}/resolver`, { resolution_note: result.value }, { preserveScroll:true, onSuccess:()=>{ Swal.fire('Incidencia resuelta','El estado del flujo se actualizó.','success'); loadIncidents() }, onError:()=>Swal.fire('No se pudo resolver','Intenta nuevamente.','error') })
}
const generateReport = () => {
    if (!period.value?.can_generate_radiography) return toastError('Generación bloqueada', period.value?.blocking_reasons?.join(' ') || 'Completa las etapas previas.')
    if (reportConfig.value.report_type !== 'simple' && !reportConfig.value.compare_period_id) return toastError('Falta periodo comparable', 'Selecciona explícitamente el periodo a comparar.')
    Swal.fire({ title:'El reporte se está procesando', text:'Puedes cerrar esta ventana. Te avisaremos por correo cuando esté listo.', icon:'info', confirmButtonText:'Entendido' })
    router.post(`/historico-general/${selectedPeriodId.value}/generar-radiografia`, { config: reportConfig.value }, { preserveScroll:true })
}
</script>

<template>
    <Head title="Histórico general" />
    <main class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-indigo-50/40 p-4 sm:p-6 lg:p-8">
        <div class="mx-auto max-w-7xl space-y-6">
            <section class="overflow-hidden rounded-[2rem] bg-slate-950 p-6 text-white shadow-2xl shadow-slate-300 sm:p-8">
                <p class="text-xs font-black uppercase tracking-[0.28em] text-indigo-200">Histórico general</p>
                <h1 class="mt-3 max-w-4xl text-3xl font-black tracking-tight sm:text-4xl">Flujo guiado para carga, generación y consulta de Radiografía</h1>
                <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-300">Selecciona periodos reales, carga fuentes, actualiza BD, resuelve incidencias y genera reportes con Excel/PDF guardados para Reportes mensuales.</p>
            </section>
            <WorkflowStepper :steps="steps" :current="currentStep" @select="selectStep" />
            <PeriodSelector v-model="selectedPeriodId" :periods="periods" />
            <transition name="fade" mode="out-in">
                <UploadSourcesStep v-if="currentStep === 'files'" :key="`files-${selectedPeriodId}`" :period="period" :sources="sources" :uploads-by-source="uploadsBySource" :selected-period-id="selectedPeriodId" @upload="uploadFile" @delete="deleteUpload" @reprocess="reprocessUpload" />
                <DatabaseUpdateStep v-else-if="currentStep === 'bd'" :period="period" :can-update="Boolean(period?.can_update_database)" @update="updateDatabase" />
                <IncidentsStep v-else-if="currentStep === 'incidents'" :period="period" :incidents="incidents" @resolve="resolveIncident" @refresh="loadIncidents" />
                <div v-else-if="currentStep === 'config'" class="space-y-5"><ReportConfigurationStep v-model="reportConfig" :period="period" :periods="periods" :can-generate="Boolean(period?.can_generate_radiography)" @generate="generateReport" /><ReportGenerationStatus :period="period" /></div>
                <ReportPreview v-else-if="currentStep === 'preview'" :period="period" :preview="preview" :config="reportConfig" />
                <GeneratedReportActions v-else :period="period" />
            </transition>
        </div>
    </main>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: all 220ms ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; transform: translateY(8px); }
</style>
