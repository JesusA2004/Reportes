import { computed, ref } from 'vue'
import Swal from 'sweetalert2'
import { router, useForm } from '@inertiajs/vue3'
import {
    AlertCircle,
    CheckCircle2,
    Clock3,
    ShieldAlert,
    FolderOpen,
    type LucideIcon,
} from 'lucide-vue-next'

type PeriodItem = {
    id: number
    code: string
    label: string
    year?: number | null
    month?: number | null
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
        data_source_id: string | number
        file: File | null
        notes: string
    }>({
        period_id: props.currentPeriodId ?? props.periods[0]?.id ?? '',
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
                updated_at: grouped?.updated_at ?? period.updated_at ?? null,
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
            }
        })
    })

    const filteredPeriods = computed(() => {
        const query = filters.value.query.trim().toLowerCase()

        if (!query) return periodRows.value

        return periodRows.value.filter((period) => {
            return (
                period.label.toLowerCase().includes(query) ||
                period.code.toLowerCase().includes(query)
            )
        })
    })

    const selectedPeriodRow = computed(() => {
        return periodRows.value.find((period) => String(period.id) === String(form.period_id)) ?? null
    })

    const totalPeriods = computed(() => periodRows.value.length)

    const totalUploads = computed(() =>
        periodRows.value.reduce((acc, period) => acc + period.uploads.length, 0),
    )

    const completePeriods = computed(() =>
        periodRows.value.filter((period) => {
            return (
                period.required_sources_count > 0 &&
                period.uploaded_sources_count === period.required_sources_count
            )
        }).length,
    )

    const incompletePeriods = computed(() => totalPeriods.value - completePeriods.value)

    const currentProgress = computed(() => {
        if (!selectedPeriodRow.value) return 0

        return Math.round(
            (selectedPeriodRow.value.uploaded_sources_count /
                Math.max(selectedPeriodRow.value.required_sources_count, 1)) *
                100,
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
        if (isCurrentPeriodComplete.value) return false
        return true
    })

    const uploadDisabledReason = computed(() => {
        if (!selectedPeriodRow.value) return 'Selecciona un periodo.'
        if (isCurrentPeriodComplete.value) return 'Periodo completo'
        return ''
    })

    const selectedFileName = computed(() => form.file?.name ?? 'Ningún archivo seleccionado')

    const selectedUploads = computed(() => {
        const uploads = selectedPeriodRow.value?.uploads ?? []
        const query = quickFilter.value.trim().toLowerCase()

        if (!query) return uploads

        return uploads.filter((upload) => {
            return (
                (upload.original_name ?? '').toLowerCase().includes(query) ||
                (upload.source_name ?? '').toLowerCase().includes(query)
            )
        })
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
        if (period.uploaded_sources_count === 0) return 'Sin carga'
        if (period.failed_count > 0) return 'Con error'
        if (period.pending_count > 0) return 'Procesando'
        if (period.missing_sources_count > 0) return 'Incompleto'
        return 'Completo'
    }

    const currentStatusClass = (period: PeriodRow) => {
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
        form.clearErrors()
        quickFilter.value = ''
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
        Swal.fire({
            title: 'Subiendo archivo...',
            text: 'Estamos cargando y registrando tu archivo.',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            buttonsStyling: false,
            customClass: {
                popup: 'app-swal-popup',
                title: 'app-swal-title',
                htmlContainer: 'app-swal-text',
            },
            didOpen: () => {
                Swal.showLoading()
            },
        })
        form.post('/historico-general', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset('file', 'notes', 'data_source_id')
                dragActive.value = false

                if (fileInputRef.value) {
                    fileInputRef.value.value = ''
                }
                Swal.fire({
                    title: 'Archivo subido',
                    text: 'Tu archivo se subió correctamente.',
                    icon: 'success',
                    confirmButtonText: 'Entendido',
                    buttonsStyling: false,
                    customClass: {
                        popup: 'app-swal-popup',
                        icon: 'app-swal-icon',
                        title: 'app-swal-title',
                        htmlContainer: 'app-swal-text',
                        actions: 'app-swal-actions',
                        confirmButton: 'app-swal-confirm',
                    },
                })
            },
            onError: () => {
                Swal.fire({
                    title: 'No se pudo subir',
                    text: 'Revisa los datos del formulario o el archivo e inténtalo de nuevo.',
                    icon: 'error',
                    confirmButtonText: 'Cerrar',
                    buttonsStyling: false,
                    customClass: {
                        popup: 'app-swal-popup',
                        icon: 'app-swal-icon',
                        title: 'app-swal-title',
                        htmlContainer: 'app-swal-text',
                        actions: 'app-swal-actions',
                        confirmButton: 'app-swal-confirm',
                    },
                })
            },
        })
    }

    const deleteUpload = async (uploadId: number) => {
        const result = await Swal.fire({
            title: '¿Eliminar archivo?',
            text: 'Este archivo se quitará del periodo y podrás volver a subirlo después.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar',
            reverseButtons: true,
            buttonsStyling: false,
            customClass: {
                popup: 'app-swal-popup',
                icon: 'app-swal-icon',
                title: 'app-swal-title',
                htmlContainer: 'app-swal-text',
                actions: 'app-swal-actions',
                confirmButton: 'app-swal-confirm',
                cancelButton: 'app-swal-cancel',
            },
        })
        if (!result.isConfirmed) return
        deletingIds.value.push(uploadId)
        Swal.fire({
            title: 'Eliminando archivo...',
            text: 'Espera un momento mientras se elimina el registro.',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            buttonsStyling: false,
            customClass: {
                popup: 'app-swal-popup',
                title: 'app-swal-title',
                htmlContainer: 'app-swal-text',
            },
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
                    buttonsStyling: false,
                    customClass: {
                        popup: 'app-swal-popup',
                        icon: 'app-swal-icon',
                        title: 'app-swal-title',
                        htmlContainer: 'app-swal-text',
                        actions: 'app-swal-actions',
                        confirmButton: 'app-swal-confirm',
                    },
                })
            },
            onError: () => {
                Swal.fire({
                    title: 'No se pudo eliminar',
                    text: 'Ocurrió un problema al eliminar el archivo.',
                    icon: 'error',
                    confirmButtonText: 'Cerrar',
                    buttonsStyling: false,
                    customClass: {
                        popup: 'app-swal-popup',
                        icon: 'app-swal-icon',
                        title: 'app-swal-title',
                        htmlContainer: 'app-swal-text',
                        actions: 'app-swal-actions',
                        confirmButton: 'app-swal-confirm',
                    },
                })
            },
            onFinish: () => {
                deletingIds.value = deletingIds.value.filter((id) => id !== uploadId)
            },
        })
    }

    const isDeletingId = (uploadId: number) => deletingIds.value.includes(uploadId)

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
        formatUploadStatus,
        uploadStatusClass,
        currentStatusLabel,
        currentStatusClass,
        currentStatusIcon,
        deleteUpload,
        isDeletingId,
        quickFilter,
    }
}
