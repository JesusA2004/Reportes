<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head } from '@inertiajs/vue3'

import {
    CalendarDays,
    ChevronDown,
    ChevronRight,
    FolderOpen,
    Info,
    MoreHorizontal,
    Plus,
    Search,
    Sparkles,
} from 'lucide-vue-next'

import InputError from '@/components/InputError.vue'
import { usePeriodosIndex } from '@/composables/usePeriodosIndex'
import AppLayout from '@/layouts/AppLayout.vue'
import { index as periodosIndex } from '@/routes/periodos'

const props = withDefaults(
    defineProps<{
        periods: Array<{
            id: number
            name?: string | null
            code: string
            type?: string | null
            sequence?: number | null
            label: string
            year: number
            month: number | null
            start_date?: string | null
            end_date?: string | null
            is_closed?: boolean
            is_compound?: boolean
            can_receive_uploads?: boolean
            component_labels?: string[]
            component_weeks?: Array<{
                id: number
                sequence: number | null
                start_date: string | null
                end_date: string | null
            }>
            uploaded_sources_count?: number
            required_sources_count?: number
            can_close?: boolean
            close_issues_count?: number
            close_issues_preview?: string[]
        }>
        availableWeeks?: Array<{
            id: number
            label: string
            year: number
            month: number
            sequence: number
            start_date?: string
            end_date?: string
        }>
    }>(),
    {
        periods: () => [],
        availableWeeks: () => [],
    },
)

defineOptions({
    layout: (h, page) =>
        h(
            AppLayout,
            {
                breadcrumbs: [
                    {
                        title: 'Periodos',
                        href: periodosIndex(),
                    },
                ],
            },
            () => page,
        ),
})

const {
    filters,
    form,
    monthlyForm,
    filteredWeeksForMonth,
    filteredPeriods,
    totalPeriods,
    closedPeriods,
    openPeriods,
    blockedPeriods,
    createLabel,
    submitCreate,
    submitConfigureMonth,
    togglePeriod,
    destroyMonthly,
} = usePeriodosIndex(props)

form.type = 'weekly'

const monthNames = [
    '',
    'Enero',
    'Febrero',
    'Marzo',
    'Abril',
    'Mayo',
    'Junio',
    'Julio',
    'Agosto',
    'Septiembre',
    'Octubre',
    'Noviembre',
    'Diciembre',
]

const collapsedWeeklyGroups = ref<Record<string, boolean>>({})

function toggleWeeklyGroup(key: string) {
    collapsedWeeklyGroups.value[key] = !collapsedWeeklyGroups.value[key]
}

function isWeeklyGroupCollapsed(key: string) {
    return !!collapsedWeeklyGroups.value[key]
}

function formatShortDate(date?: string | null) {
    if (!date) return 'Sin fecha'

    const parsed = new Date(`${date}T00:00:00`)
    if (Number.isNaN(parsed.getTime())) return date

    return new Intl.DateTimeFormat('es-MX', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(parsed)
}

function formatLongDate(date?: string | null) {
    if (!date) return 'Sin fecha'

    const parsed = new Date(`${date}T00:00:00`)
    if (Number.isNaN(parsed.getTime())) return date

    return new Intl.DateTimeFormat('es-MX', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(parsed)
}

function formatRange(start?: string | null, end?: string | null) {
    if (!start && !end) return 'Sin rango definido'
    if (start && !end) return `Desde ${formatLongDate(start)}`
    if (!start && end) return `Hasta ${formatLongDate(end)}`
    return `${formatLongDate(start)} al ${formatLongDate(end)}`
}

// ── Helpers para la selección de semanas en el formulario mensual ──

function weekCrossesMonth(week: { start_date?: string; end_date?: string }): boolean {
    if (!week.start_date || !week.end_date) return false
    return week.start_date.substring(5, 7) !== week.end_date.substring(5, 7)
}

function weekFromPreviousMonth(week: { start_date?: string }, selectedMonth: number): boolean {
    if (!week.start_date) return false
    return parseInt(week.start_date.substring(5, 7)) < selectedMonth
}

const selectedWeeksDetail = computed(() => {
    const ids = new Set(monthlyForm.week_ids)
    return filteredWeeksForMonth.value
        .filter((w) => ids.has(w.id))
        .sort((a, b) => (a.start_date ?? '').localeCompare(b.start_date ?? ''))
})

const selectedWeeksSummary = computed(() => {
    const weeks = selectedWeeksDetail.value
    if (!weeks.length) return null
    const first = weeks[0]
    const last  = weeks[weeks.length - 1]
    return {
        firstSequence: first.sequence,
        lastSequence:  last.sequence,
        startDate:     first.start_date,
        endDate:       last.end_date,
        count:         weeks.length,
    }
})

const hasNonConsecutiveWeeks = computed(() => {
    const weeks = selectedWeeksDetail.value
    if (weeks.length < 2) return false
    for (let i = 1; i < weeks.length; i++) {
        const prevEnd   = weeks[i - 1].end_date
        const currStart = weeks[i].start_date
        if (!prevEnd || !currStart) continue
        const expected = new Date(`${prevEnd}T00:00:00`)
        expected.setDate(expected.getDate() + 1)
        const expectedStr = expected.toISOString().split('T')[0]
        if (expectedStr !== currStart) return true
    }
    return false
})

function getProgress(period: {
    uploaded_sources_count?: number
    required_sources_count?: number
}) {
    const uploaded = Number(period.uploaded_sources_count ?? 0)
    const required = Number(period.required_sources_count ?? 0)

    if (!required) return 0

    return Math.min((uploaded / required) * 100, 100)
}

function getProgressText(period: {
    uploaded_sources_count?: number
    required_sources_count?: number
}) {
    const uploaded = Number(period.uploaded_sources_count ?? 0)
    const required = Number(period.required_sources_count ?? 0)

    if (!required) return 'No requiere carga de archivos'

    return `${uploaded} de ${required} archivo(s) completados`
}

function getMonthTitle(period: {
    month: number | null
    year: number
    start_date?: string | null
}) {
    if (period.month && monthNames[period.month]) {
        return `${monthNames[period.month]} ${period.year}`
    }

    if (period.start_date) {
        const parsed = new Date(`${period.start_date}T00:00:00`)
        if (!Number.isNaN(parsed.getTime())) {
            return new Intl.DateTimeFormat('es-MX', {
                month: 'long',
                year: 'numeric',
            }).format(parsed)
        }
    }

    return `Periodo ${period.year}`
}

function getFriendlyType(period: { type?: string | null }) {
    switch (period.type) {
        case 'bimonthly':
            return 'Bimestres'
        case 'quarterly':
            return 'Trimestres'
        case 'semiannual':
            return 'Semestres'
        case 'annual':
            return 'Anual'
        default:
            return 'Periodo'
    }
}

function getPeriodTitle(period: {
    type?: string | null
    sequence?: number | null
    year: number
    label: string
}) {
    if (period.type === 'weekly' && period.sequence) return `Semana ${period.sequence}`
    if (period.type === 'bimonthly' && period.sequence) return `Bimestre ${period.sequence}`
    if (period.type === 'quarterly' && period.sequence) return `Trimestre ${period.sequence}`
    if (period.type === 'semiannual' && period.sequence) return `Semestre ${period.sequence}`
    if (period.type === 'annual') return `Anual ${period.year}`

    return period.label
}

function getPeriodSubtitle(period: {
    start_date?: string | null
    end_date?: string | null
}) {
    return formatRange(period.start_date, period.end_date)
}

const weeklyPeriods = computed(() =>
    filteredPeriods.value.filter((period) => period.type === 'weekly'),
)

const monthlyPeriods = computed(() =>
    filteredPeriods.value.filter((period) => period.type === 'monthly'),
)

const nonWeeklyPeriods = computed(() =>
    filteredPeriods.value.filter((period) => period.type !== 'weekly' && period.type !== 'monthly'),
)

const groupedWeeklyPeriods = computed(() => {
    const groups = weeklyPeriods.value.reduce<
        Array<{
            key: string
            title: string
            periods: typeof weeklyPeriods.value
            openCount: number
            closedCount: number
            blockedCount: number
        }>
    >((acc, period) => {
        const title = getMonthTitle(period)
        const key = `${period.year}-${period.month ?? 0}-${title}`

        let group = acc.find((item) => item.key === key)

        if (!group) {
            group = {
                key,
                title,
                periods: [],
                openCount: 0,
                closedCount: 0,
                blockedCount: 0,
            }
            acc.push(group)
        }

        group.periods.push(period)

        if (period.is_closed) {
            group.closedCount += 1
        } else {
            group.openCount += 1
        }

        if (!period.is_closed && period.can_close === false) {
            group.blockedCount += 1
        }

        return acc
    }, [])

    return groups.map((group) => ({
        ...group,
        periods: [...group.periods].sort((a, b) => {
            const aSequence = a.sequence ?? 0
            const bSequence = b.sequence ?? 0

            if (aSequence !== bSequence) return aSequence - bSequence
            return (a.start_date ?? '').localeCompare(b.start_date ?? '')
        }),
    }))
})

const groupedOtherPeriods = computed(() => {
    const orderMap: Record<string, number> = {
        bimonthly: 1,
        quarterly: 2,
        semiannual: 3,
        annual: 4,
    }

    const groups = nonWeeklyPeriods.value.reduce<
        Array<{
            key: string
            type: string
            title: string
            periods: typeof nonWeeklyPeriods.value
        }>
    >((acc, period) => {
        const type = period.type ?? 'other'
        let group = acc.find((item) => item.type === type)

        if (!group) {
            group = {
                key: type,
                type,
                title: getFriendlyType(period),
                periods: [],
            }
            acc.push(group)
        }

        group.periods.push(period)
        return acc
    }, [])

    return groups
        .map((group) => ({
            ...group,
            periods: [...group.periods].sort((a, b) => {
                if (a.year !== b.year) return b.year - a.year
                const aSequence = a.sequence ?? 0
                const bSequence = b.sequence ?? 0
                return aSequence - bSequence
            }),
        }))
        .sort((a, b) => (orderMap[a.type] ?? 99) - (orderMap[b.type] ?? 99))
})

function getStatusLabel(period: {
    is_closed?: boolean
    can_close?: boolean
}) {
    if (period.is_closed) return 'Cerrado'
    if (period.can_close === false) return 'Requiere revisión'
    return 'Disponible'
}

function getStatusClasses(period: {
    is_closed?: boolean
    can_close?: boolean
}) {
    if (period.is_closed) {
        return 'border-slate-200 bg-slate-100 text-slate-700 dark:border-slate-500/20 dark:bg-slate-500/15 dark:text-slate-300'
    }

    if (period.can_close === false) {
        return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300'
    }

    return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300'
}
</script>

<template>
    <Head title="Periodos" />

    <div class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-indigo-50/40 p-4 sm:p-6 lg:p-8">
        <div class="mx-auto max-w-screen-2xl space-y-6">

            <section class="overflow-hidden rounded-[2rem] bg-slate-950 p-6 text-white shadow-2xl shadow-slate-300 sm:p-8">
                <div class="flex items-center gap-3">
                    <div class="flex size-10 items-center justify-center rounded-2xl bg-violet-500">
                        <CalendarDays class="size-5 text-white" />
                    </div>
                    <p class="text-xs font-black uppercase tracking-[0.28em] text-violet-300">Gestión de periodos</p>
                </div>
                <h1 class="mt-3 text-3xl font-black tracking-tight sm:text-4xl">Periodos</h1>
                <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-300">
                    Genera semanas manualmente. Bimestres, trimestres, semestres y anual se crean automáticamente según las semanas existentes.
                </p>
            </section>

            <section class="app-card overflow-hidden">
                <div class="border-b px-4 py-4 sm:px-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-bold tracking-tight">Generar semanas del mes</h2>
                            <p class="mt-2 text-sm text-muted-foreground">
                                Selecciona el año y mes base. Los periodos agregados se recalculan automáticamente.
                            </p>
                        </div>
                    </div>
                </div>

                <form @submit.prevent="submitCreate" class="space-y-5 p-4 sm:p-5">
                    <div class="rounded-2xl border border-primary/15 bg-primary/5 px-4 py-3">
                        <div class="flex items-start gap-3">
                            <Info class="mt-0.5 size-4 text-primary" />
                            <div class="text-sm text-muted-foreground">
                                <p class="font-semibold text-foreground">Generación automática activada</p>
                                <p class="mt-1">
                                    Solo se generan semanas manualmente. Bimestres, trimestres, semestres y anual
                                    se crean o actualizan solos con base en las semanas existentes.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2 sm:col-span-2">
                            <label class="text-sm font-semibold">Tipo</label>
                            <select v-model="form.type" class="app-input">
                                <option value="weekly">Semanal (base)</option>
                            </select>
                            <InputError :message="form.errors.type" />
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-semibold">Año</label>
                            <input
                                v-model="form.year"
                                type="number"
                                class="app-input"
                                min="2020"
                                max="2100"
                                placeholder="2026"
                            />
                            <InputError :message="form.errors.year" />
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-semibold">Mes base</label>
                            <select v-model="form.month" class="app-input">
                                <option value="">Selecciona un mes</option>
                                <option value="1">Enero</option>
                                <option value="2">Febrero</option>
                                <option value="3">Marzo</option>
                                <option value="4">Abril</option>
                                <option value="5">Mayo</option>
                                <option value="6">Junio</option>
                                <option value="7">Julio</option>
                                <option value="8">Agosto</option>
                                <option value="9">Septiembre</option>
                                <option value="10">Octubre</option>
                                <option value="11">Noviembre</option>
                                <option value="12">Diciembre</option>
                            </select>
                            <InputError :message="form.errors.month" />
                        </div>
                    </div>

                    <div class="rounded-2xl border border-dashed border-border/70 bg-muted/30 px-4 py-3">
                        <p class="text-sm text-muted-foreground">
                            Se generará:
                            <span class="font-semibold text-foreground">
                                {{ createLabel || 'Selecciona año y mes' }}
                            </span>
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Se crean semanas base para ese mes. Los bimestres/trimestres/etc. se recalculan automáticamente.
                        </p>
                    </div>

                    <div>
                        <button type="submit" class="app-btn h-11 px-5" :disabled="form.processing">
                            <Plus class="mr-2 size-4" />
                            {{ form.processing ? 'Generando...' : 'Generar semanas y sincronizar' }}
                        </button>
                    </div>
                </form>
            </section>

            <!-- ── Configurar mes operativo ── -->
            <section class="app-card overflow-hidden">
                <div class="border-b px-4 py-4 sm:px-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-bold tracking-tight">Configurar mes operativo</h2>
                            <p class="mt-2 text-sm text-muted-foreground">
                                Selecciona el año, mes y las semanas que formarán el periodo mensual operativo.
                            </p>
                        </div>
                    </div>
                </div>

                <form @submit.prevent="submitConfigureMonth" class="space-y-5 p-4 sm:p-5">
                    <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/5 px-4 py-3">
                        <div class="flex items-start gap-3">
                            <Info class="mt-0.5 size-4 text-emerald-600 dark:text-emerald-400" />
                            <div class="text-sm text-muted-foreground">
                                <p class="font-semibold text-foreground">Agrupa semanas en un mes operativo</p>
                                <p class="mt-1">
                                    Se muestran <strong class="text-foreground">todas las semanas disponibles no asignadas</strong>
                                    del año seleccionado — incluyendo semanas sobrantes de meses anteriores y semanas
                                    que cruzan el cambio de mes. Selecciona 3, 4 o 5 semanas consecutivas según el rango
                                    operativo real. Las semanas no seleccionadas quedan disponibles para el siguiente mes.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <label class="text-sm font-semibold">Año</label>
                            <input
                                v-model="monthlyForm.year"
                                type="number"
                                class="app-input"
                                min="2020"
                                max="2100"
                                placeholder="2026"
                            />
                            <InputError :message="monthlyForm.errors.year" />
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-semibold">Mes</label>
                            <select v-model="monthlyForm.month" class="app-input">
                                <option value="">Selecciona un mes</option>
                                <option value="1">Enero</option>
                                <option value="2">Febrero</option>
                                <option value="3">Marzo</option>
                                <option value="4">Abril</option>
                                <option value="5">Mayo</option>
                                <option value="6">Junio</option>
                                <option value="7">Julio</option>
                                <option value="8">Agosto</option>
                                <option value="9">Septiembre</option>
                                <option value="10">Octubre</option>
                                <option value="11">Noviembre</option>
                                <option value="12">Diciembre</option>
                            </select>
                            <InputError :message="monthlyForm.errors.month" />
                        </div>
                    </div>

                    <div v-if="monthlyForm.year && monthlyForm.month" class="space-y-3">
                        <div>
                            <p class="text-sm font-semibold">Semanas disponibles para este mes operativo</p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                Selecciona las semanas que formarán este mes operativo. Pueden incluir semanas que inician en un mes calendario anterior o terminan en el siguiente.
                            </p>
                        </div>

                        <div v-if="filteredWeeksForMonth.length" class="space-y-2">
                            <label
                                v-for="week in filteredWeeksForMonth"
                                :key="week.id"
                                class="flex cursor-pointer items-start gap-3 rounded-2xl border bg-muted/20 px-4 py-3 transition"
                                :class="monthlyForm.week_ids.includes(week.id)
                                    ? 'border-primary/40 bg-primary/5'
                                    : 'border-border/70 hover:border-primary/25 hover:bg-primary/5'"
                            >
                                <input
                                    type="checkbox"
                                    :value="week.id"
                                    v-model="monthlyForm.week_ids"
                                    class="mt-0.5 size-4 rounded accent-primary"
                                />
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-semibold text-foreground">
                                            Semana {{ week.sequence }}
                                        </span>
                                        <span
                                            v-if="weekCrossesMonth(week)"
                                            class="rounded-full bg-violet-100 px-2 py-0.5 text-[10px] font-semibold text-violet-700 dark:bg-violet-500/15 dark:text-violet-300"
                                        >
                                            Cruza mes
                                        </span>
                                        <span
                                            v-else-if="weekFromPreviousMonth(week, Number(monthlyForm.month))"
                                            class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700 dark:bg-amber-500/15 dark:text-amber-300"
                                        >
                                            Del mes anterior
                                        </span>
                                    </div>
                                    <p class="mt-0.5 text-xs text-muted-foreground">
                                        {{ formatShortDate(week.start_date) }} al {{ formatShortDate(week.end_date) }}
                                    </p>
                                </div>
                            </label>
                        </div>

                        <div v-else class="rounded-2xl border border-dashed border-amber-300/60 bg-amber-50/40 px-4 py-4 dark:border-amber-500/20 dark:bg-amber-500/5">
                            <p class="text-sm text-amber-700 dark:text-amber-300">
                                No hay semanas disponibles para el año {{ monthlyForm.year }}.
                                Todas las semanas ya fueron asignadas a meses operativos,
                                o aún no se han generado semanas para ese año.
                            </p>
                        </div>

                        <!-- Advertencia de semanas no consecutivas -->
                        <div
                            v-if="hasNonConsecutiveWeeks"
                            class="rounded-2xl border border-red-200 bg-red-50/60 px-4 py-3 dark:border-red-500/20 dark:bg-red-500/10"
                        >
                            <p class="text-sm font-semibold text-red-700 dark:text-red-300">
                                Las semanas seleccionadas no son consecutivas
                            </p>
                            <p class="mt-0.5 text-xs text-red-600 dark:text-red-400">
                                Un mes operativo debe formarse con semanas seguidas sin saltos entre ellas.
                            </p>
                        </div>

                        <!-- Resumen de selección -->
                        <div
                            v-if="selectedWeeksSummary"
                            class="rounded-2xl border border-emerald-200/60 bg-emerald-50/40 px-4 py-3 dark:border-emerald-500/20 dark:bg-emerald-500/5"
                        >
                            <p class="text-[10px] font-bold uppercase tracking-widest text-emerald-700/70 dark:text-emerald-400/70">
                                Resumen del mes operativo
                            </p>
                            <p class="mt-1.5 text-sm font-semibold text-foreground">
                                Semana {{ selectedWeeksSummary.firstSequence }}
                                <span v-if="selectedWeeksSummary.firstSequence !== selectedWeeksSummary.lastSequence">
                                    a Semana {{ selectedWeeksSummary.lastSequence }}
                                </span>
                            </p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ formatShortDate(selectedWeeksSummary.startDate) }} al {{ formatShortDate(selectedWeeksSummary.endDate) }}
                            </p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ selectedWeeksSummary.count }} semana(s)
                            </p>
                        </div>
                        <div
                            v-else-if="filteredWeeksForMonth.length && monthlyForm.week_ids.length === 0"
                            class="rounded-2xl border border-dashed border-border/60 px-4 py-3"
                        >
                            <p class="text-xs text-muted-foreground">
                                Selecciona las semanas que formarán el rango operativo.
                            </p>
                        </div>

                        <InputError :message="monthlyForm.errors.week_ids" />
                    </div>

                    <div>
                        <button
                            type="submit"
                            class="app-btn h-11 px-5"
                            :disabled="monthlyForm.processing || monthlyForm.week_ids.length === 0"
                        >
                            <Plus class="mr-2 size-4" />
                            {{ monthlyForm.processing ? 'Creando...' : 'Crear mes operativo' }}
                        </button>
                    </div>
                </form>
            </section>

            <section class="app-card overflow-hidden">
                <div class="border-b px-4 py-4 sm:px-5">
                    <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                        <div>
                            <h2 class="text-lg font-bold tracking-tight">Listado de periodos</h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Las semanas se agrupan por mes y los demás periodos aparecen como agrupaciones automáticas.
                            </p>
                        </div>

                        <div class="relative w-full xl:w-[320px]">
                            <Search
                                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <input
                                v-model="filters.query"
                                type="text"
                                class="app-input h-11 rounded-full pl-10"
                                placeholder="Buscar periodo..."
                            />
                        </div>
                    </div>
                </div>

                <div v-if="filteredPeriods.length" class="space-y-8 p-4 sm:p-5">
                    <div v-if="groupedWeeklyPeriods.length" class="space-y-5">
                        <div class="flex items-center gap-3">
                            <div class="flex size-11 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                                <CalendarDays class="size-5" />
                            </div>
                            <div>
                                <h3 class="text-base font-bold tracking-tight">Semanas calendario por mes</h3>
                                <p class="text-sm text-muted-foreground">
                                    Se muestran las semanas base generadas por calendario. Sirven para armar los meses operativos, pero no son reportes mensuales por sí mismas.
                                </p>
                            </div>
                        </div>

                        <section
                            v-for="group in groupedWeeklyPeriods"
                            :key="group.key"
                            class="overflow-hidden rounded-[30px] border border-border/70 bg-background shadow-sm"
                        >
                            <div class="border-b bg-muted/30 px-5 py-4 sm:px-6">
                                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                                    <div class="flex items-center gap-3">
                                        <div class="flex size-11 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                                            <CalendarDays class="size-5" />
                                        </div>

                                        <div>
                                            <h3 class="text-lg font-bold tracking-tight">
                                                {{ group.title }}
                                            </h3>
                                            <p class="text-sm text-muted-foreground">
                                                {{ group.periods.length }} semana(s) generadas
                                                <span v-if="group.periods.length">
                                                    · Rango:
                                                    {{ formatShortDate(group.periods[0]?.start_date) }}
                                                    al {{ formatShortDate(group.periods[group.periods.length - 1]?.end_date) }}
                                                </span>
                                            </p>
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        class="inline-flex h-10 items-center gap-2 rounded-full border border-border bg-background px-4 text-sm font-semibold transition hover:border-primary/25 hover:bg-primary/5"
                                        @click="toggleWeeklyGroup(group.key)"
                                    >
                                        <ChevronDown
                                            v-if="!isWeeklyGroupCollapsed(group.key)"
                                            class="size-4"
                                        />
                                        <ChevronRight
                                            v-else
                                            class="size-4"
                                        />
                                        {{ isWeeklyGroupCollapsed(group.key) ? 'Expandir' : 'Contraer' }}
                                    </button>
                                </div>
                            </div>

                            <div
                                v-show="!isWeeklyGroupCollapsed(group.key)"
                                class="grid gap-4 p-4 sm:p-5 lg:grid-cols-2 2xl:grid-cols-3"
                            >
                                <article
                                    v-for="period in group.periods"
                                    :key="period.id"
                                    class="relative overflow-hidden rounded-[26px] border border-border/70 bg-background p-4 shadow-sm"
                                >
                                    <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-primary/50 via-primary/20 to-transparent" />

                                    <div class="flex items-center justify-between gap-3">
                                        <span class="inline-flex items-center rounded-full bg-primary/8 px-2.5 py-1 text-[11px] font-semibold text-primary">
                                            {{ getPeriodTitle(period) }}
                                        </span>
                                        <span class="text-xs text-muted-foreground">
                                            Semana base
                                        </span>
                                    </div>

                                    <p class="mt-3 text-sm font-semibold text-foreground">
                                        {{ getPeriodSubtitle(period) }}
                                    </p>
                                    <p class="mt-0.5 text-xs text-muted-foreground">
                                        {{ formatShortDate(period.start_date) }} — {{ formatShortDate(period.end_date) }}
                                    </p>
                                </article>
                            </div>
                        </section>
                    </div>

                    <!-- ── Periodos mensuales operativos ── -->
                    <div v-if="monthlyPeriods.length" class="space-y-5">
                        <div class="flex items-center gap-3">
                            <div class="flex size-11 items-center justify-center rounded-2xl bg-emerald-500/10 text-emerald-700 dark:text-emerald-300">
                                <CalendarDays class="size-5" />
                            </div>
                            <div>
                                <h3 class="text-base font-bold tracking-tight">Periodos mensuales operativos</h3>
                                <p class="text-sm text-muted-foreground">
                                    Agrupan semanas base y habilitan reportes mensuales, bimestrales, etc.
                                </p>
                            </div>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
                            <article
                                v-for="period in monthlyPeriods"
                                :key="period.id"
                                class="group relative overflow-hidden rounded-[26px] border border-border/70 bg-background p-5 shadow-sm transition-all duration-300 hover:-translate-y-1 hover:border-emerald-400/40 hover:shadow-xl"
                            >
                                <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-emerald-400/80 via-teal-400/80 to-emerald-200/20" />

                                <!-- Header: nombre + estado -->
                                <div class="flex items-start justify-between gap-3">
                                    <span class="inline-flex items-center rounded-full bg-emerald-500/10 px-2.5 py-1 text-[11px] font-semibold text-emerald-700 dark:text-emerald-300">
                                        {{ period.label }}
                                    </span>
                                    <span class="shrink-0 rounded-full border px-3 py-1 text-xs font-semibold" :class="getStatusClasses(period)">
                                        {{ getStatusLabel(period) }}
                                    </span>
                                </div>

                                <!-- Resumen operativo -->
                                <div class="mt-4 space-y-1.5">
                                    <div v-if="period.component_weeks?.length" class="flex items-baseline gap-2">
                                        <span class="text-xs font-semibold text-muted-foreground uppercase tracking-wide w-28 shrink-0">Periodo op.</span>
                                        <span class="text-sm font-semibold text-foreground">
                                            Semana {{ period.component_weeks[0].sequence }}
                                            a Semana {{ period.component_weeks[period.component_weeks.length - 1].sequence }}
                                        </span>
                                    </div>
                                    <div class="flex items-baseline gap-2">
                                        <span class="text-xs font-semibold text-muted-foreground uppercase tracking-wide w-28 shrink-0">Rango</span>
                                        <span class="text-sm text-foreground">
                                            {{ formatShortDate(period.start_date) }} al {{ formatShortDate(period.end_date) }}
                                        </span>
                                    </div>
                                    <div v-if="period.component_weeks?.length" class="flex items-baseline gap-2">
                                        <span class="text-xs font-semibold text-muted-foreground uppercase tracking-wide w-28 shrink-0">Total</span>
                                        <span class="text-sm text-foreground">{{ period.component_weeks.length }} semana(s)</span>
                                    </div>
                                </div>

                                <!-- Detalle de semanas -->
                                <div
                                    v-if="period.component_weeks?.length"
                                    class="mt-4 rounded-2xl border border-emerald-200/60 bg-emerald-50/40 px-4 py-3 dark:border-emerald-500/20 dark:bg-emerald-500/5"
                                >
                                    <p class="mb-2.5 text-[10px] font-bold uppercase tracking-widest text-emerald-700/70 dark:text-emerald-400/70">
                                        Semanas incluidas
                                    </p>
                                    <ul class="space-y-1.5">
                                        <li
                                            v-for="w in period.component_weeks"
                                            :key="w.id"
                                            class="flex items-center gap-2 text-xs"
                                        >
                                            <span class="size-1.5 shrink-0 rounded-full bg-emerald-400 dark:bg-emerald-500" />
                                            <span class="font-semibold text-foreground">Semana {{ w.sequence }}</span>
                                            <span class="text-muted-foreground">—</span>
                                            <span class="text-muted-foreground">{{ formatShortDate(w.start_date) }} al {{ formatShortDate(w.end_date) }}</span>
                                        </li>
                                    </ul>
                                </div>

                                <div class="mt-5 flex items-center justify-end border-t border-border/60 pt-4">
                                    <button
                                        type="button"
                                        class="inline-flex h-10 items-center gap-2 rounded-full border border-red-200 bg-red-50 px-4 text-sm font-semibold text-red-700 transition hover:border-red-300 hover:bg-red-100 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300 dark:hover:bg-red-500/20"
                                        @click="destroyMonthly(period)"
                                    >
                                        Eliminar mes operativo
                                    </button>
                                </div>
                            </article>
                        </div>
                    </div>

                    <div v-if="groupedOtherPeriods.length" class="space-y-5">
                        <div class="flex items-center gap-3">
                            <div class="flex size-11 items-center justify-center rounded-2xl bg-slate-500/10 text-slate-700 dark:text-slate-300">
                                <Sparkles class="size-5" />
                            </div>
                            <div>
                                <h3 class="text-base font-bold tracking-tight">Periodos agrupados automáticos</h3>
                                <p class="text-sm text-muted-foreground">
                                    Se generan solos cuando ya existen suficientes semanas para formar cada bloque.
                                </p>
                            </div>
                        </div>

                        <section
                            v-for="group in groupedOtherPeriods"
                            :key="group.key"
                            class="overflow-hidden rounded-[30px] border border-border/70 bg-background shadow-sm"
                        >
                            <div class="border-b bg-muted/30 px-5 py-4 sm:px-6">
                                <h3 class="text-lg font-bold tracking-tight">
                                    {{ group.title }}
                                </h3>
                                <p class="text-sm text-muted-foreground">
                                    {{ group.periods.length }} periodo(s) generados automáticamente
                                </p>
                            </div>

                            <div class="grid gap-4 p-4 sm:p-5 lg:grid-cols-2 2xl:grid-cols-3">
                                <article
                                    v-for="period in group.periods"
                                    :key="period.id"
                                    class="group relative overflow-hidden rounded-[26px] border border-border/70 bg-background p-5 shadow-sm transition-all duration-300 hover:-translate-y-1 hover:border-primary/25 hover:shadow-xl"
                                >
                                    <div
                                        class="absolute inset-x-0 top-0 h-1"
                                        :class="period.is_closed ? 'bg-slate-300 dark:bg-slate-600' : 'bg-gradient-to-r from-primary/70 via-sky-400/70 to-primary/20'"
                                    />

                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <span class="inline-flex items-center rounded-full bg-primary/8 px-2.5 py-1 text-[11px] font-semibold text-primary">
                                                {{ getPeriodTitle(period) }}
                                            </span>

                                            <p class="mt-3 text-sm font-semibold text-foreground">
                                                {{ getPeriodSubtitle(period) }}
                                            </p>

                                            <p v-if="period.component_labels?.length" class="mt-1 text-xs text-muted-foreground">
                                                Meses operativos: {{ period.component_labels.join(', ') }}
                                            </p>
                                            <p v-else class="mt-1 text-xs text-muted-foreground">
                                                Agrupación de meses operativos.
                                            </p>
                                        </div>

                                        <span
                                            class="shrink-0 rounded-full border px-3 py-1 text-xs font-semibold"
                                            :class="getStatusClasses(period)"
                                        >
                                            {{ getStatusLabel(period) }}
                                        </span>
                                    </div>

                                    <div class="mt-5 rounded-2xl border border-border/60 bg-muted/25 p-4">
                                        <div class="mb-2 flex items-center justify-between gap-3">
                                            <span class="text-sm font-semibold text-foreground">
                                                Progreso del periodo
                                            </span>
                                            <span class="text-xs font-medium text-muted-foreground">
                                                {{ getProgressText(period) }}
                                            </span>
                                        </div>

                                        <div class="h-2.5 overflow-hidden rounded-full bg-background shadow-inner">
                                            <div
                                                class="h-full rounded-full transition-all duration-500"
                                                :class="period.is_closed ? 'bg-slate-400 dark:bg-slate-500' : 'bg-primary'"
                                                :style="{ width: `${getProgress(period)}%` }"
                                            />
                                        </div>
                                    </div>

                                    <div class="mt-5 flex items-center justify-end border-t border-border/60 pt-4">
                                        <button
                                            type="button"
                                            class="app-btn app-btn-secondary h-11 rounded-full px-5 transition-all duration-300 group-hover:border-primary/20 group-hover:bg-primary/5"
                                            @click="togglePeriod(period)"
                                        >
                                            <MoreHorizontal class="mr-2 size-4" />
                                            {{ period.is_closed ? 'Reabrir' : 'Cambiar estado' }}
                                        </button>
                                    </div>
                                </article>
                            </div>
                        </section>
                    </div>
                </div>

                <div v-else class="px-4 py-12 text-center sm:px-5">
                    <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted">
                        <FolderOpen class="size-6 text-muted-foreground" />
                    </div>
                    <p class="mt-4 text-sm font-semibold">No hay periodos</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        No se encontraron periodos con los filtros actuales.
                    </p>
                </div>
            </section>
        </div>
    </div>
</template>
