<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Swal from 'sweetalert2';
import AppLayout from '@/layouts/AppLayout.vue';
import WorkflowStepper from '@/components/historico-general/WorkflowStepper.vue';
import PeriodSelector from '@/components/historico-general/PeriodSelector.vue';
import UploadSourcesStep from '@/components/historico-general/UploadSourcesStep.vue';
import DatabaseUpdateStep from '@/components/historico-general/DatabaseUpdateStep.vue';
import IncidentsStep from '@/components/historico-general/IncidentsStep.vue';
import ReportConfigurationStep from '@/components/historico-general/ReportConfigurationStep.vue';
import ReportGenerationStatus from '@/components/historico-general/ReportGenerationStatus.vue';
import ReportPreview from '@/components/historico-general/ReportPreview.vue';
import GeneratedReportActions from '@/components/historico-general/GeneratedReportActions.vue';
defineOptions({ layout: AppLayout });
const props = defineProps<{ periods:any[]; sources:any[]; groupedUploads:any[]; currentPeriodId:number|null }>();
const currentStep = ref('files');
const selectedPeriodId = ref<number|null>(props.currentPeriodId ?? null);
const incidents = ref<any[]>([]);
const steps = [
{ key:'files', label:'Periodo y archivos'}, { key:'bd', label:'Actualización BD'}, { key:'incidents', label:'Incidencias'}, { key:'config', label:'Configurar reporte'}, { key:'preview', label:'Vista previa'}, { key:'exports', label:'Exportación / archivos generados'}
];
const period = computed(()=> props.periods.find(p=>p.id===selectedPeriodId.value) ?? null);
const grouped = computed(()=> props.groupedUploads.find(p=>p.period_id===selectedPeriodId.value) ?? null);
const uploadsBySource = computed(()=> {
  const map:Record<string, any> = {};
  for (const u of grouped.value?.uploads ?? []) map[u.source_code]=u;
  return map;
});
const form = useForm({ period_id:'', data_source_id:'', file:null as File | null, notes:'', covered_period_ids:[] as number[] });
watch(selectedPeriodId, ()=> { incidents.value=[]; currentStep.value='files'; });
const uploadFile = ({sourceId, file}:{sourceId:number; file:File}) => {
  if (!selectedPeriodId.value) return;
  form.period_id = String(selectedPeriodId.value); form.data_source_id = String(sourceId); form.file = file; form.covered_period_ids=[selectedPeriodId.value];
  form.post('/historico-general', { forceFormData:true, onSuccess:()=>Swal.fire('Archivo cargado','Pendiente de procesar','success') });
};
const deleteUpload = (id:number)=> router.delete(`/historico-general/${id}`);
const updateDatabase = ()=> selectedPeriodId.value && router.post(`/historico-general/${selectedPeriodId.value}/actualizar-bd`, {}, { onSuccess:()=>{ Swal.fire('BD actualizada','Revisa incidencias pendientes','success'); currentStep.value='incidents'; loadIncidents(); } });
const loadIncidents = async () => {
  if (!selectedPeriodId.value) return;
  const response = await fetch(`/historico-general/${selectedPeriodId.value}/incidencias`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  const data = await response.json();
  incidents.value = data.items ?? [];
};
const resolveIncident = (id:number)=> selectedPeriodId.value && router.post(`/historico-general/${selectedPeriodId.value}/incidencias/${id}/resolver`, { resolution_note:'Resuelta desde flujo.' }, { onSuccess:()=>{ Swal.fire('Incidencia resuelta','','success'); loadIncidents(); } });
</script>
<template>
  <Head title="Histórico general" />
  <div class="space-y-4 p-4">
    <WorkflowStepper :steps="steps" :current="currentStep" />
    <PeriodSelector v-model="selectedPeriodId" :periods="periods" />
    <UploadSourcesStep :sources="sources" :uploads-by-source="uploadsBySource" :selected-period-id="selectedPeriodId" @upload="uploadFile" @delete="deleteUpload" />
    <DatabaseUpdateStep :can-update="Boolean(period?.can_update_database)" @update="updateDatabase" />
    <IncidentsStep :incidents="incidents" @resolve="resolveIncident" />
    <ReportConfigurationStep :period="period" />
    <ReportGenerationStatus :period="period" />
    <ReportPreview :period="period" />
    <GeneratedReportActions :period="period" />
  </div>
</template>
