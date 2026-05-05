<script setup lang="ts">
defineProps<{ steps: { key: string; label: string }[]; current: string }>();
</script>

<template>
  <div class="rounded-xl border bg-white p-4">
    <div class="grid gap-2 md:grid-cols-6">
      <button
        v-for="(step, i) in steps"
        :key="step.key"
        type="button"
        class="rounded-lg border px-3 py-2 text-left text-sm"
        :class="current === step.key ? 'border-indigo-600 bg-indigo-50 text-indigo-700' : 'border-slate-200 text-slate-600'"
      >
        <div class="text-xs font-semibold">Etapa {{ i + 1 }}</div>
        <div>{{ step.label }}</div>
      </button>
    </div>
  </div>
</template>
