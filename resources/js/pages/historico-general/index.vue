<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import {
    AlertCircle,
    CalendarDays,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    Clock3,
    FileSpreadsheet,
    FileUp,
    FolderSearch2,
    Layers3,
    UploadCloud,
} from 'lucide-vue-next'

import InputError from '@/components/InputError.vue'

interface PeriodItem {
    id: number
    code: string
    label: string
    year?: number
    month?: number
    is_closed?: boolean
    uploaded_sources_count?: number
    required_sources_count?: number
    missing_sources_count?: number
    processed_count?: number
    pending_count?: number
    failed_count?: number
    updated_at?: string | null
    missing_sources?: string[]
    report_final_available?: boolean
}

interface SourceItem {
    id: number
    code: string
    name: string
    description?: string | null
}

interface UploadItem {
    id: number
    original_name: string
    status: 'pending' | 'processing' | 'processed' | 'failed'
    uploaded_at?: string | null
    notes?: string | null
    source_code?: string | null
    source_name?: string | null
}

interface GroupedPeriodUploads {
    period_id: number
    period_code: string
    period_label: string
    updated_at?: string | null
    uploaded_sources_count?: number
    required_sources_count?: number
    missing_sources_count?: number
    processed_count?: number
    pending_count?: number
    failed_count?: number
    missing_sources?: string[]
    report_final_available?: boolean
    uploads: UploadItem[]
}

const props = withDefaults(
    defineProps<{
        periods: PeriodItem[]
        sources: SourceItem[]
        groupedUploads?: GroupedPeriodUploads[]
        currentPeriodId?: number | null
    }>(),
    {
        groupedUploads: () => [],
        currentPeriodId: null,
    },
)

const form = useForm<{
    period_id: string | number
    data_source_id: string | number
    file: File | null
    notes: string
}>({
    period_id: props.currentPeriodId ?? '',
    data_source_id: '',
    file: null,
    notes: '',
})

const selectedFileName = computed(() => form.file?.name ?? 'Seleccionar archivo')

const groupedMap = computed(() => {
    const map = new Map<number, GroupedPeriodUploads>()

    for (const item of props.groupedUploads) {
        map.set(item.period_id, item)
    }

    return map
})

const periodRows = computed(() => {
    return props.periods.map((period) => {
        const grouped = groupedMap.value.get(period.id)

        return {
            id: period.id,
            code: period.code,
            label: period.label,
            updated_at: grouped?.updated_at ?? period.updated_at ?? null,
            uploaded_sources_count:
                grouped?.uploaded_sources_count ?? period.uploaded_sources_count ?? 0,
            required_sources_count:
                grouped?.required_sources_count ??
                period.required_sources_count ??
                props.sources.length,
            missing_sources_count:
                grouped?.missing_sources_count ?? period.missing_sources_count ?? 0,
            processed_count: grouped?.processed_count ?? period.processed_count ?? 0,
            pending_count: grouped?.pending_count ?? period.pending_count ?? 0,
            failed_count: grouped?.failed_count ?? period.failed_count ?? 0,
            missing_sources: grouped?.missing_sources ?? period.missing_sources ?? [],
            report_final_available:
                grouped?.report_final_available ?? period.report_final_available ?? false,
            uploads: grouped?.uploads ?? [],
        }
    })
})

const selectedPeriodRow = computed(() =>
    periodRows.value.find((period) => String(period.id) === String(form.period_id)),
)

const totalPeriods = computed(() => periodRows.value.length)

const completePeriods = computed(() =>
    periodRows.value.filter((period) => (period.missing_sources_count ?? 0) === 0).length,
)

const incompletePeriods = computed(() =>
    periodRows.value.filter((period) => (period.missing_sources_count ?? 0) > 0).length,
)

const totalUploads = computed(() =>
    periodRows.value.reduce((acc, period) => acc + (period.uploads?.length ?? 0), 0),
)

const openPeriods = ref<number[]>(periodRows.value.slice(0, 2).map((item) => item.id))

const isOpen = (periodId: number) => openPeriods.value.includes(periodId)

const togglePeriod = (periodId: number) => {
    if (isOpen(periodId)) {
        openPeriods.value = openPeriods.value.filter((id) => id !== periodId)
        return
    }

    openPeriods.value.push(periodId)
}

const onFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement | null
    form.file = input?.files?.[0] ?? null
}

const submit = () => {
    form.post('/historico-general', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset('file', 'notes')
        },
    })
}

const groupedSourceStatus = (
    period: (typeof periodRows.value)[number],
    source: SourceItem,
) => {
    const found = period.uploads.find((upload) => upload.source_code === source.code)

    if (!found) {
        return {
            label: 'Faltante',
            classes: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
        }
    }

    if (found.status === 'processed') {
        return {
            label: 'Cargado',
            classes:
                'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
        }
    }

    if (found.status === 'failed') {
        return {
            label: 'Error',
            classes: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
        }
    }

    return {
        label: 'Pendiente',
        classes: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    }
}

const globalStatusLabel = (period: (typeof periodRows.value)[number]) => {
    const missing = period.missing_sources_count ?? 0
    const failed = period.failed_count ?? 0
    const pending = period.pending_count ?? 0

    if (failed > 0) return 'Con incidencias'
    if (missing > 0) return `Faltan ${missing} reporte${missing === 1 ? '' : 's'}`
    if (pending > 0) return 'En proceso'
    return 'Completo'
}

const globalStatusClasses = (period: (typeof periodRows.value)[number]) => {
    const missing = period.missing_sources_count ?? 0
    const failed = period.failed_count ?? 0
    const pending = period.pending_count ?? 0

    if (failed > 0) {
        return 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300'
    }

    if (missing > 0) {
        return 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'
    }

    if (pending > 0) {
        return 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300'
    }

    return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'
}
</script>

<template>
    <div class="app-page w-full px-3 py-3 sm:px-4 sm:py-4 md:px-5 lg:px-6 xl:px-7 2xl:px-8">
        <div class="space-y-5">
            <section class="app-card overflow-hidden">
                <div class="border-b px-4 py-4 sm:px-5 lg:px-6">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="space-y-2">
                            <div
                                class="inline-flex items-center gap-2 rounded-full border border-primary/15 bg-primary/5 px-3 py-1.5 text-xs font-semibold text-primary"
                            >
                                <Layers3 class="size-3.5" />
                                Histórico por periodos
                            </div>

                            <div class="space-y-1">
                                <h1 class="text-2xl font-extrabold tracking-tight sm:text-3xl">
                                    Histórico General
                                </h1>
                                <p class="max-w-3xl text-sm leading-6 text-muted-foreground sm:text-base">
                                    Administra la carga mensual de reportes, identifica faltantes
                                    y consulta el histórico agrupado por mes.
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:min-w-[560px]">
                            <div class="app-card-soft px-4 py-3">
                                <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                    <CalendarDays class="size-4" />
                                    Periodos
                                </div>
                                <p class="mt-2 text-sm font-bold sm:text-base">
                                    {{ totalPeriods }}
                                </p>
                            </div>

                            <div class="app-card-soft px-4 py-3">
                                <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                    <CheckCircle2 class="size-4" />
                                    Completos
                                </div>
                                <p class="mt-2 text-sm font-bold sm:text-base">
                                    {{ completePeriods }}
                                </p>
                            </div>

                            <div class="app-card-soft px-4 py-3">
                                <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                    <AlertCircle class="size-4" />
                                    Con faltantes
                                </div>
                                <p class="mt-2 text-sm font-bold sm:text-base">
                                    {{ incompletePeriods }}
                                </p>
                            </div>

                            <div class="app-card-soft px-4 py-3">
                                <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                    <FileSpreadsheet class="size-4" />
                                    Reportes
                                </div>
                                <p class="mt-2 text-sm font-bold sm:text-base">
                                    {{ totalUploads }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid gap-5 p-4 sm:p-5 xl:grid-cols-12 xl:p-6">
                    <section class="app-card-soft xl:col-span-8">
                        <div class="border-b px-4 py-4 sm:px-5">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h2 class="text-lg font-bold tracking-tight">
                                        Carga rápida del periodo
                                    </h2>
                                    <p class="mt-1 text-sm text-muted-foreground">
                                        Selecciona el mes y sube el reporte correspondiente.
                                    </p>
                                </div>

                                <span
                                    v-if="selectedPeriodRow"
                                    class="inline-flex w-fit rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="globalStatusClasses(selectedPeriodRow)"
                                >
                                    {{ globalStatusLabel(selectedPeriodRow) }}
                                </span>
                            </div>
                        </div>

                        <form @submit.prevent="submit" class="space-y-5 p-4 sm:p-5">
                            <div class="grid grid-cols-1 gap-4 lg:grid-cols-[1.15fr_1fr]">
                                <div class="space-y-2">
                                    <label for="period_id" class="text-sm font-semibold">Periodo</label>
                                    <div class="relative">
                                        <CalendarDays
                                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                        />
                                        <select
                                            id="period_id"
                                            v-model="form.period_id"
                                            class="app-input appearance-none pl-10"
                                        >
                                            <option value="">Selecciona un periodo</option>
                                            <option
                                                v-for="period in periods"
                                                :key="period.id"
                                                :value="period.id"
                                            >
                                                {{ period.label }}
                                            </option>
                                        </select>
                                    </div>
                                    <InputError :message="form.errors.period_id" />
                                </div>

                                <div class="space-y-2">
                                    <label for="data_source_id" class="text-sm font-semibold">Reporte</label>
                                    <select
                                        id="data_source_id"
                                        v-model="form.data_source_id"
                                        class="app-input appearance-none"
                                    >
                                        <option value="">Selecciona el reporte</option>
                                        <option
                                            v-for="source in sources"
                                            :key="source.id"
                                            :value="source.id"
                                        >
                                            {{ source.name }}
                                        </option>
                                    </select>
                                    <InputError :message="form.errors.data_source_id" />
                                </div>
                            </div>

                            <div class="space-y-2">
                                <label class="text-sm font-semibold">Archivo</label>
                                <label
                                    for="file"
                                    class="flex min-h-16 cursor-pointer items-center gap-3 rounded-2xl border border-dashed bg-background px-4 py-3 text-sm shadow-sm transition hover:border-primary/40 hover:bg-primary/5"
                                >
                                    <div
                                        class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-primary/10 text-primary"
                                    >
                                        <FileUp class="size-5" />
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <p class="truncate font-semibold">{{ selectedFileName }}</p>
                                        <p class="text-xs text-muted-foreground">
                                            Formato Excel (.xls, .xlsx, .xlsm)
                                        </p>
                                    </div>

                                    <span class="hidden font-semibold text-primary sm:inline">
                                        Seleccionar
                                    </span>

                                    <input
                                        id="file"
                                        type="file"
                                        class="hidden"
                                        accept=".xls,.xlsx,.xlsm"
                                        @change="onFileChange"
                                    />
                                </label>
                                <InputError :message="form.errors.file" />
                            </div>

                            <div class="space-y-2">
                                <label for="notes" class="text-sm font-semibold">Notas</label>
                                <textarea
                                    id="notes"
                                    v-model="form.notes"
                                    rows="3"
                                    class="app-textarea"
                                    placeholder="Observaciones opcionales del archivo o del periodo."
                                />
                                <InputError :message="form.errors.notes" />
                            </div>

                            <div
                                v-if="selectedPeriodRow"
                                class="rounded-2xl border border-border/70 bg-background/70 px-4 py-4"
                            >
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                    <div class="space-y-1">
                                        <p class="text-sm font-semibold">
                                            {{ selectedPeriodRow.label }}
                                        </p>
                                        <p class="text-sm text-muted-foreground">
                                            {{ selectedPeriodRow.uploaded_sources_count }} de
                                            {{ selectedPeriodRow.required_sources_count }} reportes cargados
                                        </p>
                                    </div>

                                    <span
                                        class="inline-flex w-fit rounded-full px-2.5 py-1 text-xs font-semibold"
                                        :class="globalStatusClasses(selectedPeriodRow)"
                                    >
                                        {{ globalStatusLabel(selectedPeriodRow) }}
                                    </span>
                                </div>

                                <div v-if="selectedPeriodRow.missing_sources.length" class="mt-4">
                                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                        Reportes faltantes
                                    </p>
                                    <div class="flex flex-wrap gap-2">
                                        <span
                                            v-for="missing in selectedPeriodRow.missing_sources"
                                            :key="missing"
                                            class="inline-flex rounded-full bg-rose-100 px-2.5 py-1 text-xs font-semibold text-rose-700 dark:bg-rose-500/15 dark:text-rose-300"
                                        >
                                            {{ missing }}
                                        </span>
                                    </div>
                                </div>

                                <div v-else class="mt-4">
                                    <span
                                        class="inline-flex rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"
                                    >
                                        Periodo completo
                                    </span>
                                </div>
                            </div>

                            <div class="flex justify-end border-t pt-4">
                                <button
                                    type="submit"
                                    class="app-btn app-btn-primary h-11 px-5 shadow-sm"
                                    :disabled="form.processing"
                                >
                                    <UploadCloud class="size-4" />
                                    {{ form.processing ? 'Subiendo...' : 'Subir reporte' }}
                                </button>
                            </div>
                        </form>
                    </section>

                    <aside class="grid gap-5 xl:col-span-4">
                        <section class="app-card-soft">
                            <div class="border-b px-4 py-4">
                                <h3 class="text-base font-bold tracking-tight">Estado por mes</h3>
                            </div>

                            <div class="space-y-3 p-4">
                                <div
                                    v-for="period in periodRows.slice(0, 4)"
                                    :key="period.id"
                                    class="rounded-2xl border border-border/70 bg-background/80 px-4 py-3"
                                >
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold">
                                                {{ period.label }}
                                            </p>
                                            <p class="mt-1 text-xs text-muted-foreground">
                                                {{ period.uploaded_sources_count }} /
                                                {{ period.required_sources_count }}
                                                reportes
                                            </p>
                                        </div>

                                        <span
                                            class="inline-flex rounded-full px-2 py-1 text-[11px] font-semibold"
                                            :class="globalStatusClasses(period)"
                                        >
                                            {{ globalStatusLabel(period) }}
                                        </span>
                                    </div>

                                    <div
                                        v-if="period.missing_sources.length"
                                        class="mt-3 flex flex-wrap gap-2"
                                    >
                                        <span
                                            v-for="missing in period.missing_sources.slice(0, 2)"
                                            :key="missing"
                                            class="inline-flex rounded-full bg-rose-100 px-2 py-1 text-[11px] font-semibold text-rose-700 dark:bg-rose-500/15 dark:text-rose-300"
                                        >
                                            {{ missing }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </aside>
                </div>
            </section>

            <section class="app-card overflow-hidden">
                <div class="border-b px-4 py-4 sm:px-5 lg:px-6">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-lg font-bold tracking-tight">Histórico por periodo</h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Meses agrupados con sus reportes cargados, faltantes y acceso al reporte final.
                            </p>
                        </div>

                        <span class="app-badge-muted">
                            {{ periodRows.length }} periodo{{ periodRows.length === 1 ? '' : 's' }}
                        </span>
                    </div>
                </div>

                <div v-if="periodRows.length" class="divide-y">
                    <article
                        v-for="period in periodRows"
                        :key="period.id"
                        class="transition-colors"
                    >
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-4 px-4 py-4 text-left hover:bg-muted/20 sm:px-5 lg:px-6"
                            @click="togglePeriod(period.id)"
                        >
                            <div class="flex min-w-0 items-start gap-3">
                                <div class="pt-0.5 text-muted-foreground">
                                    <ChevronDown v-if="isOpen(period.id)" class="size-4" />
                                    <ChevronRight v-else class="size-4" />
                                </div>

                                <div class="min-w-0 space-y-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-base font-bold tracking-tight sm:text-lg">
                                            {{ period.label }}
                                        </h3>

                                        <span
                                            class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                            :class="globalStatusClasses(period)"
                                        >
                                            {{ globalStatusLabel(period) }}
                                        </span>
                                    </div>

                                    <div class="flex flex-wrap gap-2 text-xs text-muted-foreground sm:text-sm">
                                        <span>
                                            {{ period.uploaded_sources_count }} de
                                            {{ period.required_sources_count }} reportes cargados
                                        </span>
                                        <span>•</span>
                                        <span>Última actualización: {{ period.updated_at ?? '—' }}</span>
                                    </div>

                                    <div v-if="period.missing_sources.length" class="flex flex-wrap gap-2">
                                        <span
                                            v-for="missing in period.missing_sources"
                                            :key="missing"
                                            class="inline-flex rounded-full bg-rose-100 px-2.5 py-1 text-xs font-semibold text-rose-700 dark:bg-rose-500/15 dark:text-rose-300"
                                        >
                                            Falta {{ missing }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="hidden shrink-0 items-center gap-2 lg:flex">
                                <a
                                    :href="`/reporte-final?period=${period.id}`"
                                    class="app-btn app-btn-primary h-10 px-4"
                                >
                                    Reporte final
                                </a>
                            </div>
                        </button>

                        <div
                            v-if="isOpen(period.id)"
                            class="border-t bg-muted/10 px-4 py-4 sm:px-5 lg:px-6"
                        >
                            <div class="grid gap-5 xl:grid-cols-[1.2fr_.8fr]">
                                <div class="space-y-4">
                                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                        <div
                                            v-for="source in sources"
                                            :key="source.id"
                                            class="rounded-2xl border border-border/70 bg-background px-4 py-4 shadow-sm"
                                        >
                                            <div class="flex items-start justify-between gap-3">
                                                <div class="min-w-0">
                                                    <p class="text-sm font-semibold">
                                                        {{ source.name }}
                                                    </p>
                                                    <p class="mt-1 text-xs text-muted-foreground">
                                                        {{ source.description || 'Reporte mensual' }}
                                                    </p>
                                                </div>

                                                <span
                                                    class="inline-flex rounded-full px-2 py-1 text-[11px] font-semibold"
                                                    :class="groupedSourceStatus(period, source).classes"
                                                >
                                                    {{ groupedSourceStatus(period, source).label }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    <div
                                        v-if="period.uploads.length"
                                        class="overflow-x-auto rounded-2xl border border-border/70 bg-background"
                                    >
                                        <table class="min-w-full text-sm">
                                            <thead class="bg-muted/40 text-left text-muted-foreground">
                                                <tr>
                                                    <th class="px-4 py-3 font-semibold">Reporte</th>
                                                    <th class="px-4 py-3 font-semibold">Archivo</th>
                                                    <th class="px-4 py-3 font-semibold">Fecha</th>
                                                    <th class="px-4 py-3 font-semibold">Estatus</th>
                                                </tr>
                                            </thead>

                                            <tbody>
                                                <tr
                                                    v-for="upload in period.uploads"
                                                    :key="upload.id"
                                                    class="border-t hover:bg-muted/20"
                                                >
                                                    <td class="px-4 py-3 font-medium">
                                                        {{ upload.source_name || '—' }}
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <div class="max-w-[340px] truncate">
                                                            {{ upload.original_name }}
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3 text-muted-foreground">
                                                        {{ upload.uploaded_at ?? '—' }}
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <span
                                                            class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                                            :class="{
                                                                'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300':
                                                                    upload.status === 'pending' || upload.status === 'processing',
                                                                'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300':
                                                                    upload.status === 'processed',
                                                                'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300':
                                                                    upload.status === 'failed',
                                                            }"
                                                        >
                                                            {{
                                                                upload.status === 'processed'
                                                                    ? 'Procesado'
                                                                    : upload.status === 'failed'
                                                                      ? 'Error'
                                                                      : 'Pendiente'
                                                            }}
                                                        </span>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div
                                        v-else
                                        class="rounded-2xl border border-border/70 bg-background px-4 py-8 text-center"
                                    >
                                        <p class="font-semibold">Sin reportes cargados</p>
                                        <p class="mt-1 text-sm text-muted-foreground">
                                            Este periodo aún no tiene archivos registrados.
                                        </p>
                                    </div>
                                </div>

                                <aside class="space-y-4">
                                    <div class="rounded-2xl border border-border/70 bg-background px-4 py-4 shadow-sm">
                                        <h4 class="text-sm font-bold tracking-tight">Resumen del mes</h4>

                                        <div class="mt-4 space-y-3">
                                            <div class="flex items-center justify-between text-sm">
                                                <span class="text-muted-foreground">Cargados</span>
                                                <span class="font-semibold">
                                                    {{ period.uploaded_sources_count }}
                                                </span>
                                            </div>

                                            <div class="flex items-center justify-between text-sm">
                                                <span class="text-muted-foreground">Faltantes</span>
                                                <span class="font-semibold">
                                                    {{ period.missing_sources_count }}
                                                </span>
                                            </div>

                                            <div class="flex items-center justify-between text-sm">
                                                <span class="text-muted-foreground">Reporte final</span>
                                                <span class="font-semibold">
                                                    {{ period.report_final_available ? 'Disponible' : 'Pendiente' }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="rounded-2xl border border-border/70 bg-background px-4 py-4 shadow-sm">
                                        <h4 class="text-sm font-bold tracking-tight">Acciones</h4>

                                        <div class="mt-4 grid gap-2">
                                            <a
                                                :href="`/reporte-final?period=${period.id}`"
                                                class="app-btn app-btn-primary h-10 justify-center"
                                            >
                                                Reporte final
                                            </a>
                                        </div>
                                    </div>
                                </aside>
                            </div>
                        </div>
                    </article>
                </div>

                <div v-else class="px-4 py-12 text-center sm:px-5">
                    <div class="mx-auto flex max-w-sm flex-col items-center gap-3">
                        <div
                            class="flex size-12 items-center justify-center rounded-2xl bg-muted text-muted-foreground"
                        >
                            <FolderSearch2 class="size-5" />
                        </div>
                        <div class="space-y-1">
                            <p class="font-semibold">Aún no hay periodos cargados</p>
                            <p class="text-sm text-muted-foreground">
                                Cuando subas reportes mensuales aparecerán agrupados aquí.
                            </p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
