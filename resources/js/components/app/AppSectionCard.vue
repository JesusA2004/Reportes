<script setup lang="ts">
withDefaults(defineProps<{
  title?: string | null
  subtitle?: string | null
  padded?: boolean
}>(), {
  title: null,
  subtitle: null,
  padded: true,
})
</script>

<template>
  <section class="app-section">
    <div
      v-if="title || subtitle"
      class="border-b border-border px-5 py-4"
    >
      <h2
        v-if="title"
        class="text-base font-semibold text-foreground"
      >
        {{ title }}
      </h2>
      <p
        v-if="subtitle"
        class="mt-1 text-sm text-muted-foreground"
      >
        {{ subtitle }}
      </p>
    </div>

    <div :class="padded ? 'p-5' : ''">
      <slot />
    </div>
  </section>
</template>
