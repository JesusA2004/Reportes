import { router, useForm } from '@inertiajs/vue3'
import Swal from 'sweetalert2'
import { computed, ref } from 'vue'

type PeriodItem = {
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
    component_period_ids?: number[]
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
}

type WeekItem = {
    id: number
    label: string
    year: number
    month: number
    sequence: number
    start_date?: string
    end_date?: string
}

type Props = {
    periods: PeriodItem[]
    availableWeeks?: WeekItem[]
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
        type: string
        year: string | number
        month: string | number
    }>({
        type: 'weekly',
        year: '',
        month: '',
    })

    const monthlyForm = useForm<{
        type: string
        year: string | number
        month: string | number
        week_ids: number[]
    }>({
        type: 'monthly',
        year: new Date().getFullYear(),
        month: '',
        week_ids: [],
    })

    const filteredWeeksForMonth = computed(() => {
        const year  = Number(monthlyForm.year)
        const month = Number(monthlyForm.month)
        if (!year || !month) return []
        // Muestra todas las semanas sin asignar del año (no filtrar por mes calendario)
        // para que semanas sobrantes del mes anterior estén disponibles
        return [...(props.availableWeeks ?? [])]
            .filter((w) => w.year === year)
            .sort((a, b) => (a.start_date ?? '').localeCompare(b.start_date ?? ''))
    })

    const filteredPeriods = computed(() => {
        const query = filters.value.query.trim().toLowerCase()

        if (!query) {
            return props.periods
        }

        return props.periods.filter((period) => {
            return (
                period.label.toLowerCase().includes(query) ||
                period.code.toLowerCase().includes(query) ||
                String(period.year).includes(query) ||
                String(period.month ?? '').includes(query) ||
                String(period.type ?? '').toLowerCase().includes(query)
            )
        })
    })

    const totalPeriods = computed(() => props.periods.length)
    const closedPeriods = computed(() => props.periods.filter((p) => p.is_closed).length)
    const openPeriods = computed(() => props.periods.filter((p) => !p.is_closed).length)
    const blockedPeriods = computed(() => props.periods.filter((p) => !p.is_closed && p.can_close === false).length)

    const createLabel = computed(() => {
        const type = form.type
        const year = Number(form.year)
        const month = Number(form.month)

        if (!year || !month) {
            return ''
        }

        if (type === 'weekly') {
            return `Semanas de ${monthNames[month] ?? 'Periodo'} ${year}`
        }

        if (type === 'monthly') {
            return `Periodo mensual: ${monthNames[month] ?? 'Periodo'} ${year}`
        }

        if (type === 'bimonthly') {
            return `Bimestres de ${year}`
        }

        if (type === 'quarterly') {
            const quarter = Math.ceil(month / 3)
            return `Trimestre ${quarter} de ${year}`
        }

        if (type === 'semiannual') {
            return month <= 6 ? `Semestre 1 de ${year}` : `Semestre 2 de ${year}`
        }

        if (type === 'annual') {
            return `Periodo anual ${year}`
        }

        return `${monthNames[month] ?? 'Periodo'} ${year}`
    })

    const submitCreate = async () => {
        const result = await Swal.fire({
            title: '¿Crear periodo?',
            text: createLabel.value || 'Se generará el periodo seleccionado.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, crear',
            cancelButtonText: 'Cancelar',
        })

        if (!result.isConfirmed) {
            return
        }

        form.post('/periodos', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('year', 'month')
                form.type = 'weekly'
            },
        })
    }

    const submitConfigureMonth = async () => {
        const year  = Number(monthlyForm.year)
        const month = Number(monthlyForm.month)
        const monthLabel = monthNames[month] ?? 'Periodo'

        if (!year || !month) {
            await Swal.fire({
                title: 'Datos incompletos',
                text: 'Selecciona el año y el mes antes de continuar.',
                icon: 'warning',
                confirmButtonText: 'Entendido',
            })
            return
        }

        if (monthlyForm.week_ids.length === 0) {
            await Swal.fire({
                title: 'Sin semanas seleccionadas',
                text: 'Selecciona al menos una semana para el mes operativo.',
                icon: 'warning',
                confirmButtonText: 'Entendido',
            })
            return
        }

        const result = await Swal.fire({
            title: '¿Configurar mes operativo?',
            text: `Se creará el periodo mensual de ${monthLabel} ${year} con ${monthlyForm.week_ids.length} semana(s) seleccionada(s).`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, crear',
            cancelButtonText: 'Cancelar',
        })

        if (!result.isConfirmed) return

        monthlyForm.post('/periodos', {
            preserveScroll: true,
            onSuccess: () => {
                monthlyForm.reset('month', 'week_ids')
            },
        })
    }

    const togglePeriod = async (period: PeriodItem) => {
        const isClosing = !period.is_closed

        let text = isClosing
            ? `Se intentará cerrar ${period.label}.`
            : `Se reabrirá ${period.label}.`

        if (isClosing && period.can_close === false) {
            text = `Este periodo tiene ${period.close_issues_count ?? 0} incidencia(s) crítica(s). Si intentas cerrarlo, el backend lo bloqueará.`
        }

        const result = await Swal.fire({
            title: isClosing ? '¿Cerrar periodo?' : '¿Reabrir periodo?',
            text,
            icon: isClosing ? 'warning' : 'question',
            showCancelButton: true,
            confirmButtonText: isClosing ? 'Sí, cerrar' : 'Sí, reabrir',
            cancelButtonText: 'Cancelar',
        })

        if (!result.isConfirmed) {
            return
        }

        form.post(`/periodos/${period.id}/${period.is_closed ? 'open' : 'close'}`, {
            preserveScroll: true,
        })
    }

    const destroyMonthly = async (period: PeriodItem) => {
        const result = await Swal.fire({
            title: '¿Eliminar mes operativo?',
            html: `Se eliminará únicamente la agrupación <strong>${period.label}</strong>.<br><br>Las semanas base <strong>no se eliminarán</strong> y volverán a estar disponibles para crear otro periodo.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Eliminar mes operativo',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#dc2626',
        })

        if (!result.isConfirmed) return

        router.delete(`/periodos/${period.id}`, {
            preserveScroll: true,
        })
    }

    return {
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
    }
}
