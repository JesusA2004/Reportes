<script setup lang="ts">
import { computed } from 'vue'
import { Sparkles } from 'lucide-vue-next'
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
            <div class="flex items-start gap-3 rounded-2xl border border-indigo-100 bg-indigo-50/60 p-4">
                <Sparkles class="mt-0.5 size-5 shrink-0 text-indigo-600" />
                <p class="text-xs leading-5 text-indigo-900">
                    <strong>Rotación de personal</strong> e <strong>IMSS</strong> ya no se cargan por archivo — se calculan automáticamente
                    a partir de NOI Nómina y NOI Nómina Fiscal al actualizar la base de datos.
                </p>
            </div>
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
