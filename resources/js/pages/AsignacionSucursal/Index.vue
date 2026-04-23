<script setup lang="ts">
import { computed, reactive } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import {
    AlertTriangle,
    ArrowRightLeft,
    Building2,
    CheckCircle2,
    ClipboardList,
    FileText,
    GitCompareArrows,
    Info,
    Link2,
    Search,
    Sparkles,
    UserMinus,
    UserPlus,
    UserRound,
    Wand2,
} from 'lucide-vue-next'

import AppLayout from '@/layouts/AppLayout.vue'

type Assignment = {
    id: number
    employee_id?: number | null
    branch_id?: number | null
    employee_name: string
    normalized_name?: string | null
    branch_name?: string | null
    source_name?: string | null
    source_reference?: string | null
    match_type?: 'exact' | 'normalized' | 'manual' | 'unmatched' | string | null
    match_label?: string | null
    match_explanation?: string | null
    confidence?: number | null
    was_manual_reviewed?: boolean
    ui_status: 'matched' | 'pending' | 'manual' | 'unmatched'
    period_label?: string | null
    updated_at?: string | null
    notes?: string | null
    needs_manual_attention?: boolean
    context?: string
}

type Branch = {
    id: number
    name: string
}

type PeriodOption = {
    id: number
    label: string
    type?: string | null
    start_date?: string | null
    end_date?: string | null
}

const props = withDefaults(
    defineProps<{
        assignments: Assignment[]
        branches: Branch[]
        periods: PeriodOption[]
        selected_period_id?: number | null
        selected_period_label?: string | null
        summary?: {
            total: number
            matched: number
            manual: number
            pending: number
            unmatched: number
            with_branch: number
            without_branch: number
            high_confidence: number
            needs_review: number
            hires: number
            leavers: number
        }
        incidences?: Assignment[]
        hires?: Assignment[]
        leavers?: Assignment[]
    }>(),
    {
        assignments: () => [],
        branches: () => [],
        periods: () => [],
        selected_period_id: null,
        selected_period_label: null,
        summary: () => ({
            total: 0,
            matched: 0,
            manual: 0,
            pending: 0,
            unmatched: 0,
            with_branch: 0,
            without_branch: 0,
            high_confidence: 0,
            needs_review: 0,
            hires: 0,
            leavers: 0,
        }),
        incidences: () => [],
        hires: () => [],
        leavers: () => [],
    },
)

defineOptions({
    layout: AppLayout,
})

const filters = reactive({
    query: '',
    status: 'all',
})

const selectedPeriodId = computed({
    get: () => (props.selected_period_id ? String(props.selected_period_id) : ''),
    set: (value: string) => {
        router.get(
            '/asignaciones-empleado-sucursal',
            {
                period_id: value || undefined,
            },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        )
    },
})

const autoMatchForm = useForm({
    period_id: props.selected_period_id ?? null,
})

const manualForms = reactive<Record<number, { branch_id: string; notes: string }>>({})

function getManualForm(item: Assignment) {
    if (!manualForms[item.id]) {
        manualForms[item.id] = {
            branch_id: item.branch_id ? String(item.branch_id) : '',
            notes: item.notes ?? '',
        }
    }

    return manualForms[item.id]
}

function runAutoMatch() {
    autoMatchForm.period_id = props.selected_period_id ?? null

    autoMatchForm.post('/asignaciones-empleado-sucursal/match-automatico', {
        preserveScroll: true,
    })
}

function saveManualAssignment(item: Assignment) {
    const form = getManualForm(item)

    router.post(
        `/asignaciones-empleado-sucursal/${item.id}/manual-match`,
        {
            branch_id: form.branch_id,
            notes: form.notes,
        },
        {
            preserveScroll: true,
        },
    )
}

function statusClass(status: Assignment['ui_status']) {
    switch (status) {
        case 'matched':
            return 'border border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300'
        case 'manual':
            return 'border border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300'
        case 'unmatched':
            return 'border border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300'
        default:
            return 'border border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300'
    }
}

function statusLabel(status: Assignment['ui_status']) {
    switch (status) {
        case 'matched':
            return 'Match correcto'
        case 'manual':
            return 'Manual'
        case 'unmatched':
            return 'Sin match'
        default:
            return 'Pendiente'
    }
}

function formatConfidence(value?: number | null) {
    if (value === null || value === undefined) return '—'
    return `${Math.round(value * 100)}%`
}

const filteredAssignments = computed(() => {
    const query = filters.query.trim().toLowerCase()

    return props.assignments.filter((item) => {
        const matchesQuery =
            !query ||
            item.employee_name.toLowerCase().includes(query) ||
            (item.normalized_name ?? '').toLowerCase().includes(query) ||
            (item.branch_name ?? '').toLowerCase().includes(query) ||
            (item.notes ?? '').toLowerCase().includes(query)

        const matchesStatus =
            filters.status === 'all' ||
            item.ui_status === filters.status

        return matchesQuery && matchesStatus
    })
})

const matchedAssignments = computed(() =>
    filteredAssignments.value.filter((item) => item.ui_status === 'matched'),
)

const manualAssignments = computed(() =>
    filteredAssignments.value.filter((item) => item.ui_status === 'manual'),
)

const pendingAssignments = computed(() =>
    filteredAssignments.value.filter((item) =>
        ['pending', 'unmatched'].includes(item.ui_status) || item.needs_manual_attention,
    ),
)

const hasNoAssignments = computed(() => props.assignments.length === 0)
</script>

<template>
    <Head title="Asignación sucursal" />

    <div class="app-page px-3 py-3 sm:px-4 sm:py-4 md:px-5 lg:px-6 xl:px-7 2xl:px-8">
        <div class="space-y-6">
            <section class="app-card overflow-hidden">
                <div class="relative">
                    <div class="absolute inset-0 bg-gradient-to-br from-primary/10 via-transparent to-primary/5" />

                    <div class="relative p-4 sm:p-5 lg:p-6">
                        <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                            <div class="space-y-3">
                                <div
                                    class="inline-flex w-fit items-center gap-2 rounded-full border border-primary/15 bg-primary/5 px-3 py-1.5 text-xs font-semibold text-primary"
                                >
                                    <GitCompareArrows class="size-3.5" />
                                    Cruce NOI ↔ operación
                                </div>

                                <div>
                                    <h1 class="text-2xl font-extrabold tracking-tight sm:text-3xl">
                                        Asignación sucursal
                                    </h1>
                                    <p class="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground sm:text-base">
                                        Toma los colaboradores detectados en el periodo, intenta asignar sucursal,
                                        separa incidencias y deja lista la revisión manual para altas, bajas y nombres
                                        que no empaten correctamente.
                                    </p>
                                </div>

                                <div class="flex flex-wrap items-center gap-3">
                                    <select
                                        v-model="selectedPeriodId"
                                        class="app-input h-11 min-w-[260px]"
                                    >
                                        <option value="">Selecciona un periodo</option>
                                        <option
                                            v-for="period in periods"
                                            :key="period.id"
                                            :value="String(period.id)"
                                        >
                                            {{ period.label }}
                                        </option>
                                    </select>

                                    <button
                                        type="button"
                                        class="app-btn h-11 px-5"
                                        :disabled="autoMatchForm.processing || !selected_period_id"
                                        @click="runAutoMatch"
                                    >
                                        <Wand2 class="mr-2 size-4" />
                                        {{ autoMatchForm.processing ? 'Procesando...' : 'Cruzar y actualizar' }}
                                    </button>
                                </div>

                                <p class="text-xs text-muted-foreground">
                                    Periodo actual:
                                    <span class="font-semibold text-foreground">
                                        {{ selected_period_label || 'Sin periodo seleccionado' }}
                                    </span>
                                </p>

                                <div
                                    v-if="hasNoAssignments"
                                    class="mt-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200"
                                >
                                    <div class="flex items-start gap-2">
                                        <Info class="mt-0.5 size-4" />
                                        <div>
                                            <p class="font-semibold">Aún no hay asignaciones generadas</p>
                                            <p class="mt-1 leading-6">
                                                Si ya analizaste NOI y otras fuentes del periodo, ejecuta
                                                <span class="font-semibold">Cruzar y actualizar</span> para poblar este módulo.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3 lg:w-[460px]">
                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <UserRound class="size-4" />
                                        Activos del periodo
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ summary.total }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <CheckCircle2 class="size-4" />
                                        Con sucursal
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ summary.with_branch }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <AlertTriangle class="size-4" />
                                        Incidencias
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ summary.needs_review }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <Sparkles class="size-4" />
                                        Manuales
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ summary.manual }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <UserPlus class="size-4" />
                                        Altas
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ summary.hires }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <UserMinus class="size-4" />
                                        Bajas
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ summary.leavers }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="grid gap-6 xl:grid-cols-3">
                <div class="app-card overflow-hidden">
                    <div class="border-b px-4 py-4 sm:px-5">
                        <h2 class="text-lg font-bold tracking-tight">Resumen rápido</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Estado de las asignaciones del periodo actual.
                        </p>
                    </div>

                    <div class="space-y-3 p-4 sm:p-5">
                        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-4 dark:border-emerald-500/20 dark:bg-emerald-500/10">
                            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
                                Match correcto
                            </p>
                            <p class="mt-2 text-2xl font-extrabold text-emerald-800 dark:text-emerald-200">
                                {{ matchedAssignments.length }}
                            </p>
                        </div>

                        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4 dark:border-amber-500/20 dark:bg-amber-500/10">
                            <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">
                                Pendientes / incidencias
                            </p>
                            <p class="mt-2 text-2xl font-extrabold text-amber-800 dark:text-amber-200">
                                {{ pendingAssignments.length }}
                            </p>
                        </div>

                        <div class="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-4 dark:border-sky-500/20 dark:bg-sky-500/10">
                            <p class="text-xs font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-300">
                                Ajustados manualmente
                            </p>
                            <p class="mt-2 text-2xl font-extrabold text-sky-800 dark:text-sky-200">
                                {{ manualAssignments.length }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="app-card overflow-hidden xl:col-span-2">
                    <div class="border-b px-4 py-4 sm:px-5">
                        <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                            <div>
                                <h2 class="text-lg font-bold tracking-tight">Empleados del periodo</h2>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Filtra y corrige sucursales desde aquí. Los registros manuales se respetan en los próximos cruces.
                                </p>
                            </div>

                            <div class="flex flex-col gap-3 sm:flex-row">
                                <div class="relative w-full sm:w-[320px]">
                                    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <input
                                        v-model="filters.query"
                                        type="text"
                                        class="app-input h-11 pl-10"
                                        placeholder="Buscar empleado, sucursal o nota..."
                                    />
                                </div>

                                <select
                                    v-model="filters.status"
                                    class="app-input h-11 sm:w-[190px]"
                                >
                                    <option value="all">Todos</option>
                                    <option value="matched">Match correcto</option>
                                    <option value="manual">Manual</option>
                                    <option value="pending">Pendiente</option>
                                    <option value="unmatched">Sin match</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="filteredAssignments.length"
                        class="grid gap-4 p-4 sm:p-5 lg:grid-cols-2"
                    >
                        <article
                            v-for="item in filteredAssignments"
                            :key="item.id"
                            class="rounded-[28px] border border-border/70 bg-background px-4 py-4 shadow-sm transition-all duration-200 hover:-translate-y-1 hover:shadow-md"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="truncate text-base font-bold tracking-tight">
                                        {{ item.employee_name }}
                                    </h3>
                                    <p class="mt-1 text-xs text-muted-foreground">
                                        {{ item.normalized_name || 'Sin nombre normalizado' }}
                                    </p>
                                </div>

                                <span
                                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="statusClass(item.ui_status)"
                                >
                                    {{ statusLabel(item.ui_status) }}
                                </span>
                            </div>

                            <div class="mt-4 space-y-3">
                                <div class="flex items-center gap-2 text-sm">
                                    <Building2 class="size-4 text-muted-foreground" />
                                    <span class="font-medium">{{ item.branch_name || 'Sin sucursal asignada' }}</span>
                                </div>

                                <div class="flex items-center gap-2 text-sm">
                                    <Link2 class="size-4 text-muted-foreground" />
                                    <span>{{ item.source_name || 'Sin fuente' }}</span>
                                </div>

                                <div class="flex items-center gap-2 text-sm">
                                    <ArrowRightLeft class="size-4 text-muted-foreground" />
                                    <span>{{ item.match_label || 'Pendiente' }}</span>
                                </div>

                                <div class="flex items-center gap-2 text-sm">
                                    <ClipboardList class="size-4 text-muted-foreground" />
                                    <span>{{ item.match_explanation || 'Sin explicación' }}</span>
                                </div>

                                <div class="flex items-center gap-2 text-sm text-muted-foreground">
                                    <GitCompareArrows class="size-4" />
                                    <span>{{ item.period_label || 'Sin periodo' }}</span>
                                </div>

                                <div class="flex items-center gap-2 text-sm text-muted-foreground">
                                    <FileText class="size-4" />
                                    <span>Confianza: {{ formatConfidence(item.confidence) }}</span>
                                </div>

                                <div
                                    v-if="item.notes"
                                    class="rounded-2xl border border-border/70 bg-muted/30 px-3 py-3 text-sm text-muted-foreground"
                                >
                                    {{ item.notes }}
                                </div>

                                <div
                                    v-if="item.was_manual_reviewed"
                                    class="rounded-2xl border border-sky-200 bg-sky-50 px-3 py-3 text-xs font-medium text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300"
                                >
                                    Registro revisado manualmente.
                                </div>
                            </div>

                            <div
                                v-if="item.ui_status !== 'matched' || !item.branch_id"
                                class="mt-4 space-y-3 rounded-[22px] border border-border/70 bg-muted/20 p-4"
                            >
                                <p class="text-sm font-semibold">Asignación manual</p>

                                <select
                                    v-model="getManualForm(item).branch_id"
                                    class="app-input h-11"
                                >
                                    <option value="">Selecciona sucursal</option>
                                    <option
                                        v-for="branch in branches"
                                        :key="branch.id"
                                        :value="String(branch.id)"
                                    >
                                        {{ branch.name }}
                                    </option>
                                </select>

                                <textarea
                                    v-model="getManualForm(item).notes"
                                    class="app-input min-h-[96px]"
                                    placeholder="Notas de revisión..."
                                />

                                <button
                                    type="button"
                                    class="app-btn h-11 px-5"
                                    @click="saveManualAssignment(item)"
                                >
                                    Guardar asignación
                                </button>
                            </div>

                            <div class="mt-4 flex items-center justify-between border-t pt-4">
                                <p class="text-xs text-muted-foreground">
                                    Actualizado: {{ item.updated_at ?? '—' }}
                                </p>
                            </div>
                        </article>
                    </div>

                    <div v-else class="px-4 py-12 text-center sm:px-5">
                        <UserRound class="mx-auto size-6 text-muted-foreground" />
                        <p class="mt-3 text-sm font-semibold">No hay registros visibles</p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{
                                hasNoAssignments
                                    ? 'Todavía no se han generado asignaciones para este periodo. Ejecuta “Cruzar y actualizar”.'
                                    : 'No se encontraron coincidencias con los filtros actuales.'
                            }}
                        </p>
                    </div>
                </div>
            </section>

            <section
                v-if="incidences.length || hires.length || leavers.length"
                class="grid gap-6 xl:grid-cols-3"
            >
                <div class="app-card overflow-hidden">
                    <div class="border-b px-4 py-4 sm:px-5">
                        <div class="flex items-center gap-2">
                            <AlertTriangle class="size-5 text-amber-500" />
                            <h2 class="text-lg font-bold tracking-tight">Incidencias</h2>
                        </div>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Registros que requieren atención manual.
                        </p>
                    </div>

                    <div v-if="incidences.length" class="space-y-3 p-4 sm:p-5">
                        <article
                            v-for="item in incidences"
                            :key="`inc-${item.id}`"
                            class="rounded-2xl border border-border/70 bg-background px-4 py-4"
                        >
                            <p class="font-semibold">{{ item.employee_name }}</p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{ item.branch_name || 'Sin sucursal asignada' }}
                            </p>
                            <p class="mt-2 text-xs text-muted-foreground">
                                {{ item.notes || item.match_explanation }}
                            </p>
                        </article>
                    </div>

                    <div v-else class="px-4 py-10 text-center text-sm text-muted-foreground">
                        Sin incidencias.
                    </div>
                </div>

                <div class="app-card overflow-hidden">
                    <div class="border-b px-4 py-4 sm:px-5">
                        <div class="flex items-center gap-2">
                            <UserPlus class="size-5 text-emerald-500" />
                            <h2 class="text-lg font-bold tracking-tight">Altas del periodo</h2>
                        </div>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Empleados que aparecen en este periodo y no en el anterior.
                        </p>
                    </div>

                    <div v-if="hires.length" class="space-y-3 p-4 sm:p-5">
                        <article
                            v-for="item in hires"
                            :key="`hire-${item.id}`"
                            class="rounded-2xl border border-border/70 bg-background px-4 py-4"
                        >
                            <p class="font-semibold">{{ item.employee_name }}</p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{ item.branch_name || 'Sin sucursal asignada' }}
                            </p>
                        </article>
                    </div>

                    <div v-else class="px-4 py-10 text-center text-sm text-muted-foreground">
                        Sin altas detectadas.
                    </div>
                </div>

                <div class="app-card overflow-hidden">
                    <div class="border-b px-4 py-4 sm:px-5">
                        <div class="flex items-center gap-2">
                            <UserMinus class="size-5 text-rose-500" />
                            <h2 class="text-lg font-bold tracking-tight">Bajas del periodo</h2>
                        </div>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Empleados vistos en el periodo anterior pero no en el actual.
                        </p>
                    </div>

                    <div v-if="leavers.length" class="space-y-3 p-4 sm:p-5">
                        <article
                            v-for="item in leavers"
                            :key="`leave-${item.id}`"
                            class="rounded-2xl border border-border/70 bg-background px-4 py-4"
                        >
                            <p class="font-semibold">{{ item.employee_name }}</p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Última sucursal conocida: {{ item.branch_name || 'Sin sucursal asignada' }}
                            </p>
                            <p class="mt-2 text-xs text-muted-foreground">
                                Último periodo: {{ item.period_label || '—' }}
                            </p>
                        </article>
                    </div>

                    <div v-else class="px-4 py-10 text-center text-sm text-muted-foreground">
                        Sin bajas detectadas.
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
