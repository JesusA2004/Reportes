<script setup lang="ts">
withDefaults(defineProps<{
  title: string
  subtitle?: string | null
}>(), {
  subtitle: null,
})
</script>

<template>
  <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
    <div class="space-y-1">
      <h1 class="text-2xl font-bold tracking-tight text-foreground md:text-3xl">
        {{ title }}
      </h1>
      <p
        v-if="subtitle"
        class="text-sm text-muted-foreground md:text-base"
      >
        {{ subtitle }}
      </p>
    </div>

    <div class="flex flex-wrap items-center gap-2">
      <slot name="actions" />
    </div>
  </div>
</template>
