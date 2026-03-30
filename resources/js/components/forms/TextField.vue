<script setup lang="ts">
const model = defineModel<string | number | null>()

withDefaults(defineProps<{
  label?: string
  type?: string
  placeholder?: string
  error?: string | null
  description?: string | null
  disabled?: boolean
}>(), {
  type: 'text',
  placeholder: '',
  disabled: false,
})
</script>

<template>
  <div class="w-full space-y-1.5">
    <label
      v-if="label"
      class="text-sm font-semibold text-foreground"
    >
      {{ label }}
    </label>

    <input
      v-model="model"
      :type="type"
      :placeholder="placeholder"
      :disabled="disabled"
      class="app-input"
    >

    <p
      v-if="description && !error"
      class="text-xs text-muted-foreground"
    >
      {{ description }}
    </p>

    <p
      v-if="error"
      class="text-xs font-semibold text-destructive"
    >
      {{ error }}
    </p>
  </div>
</template>
