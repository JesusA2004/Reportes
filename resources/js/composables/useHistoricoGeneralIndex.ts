import { router, useForm } from '@inertiajs/vue3'
import {
    AlertCircle,
    CheckCircle2,
    Clock3,
    FolderOpen,
    ShieldAlert,
} from 'lucide-vue-next'
import type { LucideIcon } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { computed, ref } from 'vue'

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
    type?: string | null
    year?: number | null
    month?: number | null
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
    type: string | null
    updated_at: string | null
    can_receive_uploads: boolean
    is_derived: boolean
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

type Props = {
    periods: PeriodItem[]
    sources: SourceItem[]
    groupedUploads?: GroupedPeriodUploads[]
    currentPeriodId?: number | null
}

export function useHistoricoGeneralIndex(props: Props) {
    const fileInputRef = ref<HTMLInputElement | null>(null)
    const dragActive = ref(false)
    const deletingIds = ref<number[]>([])
    const quickFilter = ref('')

    const form = useForm<{
        period_id: string | number
        covered_period_ids: number[]
        data_source_id: string | number
        file: File | null
        notes: string
    }>({
        period_id: props.currentPeriodId ?? props.periods[0]?.id ?? '',
        covered_period_ids: [],
        data_source_id: '',
        file: null,
        notes: '',
    })

    const filters = ref({
        query: '',
    })

    const groupedMap = computed(() => {
        const map = new Map<number, GroupedPeriodUploads>()

        for (const item of props.groupedUploads ?? []) {
            map.set(item.period_id, item)
        }

        return map
    })

    const periodRows = computed<PeriodRow[]>(() => {
        return props.periods.map((period) => {
            const grouped = groupedMap.value.get(period.id)

            return {
                id: period.id,
                code: period.code,
                label: period.label,
                type: period.type ?? null,
                updated_at: grouped?.updated_at ?? period.updated_at ?? null,
                can_receive_uploads: !!period.can_receive_uploads,
                is_derived: !!period.is_derived,
                uploaded_sources_count:
                    grouped?.uploaded_sources_count ?? period.uploaded_sources_count ?? 0,
                required_sources_count:
                    grouped?.required_sources_count ??
                    period.required_sources_count ??
                    props.sources.length,
                missing_sources_count:
                    grouped?.missing_sources_count ?? period.missing_sources_count ?? props.sources.length,
                processed_count: grouped?.processed_count ?? period.processed_count ?? 0,
                pending_count: grouped?.pending_count ?? period.pending_count ?? 0,
                failed_count: grouped?.failed_count ?? period.failed_count ?? 0,
                missing_sources: grouped?.missing_sources ?? period.missing_sources ?? props.sources.map((s) => s.name),
                report_final_available:
                    grouped?.report_final_available ?? period.report_final_available ?? false,
                uploads: grouped?.uploads ?? [],
                available_week_options: period.available_week_options ?? [],
            }
        })
    })

    const filteredPeriods = computed(() => {
        const query = filters.value.query.trim().toLowerCase()

        if (!query) return periodRows.value

        return periodRows.value.filter((period) =>
            period.label.toLowerCase().includes(query) ||
            period.code.toLowerCase().includes(query),
        )
    })

    const selectedPeriodRow = computed(() => {
        return periodRows.value.find((period) => String(period.id) === String(form.period_id)) ?? null
    })

    const totalPeriods = computed(() => periodRows.value.length)
    const totalUploads = computed(() =>
        periodRows.value.reduce((acc, period) => acc + period.uploads.length, 0),
    )

    const completePeriods = computed(() =>
        periodRows.value.filter((period) =>
            period.required_sources_count > 0 &&
            period.uploaded_sources_count === period.required_sources_count,
        ).length,
    )

    const incompletePeriods = computed(() => totalPeriods.value - completePeriods.value)

    const currentProgress = computed(() => {
        if (!selectedPeriodRow.value) return 0

        return Math.round(
            (selectedPeriodRow.value.uploaded_sources_count /
                Math.max(selectedPeriodRow.value.required_sources_count, 1)) * 100,
        )
    })

    const isCurrentPeriodComplete = computed(() => {
        if (!selectedPeriodRow.value) return false

        return (
            selectedPeriodRow.value.required_sources_count > 0 &&
            selectedPeriodRow.value.uploaded_sources_count >= selectedPeriodRow.value.required_sources_count
        )
    })

    const canUploadCurrentPeriod = computed(() => {
        if (!selectedPeriodRow.value) return false
        if (!selectedPeriodRow.value.can_receive_uploads) return false
        if (isCurrentPeriodComplete.value) return false

        return true
    })

    const uploadDisabledReason = computed(() => {
        if (!selectedPeriodRow.value) return 'Selecciona un periodo.'
        if (!selectedPeriodRow.value.can_receive_uploads) return 'Este periodo se alimenta automáticamente desde semanas. Aquí no se suben archivos.'
        if (isCurrentPeriodComplete.value) return 'Periodo completo'

        return ''
    })

    const selectedFileName = computed(() => form.file?.name ?? 'Ningún archivo seleccionado')

    const selectedUploads = computed(() => {
        const uploads = selectedPeriodRow.value?.uploads ?? []
        const query = quickFilter.value.trim().toLowerCase()

        if (!query) return uploads

        return uploads.filter((upload) =>
            (upload.original_name ?? '').toLowerCase().includes(query) ||
            (upload.source_name ?? '').toLowerCase().includes(query) ||
            (upload.covered_period_labels ?? []).join(' ').toLowerCase().includes(query),
        )
    })

    const uploadedSourceCodesForCurrentPeriod = computed(() => {
        return new Set(
            (selectedPeriodRow.value?.uploads ?? [])
                .map((upload) => upload.source_code)
                .filter(Boolean),
        )
    })

    const sourceOptions = computed(() => {
        return props.sources.map((source) => ({
            ...source,
            disabled: uploadedSourceCodesForCurrentPeriod.value.has(source.code),
        }))
    })

    const selectedSourceCards = computed(() => {
        const uploads = selectedPeriodRow.value?.uploads ?? []

        return props.sources.map((source) => {
            const found = uploads.find((upload) => upload.source_code === source.code)

            if (!found) {
                return {
                    ...source,
                    statusLabel: 'Pendiente',
                    statusClass: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
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

    const availableWeekOptions = computed(() => selectedPeriodRow.value?.available_week_options ?? [])

    const selectedWeekIds = computed({
        get: () => form.covered_period_ids,
        set: (value: number[]) => {
            form.covered_period_ids = value
        },
    })

    const formatUploadStatus = (status: UploadItem['status']) => {
        if (status === 'processed') return 'Procesado'
        if (status === 'failed') return 'Error'
        if (status === 'processing') return 'Procesando'
        return 'Pendiente'
    }

    const uploadStatusClass = (status: UploadItem['status']) => {
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

    const currentStatusLabel = (period: PeriodRow) => {
        if (period.is_derived) return 'Automático'
        if (period.uploaded_sources_count === 0) return 'Sin carga'
        if (period.failed_count > 0) return 'Con error'
        if (period.pending_count > 0) return 'Procesando'
        if (period.missing_sources_count > 0) return 'Incompleto'
        return 'Completo'
    }

    const currentStatusClass = (period: PeriodRow) => {
        if (period.is_derived) {
            return 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300'
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

    const currentStatusIcon = (period: PeriodRow): LucideIcon => {
        if (period.is_derived) return CheckCircle2
        if (period.uploaded_sources_count === 0) return FolderOpen
        if (period.failed_count > 0) return ShieldAlert
        if (period.pending_count > 0) return Clock3
        if (period.missing_sources_count > 0) return AlertCircle
        return CheckCircle2
    }

    const selectPeriod = (periodId: number) => {
        form.period_id = periodId
        form.data_source_id = ''
        form.file = null
        form.notes = ''
        form.clearErrors()
        quickFilter.value = ''

        const period = periodRows.value.find((item) => item.id === periodId)

        form.covered_period_ids = period?.can_receive_uploads
            ? (period.available_week_options ?? []).map((week) => week.id)
            : []
    }

    const openFileDialog = () => {
        if (!canUploadCurrentPeriod.value || form.processing) return
        fileInputRef.value?.click()
    }

    const assignFile = (file: File | null) => {
        if (!file) return
        form.file = file
    }

    const onFileChange = (event: Event) => {
        const input = event.target as HTMLInputElement | null
        assignFile(input?.files?.[0] ?? null)
    }

    const onDragEnter = () => {
        if (!canUploadCurrentPeriod.value || form.processing) return
        dragActive.value = true
    }

    const onDragLeave = () => {
        dragActive.value = false
    }

    const onDragOver = () => {
        if (!canUploadCurrentPeriod.value || form.processing) return
        dragActive.value = true
    }

    const onDrop = (event: DragEvent) => {
        dragActive.value = false

        if (!canUploadCurrentPeriod.value || form.processing) return

        const file = event.dataTransfer?.files?.[0] ?? null
        assignFile(file)
    }

    const submitLabel = computed(() => {
        if (form.processing) return 'Subiendo...'
        return 'Subir archivo'
    })

    const submit = async () => {
        if (!selectedPeriodRow.value) return

        form.period_id = selectedPeriodRow.value.id

        if (!form.covered_period_ids.length) {
            await Swal.fire({
                title: 'Faltan semanas',
                text: 'Selecciona al menos una semana cubierta por el archivo.',
                icon: 'warning',
                confirmButtonText: 'Entendido',
            })
            return
        }

        Swal.fire({
            title: 'Subiendo archivo...',
            text: 'Estamos registrando el archivo y sus semanas cubiertas.',
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
                const weeks = [...form.covered_period_ids]
                form.reset('file', 'notes', 'data_source_id')
                form.covered_period_ids = weeks
                dragActive.value = false

                if (fileInputRef.value) {
                    fileInputRef.value.value = ''
                }

                Swal.fire({
                    title: 'Archivo subido',
                    text: 'El archivo quedó ligado a las semanas seleccionadas.',
                    icon: 'success',
                    confirmButtonText: 'Entendido',
                })
            },
            onError: () => {
                Swal.fire({
                    title: 'No se pudo subir',
                    text: 'Revisa la fuente, el archivo y las semanas cubiertas.',
                    icon: 'error',
                    confirmButtonText: 'Cerrar',
                })
            },
        })
    }

    const deleteUpload = async (uploadId: number) => {
        const result = await Swal.fire({
            title: '¿Eliminar archivo?',
            text: 'Se quitará el archivo y su relación con las semanas cubiertas.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar',
            reverseButtons: true,
        })

        if (!result.isConfirmed) return

        deletingIds.value.push(uploadId)

        Swal.fire({
            title: 'Eliminando archivo...',
            text: 'Espera un momento.',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading()
            },
        })

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
            onError: () => {
                Swal.fire({
                    title: 'No se pudo eliminar',
                    text: 'Ocurrió un problema al eliminar el archivo.',
                    icon: 'error',
                    confirmButtonText: 'Cerrar',
                })
            },
            onFinish: () => {
                deletingIds.value = deletingIds.value.filter((id) => id !== uploadId)
            },
        })
    }

    const analyzeUpload = async (uploadId: number) => {
        const result = await Swal.fire({
            title: '¿Analizar archivo?',
            text: 'Se ejecutará el procesamiento del archivo.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, analizar',
            cancelButtonText: 'Cancelar',
            reverseButtons: true,
        })

        if (!result.isConfirmed) return

        Swal.fire({
            title: 'Analizando archivo...',
            text: 'Estamos procesando el archivo seleccionado.',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => Swal.showLoading(),
        })

        router.post(`/historico-general/${uploadId}/analizar`, {}, {
            preserveScroll: true,
            onSuccess: () => {
                Swal.fire({
                    title: 'Archivo analizado',
                    text: 'El procesamiento finalizó correctamente.',
                    icon: 'success',
                    confirmButtonText: 'Entendido',
                })
            },
            onError: () => {
                Swal.fire({
                    title: 'No se pudo analizar',
                    text: 'Ocurrió un problema durante el análisis.',
                    icon: 'error',
                    confirmButtonText: 'Cerrar',
                })
            },
        })
    }

    const isDeletingId = (uploadId: number) => deletingIds.value.includes(uploadId)

    if (!form.covered_period_ids.length && selectedPeriodRow.value?.can_receive_uploads) {
        form.covered_period_ids = (selectedPeriodRow.value.available_week_options ?? []).map((week) => week.id)
    }

    return {
        form,
        filters,
        selectedFileName,
        selectedPeriodRow,
        filteredPeriods,
        selectedUploads,
        selectedSourceCards,
        totalPeriods,
        totalUploads,
        completePeriods,
        incompletePeriods,
        currentProgress,
        canUploadCurrentPeriod,
        isCurrentPeriodComplete,
        uploadDisabledReason,
        submitLabel,
        dragActive,
        onFileChange,
        onDrop,
        onDragEnter,
        onDragLeave,
        onDragOver,
        submit,
        selectPeriod,
        fileInputRef,
        openFileDialog,
        sourceOptions,
        availableWeekOptions,
        selectedWeekIds,
        formatUploadStatus,
        uploadStatusClass,
        currentStatusLabel,
        currentStatusClass,
        currentStatusIcon,
        deleteUpload,
        analyzeUpload,
        isDeletingId,
        quickFilter,
    }
}
