<script setup lang="ts">
import AutomaticPeriodInfo from './AutomaticPeriodInfo.vue'
import SourceUploadCard from './SourceUploadCard.vue'
import SectionHeader from './SectionHeader.vue'

defineProps<{ sources: any[]; uploadsBySource: Record<string, any>; selectedPeriodId: number | null; period: any }>()
const emit = defineEmits(['upload', 'delete'])
</script>

<template>
    <div class="space-y-5">
        <SectionHeader
            eyebrow="Etapa 1"
            title="Archivos y periodo"
            description="Carga los archivos sobre el mes operativo seleccionado. Las fuentes marcadas &quot;Req. Carga&quot; habilitan la carga de registros; las marcadas &quot;Req. Radiografía&quot; son necesarias para generar el reporte final."
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
            />
        </div>
    </div>
</template>
