<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { format } from 'date-fns'
import { es } from 'date-fns/locale'
import { Calendar as CalendarIcon, X } from 'lucide-vue-next'
import { parseDate } from '@internationalized/date'
// El tipo DateValue se toma de "reka-ui" (no de "@internationalized/date" directo)
// porque es el que espera <Calendar> (ver components/ui/calendar/Calendar.vue) —
// aunque describen el mismo valor en tiempo de ejecución, son declaraciones de tipo
// distintas y TypeScript las trataba como incompatibles al hacer v-model="selected".
import type { DateValue } from 'reka-ui'

import { Button } from '@/components/ui/button'
import { Calendar } from '@/components/ui/calendar'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'

type Model = string | null | undefined

const props = withDefaults(defineProps<{
  modelValue: Model
  label?: string
  placeholder?: string
  disabled?: boolean
  error?: string | null
  description?: string | null
  clearable?: boolean
}>(), {
  placeholder: 'Selecciona fecha',
  clearable: false,
})

const emit = defineEmits<{
  (e: 'update:modelValue', value: Model): void
}>()

const open = ref(false)
const selected = ref<DateValue | undefined>(undefined)

const toDateValue = (value: Model): DateValue | undefined => {
  if (!value) return undefined
  try {
    return parseDate(value)
  } catch {
    return undefined
  }
}

watch(
  () => props.modelValue,
  (value) => {
    selected.value = toDateValue(value)
  },
  { immediate: true },
)

const displayValue = computed(() => {
  const value = selected.value
  if (!value) return ''
  const localDate = new Date(value.year, value.month - 1, value.day)
  return format(localDate, 'dd/MM/yyyy', { locale: es })
})

const onPick = (value: DateValue | undefined) => {
  if (!value) return
  selected.value = value
  emit('update:modelValue', value.toString())
  open.value = false
}

const clearValue = () => {
  selected.value = undefined
  emit('update:modelValue', null)
}
</script>

<template>
  <div class="w-full space-y-1.5">
    <label
      v-if="label"
      class="text-sm font-semibold text-foreground"
    >
      {{ label }}
    </label>

    <Popover v-model:open="open">
      <PopoverTrigger as-child>
        <Button
          type="button"
          variant="outline"
          :disabled="disabled"
          class="h-11 w-full justify-between rounded-2xl border-border bg-background px-3 text-left font-normal hover:bg-muted/60"
        >
          <span
            :class="displayValue ? 'text-foreground font-medium' : 'text-muted-foreground'"
            class="truncate"
          >
            {{ displayValue || placeholder }}
          </span>

          <div class="flex items-center gap-2">
            <button
              v-if="clearable && modelValue"
              type="button"
              class="inline-flex h-6 w-6 items-center justify-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground"
              @click.stop="clearValue"
            >
              <X class="h-4 w-4" />
            </button>

            <CalendarIcon class="h-4 w-4 text-muted-foreground" />
          </div>
        </Button>
      </PopoverTrigger>

      <PopoverContent
        align="start"
        class="w-auto overflow-hidden rounded-3xl border-border bg-popover p-0 shadow-2xl"
      >
        <!-- @vue-expect-error DateValue de reka-ui (Calendar) y de @internationalized/date
             (parseDate/toDateValue de este archivo) son dos declaraciones de tipo
             distintas para el mismo valor en tiempo de ejecución — friction real de
             tipos entre paquetes externos, no un bug de este componente. -->
        <Calendar
          v-model="selected"
          locale="es"
          mode="single"
          class="p-3"
          @update:model-value="onPick"
        />
      </PopoverContent>
    </Popover>

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
