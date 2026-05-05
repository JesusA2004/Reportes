<script setup lang="ts">
import { computed } from 'vue'
import { SlidersHorizontal, TriangleAlert } from 'lucide-vue-next'
import SectionHeader from './SectionHeader.vue'
import SearchableSelect from './SearchableSelect.vue'

interface ReportConfig {
    report_type: string
    scope: string
    branch_id: number | null
    employee_id: number | null
    compare_period_id: number | null
    extra_employee_expense_amount: number
    extra_employee_expense_notes: string
}

const props = defineProps<{
    period: any
    modelValue: ReportConfig
    periods: any[]
    branches: Array<{ id: number; name: string }>
    employees: Array<{ id: number; full_name: string; branch_name?: string }>
    canGenerate: boolean
}>()
const emit = defineEmits<{ (event: 'update:modelValue', value: ReportConfig): void; (event: 'generate'): void }>()

const update = (patch: Partial<ReportConfig>) => emit('update:modelValue', { ...props.modelValue, ...patch })

const isBranchScope   = computed(() => props.modelValue.scope === 'branch')
const isEmployeeScope = computed(() => props.modelValue.scope === 'employee')
const isComparative   = computed(() => props.modelValue.report_type !== 'simple')
const hasFilters      = computed(() => isBranchScope.value || isEmployeeScope.value || isComparative.value)

const comparablePeriods = computed(() =>
    props.periods.filter((p) => p.id !== props.period?.id && p.type === props.period?.type)
)

const branchItems = computed(() =>
    props.branches.map((b) => ({ id: b.id, label: b.name }))
)
const employeeItems = computed(() =>
    props.employees.map((e) => ({ id: e.id, label: e.full_name, sublabel: e.branch_name ?? undefined }))
)

const canSubmit = computed(() => {
    if (!props.canGenerate || !props.modelValue.report_type) return false
    if (isComparative.value && !props.modelValue.compare_period_id) return false
    if (isBranchScope.value && !props.modelValue.branch_id) return false
    if (isEmployeeScope.value && !props.modelValue.employee_id) return false
    return true
})

const REPORT_TYPES = [
    { k: 'simple',               l: 'Radiografía simple',                d: 'Solo datos del periodo elegido. Sin comparativos ni flechas.' },
    { k: 'month_vs_month',       l: 'Comparativo mes vs mes',            d: 'Requiere seleccionar un periodo comparable.' },
    { k: 'bimester_vs_bimester', l: 'Comparativo bimestre vs bimestre',  d: 'Solo periodos bimestrales completos.' },
    { k: 'quarter_vs_quarter',   l: 'Comparativo trimestre vs trimestre', d: 'Solo periodos trimestrales completos.' },
]

const SCOPES = [
    { k: 'general',  l: 'General' },
    { k: 'branch',   l: 'Por sucursal' },
    { k: 'employee', l: 'Por empleado / gestor' },
]
</script>

<template>
    <section class="rounded-[2rem] border border-white/70 bg-white p-6 shadow-xl shadow-slate-200/70">
        <SectionHeader eyebrow="Etapa 4" title="Configurar reporte" description="Elige si será Radiografía simple o comparativo. La Radiografía simple solo usa el periodo seleccionado: sin meses inventados, sin flechas, sin comparaciones." />

        <div class="mt-6 grid gap-5 xl:grid-cols-[1fr_0.85fr]">
            <!-- Columna izquierda: tipo + alcance + filtros condicionales -->
            <div class="space-y-5">

                <!-- Tipo de reporte -->
                <div class="rounded-2xl border border-slate-200 p-5">
                    <p class="mb-3 text-sm font-black text-slate-950">Tipo de reporte</p>
                    <div class="grid gap-3 md:grid-cols-2">
                        <button
                            v-for="option in REPORT_TYPES"
                            :key="option.k"
                            type="button"
                            class="rounded-2xl border p-4 text-left transition hover:-translate-y-0.5 hover:border-indigo-200 hover:bg-indigo-50/40"
                            :class="modelValue.report_type === option.k ? 'border-indigo-300 bg-indigo-50 ring-2 ring-indigo-100' : 'border-slate-200'"
                            @click="update({ report_type: option.k, compare_period_id: option.k === 'simple' ? null : modelValue.compare_period_id })"
                        >
                            <p class="font-black text-slate-950">{{ option.l }}</p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">{{ option.d }}</p>
                        </button>
                    </div>
                </div>

                <!-- Alcance -->
                <div class="rounded-2xl border border-slate-200 p-5">
                    <p class="mb-3 text-sm font-black text-slate-950">Alcance</p>
                    <div class="grid gap-3 md:grid-cols-3">
                        <button
                            v-for="option in SCOPES"
                            :key="option.k"
                            type="button"
                            class="rounded-2xl border px-4 py-3 text-sm font-black transition hover:border-indigo-200 hover:bg-indigo-50/40"
                            :class="modelValue.scope === option.k ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-slate-200 text-slate-700'"
                            @click="update({ scope: option.k, branch_id: null, employee_id: null })"
                        >
                            {{ option.l }}
                        </button>
                    </div>
                </div>

                <!-- Filtros condicionales -->
                <div v-if="hasFilters" class="rounded-2xl border border-slate-200 p-5 space-y-4">
                    <p class="text-sm font-black text-slate-950">Filtros y comparación</p>

                    <!-- Sucursal (solo si alcance = branch) -->
                    <div v-if="isBranchScope">
                        <label class="block">
                            <span class="text-xs font-bold text-slate-600">Sucursal</span>
                            <div class="mt-1">
                                <SearchableSelect
                                    :items="branchItems"
                                    :model-value="modelValue.branch_id"
                                    placeholder="Buscar sucursal..."
                                    @update:model-value="update({ branch_id: $event })"
                                />
                            </div>
                        </label>
                        <p v-if="!modelValue.branch_id" class="mt-1.5 text-xs text-amber-600">Selecciona una sucursal para continuar.</p>
                    </div>

                    <!-- Empleado / gestor (solo si alcance = employee) -->
                    <div v-if="isEmployeeScope">
                        <label class="block">
                            <span class="text-xs font-bold text-slate-600">Empleado / gestor</span>
                            <div class="mt-1">
                                <SearchableSelect
                                    :items="employeeItems"
                                    :model-value="modelValue.employee_id"
                                    placeholder="Buscar empleado o gestor..."
                                    @update:model-value="update({ employee_id: $event })"
                                />
                            </div>
                        </label>
                        <p v-if="!modelValue.employee_id" class="mt-1.5 text-xs text-amber-600">Selecciona un empleado o gestor para continuar.</p>
                    </div>

                    <!-- Periodo comparable (solo si es comparativo) -->
                    <div v-if="isComparative">
                        <label class="block">
                            <span class="text-xs font-bold text-slate-600">Periodo a comparar</span>
                            <select
                                :value="modelValue.compare_period_id ?? ''"
                                class="mt-1 h-11 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:ring-4 focus:ring-indigo-100"
                                @change="update({ compare_period_id: Number(($event.target as HTMLSelectElement).value) || null })"
                            >
                                <option value="">Selecciona periodo comparable</option>
                                <option v-for="item in comparablePeriods" :key="item.id" :value="item.id">{{ item.label }}</option>
                            </select>
                        </label>
                        <p v-if="!modelValue.compare_period_id" class="mt-1.5 text-xs text-amber-600">Selecciona el periodo a comparar.</p>
                    </div>
                </div>

            </div>

            <!-- Columna derecha: gasto por gestor (condicional) + botón generar -->
            <div class="space-y-5">

                <!-- Gasto general por gestor: solo visible en alcance empleado/gestor -->
                <div v-if="isEmployeeScope" class="rounded-2xl border border-indigo-100 bg-indigo-50/60 p-5">
                    <div class="flex items-center gap-3">
                        <SlidersHorizontal class="size-5 text-indigo-700" />
                        <p class="font-black text-slate-950">Gasto general por gestor</p>
                    </div>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Monto aproximado de gastos operativos mensuales asignados al gestor seleccionado. Se guardará en el reporte para auditoría.</p>
                    <div class="mt-4 space-y-3">
                        <label class="block">
                            <span class="text-xs font-bold text-slate-600">Gasto mensual aproximado (MXN)</span>
                            <div class="relative mt-1">
                                <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-sm text-slate-400">$</span>
                                <input
                                    type="number"
                                    min="0"
                                    step="100"
                                    :value="modelValue.extra_employee_expense_amount"
                                    class="h-11 w-full rounded-2xl border border-slate-200 bg-white pl-8 pr-4 text-sm outline-none focus:ring-4 focus:ring-indigo-100"
                                    placeholder="0"
                                    @input="update({ extra_employee_expense_amount: Number(($event.target as HTMLInputElement).value) })"
                                />
                            </div>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-slate-600">Notas del gasto (opcional)</span>
                            <input
                                type="text"
                                :value="modelValue.extra_employee_expense_notes"
                                class="mt-1 h-11 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none focus:ring-4 focus:ring-indigo-100"
                                placeholder="Ej. incluye viáticos y comunicación"
                                @input="update({ extra_employee_expense_notes: ($event.target as HTMLInputElement).value })"
                            />
                        </label>
                    </div>
                </div>

                <!-- Botón generar -->
                <div class="space-y-3">
                    <button
                        type="button"
                        class="h-12 w-full rounded-2xl bg-slate-950 px-5 text-sm font-black text-white shadow-xl shadow-slate-200 transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="!canSubmit"
                        @click="emit('generate')"
                    >
                        Generar reporte
                    </button>

                    <!-- Razones de bloqueo -->
                    <div v-if="period?.blocking_reasons?.length" class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                        <div class="flex items-center gap-2">
                            <TriangleAlert class="size-4 shrink-0 text-amber-600" />
                            <p class="text-sm font-black text-amber-800">Aún no puedes generar</p>
                        </div>
                        <ul class="mt-2 list-disc pl-5 text-sm text-amber-700">
                            <li v-for="reason in period.blocking_reasons" :key="reason">{{ reason }}</li>
                        </ul>
                    </div>

                    <!-- Validaciones locales de configuración -->
                    <div v-else-if="!canSubmit && canGenerate" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                        <p v-if="isBranchScope && !modelValue.branch_id">Selecciona una sucursal para generar el reporte.</p>
                        <p v-else-if="isEmployeeScope && !modelValue.employee_id">Selecciona un empleado o gestor para generar el reporte.</p>
                        <p v-else-if="isComparative && !modelValue.compare_period_id">Selecciona el periodo a comparar.</p>
                    </div>
                </div>

            </div>
        </div>
    </section>
</template>
