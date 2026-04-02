import { computed, ref } from 'vue'

type ValidationItem = {
    id: number
    type: string
    title: string
    description?: string | null
    employee_name?: string | null
    period_label?: string | null
    severity: 'low' | 'medium' | 'high'
    status: 'open' | 'reviewed' | 'resolved'
    updated_at?: string | null
}

type Props = {
    validations: ValidationItem[]
}

export function useValidacionesIndex(props: Props) {
    const filters = ref({
        query: '',
    })

    const selectedSeverity = ref<'all' | 'low' | 'medium' | 'high'>('all')
    const selectedStatus = ref<'all' | 'open' | 'reviewed' | 'resolved'>('all')

    const filteredValidations = computed(() => {
        const query = filters.value.query.trim().toLowerCase()

        return props.validations.filter((item) => {
            const matchesQuery =
                !query ||
                item.title.toLowerCase().includes(query) ||
                item.type.toLowerCase().includes(query) ||
                (item.employee_name ?? '').toLowerCase().includes(query) ||
                (item.description ?? '').toLowerCase().includes(query)

            const matchesSeverity =
                selectedSeverity.value === 'all' || item.severity === selectedSeverity.value

            const matchesStatus =
                selectedStatus.value === 'all' || item.status === selectedStatus.value

            return matchesQuery && matchesSeverity && matchesStatus
        })
    })

    const totalValidations = computed(() => props.validations.length)
    const openValidations = computed(() => props.validations.filter((v) => v.status === 'open').length)
    const resolvedValidations = computed(() => props.validations.filter((v) => v.status === 'resolved').length)
    const highValidations = computed(() => props.validations.filter((v) => v.severity === 'high').length)

    const severityClass = (severity: ValidationItem['severity']) => {
        if (severity === 'high') {
            return 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300'
        }

        if (severity === 'medium') {
            return 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'
        }

        return 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300'
    }

    const statusClass = (status: ValidationItem['status']) => {
        if (status === 'resolved') {
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'
        }

        if (status === 'reviewed') {
            return 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300'
        }

        return 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'
    }

    return {
        filters,
        selectedSeverity,
        selectedStatus,
        filteredValidations,
        totalValidations,
        openValidations,
        resolvedValidations,
        highValidations,
        severityClass,
        statusClass,
    }
}
