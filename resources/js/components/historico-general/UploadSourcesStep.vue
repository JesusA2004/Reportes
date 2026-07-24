<script setup lang="ts">
import { computed } from 'vue'
import AutomaticPeriodInfo from './AutomaticPeriodInfo.vue'
import SourceUploadCard from './SourceUploadCard.vue'
import SectionHeader from './SectionHeader.vue'

const props = defineProps<{ sources: any[]; uploadsBySource: Record<string, any>; selectedPeriodId: number | null; period: any }>()
const emit = defineEmits(['upload', 'delete'])

// Rotación e IMSS ya no se cargan manualmente — se calculan automáticamente
// desde NOI Nómina + NOI Nómina Fiscal. Se ocultan de la grilla de carga.
const AUTO_DERIVED_CODES = ['rotacion', 'imss']
const visibleSources = computed(() => props.sources.filter((s) => !AUTO_DERIVED_CODES.includes(s.code)))
</script>

<template>
    <div class="space-y-5">
        <SectionHeader
            eyebrow="Etapa 1"
            title="Archivos y periodo"
            description="Carga los archivos del mes. Las fuentes marcadas &quot;Necesaria&quot; son obligatorias para activar la carga de registros; las marcadas &quot;Para el reporte&quot; son necesarias para generar la radiografía financiera."
        />
        <AutomaticPeriodInfo v-if="period?.is_derived" :period="period" />
        <template v-else>
            <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                <SourceUploadCard
                    v-for="source in visibleSources"
                    :key="source.id"
                    :source="source"
                    :upload="uploadsBySource[source.code]"
                    :selected-period-id="selectedPeriodId"
                    :disabled="!selectedPeriodId || period?.is_derived"
                    @upload="emit('upload', $event)"
                    @delete="emit('delete', $event)"
                />
            </div>
        </template>
    </div>
</template>
