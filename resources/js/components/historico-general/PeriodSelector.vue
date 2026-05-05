<script setup lang="ts">
defineProps<{ periods: any[]; modelValue: number | null }>();
const emit = defineEmits<{ (e: 'update:modelValue', v: number): void }>();
</script>

<template>
  <div class="rounded-xl border bg-white p-4">
    <label class="mb-2 block text-sm font-medium text-slate-700">Periodo</label>
    <select
      class="w-full rounded-lg border-slate-300"
      :value="modelValue ?? ''"
      @change="emit('update:modelValue', Number(($event.target as HTMLSelectElement).value))"
    >
      <option disabled value="">Selecciona un periodo</option>
      <option v-for="period in periods" :key="period.id" :value="period.id">{{ period.label }}</option>
    </select>
  </div>
</template>
