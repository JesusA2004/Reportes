<script setup lang="ts">
import SourceUploadCard from './SourceUploadCard.vue';
defineProps<{ sources: any[]; uploadsBySource: Record<string, any>; selectedPeriodId: number | null }>();
const emit = defineEmits(['upload', 'delete']);
</script>
<template>
  <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
    <SourceUploadCard v-for="source in sources" :key="source.id" :source="source" :upload="uploadsBySource[source.code]" :selected-period-id="selectedPeriodId" @upload="emit('upload', $event)" @delete="emit('delete', $event)" />
  </div>
</template>
