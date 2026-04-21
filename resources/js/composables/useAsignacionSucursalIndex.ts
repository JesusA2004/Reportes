import { computed, ref } from 'vue'

type AssignmentItem = {
    id: number
    employee_name: string
    normalized_name?: string | null
    branch_name?: string | null
    source_name?: string | null
    source_reference?: string | null
    match_type?: 'exact' | 'normalized' | 'manual' | 'unmatched' | string | null
    confidence?: number | null
    was_manual_reviewed?: boolean
    ui_status: 'matched' | 'pending' | 'manual' | 'unmatched'
    period_label?: string | null
    updated_at?: string | null
    notes?: string | null
}

type Props = {
    assignments: AssignmentItem[]
    branches: Array<{
        id: number
        name: string
    }>
}

export function useAsignacionSucursalIndex(props: Props) {
    const filters = ref({
        query: '',
    })

    const selectedStatus = ref<'all' | 'matched' | 'pending' | 'manual' | 'unmatched'>('all')

    const filteredAssignments = computed(() => {
        const query = filters.value.query.trim().toLowerCase()

        return props.assignments.filter((item) => {
            const matchesQuery =
                !query ||
                item.employee_name.toLowerCase().includes(query) ||
                (item.normalized_name ?? '').toLowerCase().includes(query) ||
                (item.branch_name ?? '').toLowerCase().includes(query) ||
                (item.source_name ?? '').toLowerCase().includes(query)

            const matchesStatus =
                selectedStatus.value === 'all' || item.ui_status === selectedStatus.value

            return matchesQuery && matchesStatus
        })
    })

    const totalAssignments = computed(() => props.assignments.length)
    const matchedAssignments = computed(() => props.assignments.filter((a) => a.ui_status === 'matched').length)
    const pendingAssignments = computed(() => props.assignments.filter((a) => a.ui_status === 'pending' || a.ui_status === 'manual').length)
    const unmatchedAssignments = computed(() => props.assignments.filter((a) => a.ui_status === 'unmatched').length)

    const statusClass = (status: AssignmentItem['ui_status']) => {
        if (status === 'matched') {
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'
        }

        if (status === 'manual') {
            return 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300'
        }

        if (status === 'pending') {
            return 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'
        }

        return 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300'
    }

    const formatMatchType = (matchType?: AssignmentItem['match_type']) => {
        if (matchType === 'exact') return 'Exacto'
        if (matchType === 'normalized') return 'Normalizado'
        if (matchType === 'manual') return 'Manual'
        if (matchType === 'unmatched') return 'Sin match'
        return 'Sin definir'
    }

    const formatConfidence = (confidence?: number | null) => {
        if (confidence === null || confidence === undefined) return '—'
        return `${Math.round(confidence * 100)}%`
    }

    return {
        filters,
        selectedStatus,
        filteredAssignments,
        totalAssignments,
        matchedAssignments,
        pendingAssignments,
        unmatchedAssignments,
        statusClass,
        formatMatchType,
        formatConfidence,
    }
}
