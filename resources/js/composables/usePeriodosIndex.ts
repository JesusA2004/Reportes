import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Swal from 'sweetalert2'

type PeriodItem = {
    id: number
    code: string
    label: string
    year: number
    month: number
    start_date?: string | null
    end_date?: string | null
    is_closed?: boolean
    uploaded_sources_count?: number
    required_sources_count?: number
    missing_sources_count?: number
    processed_count?: number
    pending_count?: number
    failed_count?: number
    updated_at?: string | null
}

type Props = {
    periods: PeriodItem[]
}

const monthNames: Record<number, string> = {
    1: 'Enero',
    2: 'Febrero',
    3: 'Marzo',
    4: 'Abril',
    5: 'Mayo',
    6: 'Junio',
    7: 'Julio',
    8: 'Agosto',
    9: 'Septiembre',
    10: 'Octubre',
    11: 'Noviembre',
    12: 'Diciembre',
}

export function usePeriodosIndex(props: Props) {
    const filters = ref({
        query: '',
    })

    const form = useForm<{
        year: string | number
        month: string | number
    }>({
        year: '',
        month: '',
    })

    const filteredPeriods = computed(() => {
        const query = filters.value.query.trim().toLowerCase()

        if (!query) return props.periods

        return props.periods.filter((period) => {
            return (
                period.label.toLowerCase().includes(query) ||
                period.code.toLowerCase().includes(query)
            )
        })
    })

    const totalPeriods = computed(() => props.periods.length)
    const openPeriods = computed(() => props.periods.filter((p) => !p.is_closed).length)
    const closedPeriods = computed(() => props.periods.filter((p) => p.is_closed).length)
    const activePeriods = computed(() => props.periods.filter((p) => (p.uploaded_sources_count ?? 0) > 0).length)

    const createLabel = computed(() => {
        const year = Number(form.year)
        const month = Number(form.month)

        if (!year || !month) return ''
        return `${monthNames[month] ?? 'Periodo'} ${year}`
    })

    const statusClass = (period: PeriodItem) => {
        return period.is_closed
            ? 'bg-slate-100 text-slate-700 dark:bg-slate-500/15 dark:text-slate-300'
            : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'
    }

    const progressPercent = (period: PeriodItem) => {
        const uploaded = period.uploaded_sources_count ?? 0
        const required = Math.max(period.required_sources_count ?? 0, 1)

        return Math.round((uploaded / required) * 100)
    }

    const submitCreate = async () => {
        const result = await Swal.fire({
            title: '¿Crear periodo?',
            text: createLabel.value || 'Se creará un nuevo periodo.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, crear',
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

        Swal.fire({
            title: 'Creando periodo...',
            text: 'Espera un momento mientras se registra.',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            buttonsStyling: false,
            customClass: {
                popup: 'app-swal-popup',
                title: 'app-swal-title',
                htmlContainer: 'app-swal-text',
            },
            didOpen: () => Swal.showLoading(),
        })

        form.post('/periodos', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset()
                Swal.fire({
                    title: 'Periodo creado',
                    text: 'El periodo se registró correctamente.',
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
                    title: 'No se pudo crear',
                    text: 'Revisa los datos e inténtalo de nuevo.',
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

    return {
        filters,
        form,
        filteredPeriods,
        totalPeriods,
        openPeriods,
        closedPeriods,
        activePeriods,
        createLabel,
        statusClass,
        progressPercent,
        submitCreate,
    }
}
