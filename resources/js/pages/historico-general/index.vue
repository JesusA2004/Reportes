<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import Swal from 'sweetalert2'
import {
    AlertCircle,
    ArrowUpRight,
    CalendarDays,
    CalendarRange,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    Clock3,
    FileSpreadsheet,
    FolderOpen,
    Inbox,
    Info,
    Layers3,
    LoaderCircle,
    PlayCircle,
    Search,
    Sparkles,
    Trash2,
    UploadCloud,
} from 'lucide-vue-next'

import InputError from '@/components/InputError.vue'
import AppLayout from '@/layouts/AppLayout.vue'

type WeekOption = {
    id: number
    label: string
    sequence?: number | null
    start_date?: string | null
    end_date?: string | null
}

type PeriodItem = {
    id: number
    code: string
    label: string
    type?: 'weekly' | 'bimonthly' | 'quarterly' | 'semiannual' | 'annual' | string | null
    year?: number | null
    month?: number | null
    sequence?: number | null
    start_date?: string | null
    end_date?: string | null
    is_closed?: boolean
    can_receive_uploads?: boolean
    is_derived?: boolean
    uploaded_sources_count?: number
    required_sources_count?: number
    missing_sources_count?: number
    processed_count?: number
    pending_count?: number
    failed_count?: number
    updated_at?: string | null
    missing_sources?: string[]
    report_final_available?: boolean
    available_week_options?: WeekOption[]
}

type SourceItem = {
    id: number
    code: string
    name: string
    description?: string | null
}

type UploadItem = {
    id: number
    original_name: string
    status: 'pending' | 'processing' | 'processed' | 'failed'
    uploaded_at?: string | null
    notes?: string | null
    source_code?: string | null
    source_name?: string | null
    covered_period_ids?: number[]
    covered_period_labels?: string[]
    covered_week_ids?: number[]
    covered_week_labels?: string[]
    last_process_run?: {
        status?: 'pending' | 'running' | 'success' | 'failed' | string
        rows_read?: number
        rows_inserted?: number
        rows_with_errors?: number
        finished_at?: string | null
    } | null
}

type GroupedPeriodUploads = {
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

type PeriodRow = {
    id: number
    code: string
    label: string
    type: string
    year: number | null
    month: number | null
    sequence: number | null
    start_date: string | null
    end_date: string | null
    can_receive_uploads: boolean
    is_derived: boolean
    updated_at: string | null
    uploaded_sources_count: number
    required_sources_count: number
    missing_sources_count: number
    processed_count: number
    pending_count: number
    failed_count: number
    missing_sources: string[]
    report_final_available: boolean
    uploads: UploadItem[]
    available_week_options: WeekOption[]
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

defineOptions({
    layout: AppLayout,
})

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

const fileInputRef = ref<HTMLInputElement | null>(null)
const dragActive = ref(false)
const deletingIds = ref<number[]>([])
const quickFilter = ref('')
const periodSearch = ref('')
const collapsedWeeklyGroups = ref<Record<string, boolean>>({})

const form = useForm<{
    period_id: string | number
    data_source_id: string | number
    file: File | null
    notes: string
    covered_period_ids: number[]
}>({
    period_id: props.currentPeriodId ?? '',
    data_source_id: '',
    file: null,
    notes: '',
    covered_period_ids: [],
})

const groupedMap = computed(() => {
    const map = new Map<number, GroupedPeriodUploads>()

    for (const item of props.groupedUploads ?? []) {
        map.set(item.period_id, item)
    }

    return map
})

const weekOptionMap = computed(() => {
    const map = new Map<number, WeekOption>()

    for (const period of props.periods) {
        const optionFromPeriod: WeekOption = {
            id: period.id,
            label: period.label,
            sequence: period.sequence ?? null,
            start_date: period.start_date ?? null,
            end_date: period.end_date ?? null,
        }

        map.set(period.id, optionFromPeriod)

        for (const option of period.available_week_options ?? []) {
            map.set(option.id, option)
        }
    }

    return map
})

const periodRows = computed<PeriodRow[]>(() => {
    return props.periods.map((period) => {
        const grouped = groupedMap.value.get(period.id)
        const directWeekOption = weekOptionMap.value.get(period.id)

        return {
            id: period.id,
            code: period.code,
            label: period.label,
            type: period.type ?? 'weekly',
            year: period.year ?? null,
            month: period.month ?? null,
            sequence: period.sequence ?? null,
            start_date: period.start_date ?? directWeekOption?.start_date ?? null,
            end_date: period.end_date ?? directWeekOption?.end_date ?? null,
            can_receive_uploads: Boolean(period.can_receive_uploads ?? (period.type === 'weekly')),
            is_derived: Boolean(period.is_derived ?? (period.type !== 'weekly')),
            updated_at: grouped?.updated_at ?? period.updated_at ?? null,
            uploaded_sources_count: grouped?.uploaded_sources_count ?? period.uploaded_sources_count ?? 0,
            required_sources_count: grouped?.required_sources_count ?? period.required_sources_count ?? props.sources.length,
            missing_sources_count: grouped?.missing_sources_count ?? period.missing_sources_count ?? props.sources.length,
            processed_count: grouped?.processed_count ?? period.processed_count ?? 0,
            pending_count: grouped?.pending_count ?? period.pending_count ?? 0,
            failed_count: grouped?.failed_count ?? period.failed_count ?? 0,
            missing_sources: grouped?.missing_sources ?? period.missing_sources ?? props.sources.map((s) => s.name),
            report_final_available: grouped?.report_final_available ?? period.report_final_available ?? false,
            uploads: grouped?.uploads ?? [],
            available_week_options: period.available_week_options ?? [],
        }
    })
})

const weeklyPeriods = computed(() =>
    periodRows.value.filter((period) => period.type === 'weekly'),
)

const automaticPeriods = computed(() =>
    periodRows.value.filter((period) => period.type !== 'weekly'),
)

const filteredWeeklyPeriods = computed(() => {
    const query = periodSearch.value.trim().toLowerCase()

    if (!query) return weeklyPeriods.value

    return weeklyPeriods.value.filter((period) => {
        return (
            period.label.toLowerCase().includes(query) ||
            period.code.toLowerCase().includes(query) ||
            formatRange(period.start_date, period.end_date).toLowerCase().includes(query)
        )
    })
})

function weeklyMonthGroupTitle(period: PeriodRow) {
    if (period.month && period.year) {
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

    return 'Sin mes'
}

const groupedWeeklyPeriods = computed(() => {
    const groups = filteredWeeklyPeriods.value.reduce<
        Array<{
            key: string
            title: string
            periods: PeriodRow[]
            totalUploads: number
            completeCount: number
        }>
    >((acc, period) => {
        const title = weeklyMonthGroupTitle(period)
        const key = `${period.year ?? 'x'}-${period.month ?? 'x'}-${title}`

        let found = acc.find((item) => item.key === key)

        if (!found) {
            found = {
                key,
                title,
                periods: [],
                totalUploads: 0,
                completeCount: 0,
            }
            acc.push(found)
        }

        found.periods.push(period)
        found.totalUploads += period.uploads.length

        if (period.uploaded_sources_count >= period.required_sources_count && period.required_sources_count > 0) {
            found.completeCount += 1
        }

        return acc
    }, [])

    return groups.map((group) => ({
        ...group,
        periods: [...group.periods].sort((a, b) => {
            const aSeq = a.sequence ?? 0
            const bSeq = b.sequence ?? 0
            return aSeq - bSeq
        }),
    }))
})

function toggleWeeklyGroup(key: string) {
    collapsedWeeklyGroups.value[key] = !collapsedWeeklyGroups.value[key]
}

function isWeeklyGroupCollapsed(key: string) {
    return !!collapsedWeeklyGroups.value[key]
}

const selectedPeriodRow = computed(() => {
    const id = String(form.period_id || '')
    return periodRows.value.find((period) => String(period.id) === id) ?? null
})

const selectedIsWeekly = computed(() => selectedPeriodRow.value?.type === 'weekly')
const selectedIsAutomatic = computed(() => Boolean(selectedPeriodRow.value?.is_derived))
const totalPeriods = computed(() => periodRows.value.length)

const totalUploads = computed(() =>
    periodRows.value.reduce((acc, period) => acc + period.uploads.length, 0),
)

const completePeriods = computed(() =>
    weeklyPeriods.value.filter((period) => period.required_sources_count > 0 && period.uploaded_sources_count >= period.required_sources_count).length,
)

const incompletePeriods = computed(() =>
    weeklyPeriods.value.length - completePeriods.value,
)

function currentStatusLabel(period: PeriodRow) {
    if (period.is_derived) return 'Automático'
    if (period.uploaded_sources_count === 0) return 'Sin carga'
    if (period.failed_count > 0) return 'Con error'
    if (period.pending_count > 0) return 'Procesando'
    if (period.missing_sources_count > 0) return 'Incompleto'
    return 'Completo'
}

function currentStatusClass(period: PeriodRow) {
    if (period.is_derived) {
        return 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300'
    }

    if (period.uploaded_sources_count === 0) {
        return 'bg-slate-100 text-slate-700 dark:bg-slate-500/15 dark:text-slate-300'
    }

    if (period.failed_count > 0) {
        return 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300'
    }

    if (period.pending_count > 0) {
        return 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300'
    }

    if (period.missing_sources_count > 0) {
        return 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'
    }

    return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'
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

function formatRange(start?: string | null, end?: string | null) {
    if (!start && !end) return 'Sin rango definido'
    if (start && !end) return `Desde ${formatLongDate(start)}`
    if (!start && end) return `Hasta ${formatLongDate(end)}`
    return `${formatLongDate(start)} al ${formatLongDate(end)}`
}

const currentProgress = computed(() => {
    if (!selectedPeriodRow.value) return 0

    return Math.round(
        (selectedPeriodRow.value.uploaded_sources_count / Math.max(selectedPeriodRow.value.required_sources_count, 1)) * 100,
    )
})

const selectedUploads = computed(() => {
    const uploads = selectedPeriodRow.value?.uploads ?? []
    const query = quickFilter.value.trim().toLowerCase()

    if (!query) return uploads

    return uploads.filter((upload) => {
        return (
            (upload.original_name ?? '').toLowerCase().includes(query) ||
            (upload.source_name ?? '').toLowerCase().includes(query) ||
            (upload.notes ?? '').toLowerCase().includes(query)
        )
    })
})

const selectedFileName = computed(() => form.file?.name ?? 'Ningún archivo seleccionado')

const selectedSourceCards = computed(() => {
    const uploads = selectedPeriodRow.value?.uploads ?? []

    return props.sources.map((source) => {
        const found = uploads.find((upload) => upload.source_code === source.code)

        if (!found) {
            return {
                ...source,
                statusLabel: selectedIsAutomatic.value ? 'Automático' : 'Pendiente',
                statusClass: selectedIsAutomatic.value
                    ? 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300'
                    : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
            }
        }

        if (found.status === 'processed') {
            return {
                ...source,
                statusLabel: 'Cargada',
                statusClass: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
            }
        }

        if (found.status === 'failed') {
            return {
                ...source,
                statusLabel: 'Error',
                statusClass: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
            }
        }

        return {
            ...source,
            statusLabel: 'En proceso',
            statusClass: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
        }
    })
})

const currentAvailableWeekOptions = computed<WeekOption[]>(() => {
    if (!selectedPeriodRow.value || !selectedIsWeekly.value) return []

    if (selectedPeriodRow.value.available_week_options.length) {
        return [...selectedPeriodRow.value.available_week_options].sort((a, b) => {
            const aSeq = a.sequence ?? 0
            const bSeq = b.sequence ?? 0
            return aSeq - bSeq
        })
    }

    return weeklyPeriods.value
        .filter((week) =>
            week.year === selectedPeriodRow.value?.year &&
            week.month === selectedPeriodRow.value?.month,
        )
        .map((week) => ({
            id: week.id,
            label: week.label,
            sequence: week.sequence,
            start_date: week.start_date,
            end_date: week.end_date,
        }))
        .sort((a, b) => (a.sequence ?? 0) - (b.sequence ?? 0))
})

const selectedSourceCode = computed(() => {
    const selectedId = Number(form.data_source_id)

    if (!selectedId) return null

    const source = props.sources.find((item) => item.id === selectedId)
    return source?.code ?? null
})

const takenCoveredPeriodIdsForSelectedSource = computed(() => {
    const sourceCode = selectedSourceCode.value
    if (!sourceCode || !selectedPeriodRow.value || !selectedIsWeekly.value) return new Set<number>()

    const sameMonthWeeks = weeklyPeriods.value.filter((week) =>
        week.year === selectedPeriodRow.value?.year &&
        week.month === selectedPeriodRow.value?.month,
    )

    const ids = new Set<number>()

    for (const week of sameMonthWeeks) {
        for (const upload of week.uploads) {
            if (upload.source_code !== sourceCode) continue

            const coveredIds =
                upload.covered_period_ids ??
                upload.covered_week_ids ??
                []

            if (coveredIds.length) {
                for (const id of coveredIds) {
                    ids.add(id)
                }
            } else {
                ids.add(week.id)
            }
        }
    }

    return ids
})

function isCoverageOptionDisabled(optionId: number) {
    return takenCoveredPeriodIdsForSelectedSource.value.has(optionId)
}

function syncCoveredPeriodsWithSelectedPeriod() {
    if (!selectedIsWeekly.value) {
        form.covered_period_ids = []
        return
    }

    if (!form.covered_period_ids.length && selectedPeriodRow.value) {
        form.covered_period_ids = [selectedPeriodRow.value.id]
    }
}

watch(
    () => form.data_source_id,
    () => {
        form.covered_period_ids = form.covered_period_ids.filter(
            (id) => !isCoverageOptionDisabled(id),
        )

        if (selectedPeriodRow.value && form.covered_period_ids.length === 0) {
            if (!isCoverageOptionDisabled(selectedPeriodRow.value.id)) {
                form.covered_period_ids = [selectedPeriodRow.value.id]
            }
        }
    },
)

function selectPeriod(periodId: number) {
    form.period_id = periodId
    form.data_source_id = ''
    form.file = null
    form.notes = ''
    form.clearErrors()
    quickFilter.value = ''

    const selected = periodRows.value.find((period) => period.id === periodId)

    if (selected?.type === 'weekly') {
        form.covered_period_ids = [selected.id]
    } else {
        form.covered_period_ids = []
    }
}

if (props.currentPeriodId) {
    const initial = periodRows.value.find((period) => period.id === props.currentPeriodId)
    if (initial?.type === 'weekly') {
        form.covered_period_ids = [initial.id]
    }
}

const canUploadCurrentPeriod = computed(() => {
    if (!selectedPeriodRow.value) return false
    if (!selectedPeriodRow.value.can_receive_uploads) return false
    return true
})

const uploadDisabledReason = computed(() => {
    if (!selectedPeriodRow.value) return 'Selecciona una semana.'
    if (!selectedPeriodRow.value.can_receive_uploads) {
        return 'Este periodo se alimenta automáticamente con semanas.'
    }
    return ''
})

const submitLabel = computed(() => form.processing ? 'Subiendo...' : 'Subir archivo')

function formatUploadStatus(status: UploadItem['status']) {
    if (status === 'processed') return 'Procesado'
    if (status === 'failed') return 'Error'
    if (status === 'processing') return 'Procesando'
    return 'Pendiente'
}

function uploadStatusClass(status: UploadItem['status']) {
    if (status === 'processed') {
        return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'
    }

    if (status === 'failed') {
        return 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300'
    }

    if (status === 'processing') {
        return 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300'
    }

    return 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'
}

function openFileDialog() {
    if (!canUploadCurrentPeriod.value || form.processing) return
    fileInputRef.value?.click()
}

function assignFile(file: File | null) {
    if (!file) return
    form.file = file
}

function onFileChange(event: Event) {
    const input = event.target as HTMLInputElement | null
    assignFile(input?.files?.[0] ?? null)
}

function onDragEnter() {
    if (!canUploadCurrentPeriod.value || form.processing) return
    dragActive.value = true
}

function onDragLeave() {
    dragActive.value = false
}

function onDragOver() {
    if (!canUploadCurrentPeriod.value || form.processing) return
    dragActive.value = true
}

function onDrop(event: DragEvent) {
    dragActive.value = false

    if (!canUploadCurrentPeriod.value || form.processing) return

    const file = event.dataTransfer?.files?.[0] ?? null
    assignFile(file)
}

function firstErrorMessage() {
    const errors = form.errors as Record<string, string | undefined>
    return (
        errors.file ||
        errors.data_source_id ||
        errors.covered_period_ids ||
        errors.notes ||
        'Revisa la fuente, archivo y semanas seleccionadas.'
    )
}

async function submit() {
    if (!selectedPeriodRow.value || !selectedIsWeekly.value) return

    syncCoveredPeriodsWithSelectedPeriod()

    if (!form.data_source_id) {
        await Swal.fire({
            title: 'Selecciona una fuente',
            text: 'Debes elegir si el archivo corresponde a NOI, Gastos, Lendus u otra fuente.',
            icon: 'warning',
            confirmButtonText: 'Entendido',
        })
        return
    }

    if (!form.covered_period_ids.length) {
        await Swal.fire({
            title: 'Selecciona semanas',
            text: 'Debes indicar qué semanas cubre este reporte.',
            icon: 'warning',
            confirmButtonText: 'Entendido',
        })
        return
    }

    if (!form.file) {
        await Swal.fire({
            title: 'Selecciona un archivo',
            text: 'Debes elegir el archivo que vas a subir.',
            icon: 'warning',
            confirmButtonText: 'Entendido',
        })
        return
    }

    Swal.fire({
        title: 'Subiendo archivo...',
        text: 'Estamos registrando el archivo y las semanas que cubre.',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        didOpen: () => {
            Swal.showLoading()
        },
    })

    form.post('/historico-general', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset('file', 'notes', 'data_source_id')
            form.covered_period_ids = selectedPeriodRow.value ? [selectedPeriodRow.value.id] : []
            dragActive.value = false

            if (fileInputRef.value) {
                fileInputRef.value.value = ''
            }

            Swal.fire({
                title: 'Archivo subido',
                text: 'El archivo se registró correctamente.',
                icon: 'success',
                confirmButtonText: 'Entendido',
            })
        },
        onError: async () => {
            await Swal.fire({
                title: 'No se pudo subir',
                text: firstErrorMessage(),
                icon: 'error',
                confirmButtonText: 'Cerrar',
            })
        },
    })
}

async function deleteUpload(uploadId: number) {
    const result = await Swal.fire({
        title: '¿Eliminar archivo?',
        text: 'Podrás volver a subirlo después.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar',
        reverseButtons: true,
    })

    if (!result.isConfirmed) return

    deletingIds.value.push(uploadId)

    router.delete(`/historico-general/${uploadId}`, {
        preserveScroll: true,
        onSuccess: () => {
            Swal.fire({
                title: 'Archivo eliminado',
                text: 'El archivo se eliminó correctamente.',
                icon: 'success',
                confirmButtonText: 'Entendido',
            })
        },
        onFinish: () => {
            deletingIds.value = deletingIds.value.filter((id) => id !== uploadId)
        },
    })
}

function isDeletingId(uploadId: number) {
    return deletingIds.value.includes(uploadId)
}

async function analyzeUpload(uploadId: number) {
    const result = await Swal.fire({
        title: '¿Analizar archivo?',
        text: 'Se procesará el archivo para generar o actualizar información.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sí, analizar',
        cancelButtonText: 'Cancelar',
        reverseButtons: true,
    })

    if (!result.isConfirmed) return

    let finished = false
    let pollTimer: number | null = null

    const renderProgress = async () => {
        try {
            const response = await fetch(`/historico-general/${uploadId}/progreso`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
            })

            if (!response.ok) {
                return
            }

            const data = await response.json()

            const rowsRead = Number(data.rows_read ?? 0)
            const rowsInserted = Number(data.rows_inserted ?? 0)
            const rowsSkipped = Number(data.rows_skipped ?? 0)
            const rowsWithErrors = Number(data.rows_with_errors ?? 0)
            const status = String(data.status ?? 'running')
            const log = String(data.log ?? 'Procesando...')
            const totalKnown = Math.max(rowsRead, rowsInserted + rowsSkipped + rowsWithErrors, 1)
            const progress = Math.min(
                100,
                Math.max(
                    5,
                    Math.round(((rowsInserted + rowsSkipped + rowsWithErrors) / totalKnown) * 100),
                ),
            )

            Swal.update({
                html: `
                    <div class="space-y-4 text-left">
                        <p class="text-sm text-slate-600 dark:text-slate-300">
                            ${log}
                        </p>

                        <div class="h-3 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                            <div
                                class="h-full rounded-full bg-emerald-500 transition-all duration-300"
                                style="width: ${progress}%"
                            ></div>
                        </div>

                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div class="rounded-xl border border-slate-200 px-3 py-2 dark:border-slate-700">
                                <div class="text-slate-500">Leídas</div>
                                <div class="text-lg font-semibold">${rowsRead}</div>
                            </div>
                            <div class="rounded-xl border border-slate-200 px-3 py-2 dark:border-slate-700">
                                <div class="text-slate-500">Insertadas</div>
                                <div class="text-lg font-semibold">${rowsInserted}</div>
                            </div>
                            <div class="rounded-xl border border-slate-200 px-3 py-2 dark:border-slate-700">
                                <div class="text-slate-500">Omitidas</div>
                                <div class="text-lg font-semibold">${rowsSkipped}</div>
                            </div>
                            <div class="rounded-xl border border-slate-200 px-3 py-2 dark:border-slate-700">
                                <div class="text-slate-500">Errores</div>
                                <div class="text-lg font-semibold">${rowsWithErrors}</div>
                            </div>
                        </div>

                        <div class="text-xs text-slate-500">
                            Estado: ${status}
                        </div>
                    </div>
                `,
            })

            if (status === 'success') {
                finished = true
                if (pollTimer) window.clearInterval(pollTimer)

                await Swal.fire({
                    title: 'Archivo analizado',
                    text: log || 'El procesamiento finalizó correctamente.',
                    icon: rowsInserted > 0 ? 'success' : 'warning',
                    confirmButtonText: 'Entendido',
                })

                router.visit(window.location.pathname + window.location.search, {
                    method: 'get',
                    preserveScroll: true,
                    preserveState: true,
                    replace: true,
                })
            }

            if (status === 'failed') {
                finished = true
                if (pollTimer) window.clearInterval(pollTimer)

                await Swal.fire({
                    title: 'No se pudo analizar',
                    text: log || 'Ocurrió un problema durante el análisis.',
                    icon: 'error',
                    confirmButtonText: 'Cerrar',
                })

                router.visit(window.location.pathname + window.location.search, {
                    method: 'get',
                    preserveScroll: true,
                    preserveState: true,
                    replace: true,
                })
            }
        } catch {
            // silencio temporal mientras sigue el polling
        }
    }

    Swal.fire({
        title: 'Analizando archivo...',
        html: `
            <div class="space-y-4 text-left">
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Iniciando proceso...
                </p>
                <div class="h-3 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                    <div class="h-full w-[5%] rounded-full bg-emerald-500 transition-all duration-300"></div>
                </div>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-xl border border-slate-200 px-3 py-2 dark:border-slate-700">
                        <div class="text-slate-500">Leídas</div>
                        <div class="text-lg font-semibold">0</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 px-3 py-2 dark:border-slate-700">
                        <div class="text-slate-500">Insertadas</div>
                        <div class="text-lg font-semibold">0</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 px-3 py-2 dark:border-slate-700">
                        <div class="text-slate-500">Omitidas</div>
                        <div class="text-lg font-semibold">0</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 px-3 py-2 dark:border-slate-700">
                        <div class="text-slate-500">Errores</div>
                        <div class="text-lg font-semibold">0</div>
                    </div>
                </div>
            </div>
        `,
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        didOpen: async () => {
            fetch(`/historico-general/${uploadId}/analizar`, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute('content') ?? '',
                },
                credentials: 'same-origin',
            }).catch(() => null)

            await renderProgress()
            pollTimer = window.setInterval(async () => {
                if (!finished) {
                    await renderProgress()
                }
            }, 1200)
        },
        willClose: () => {
            if (pollTimer) window.clearInterval(pollTimer)
        },
    })
}
</script>

<template>
    <Head title="Histórico general" />

    <div class="app-page px-3 py-3 sm:px-4 sm:py-4 md:px-5 lg:px-6 xl:px-7 2xl:px-8">
        <div class="space-y-6">
            <section class="app-card overflow-hidden">
                <div class="relative">
                    <div class="absolute inset-0 bg-gradient-to-br from-primary/10 via-transparent to-primary/5" />

                    <div class="relative p-4 sm:p-5 lg:p-6">
                        <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                            <div class="space-y-4">
                                <div
                                    class="inline-flex w-fit items-center gap-2 rounded-full border border-primary/15 bg-primary/5 px-3 py-1.5 text-xs font-semibold text-primary"
                                >
                                    <Sparkles class="size-3.5" />
                                    Gestión de cargas por semanas
                                </div>

                                <div class="space-y-2">
                                    <h1 class="text-2xl font-extrabold tracking-tight sm:text-3xl lg:text-4xl">
                                        Histórico General
                                    </h1>
                                    <p class="max-w-3xl text-sm leading-6 text-muted-foreground sm:text-base">
                                        Sube archivos por semanas. NOI y Lendus Cobranza alimentan el cruce de colaboradores,
                                        sucursales, altas, bajas e incidencias. Después del análisis, revisa
                                        <span class="font-semibold text-foreground">Asignación sucursal</span>
                                        para validar el resultado del periodo.
                                    </p>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3 lg:w-[460px]">
                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <CalendarDays class="size-4" />
                                        Periodos
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ totalPeriods }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <FileSpreadsheet class="size-4" />
                                        Archivos
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ totalUploads }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <CheckCircle2 class="size-4" />
                                        Semanas completas
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ completePeriods }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <AlertCircle class="size-4" />
                                        Semanas pendientes
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ incompletePeriods }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-[340px_minmax(0,1fr)]">
                <aside class="space-y-4">
                    <section class="app-card overflow-hidden">
                        <div class="border-b px-4 py-4">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <h2 class="text-base font-bold tracking-tight">Semanas por mes</h2>
                                    <p class="mt-1 text-xs text-muted-foreground">
                                        Solo aquí se carga información directamente.
                                    </p>
                                </div>

                                <span class="app-badge-muted">
                                    {{ filteredWeeklyPeriods.length }}
                                </span>
                            </div>

                            <div class="relative mt-4">
                                <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                <input
                                    v-model="periodSearch"
                                    type="text"
                                    class="app-input h-10 pl-10"
                                    placeholder="Buscar semana..."
                                />
                            </div>
                        </div>

                        <div class="max-h-[520px] overflow-y-auto p-3 space-y-3">
                            <section
                                v-for="group in groupedWeeklyPeriods"
                                :key="group.key"
                                class="rounded-[24px] border border-border/70 bg-background"
                            >
                                <button
                                    type="button"
                                    class="flex w-full items-center justify-between gap-3 px-4 py-4 text-left"
                                    @click="toggleWeeklyGroup(group.key)"
                                >
                                    <div>
                                        <p class="text-sm font-bold">{{ group.title }}</p>
                                        <p class="mt-1 text-xs text-muted-foreground">
                                            {{ group.periods.length }} semana(s) · {{ group.totalUploads }} archivo(s)
                                        </p>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">
                                            {{ group.completeCount }} completas
                                        </span>

                                        <ChevronDown
                                            v-if="!isWeeklyGroupCollapsed(group.key)"
                                            class="size-4 text-muted-foreground"
                                        />
                                        <ChevronRight
                                            v-else
                                            class="size-4 text-muted-foreground"
                                        />
                                    </div>
                                </button>

                                <div
                                    v-show="!isWeeklyGroupCollapsed(group.key)"
                                    class="space-y-2 border-t p-3"
                                >
                                    <button
                                        v-for="period in group.periods"
                                        :key="period.id"
                                        type="button"
                                        @click="selectPeriod(period.id)"
                                        class="group w-full rounded-[20px] border px-4 py-3 text-left shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md"
                                        :class="
                                            selectedPeriodRow?.id === period.id
                                                ? 'border-primary/30 bg-primary/5'
                                                : 'border-border bg-background hover:bg-muted/30'
                                        "
                                    >
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-semibold">
                                                    {{ period.label }}
                                                </p>
                                                <p class="mt-1 text-xs text-muted-foreground">
                                                    {{ formatRange(period.start_date, period.end_date) }}
                                                </p>
                                            </div>

                                            <span
                                                class="inline-flex rounded-full px-2 py-1 text-[11px] font-semibold"
                                                :class="currentStatusClass(period)"
                                            >
                                                {{ currentStatusLabel(period) }}
                                            </span>
                                        </div>
                                    </button>
                                </div>
                            </section>

                            <div
                                v-if="!groupedWeeklyPeriods.length"
                                class="rounded-[22px] border border-dashed bg-background px-4 py-8 text-center"
                            >
                                <Inbox class="mx-auto size-5 text-muted-foreground" />
                                <p class="mt-3 text-sm font-semibold">Sin semanas</p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Ajusta la búsqueda para encontrar otra semana.
                                </p>
                            </div>
                        </div>
                    </section>

                    <section class="app-card overflow-hidden">
                        <div class="border-b px-4 py-4">
                            <div class="flex items-center gap-2">
                                <Layers3 class="size-4 text-primary" />
                                <h2 class="text-base font-bold tracking-tight">Periodos automáticos</h2>
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Se alimentan solos con la data de semanas.
                            </p>
                        </div>

                        <div class="max-h-[320px] overflow-y-auto p-3 space-y-2">
                            <button
                                v-for="period in automaticPeriods"
                                :key="period.id"
                                type="button"
                                @click="selectPeriod(period.id)"
                                class="w-full rounded-[20px] border px-4 py-3 text-left transition-all duration-200"
                                :class="
                                    selectedPeriodRow?.id === period.id
                                        ? 'border-sky-300 bg-sky-50 dark:border-sky-500/30 dark:bg-sky-500/10'
                                        : 'border-border bg-background hover:bg-muted/30'
                                "
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold">
                                            {{ period.label }}
                                        </p>
                                        <p class="mt-1 text-xs text-muted-foreground">
                                            {{ formatRange(period.start_date, period.end_date) }}
                                        </p>
                                    </div>

                                    <span class="inline-flex rounded-full bg-sky-100 px-2 py-1 text-[11px] font-semibold text-sky-700 dark:bg-sky-500/15 dark:text-sky-300">
                                        Auto
                                    </span>
                                </div>
                            </button>

                            <div
                                v-if="!automaticPeriods.length"
                                class="rounded-[22px] border border-dashed bg-background px-4 py-6 text-center"
                            >
                                <p class="text-sm font-semibold">Aún no hay agrupados</p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Aparecerán conforme existan semanas suficientes.
                                </p>
                            </div>
                        </div>
                    </section>
                </aside>

                <section class="space-y-6">
                    <section v-if="selectedPeriodRow" class="app-card overflow-hidden">
                        <div class="border-b px-4 py-4 sm:px-5">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h2 class="text-xl font-bold tracking-tight">
                                            {{ selectedPeriodRow.label }}
                                        </h2>

                                        <span
                                            class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold"
                                            :class="currentStatusClass(selectedPeriodRow)"
                                        >
                                            {{ currentStatusLabel(selectedPeriodRow) }}
                                        </span>

                                        <span
                                            v-if="selectedIsAutomatic"
                                            class="inline-flex items-center gap-1 rounded-full bg-sky-100 px-2.5 py-1 text-xs font-semibold text-sky-700 dark:bg-sky-500/15 dark:text-sky-300"
                                        >
                                            <Layers3 class="size-3.5" />
                                            Automático
                                        </span>
                                    </div>

                                    <p class="mt-2 text-sm text-muted-foreground">
                                        {{ formatRange(selectedPeriodRow.start_date, selectedPeriodRow.end_date) }}
                                    </p>
                                </div>

                                <div class="grid gap-3 sm:grid-cols-3 lg:w-[430px]">
                                    <div class="rounded-2xl border bg-background px-3 py-3">
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                            Cargados
                                        </p>
                                        <p class="mt-2 text-xl font-extrabold">
                                            {{ selectedPeriodRow.uploaded_sources_count }}
                                        </p>
                                    </div>

                                    <div class="rounded-2xl border bg-background px-3 py-3">
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                            Faltantes
                                        </p>
                                        <p class="mt-2 text-xl font-extrabold">
                                            {{ selectedPeriodRow.missing_sources_count }}
                                        </p>
                                    </div>

                                    <div class="rounded-2xl border bg-background px-3 py-3">
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                            Avance
                                        </p>
                                        <p class="mt-2 text-xl font-extrabold">{{ currentProgress }}%</p>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 h-2 overflow-hidden rounded-full bg-muted">
                                <div
                                    class="h-full rounded-full bg-primary transition-all duration-500"
                                    :style="{ width: `${currentProgress}%` }"
                                />
                            </div>
                        </div>

                        <div class="grid gap-6 p-4 sm:p-5 2xl:grid-cols-[minmax(0,1fr)_320px]">
                            <div class="space-y-5">
                                <div
                                    v-if="selectedIsAutomatic"
                                    class="rounded-[26px] border border-sky-200 bg-sky-50 px-5 py-5 dark:border-sky-500/20 dark:bg-sky-500/10"
                                >
                                    <div class="flex items-start gap-3">
                                        <Info class="mt-0.5 size-5 text-sky-600 dark:text-sky-300" />
                                        <div>
                                            <p class="font-semibold text-sky-800 dark:text-sky-200">
                                                Este periodo no recibe archivos directos
                                            </p>
                                            <p class="mt-2 text-sm leading-6 text-sky-700/90 dark:text-sky-200/90">
                                                Los bimestres, trimestres, semestres y anual se construyen automáticamente con los reportes cargados en las semanas que forman parte del periodo.
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <template v-else>
                                    <div class="grid gap-4 md:grid-cols-[1fr_1fr]">
                                        <div class="space-y-2">
                                            <label class="text-sm font-semibold">Semana base</label>
                                            <div class="app-input flex items-center">
                                                {{ selectedPeriodRow.label }}
                                            </div>
                                        </div>

                                        <div class="space-y-2">
                                            <label for="data_source_id" class="text-sm font-semibold">Fuente</label>
                                            <select
                                                id="data_source_id"
                                                v-model="form.data_source_id"
                                                class="app-input"
                                                :disabled="!canUploadCurrentPeriod || form.processing"
                                            >
                                                <option
                                                    v-for="source in props.sources"
                                                    :key="source.id"
                                                    :value="source.id"
                                                >
                                                    {{ source.name }}
                                                </option>
                                            </select>
                                            <InputError :message="form.errors.data_source_id" />
                                        </div>
                                    </div>

                                    <div class="space-y-3">
                                        <div class="flex items-center gap-2">
                                            <CalendarRange class="size-4 text-primary" />
                                            <label class="text-sm font-semibold">
                                                Semanas cubiertas por este archivo
                                            </label>
                                        </div>

                                        <p class="text-sm text-muted-foreground">
                                            Selecciona exactamente las semanas incluidas en este reporte. Las ya cubiertas para la fuente elegida aparecen bloqueadas.
                                        </p>

                                        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                            <label
                                                v-for="week in currentAvailableWeekOptions"
                                                :key="week.id"
                                                class="flex items-start gap-3 rounded-2xl border px-4 py-3 transition"
                                                :class="
                                                    isCoverageOptionDisabled(week.id)
                                                        ? 'cursor-not-allowed border-border/60 bg-muted/30 opacity-60'
                                                        : 'cursor-pointer border-border/70 bg-background hover:bg-muted/30'
                                                "
                                            >
                                                <input
                                                    v-model="form.covered_period_ids"
                                                    type="checkbox"
                                                    :value="week.id"
                                                    class="mt-1"
                                                    :disabled="form.processing || isCoverageOptionDisabled(week.id)"
                                                />
                                                <div>
                                                    <p class="text-sm font-semibold">{{ week.label }}</p>
                                                    <p class="mt-1 text-xs text-muted-foreground">
                                                        {{ formatRange(week.start_date, week.end_date) }}
                                                    </p>
                                                    <p
                                                        v-if="isCoverageOptionDisabled(week.id)"
                                                        class="mt-1 text-[11px] font-medium text-amber-700 dark:text-amber-300"
                                                    >
                                                        Ya cubierta para esta fuente
                                                    </p>
                                                </div>
                                            </label>
                                        </div>
                                        <InputError :message="form.errors.covered_period_ids" />
                                    </div>

                                    <div
                                        class="rounded-[30px] border border-dashed px-5 py-6 shadow-sm transition-all duration-200"
                                        :class="
                                            dragActive
                                                ? 'border-primary bg-primary/5 ring-2 ring-primary/10'
                                                : 'border-border bg-background hover:border-primary/30 hover:bg-muted/20'
                                        "
                                        @drop.prevent="onDrop"
                                        @dragenter.prevent="onDragEnter"
                                        @dragleave.prevent="onDragLeave"
                                        @dragover.prevent="onDragOver"
                                    >
                                        <input
                                            ref="fileInputRef"
                                            type="file"
                                            class="hidden"
                                            accept=".xls,.xlsx,.xlsm"
                                            @change="onFileChange"
                                        />

                                        <div class="flex flex-col items-center justify-center text-center">
                                            <div
                                                class="flex size-16 items-center justify-center rounded-3xl bg-primary/10 text-primary transition-transform duration-200"
                                                :class="{ 'scale-110': dragActive || form.processing }"
                                            >
                                                <LoaderCircle
                                                    v-if="form.processing"
                                                    class="size-7 animate-spin"
                                                />
                                                <UploadCloud
                                                    v-else
                                                    class="size-7"
                                                />
                                            </div>

                                            <h3 class="mt-4 text-base font-bold tracking-tight">
                                                {{
                                                    form.processing
                                                        ? 'Subiendo archivo...'
                                                        : dragActive
                                                          ? 'Suelta tu archivo aquí'
                                                          : 'Arrastra y suelta tu archivo'
                                                }}
                                            </h3>

                                            <p class="mt-2 max-w-xl text-sm text-muted-foreground">
                                                Formatos permitidos: .xls, .xlsx, .xlsm
                                            </p>

                                            <button
                                                type="button"
                                                class="mt-4 text-sm font-semibold text-primary transition hover:opacity-80"
                                                @click="openFileDialog"
                                                :disabled="!canUploadCurrentPeriod || form.processing"
                                            >
                                                {{ selectedFileName }}
                                            </button>
                                        </div>
                                    </div>

                                    <div class="space-y-2">
                                        <label for="notes" class="text-sm font-semibold">Notas</label>
                                        <textarea
                                            id="notes"
                                            v-model="form.notes"
                                            rows="3"
                                            class="app-textarea"
                                            placeholder="Observaciones opcionales del archivo."
                                            :disabled="form.processing"
                                        />
                                        <InputError :message="form.errors.notes" />
                                        <InputError :message="form.errors.file" />
                                    </div>

                                    <div class="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="text-xs text-muted-foreground">
                                            {{ selectedUploads.length }} archivo{{ selectedUploads.length === 1 ? '' : 's' }}
                                            cargado{{ selectedUploads.length === 1 ? '' : 's' }} en este periodo
                                        </div>

                                        <button
                                            type="button"
                                            class="app-btn app-btn-primary h-11 px-5 transition-all duration-200 hover:-translate-y-0.5"
                                            :disabled="!canUploadCurrentPeriod || form.processing"
                                            @click="submit"
                                        >
                                            <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                                            <UploadCloud v-else class="size-4" />
                                            {{ submitLabel }}
                                        </button>
                                    </div>
                                </template>
                            </div>

                            <aside class="space-y-4">
                                <div class="rounded-[28px] border border-border/70 bg-background px-4 py-4 shadow-sm">
                                    <p class="text-sm font-semibold">Fuentes del periodo</p>

                                    <div class="mt-4 space-y-3">
                                        <div
                                            v-for="source in selectedSourceCards"
                                            :key="source.id"
                                            class="rounded-2xl border border-border/70 bg-muted/20 px-3 py-3"
                                        >
                                            <div class="flex items-start justify-between gap-3">
                                                <div class="min-w-0">
                                                    <p class="text-sm font-semibold">{{ source.name }}</p>
                                                    <p class="mt-1 text-xs text-muted-foreground">
                                                        {{ source.description || 'Fuente base' }}
                                                    </p>
                                                </div>

                                                <span
                                                    class="inline-flex rounded-full px-2 py-1 text-[11px] font-semibold"
                                                    :class="source.statusClass"
                                                >
                                                    {{ source.statusLabel }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="rounded-[28px] border border-border/70 bg-background px-4 py-4 shadow-sm">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-sm font-semibold">Salida final</p>
                                        <ArrowUpRight class="size-4 text-muted-foreground" />
                                    </div>

                                    <p class="mt-2 text-sm text-muted-foreground">
                                        Cuando NOI, cobranza y demás fuentes base estén analizadas y validadas en Asignación sucursal, podrás pasar al reporte final.
                                    </p>

                                    <a
                                        :href="selectedPeriodRow ? `/reportes-mensuales?period=${selectedPeriodRow.id}` : '#'"
                                        class="app-btn app-btn-secondary mt-4 h-11 w-full justify-between px-4"
                                    >
                                        <span>Ir a reporte final</span>
                                        <ChevronRight class="size-4" />
                                    </a>
                                </div>
                            </aside>
                        </div>
                    </section>

                    <section v-if="selectedPeriodRow" class="app-card overflow-hidden">
                        <div class="border-b px-4 py-4 sm:px-5">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                <div>
                                    <h3 class="text-lg font-bold tracking-tight">Archivos del periodo</h3>
                                    <p class="mt-1 text-sm text-muted-foreground">
                                        Revisa, analiza o elimina archivos ya cargados.
                                    </p>
                                </div>

                                <div class="relative w-full lg:max-w-sm">
                                    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <input
                                        v-model="quickFilter"
                                        type="text"
                                        class="app-input h-10 pl-10"
                                        placeholder="Buscar archivo o fuente..."
                                    />
                                </div>
                            </div>
                        </div>

                        <div v-if="selectedUploads.length" class="grid gap-3 p-4 sm:p-5 md:grid-cols-2 2xl:grid-cols-3">
                            <article
                                v-for="upload in selectedUploads"
                                :key="upload.id"
                                class="group rounded-[26px] border border-border/70 bg-background px-4 py-4 shadow-sm transition-all duration-200 hover:-translate-y-1 hover:shadow-md"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold">
                                            {{ upload.source_name || 'Sin fuente' }}
                                        </p>
                                        <p class="mt-1 truncate text-xs text-muted-foreground">
                                            {{ upload.original_name }}
                                        </p>
                                    </div>

                                    <span
                                        class="inline-flex rounded-full px-2 py-1 text-[11px] font-semibold"
                                        :class="uploadStatusClass(upload.status)"
                                    >
                                        {{ formatUploadStatus(upload.status) }}
                                    </span>
                                </div>

                                <div class="mt-4 flex items-center gap-2 text-xs text-muted-foreground">
                                    <Clock3 class="size-3.5" />
                                    {{ upload.uploaded_at ?? 'Sin fecha registrada' }}
                                </div>

                                <div
                                    v-if="(upload.covered_period_labels?.length || upload.covered_week_labels?.length)"
                                    class="mt-3 rounded-xl border border-border/70 bg-muted/20 px-3 py-3"
                                >
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                        Cubre semanas
                                    </p>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <span
                                            v-for="weekLabel in (upload.covered_period_labels?.length ? upload.covered_period_labels : upload.covered_week_labels)"
                                            :key="weekLabel"
                                            class="rounded-full bg-primary/10 px-2.5 py-1 text-[11px] font-semibold text-primary"
                                        >
                                            {{ weekLabel }}
                                        </span>
                                    </div>
                                </div>

                                <p
                                    v-if="upload.notes"
                                    class="mt-3 line-clamp-2 text-xs leading-5 text-muted-foreground"
                                >
                                    {{ upload.notes }}
                                </p>

                                <div
                                    v-if="upload.last_process_run"
                                    class="mt-3 rounded-xl border border-border/70 bg-muted/20 px-3 py-2 text-[11px] text-muted-foreground"
                                >
                                    <p>
                                        Análisis: {{ upload.last_process_run.status || '—' }} ·
                                        Leídas {{ upload.last_process_run.rows_read ?? 0 }} ·
                                        Insertadas {{ upload.last_process_run.rows_inserted ?? 0 }} ·
                                        Errores {{ upload.last_process_run.rows_with_errors ?? 0 }}
                                    </p>
                                </div>

                                <div class="mt-4 flex items-center justify-end gap-2 border-t pt-4">
                                    <button
                                        type="button"
                                        class="app-btn h-10 border border-sky-200 bg-sky-50 px-4 text-sky-700 transition-all duration-200 hover:-translate-y-0.5 hover:bg-sky-100 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300"
                                        @click="analyzeUpload(upload.id)"
                                    >
                                        <PlayCircle class="size-4" />
                                        Analizar
                                    </button>

                                    <button
                                        type="button"
                                        class="app-btn h-10 border border-rose-200 bg-rose-50 px-4 text-rose-700 transition-all duration-200 hover:-translate-y-0.5 hover:bg-rose-100 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300"
                                        :disabled="isDeletingId(upload.id)"
                                        @click="deleteUpload(upload.id)"
                                    >
                                        <LoaderCircle
                                            v-if="isDeletingId(upload.id)"
                                            class="size-4 animate-spin"
                                        />
                                        <Trash2 v-else class="size-4" />
                                        Quitar
                                    </button>
                                </div>
                            </article>
                        </div>

                        <div
                            v-else
                            class="px-4 py-10 text-center sm:px-5"
                        >
                            <FolderOpen class="mx-auto size-6 text-muted-foreground" />
                            <p class="mt-3 text-sm font-semibold">Aún no hay archivos cargados</p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{
                                    selectedIsAutomatic
                                        ? 'Este periodo se alimenta automáticamente con semanas.'
                                        : 'Sube primero una fuente para comenzar esta semana.'
                                }}
                            </p>
                        </div>

                        <div v-if="selectedPeriodRow" class="border-t px-4 py-4 sm:px-5">
                            <a
                                class="app-btn app-btn-primary h-11 px-5"
                                :href="`/reportes-mensuales/${selectedPeriodRow.id}/radiografia.xlsx`"
                            >
                                Generar radiografía
                                <ArrowUpRight class="size-4" />
                            </a>
                        </div>
                    </section>
                </section>
            </div>
        </div>
    </div>
</template>
