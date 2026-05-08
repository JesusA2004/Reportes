<script setup lang="ts">
import AutomaticPeriodInfo from './AutomaticPeriodInfo.vue'
import SourceUploadCard from './SourceUploadCard.vue'
import SectionHeader from './SectionHeader.vue'

defineProps<{ sources: any[]; uploadsBySource: Record<string, any>; selectedPeriodId: number | null; period: any }>()
const emit = defineEmits(['upload', 'delete', 'reprocess'])
</script>

<template>
    <div class="space-y-5">
        <SectionHeader
            eyebrow="Etapa 1"
            title="Periodo y archivos fuente"
            description="Carga los archivos sobre el mes operativo seleccionado. Las fuentes se muestran dinámicamente según la configuración de data_sources. Las marcadas &quot;Req. BD&quot; habilitan la actualización de BD; las marcadas &quot;Req. Radiografía&quot; son necesarias para generar el reporte final."
        />
        <AutomaticPeriodInfo v-if="period?.is_derived" :period="period" />
        <div v-else class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            <SourceUploadCard
                v-for="source in sources"
                :key="source.id"
                :source="source"
                :upload="uploadsBySource[source.code]"
                :selected-period-id="selectedPeriodId"
                :disabled="!selectedPeriodId || period?.is_derived"
                @upload="emit('upload', $event)"
                @delete="emit('delete', $event)"
                @reprocess="emit('reprocess', $event)"
            />
        </div>
    </div>
</template>
