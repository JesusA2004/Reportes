<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Check, ChevronsUpDown, Search, X } from 'lucide-vue-next'

type OptionLike = Record<string, any>

const props = withDefaults(defineProps<{
  modelValue: string | number | null
  options: OptionLike[]
  id?: string
  label?: string
  placeholder?: string
  searchPlaceholder?: string
  error?: string | null
  description?: string | null
  labelKey?: string
  secondaryKey?: string
  valueKey?: string
  allowNull?: boolean
  nullLabel?: string
  disabled?: boolean
}>(), {
  placeholder: 'Selecciona...',
  searchPlaceholder: 'Buscar...',
  labelKey: 'nombre',
  secondaryKey: 'codigo',
  valueKey: 'id',
  allowNull: false,
  nullLabel: 'Sin selección',
  disabled: false,
})

const emit = defineEmits<{
  (e: 'update:modelValue', value: string | number | null): void
  (e: 'change', value: string | number | null): void
}>()

const open = ref(false)
const query = ref('')

const buttonRef = ref<HTMLElement | null>(null)
const panelRef = ref<HTMLElement | null>(null)
const searchRef = ref<HTMLInputElement | null>(null)
const panelStyle = ref<Record<string, string>>({})

const uid = `ss-${Math.random().toString(36).slice(2, 10)}`
const buttonId = computed(() => props.id ?? uid)

const selected = computed<OptionLike | null>(() => {
  const value = props.modelValue
  if (value === null || value === undefined || value === '') return null

  return props.options.find(
    (item) => String(item?.[props.valueKey]) === String(value),
  ) ?? null
})

const filteredOptions = computed(() => {
  const term = query.value.trim().toLowerCase()
  if (!term) return props.options

  return props.options.filter((item) => {
    const main = String(item?.[props.labelKey] ?? '').toLowerCase()
    const secondary = String(item?.[props.secondaryKey] ?? '').toLowerCase()
    return main.includes(term) || secondary.includes(term)
  })
})

const pick = (value: string | number | null) => {
  emit('update:modelValue', value)
  emit('change', value)
  close()
}

const close = () => {
  open.value = false
  query.value = ''
}

const toggle = async () => {
  if (props.disabled) return
  open.value = !open.value

  if (open.value) {
    await nextTick()
    updatePosition()
    searchRef.value?.focus()
  }
}

const updatePosition = () => {
  const button = buttonRef.value
  const panel = panelRef.value
  if (!button || !panel) return

  const rect = button.getBoundingClientRect()
  const viewportWidth = window.innerWidth
  const viewportHeight = window.innerHeight
  const margin = 12
  const gap = 8

  const width = Math.min(rect.width, viewportWidth - margin * 2)
  const left = Math.max(margin, Math.min(rect.left, viewportWidth - width - margin))

  const availableBelow = viewportHeight - rect.bottom - gap - margin
  const availableAbove = rect.top - gap - margin
  const openUp = availableBelow < 260 && availableAbove > availableBelow

  panelStyle.value = {
    position: 'fixed',
    left: `${left}px`,
    width: `${width}px`,
    maxHeight: `${Math.max(180, Math.min(360, openUp ? availableAbove : availableBelow))}px`,
    top: openUp ? `${Math.max(margin, rect.top - 320)}px` : `${rect.bottom + gap}px`,
  }
}

const handleEscape = (event: KeyboardEvent) => {
  if (event.key === 'Escape' && open.value) close()
}

const handleReflow = () => {
  if (open.value) updatePosition()
}

onMounted(() => {
  document.addEventListener('keydown', handleEscape)
  window.addEventListener('resize', handleReflow)
  window.addEventListener('scroll', handleReflow, true)
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', handleEscape)
  window.removeEventListener('resize', handleReflow)
  window.removeEventListener('scroll', handleReflow, true)
})

watch(
  () => props.options,
  async () => {
    if (!open.value) return
    await nextTick()
    updatePosition()
  },
)
</script>

<template>
  <div class="w-full space-y-1.5">
    <label
      v-if="label"
      :for="buttonId"
      class="text-sm font-semibold text-foreground"
    >
      {{ label }}
    </label>

    <button
      :id="buttonId"
      ref="buttonRef"
      type="button"
      :disabled="disabled"
      class="flex h-11 w-full items-center justify-between rounded-2xl border border-border bg-background px-4 text-left text-sm shadow-sm transition hover:bg-muted/60 focus:outline-none focus:ring-2 focus:ring-primary/20 disabled:cursor-not-allowed disabled:opacity-60"
      @click="toggle"
    >
      <span class="truncate">
        <template v-if="selected">
          <span class="font-medium text-foreground">
            {{ selected[labelKey] }}
          </span>
          <span
            v-if="selected[secondaryKey]"
            class="ml-1 text-muted-foreground"
          >
            ({{ selected[secondaryKey] }})
          </span>
        </template>

        <template v-else>
          <span class="text-muted-foreground">
            {{ placeholder }}
          </span>
        </template>
      </span>

      <ChevronsUpDown class="h-4 w-4 shrink-0 text-muted-foreground" />
    </button>

    <Teleport to="body">
      <div
        v-if="open"
        class="fixed inset-0 z-[9998]"
      >
        <div
          class="absolute inset-0"
          @mousedown.prevent="close"
        />

        <div
          ref="panelRef"
          :style="panelStyle"
          class="z-[9999] overflow-hidden rounded-3xl border border-border bg-popover shadow-2xl"
          @mousedown.stop
        >
          <div class="border-b border-border p-3">
            <div class="relative">
              <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <input
                ref="searchRef"
                v-model="query"
                type="text"
                :placeholder="searchPlaceholder"
                class="app-input pl-10"
              >
            </div>
          </div>

          <div class="max-h-[inherit] overflow-auto p-2">
            <button
              v-if="allowNull"
              type="button"
              class="flex w-full items-center justify-between rounded-2xl px-3 py-2.5 text-left text-sm transition hover:bg-muted"
              @click="pick(null)"
            >
              <span>{{ nullLabel }}</span>
              <X class="h-4 w-4 text-muted-foreground" />
            </button>

            <button
              v-for="option in filteredOptions"
              :key="String(option[valueKey])"
              type="button"
              class="flex w-full items-center justify-between gap-3 rounded-2xl px-3 py-2.5 text-left text-sm transition hover:bg-muted"
              :class="String(modelValue) === String(option[valueKey]) ? 'bg-accent text-accent-foreground font-semibold' : ''"
              @click="pick(option[valueKey])"
            >
              <span class="truncate">
                {{ option[labelKey] }}
                <span
                  v-if="option[secondaryKey]"
                  class="ml-1 text-muted-foreground"
                >
                  ({{ option[secondaryKey] }})
                </span>
              </span>

              <Check
                v-if="String(modelValue) === String(option[valueKey])"
                class="h-4 w-4 shrink-0"
              />
            </button>

            <div
              v-if="filteredOptions.length === 0"
              class="px-3 py-4 text-sm text-muted-foreground"
            >
              Sin resultados.
            </div>
          </div>
        </div>
      </div>
    </Teleport>

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
