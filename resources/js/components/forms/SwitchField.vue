<script setup lang="ts">
import { Switch } from '@/components/ui/switch'

const model = defineModel<boolean>({ default: false })

withDefaults(defineProps<{
  label?: string
  description?: string | null
  disabled?: boolean
}>(), {
  disabled: false,
})
</script>

<template>
  <div class="flex items-start justify-between gap-4 rounded-3xl border border-border bg-card p-4">
    <div class="space-y-1">
      <p
        v-if="label"
        class="text-sm font-semibold text-foreground"
      >
        {{ label }}
      </p>

      <p
        v-if="description"
        class="text-xs text-muted-foreground"
      >
        {{ description }}
      </p>
    </div>

    <Switch
      v-model:checked="model"
      :disabled="disabled"
    />
  </div>
</template>
