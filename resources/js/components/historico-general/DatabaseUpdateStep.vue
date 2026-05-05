<script setup lang="ts">
defineProps<{ canUpdate: boolean }>();
const emit = defineEmits(['update']);
</script>
<template><div class="rounded-xl border bg-white p-4"><button class="rounded bg-indigo-600 px-4 py-2 text-white disabled:opacity-50" :disabled="!canUpdate" @click="emit('update')">Actualizar BD</button></div></template>
