<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head } from '@inertiajs/vue3'

import {
    AlertTriangle,
    CalendarDays,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    FolderOpen,
    HelpCircle,
    Lock,
    MoreHorizontal,
    Plus,
    Search,
    ShieldAlert,
    Sparkles,
    Unlock,
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
            uploaded_sources_count?: number
            required_sources_count?: number
            can_close?: boolean
            close_issues_count?: number
            close_issues_preview?: string[]
        }>
    }>(),
    {
        periods: () => [],
    },
)

defineOptions({
    // @ts-expect-error layout callback de Inertia
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
    filteredPeriods,
    totalPeriods,
    closedPeriods,
    openPeriods,
    blockedPeriods,
    createLabel,
    submitCreate,
    togglePeriod,
} = usePeriodosIndex(props)

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
        case 'weekly':
            return 'Semanal'
        case 'bimonthly':
            return 'Bimestral / Quincenal'
        case 'quarterly':
            return 'Trimestral'
        case 'semiannual':
            return 'Semestral'
        case 'annual':
            return 'Anual'
        default:
            return 'Periodo'
    }
}

function getPeriodTitle(period: {
    type?: string | null
    sequence?: number | null
    label: string
}) {
    if (period.type === 'weekly' && period.sequence) return `Semana ${period.sequence}`
    if (period.type === 'bimonthly' && period.sequence) return `Periodo ${period.sequence}`
    if (period.type === 'quarterly' && period.sequence) return `Trimestre ${period.sequence}`
    if (period.type === 'semiannual' && period.sequence) return `Semestre ${period.sequence}`
    if (period.type === 'annual') return 'Periodo anual'
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

const nonWeeklyPeriods = computed(() =>
    filteredPeriods.value.filter((period) => period.type !== 'weekly'),
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

    <div class="app-page px-4 py-4 sm:px-6">
        <div class="space-y-6">
            <section class="app-card overflow-hidden">
                <div class="relative">
                    <div class="absolute inset-0 bg-gradient-to-br from-primary/10 via-transparent to-primary/5" />

                    <div class="relative p-5 sm:p-6">
                        <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                            <div class="space-y-3">
                                <div
                                    class="inline-flex w-fit items-center gap-2 rounded-full border border-primary/15 bg-primary/5 px-3 py-1.5 text-xs font-semibold text-primary"
                                >
                                    <CalendarDays class="size-3.5" />
                                    Control operativo por periodo
                                </div>

                                <div>
                                    <h1 class="text-2xl font-extrabold tracking-tight sm:text-3xl">
                                        Periodos
                                    </h1>
                                    <p class="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground sm:text-base">
                                        Consulta los periodos agrupados de forma más clara. Las semanas se muestran por mes,
                                        mientras que los demás tipos se separan en su propia sección.
                                    </p>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3 lg:w-[420px]">
                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <FolderOpen class="size-4" />
                                        Total
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ totalPeriods }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <Unlock class="size-4" />
                                        Abiertos
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ openPeriods }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <Lock class="size-4" />
                                        Cerrados
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ closedPeriods }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <ShieldAlert class="size-4" />
                                        Revisión
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ blockedPeriods }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="app-card overflow-hidden">
                <div class="border-b px-4 py-4 sm:px-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-bold tracking-tight">Nuevo periodo</h2>
                            <p class="mt-2 text-sm text-muted-foreground">
                                Crea periodos operativos semanales, quincenales, trimestrales, semestrales o anuales.
                            </p>
                        </div>
                    </div>
                </div>

                <form @submit.prevent="submitCreate" class="space-y-5 p-4 sm:p-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2 sm:col-span-2">
                            <label class="text-sm font-semibold">Tipo</label>
                            <select v-model="form.type" class="app-input">
                                <option value="weekly">Semanal</option>
                                <option value="bimonthly">Bimestral / Quincenal</option>
                                <option value="quarterly">Trimestral</option>
                                <option value="semiannual">Semestral</option>
                                <option value="annual">Anual</option>
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
                    </div>

                    <div>
                        <button type="submit" class="app-btn h-11 px-5" :disabled="form.processing">
                            <Plus class="mr-2 size-4" />
                            {{ form.processing ? 'Creando...' : 'Crear periodo' }}
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
                                Las semanas se agrupan por mes. Los demás tipos se muestran por categoría.
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
                                <h3 class="text-base font-bold tracking-tight">Semanales por mes</h3>
                                <p class="text-sm text-muted-foreground">
                                    Aquí se muestran únicamente los periodos semanales agrupados por mes.
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
                                                {{ group.periods.length }} semana(s) registradas
                                            </p>
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
                                            {{ group.openCount }} abierta(s)
                                        </span>

                                        <span class="rounded-full border border-slate-200 bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700 dark:border-slate-500/20 dark:bg-slate-500/15 dark:text-slate-300">
                                            {{ group.closedCount }} cerrada(s)
                                        </span>

                                        <span
                                            v-if="group.blockedCount"
                                            class="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300"
                                        >
                                            {{ group.blockedCount }} en revisión
                                        </span>

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
                            </div>

                            <div
                                v-show="!isWeeklyGroupCollapsed(group.key)"
                                class="grid gap-4 p-4 sm:p-5 lg:grid-cols-2 2xl:grid-cols-3"
                            >
                                <article
                                    v-for="period in group.periods"
                                    :key="period.id"
                                    class="group relative overflow-hidden rounded-[26px] border border-border/70 bg-background p-5 shadow-sm transition-all duration-300 hover:-translate-y-1 hover:border-primary/25 hover:shadow-xl"
                                >
                                    <div
                                        class="absolute inset-x-0 top-0 h-1"
                                        :class="period.is_closed ? 'bg-slate-300 dark:bg-slate-600' : 'bg-gradient-to-r from-primary/80 via-emerald-400/80 to-primary/20'"
                                    />

                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <span class="inline-flex items-center rounded-full bg-primary/8 px-2.5 py-1 text-[11px] font-semibold text-primary">
                                                {{ getPeriodTitle(period) }}
                                            </span>

                                            <p class="mt-3 text-sm font-semibold text-foreground">
                                                {{ getPeriodSubtitle(period) }}
                                            </p>

                                            <p class="mt-1 text-xs text-muted-foreground">
                                                Inicio: {{ formatShortDate(period.start_date) }}
                                                <span class="mx-1">•</span>
                                                Fin: {{ formatShortDate(period.end_date) }}
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

                                    <div class="mt-4">
                                        <div
                                            v-if="period.is_closed"
                                            class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 dark:border-slate-500/20 dark:bg-slate-500/10"
                                        >
                                            <div class="flex items-center gap-2 font-semibold text-slate-700 dark:text-slate-300">
                                                <CheckCircle2 class="size-4" />
                                                Periodo finalizado
                                            </div>
                                            <p class="mt-1 text-xs leading-5 text-slate-600 dark:text-slate-400">
                                                Esta semana ya fue cerrada y quedó registrada en el historial.
                                            </p>
                                        </div>

                                        <div
                                            v-else-if="period.can_close === false"
                                            class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4 dark:border-amber-500/20 dark:bg-amber-500/10"
                                        >
                                            <div class="flex items-start justify-between gap-3">
                                                <div>
                                                    <div class="flex items-center gap-2 font-semibold text-amber-700 dark:text-amber-300">
                                                        <AlertTriangle class="size-4" />
                                                        Requiere revisión antes de finalizar
                                                    </div>
                                                    <p class="mt-1 text-xs leading-5 text-amber-700/90 dark:text-amber-200/90">
                                                        Se detectaron {{ period.close_issues_count ?? 0 }} detalle(s) por revisar antes de cerrar esta semana.
                                                    </p>
                                                </div>

                                                <span
                                                    class="shrink-0 text-amber-600 dark:text-amber-300"
                                                    :title="'Aquí se muestran avisos o pendientes que conviene revisar antes de cerrar el periodo.'"
                                                >
                                                    <HelpCircle class="size-4" />
                                                </span>
                                            </div>

                                            <ul
                                                v-if="period.close_issues_preview?.length"
                                                class="mt-3 space-y-2"
                                            >
                                                <li
                                                    v-for="issue in period.close_issues_preview"
                                                    :key="issue"
                                                    class="flex items-start gap-2 rounded-xl bg-background/80 px-3 py-2 text-xs text-amber-800 dark:bg-background/20 dark:text-amber-100"
                                                >
                                                    <ChevronRight class="mt-0.5 size-3.5 shrink-0" />
                                                    <span>{{ issue }}</span>
                                                </li>
                                            </ul>
                                        </div>

                                        <div
                                            v-else
                                            class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-4 dark:border-emerald-500/20 dark:bg-emerald-500/10"
                                        >
                                            <div class="flex items-start justify-between gap-3">
                                                <div>
                                                    <div class="flex items-center gap-2 font-semibold text-emerald-700 dark:text-emerald-300">
                                                        <CheckCircle2 class="size-4" />
                                                        Puede finalizarse
                                                    </div>
                                                    <p class="mt-1 text-xs leading-5 text-emerald-700/90 dark:text-emerald-200/90">
                                                        Esta semana cumple con las condiciones visibles para marcarse como finalizada.
                                                    </p>
                                                </div>

                                                <span
                                                    class="shrink-0 text-emerald-600 dark:text-emerald-300"
                                                    :title="'No se detectaron incidencias críticas en la validación actual.'"
                                                >
                                                    <HelpCircle class="size-4" />
                                                </span>
                                            </div>
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

                    <div v-if="groupedOtherPeriods.length" class="space-y-5">
                        <div class="flex items-center gap-3">
                            <div class="flex size-11 items-center justify-center rounded-2xl bg-slate-500/10 text-slate-700 dark:text-slate-300">
                                <Sparkles class="size-5" />
                            </div>
                            <div>
                                <h3 class="text-base font-bold tracking-tight">Otros tipos de periodos</h3>
                                <p class="text-sm text-muted-foreground">
                                    Trimestrales, bimestrales/quincenales, semestrales y anuales se muestran por separado.
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
                                    {{ group.periods.length }} periodo(s)
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
