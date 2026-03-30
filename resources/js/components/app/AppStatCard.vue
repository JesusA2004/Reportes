<script setup lang="ts">
withDefaults(defineProps<{
  title: string
  value: string | number
  helper?: string | null
}>(), {
  helper: null,
})
</script>

<template>
  <div class="rounded-3xl border border-border bg-card p-5 shadow-sm">
    <p class="text-sm font-medium text-muted-foreground">
      {{ title }}
    </p>

    <p class="mt-2 text-3xl font-bold tracking-tight text-foreground">
      {{ value }}
    </p>

    <p
      v-if="helper"
      class="mt-2 text-xs text-muted-foreground"
    >
      {{ helper }}
    </p>
  </div>
</template>
