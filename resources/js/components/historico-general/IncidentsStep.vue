<script setup lang="ts">
defineProps<{ incidents: any[] }>();
const emit = defineEmits(['resolve']);
</script>
<template>
  <div class="rounded-xl border bg-white p-4">
    <h3 class="mb-3 font-semibold">Incidencias</h3>
    <div v-if="incidents.length===0" class="text-sm text-emerald-600">Sin incidencias críticas.</div>
    <div v-for="item in incidents" :key="item.id" class="mb-2 rounded border p-2 text-sm">
      <div class="font-medium">{{ item.type }} - {{ item.message }}</div>
      <button class="mt-2 rounded border px-2 py-1 text-xs" @click="emit('resolve', item.id)">Resolver</button>
    </div>
  </div>
</template>
