<script setup lang="ts">
import { computed } from 'vue'
import { SlidersHorizontal } from 'lucide-vue-next'
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
    included_branch_ids: number[]
}

const props = defineProps<{
    period: any
    modelValue: ReportConfig
    periods: any[]
    branches: Array<{ id: number; name: string }>
    employees: Array<{ id: number; full_name: string; branch_name?: string }>
    canGenerate: boolean
}>()
const emit = defineEmits<{ (event: 'update:modelValue', value: ReportConfig): void; (event: 'next'): void }>()

const update = (patch: Partial<ReportConfig>) => emit('update:modelValue', { ...props.modelValue, ...patch })

const isBranchScope   = computed(() => props.modelValue.scope === 'branch')
const isEmployeeScope = computed(() => props.modelValue.scope === 'employee')
const isComparative   = computed(() => props.modelValue.report_type !== 'simple')
const hasFilters      = computed(() => isBranchScope.value || isEmployeeScope.value || isComparative.value)

const comparablePeriods = computed(() =>
    props.periods.filter((p) => p.id !== props.period?.id && p.type === props.period?.type)
)

const OPERATIVE_BRANCH_NAMES = new Set([
    'ATLACOMULCO', 'ATLIXCO', 'CORDOBA', 'CUERNAVACA', 'HUAMANTLA',
    'IXTLAHUACA', 'MIACATLAN', 'ORIZABA', 'SAN JUAN DEL RÍO',
    'SAN LUIS POTOSI', 'TENANGO DEL VALLE', 'TLAXCALA', 'TULA',
])

const FORBIDDEN_BRANCH_LIKE_ROUTES = new Set([
    '20 DE NOVIEMBRE', 'ACAJ', 'AGUASCALIENTES', 'ALMOLOYA DE JUAREZ',
    'APIX-SUR', 'APIZ-CEN', 'CHIHUAHUA', 'DURANGO', 'CORPORATIVO',
    'FALSO', 'NORTE',
])

// The 12 operative branches with their DB IDs
const operativeBranches = computed(() =>
    props.branches.filter((b) => {
        const name = b.name.trim().toUpperCase()
        return !FORBIDDEN_BRANCH_LIKE_ROUTES.has(name) && OPERATIVE_BRANCH_NAMES.has(name)
    })
)

// For the branch selector dropdown (scope=branch), only show included ones
const operativeBranchItems = computed(() =>
    operativeBranches.value
        .filter((b) => props.modelValue.included_branch_ids.includes(b.id))
        .map((b) => ({ id: b.id, label: b.name }))
)

const employeeItems = computed(() =>
    props.employees.map((e) => ({ id: e.id, label: e.full_name, sublabel: e.branch_name ?? undefined }))
)

function toggleBranch(branchId: number) {
    const current = props.modelValue.included_branch_ids
    const next = current.includes(branchId)
        ? current.filter((id) => id !== branchId)
        : [...current, branchId]
    update({ included_branch_ids: next })
}

function selectAllBranches() {
    update({ included_branch_ids: operativeBranches.value.map((b) => b.id) })
}

function deselectAllBranches() {
    update({ included_branch_ids: [] })
}

const includedCount  = computed(() => props.modelValue.included_branch_ids.length)
const excludedBranches = computed(() =>
    operativeBranches.value.filter((b) => !props.modelValue.included_branch_ids.includes(b.id))
)

const canSubmit = computed(() => {
    if (!props.canGenerate || !props.modelValue.report_type) return false
    if (isComparative.value && !props.modelValue.compare_period_id) return false
    if (isBranchScope.value && !props.modelValue.branch_id) return false
    if (isEmployeeScope.value && !props.modelValue.employee_id) return false
    if (!isBranchScope.value && !isEmployeeScope.value && props.modelValue.included_branch_ids.length === 0) return false
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
        <SectionHeader eyebrow="Etapa 4" title="Configurar reporte" description="Define el tipo, alcance y filtros del reporte. Una vez guardada la configuración podrás proceder a generar el reporte." />

        <div class="mt-6 grid gap-5 xl:grid-cols-[1fr_0.85fr]">
            <!-- Columna izquierda -->
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

                    <div v-if="isBranchScope">
                        <label class="block">
                            <span class="text-xs font-bold text-slate-600">Sucursal</span>
                            <div class="mt-1">
                                <SearchableSelect
                                    :items="operativeBranchItems"
                                    :model-value="modelValue.branch_id"
                                    placeholder="Buscar sucursal..."
                                    @update:model-value="update({ branch_id: $event })"
                                />
                            </div>
                        </label>
                        <p v-if="!modelValue.branch_id" class="mt-1.5 text-xs text-amber-600">Selecciona una sucursal para continuar.</p>
                    </div>

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

            <!-- Columna derecha -->
            <div class="space-y-5">

                <!-- Sucursales editables — solo cuando alcance = general -->
                <div v-if="!isBranchScope && !isEmployeeScope" class="rounded-2xl border border-emerald-100 bg-emerald-50/60 p-5">
                    <div class="mb-3 flex items-center justify-between">
                        <p class="text-sm font-black text-slate-950">Sucursales que se tomarán en cuenta</p>
                        <div class="flex items-center gap-2 text-xs">
                            <button type="button" class="font-bold text-indigo-600 hover:underline" @click="selectAllBranches">Todas</button>
                            <span class="text-slate-300">|</span>
                            <button type="button" class="font-bold text-rose-600 hover:underline" @click="deselectAllBranches">Ninguna</button>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <label
                            v-for="branch in operativeBranches"
                            :key="branch.id"
                            class="flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2 transition hover:bg-emerald-100/60"
                            :class="modelValue.included_branch_ids.includes(branch.id) ? 'bg-white/80' : 'opacity-50'"
                        >
                            <input
                                type="checkbox"
                                class="size-4 rounded accent-emerald-600"
                                :checked="modelValue.included_branch_ids.includes(branch.id)"
                                @change="toggleBranch(branch.id)"
                            />
                            <span class="text-sm font-bold text-slate-800">{{ branch.name }}</span>
                        </label>
                    </div>

                    <div class="mt-3 border-t border-emerald-200 pt-3 text-xs text-slate-500 space-y-1">
                        <p class="text-emerald-700 font-bold">{{ includedCount }} sucursal(es) incluida(s)</p>
                        <p v-if="excludedBranches.length > 0" class="text-rose-600">
                            Excluidas para este reporte:
                            <span v-for="(b, i) in excludedBranches" :key="b.id">{{ b.name }}<span v-if="i < excludedBranches.length - 1">, </span></span>
                        </p>
                        <p>Siempre excluida: <span class="font-bold text-rose-700">CORPORATIVO</span> (no operativo) · SAN JUAN DEL RÍO se incluye si tiene movimiento en el periodo.</p>
                    </div>

                    <p v-if="includedCount === 0" class="mt-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-700">
                        Selecciona al menos una sucursal para continuar.
                    </p>
                </div>

                <!-- Sucursales en modo lectura cuando alcance = sucursal -->
                <div v-if="isBranchScope" class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                    <p class="mb-2 text-xs font-bold text-slate-500">El reporte por sucursal solo incluye la sucursal seleccionada.</p>
                </div>

                <!-- Gasto general por gestor -->
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

                <!-- Botón guardar configuración -->
                <div class="space-y-3">
                    <button
                        type="button"
                        class="h-12 w-full rounded-2xl bg-slate-950 px-5 text-sm font-black text-white shadow-xl shadow-slate-200 transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="!canSubmit"
                        @click="emit('next')"
                    >
                        Guardar configuración y continuar
                    </button>

                    <!-- Razón de bloqueo cuando la etapa 2 no está completa -->
                    <div v-if="!canGenerate" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                        <p class="mb-1 font-semibold">No puedes continuar todavía:</p>
                        <ul v-if="period?.blocking_reasons?.length" class="list-inside list-disc space-y-1">
                            <li v-for="reason in period.blocking_reasons" :key="reason">{{ reason }}</li>
                        </ul>
                        <p v-else>Completa las etapas previas (Cargar registros) antes de configurar el reporte.</p>
                    </div>

                    <!-- Razón de bloqueo cuando canGenerate=true pero falta configuración -->
                    <div v-else-if="!canSubmit" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                        <p v-if="isBranchScope && !modelValue.branch_id">Selecciona una sucursal para continuar.</p>
                        <p v-else-if="isEmployeeScope && !modelValue.employee_id">Selecciona un empleado o gestor para continuar.</p>
                        <p v-else-if="isComparative && !modelValue.compare_period_id">Selecciona el periodo a comparar.</p>
                        <p v-else-if="!isBranchScope && !isEmployeeScope && includedCount === 0">Selecciona al menos una sucursal para continuar.</p>
                    </div>
                </div>

            </div>
        </div>
    </section>
</template>
