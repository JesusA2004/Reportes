<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Check, ChevronDown, Search, X } from 'lucide-vue-next'

interface SelectItem {
    id: number
    label: string
    sublabel?: string
}

const props = withDefaults(defineProps<{
    items: SelectItem[]
    modelValue: number | null
    placeholder?: string
    disabled?: boolean
}>(), {
    placeholder: 'Selecciona una opción',
    disabled: false,
})

const emit = defineEmits<{ (e: 'update:modelValue', value: number | null): void }>()

const open = ref(false)
const search = ref('')
const containerRef = ref<HTMLDivElement | null>(null)

const selected = computed(() => props.items.find((item) => item.id === props.modelValue) ?? null)

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase()
    if (!q) return props.items
    return props.items.filter(
        (item) => item.label.toLowerCase().includes(q) || (item.sublabel?.toLowerCase().includes(q) ?? false)
    )
})

function toggle() {
    if (props.disabled) return
    open.value = !open.value
    if (open.value) search.value = ''
}

function pick(id: number) {
    emit('update:modelValue', id)
    open.value = false
    search.value = ''
}

function clear(event: Event) {
    event.stopPropagation()
    emit('update:modelValue', null)
}

function onOutsideClick(event: MouseEvent) {
    if (containerRef.value && !containerRef.value.contains(event.target as Node)) {
        open.value = false
    }
}

watch(open, (val) => {
    if (val) document.addEventListener('click', onOutsideClick)
    else document.removeEventListener('click', onOutsideClick)
})
</script>

<template>
    <div ref="containerRef" class="relative">
        <button
            type="button"
            class="flex h-11 w-full items-center justify-between gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm transition focus:outline-none focus:ring-4 focus:ring-indigo-100"
            :class="disabled ? 'cursor-not-allowed opacity-50' : 'hover:border-indigo-200 hover:bg-indigo-50/30'"
            :disabled="disabled"
            @click="toggle"
        >
            <span v-if="selected" class="truncate font-semibold text-slate-900">{{ selected.label }}</span>
            <span v-else class="truncate text-slate-400">{{ placeholder }}</span>
            <span class="flex shrink-0 items-center gap-1">
                <button v-if="selected && !disabled" type="button" class="rounded-full p-0.5 text-slate-400 transition hover:bg-rose-100 hover:text-rose-500" @click="clear"><X class="size-3.5" /></button>
                <ChevronDown class="size-4 shrink-0 text-slate-400 transition" :class="open ? 'rotate-180' : ''" />
            </span>
        </button>

        <transition name="dropdown">
            <div v-if="open" class="absolute z-50 mt-2 w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-200/80">
                <div class="border-b border-slate-100 p-2">
                    <div class="flex items-center gap-2 rounded-xl bg-slate-50 px-3 py-2">
                        <Search class="size-4 shrink-0 text-slate-400" />
                        <input
                            v-model="search"
                            type="text"
                            class="flex-1 bg-transparent text-sm outline-none placeholder:text-slate-400"
                            placeholder="Buscar..."
                            autofocus
                        />
                    </div>
                </div>
                <ul class="max-h-60 overflow-y-auto py-1">
                    <li v-if="filtered.length === 0" class="px-4 py-3 text-sm text-slate-400">Sin resultados</li>
                    <li
                        v-for="item in filtered"
                        :key="item.id"
                        class="flex cursor-pointer items-center gap-3 px-4 py-2.5 transition hover:bg-indigo-50"
                        :class="modelValue === item.id ? 'bg-indigo-50/60' : ''"
                        @click="pick(item.id)"
                    >
                        <Check class="size-4 shrink-0 text-indigo-600 transition" :class="modelValue === item.id ? 'opacity-100' : 'opacity-0'" />
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-slate-900">{{ item.label }}</p>
                            <p v-if="item.sublabel" class="truncate text-xs text-slate-500">{{ item.sublabel }}</p>
                        </div>
                    </li>
                </ul>
            </div>
        </transition>
    </div>
</template>

<style scoped>
.dropdown-enter-active, .dropdown-leave-active { transition: all 150ms ease; }
.dropdown-enter-from, .dropdown-leave-to { opacity: 0; transform: translateY(-6px); }
</style>
