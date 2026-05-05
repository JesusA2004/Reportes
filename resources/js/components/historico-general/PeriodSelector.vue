<script setup lang="ts">
import { computed, ref } from 'vue'
import { CalendarDays, CheckCircle2, Search } from 'lucide-vue-next'
import StatusBadge from './StatusBadge.vue'

const props = defineProps<{ periods: any[]; modelValue: number | null }>()
const emit = defineEmits<{ (event: 'update:modelValue', value: number): void }>()
const query = ref('')

const selected = computed(() => props.periods.find((period) => period.id === props.modelValue) ?? null)
const filteredPeriods = computed(() => {
    const term = query.value.trim().toLowerCase()
    if (!term) return props.periods.slice(0, 18)
    return props.periods.filter((period) => [period.label, period.code, period.type].join(' ').toLowerCase().includes(term)).slice(0, 24)
})

const typeLabel = (type?: string) => ({ weekly: 'Semana base', monthly: 'Mes automático', bimonthly: 'Bimestre automático', quarterly: 'Trimestre automático', annual: 'Anual automático' } as Record<string, string>)[type ?? ''] ?? 'Periodo'
const status = (period: any) => period.is_derived ? 'automatic' : period.failed_count > 0 ? 'error' : period.missing_sources_count > 0 ? 'blocked' : 'completed'
const statusLabel = (period: any) => period.is_derived ? 'Automático' : period.failed_count > 0 ? 'Con error' : period.missing_sources_count > 0 ? 'Incompleto' : 'Completo'
</script>

<template>
    <section class="rounded-[2rem] border border-white/70 bg-white p-5 shadow-xl shadow-slate-200/70 sm:p-6">
        <div class="grid gap-5 lg:grid-cols-[0.9fr_1.1fr]">
            <div>
                <div class="flex items-center gap-3">
                    <div class="flex size-11 items-center justify-center rounded-2xl bg-indigo-600 text-white shadow-lg shadow-indigo-200">
                        <CalendarDays class="size-5" />
                    </div>
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.22em] text-indigo-600">Periodo operativo</p>
                        <h2 class="text-xl font-black text-slate-950">Selecciona el periodo</h2>
                    </div>
                </div>
                <p class="mt-4 text-sm leading-6 text-slate-600">
                    Busca semanas base para carga directa o periodos automáticos para generar reportes integrados desde sus fuentes reales.
                </p>
                <div v-if="selected" class="mt-5 rounded-2xl border border-indigo-100 bg-indigo-50/60 p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-black text-slate-950">{{ selected.label }}</p>
                            <p class="mt-1 text-xs text-slate-600">{{ selected.code }} • {{ typeLabel(selected.type) }}</p>
                            <p class="mt-2 text-xs text-slate-500">{{ selected.start_date }} → {{ selected.end_date }}</p>
                        </div>
                        <StatusBadge :status="status(selected)" :label="statusLabel(selected)" />
                    </div>
                </div>
            </div>

            <div>
                <label class="relative block">
                    <Search class="pointer-events-none absolute left-4 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="query"
                        type="search"
                        class="h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 pl-11 pr-4 text-sm font-medium text-slate-800 outline-none transition focus:border-indigo-300 focus:bg-white focus:ring-4 focus:ring-indigo-100"
                        placeholder="Buscar por nombre, código o tipo de periodo..."
                    />
                </label>

                <div class="mt-3 max-h-[25rem] space-y-2 overflow-y-auto pr-1">
                    <button
                        v-for="period in filteredPeriods"
                        :key="period.id"
                        type="button"
                        class="w-full rounded-2xl border p-4 text-left transition duration-200 hover:-translate-y-0.5 hover:border-indigo-200 hover:bg-indigo-50/40 hover:shadow-md focus:outline-none focus:ring-4 focus:ring-indigo-100"
                        :class="modelValue === period.id ? 'border-indigo-300 bg-indigo-50 ring-2 ring-indigo-100' : 'border-slate-200 bg-white'"
                        @click="emit('update:modelValue', period.id)"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-black text-slate-950">{{ period.label }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ typeLabel(period.type) }} • {{ period.start_date }} → {{ period.end_date }}</p>
                                <p class="mt-2 text-xs text-slate-500">
                                    {{ period.uploaded_sources_count }}/{{ period.required_sources_count }} fuentes • {{ period.pending_critical_incidents_count ?? 0 }} incidencia(s) crítica(s)
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <CheckCircle2 v-if="modelValue === period.id" class="size-4 text-indigo-600" />
                                <StatusBadge :status="status(period)" :label="statusLabel(period)" />
                            </div>
                        </div>
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>
