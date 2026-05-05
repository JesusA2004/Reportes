<script setup lang="ts">
import { computed } from 'vue'
import { SlidersHorizontal } from 'lucide-vue-next'
import SectionHeader from './SectionHeader.vue'

const props = defineProps<{ period: any; modelValue: any; periods: any[]; canGenerate: boolean }>()
const emit = defineEmits<{ (event: 'update:modelValue', value: any): void; (event: 'generate'): void }>()

const update = (patch: Record<string, any>) => emit('update:modelValue', { ...props.modelValue, ...patch })
const comparablePeriods = computed(() => props.periods.filter((period) => period.id !== props.period?.id && period.type === props.period?.type))
const isEmployeeScope = computed(() => props.modelValue.scope === 'employee')
const isComparative = computed(() => props.modelValue.report_type !== 'simple')
</script>

<template>
    <section class="rounded-[2rem] border border-white/70 bg-white p-6 shadow-xl shadow-slate-200/70">
        <SectionHeader eyebrow="Etapa 4" title="Configurar reporte" description="Elige explícitamente si será Radiografía simple o comparativo. La Radiografía simple solo usa el periodo seleccionado; no inventa meses ni flechas de comparación." />
        <div class="mt-6 grid gap-5 xl:grid-cols-[1fr_0.85fr]">
            <div class="space-y-5">
                <div class="rounded-2xl border border-slate-200 p-5">
                    <p class="mb-3 text-sm font-black text-slate-950">Tipo de reporte</p>
                    <div class="grid gap-3 md:grid-cols-2">
                        <button v-for="option in [
                            { k:'simple', l:'Radiografía simple', d:'Solo datos del periodo elegido.' },
                            { k:'month_vs_month', l:'Comparativo mes vs mes', d:'Requiere periodo comparable.' },
                            { k:'bimester_vs_bimester', l:'Comparativo bimestre vs bimestre', d:'Solo bimestres completos.' },
                            { k:'quarter_vs_quarter', l:'Comparativo trimestre vs trimestre', d:'Solo trimestres completos.' },
                        ]" :key="option.k" type="button" class="rounded-2xl border p-4 text-left transition hover:-translate-y-0.5 hover:border-indigo-200 hover:bg-indigo-50/40" :class="modelValue.report_type === option.k ? 'border-indigo-300 bg-indigo-50 ring-2 ring-indigo-100' : 'border-slate-200'" @click="update({ report_type: option.k, compare_period_id: option.k === 'simple' ? null : modelValue.compare_period_id })">
                            <p class="font-black text-slate-950">{{ option.l }}</p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">{{ option.d }}</p>
                        </button>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 p-5">
                    <p class="mb-3 text-sm font-black text-slate-950">Alcance</p>
                    <div class="grid gap-3 md:grid-cols-3">
                        <button v-for="option in [{k:'general',l:'General'},{k:'branch',l:'Por sucursal'},{k:'employee',l:'Por empleado / gestor'}]" :key="option.k" type="button" class="rounded-2xl border px-4 py-3 text-sm font-black transition hover:border-indigo-200 hover:bg-indigo-50/40" :class="modelValue.scope === option.k ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-slate-200 text-slate-700'" @click="update({ scope: option.k })">{{ option.l }}</button>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 p-5">
                    <p class="mb-3 text-sm font-black text-slate-950">Filtros y comparación</p>
                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="block"><span class="text-xs font-bold text-slate-600">Sucursal</span><input :value="modelValue.branch" class="mt-1 h-11 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:ring-4 focus:ring-indigo-100" placeholder="Todas" @input="update({ branch: ($event.target as HTMLInputElement).value })" /></label>
                        <label class="block"><span class="text-xs font-bold text-slate-600">Empleado / gestor</span><input :value="modelValue.employee" class="mt-1 h-11 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:ring-4 focus:ring-indigo-100" placeholder="Todos" @input="update({ employee: ($event.target as HTMLInputElement).value })" /></label>
                        <label v-if="isComparative" class="block md:col-span-2"><span class="text-xs font-bold text-slate-600">Periodo a comparar</span><select :value="modelValue.compare_period_id ?? ''" class="mt-1 h-11 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:ring-4 focus:ring-indigo-100" @change="update({ compare_period_id: Number(($event.target as HTMLSelectElement).value) || null })"><option value="">Selecciona periodo comparable</option><option v-for="item in comparablePeriods" :key="item.id" :value="item.id">{{ item.label }}</option></select></label>
                    </div>
                </div>
            </div>

            <div class="space-y-5">
                <div class="rounded-2xl border border-indigo-100 bg-indigo-50 p-5">
                    <div class="flex items-center gap-3"><SlidersHorizontal class="size-5 text-indigo-700" /><p class="font-black text-slate-950">Gasto aproximado por empleado</p></div>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Solo se aplica cuando el alcance es por empleado / gestor.</p>
                    <div class="mt-4 space-y-3" :class="!isEmployeeScope ? 'opacity-50' : ''">
                        <label v-for="field in [{k:'gasolina',l:'Gasolina'},{k:'recargas',l:'Recargas celulares'},{k:'nomina_indirecta',l:'Nómina indirecta'},{k:'otros',l:'Otros gastos operativos'}]" :key="field.k" class="block"><span class="text-xs font-bold text-slate-600">{{ field.l }}</span><input type="number" min="0" step="0.01" :disabled="!isEmployeeScope" :value="modelValue.employee_expenses[field.k]" class="mt-1 h-11 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none focus:ring-4 focus:ring-indigo-100 disabled:cursor-not-allowed" @input="update({ employee_expenses: { ...modelValue.employee_expenses, [field.k]: Number(($event.target as HTMLInputElement).value) } })" /></label>
                    </div>
                </div>
                <button type="button" class="h-12 w-full rounded-2xl bg-slate-950 px-5 text-sm font-black text-white shadow-xl shadow-slate-200 transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="!canGenerate || !modelValue.report_type || (isComparative && !modelValue.compare_period_id)" @click="emit('generate')">Generar reporte</button>
                <div v-if="period?.blocking_reasons?.length" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    <p class="font-black">Aún no puedes generar</p>
                    <ul class="mt-2 list-disc pl-5"><li v-for="reason in period.blocking_reasons" :key="reason">{{ reason }}</li></ul>
                </div>
            </div>
        </div>
    </section>
</template>
