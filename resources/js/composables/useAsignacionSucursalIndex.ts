import { computed, ref } from 'vue'

type AssignmentItem = {
    id: number
    employee_name: string
    normalized_name?: string | null
    branch_name?: string | null
    source_name?: string | null
    match_status: 'matched' | 'pending' | 'manual' | 'unmatched'
    period_label?: string | null
    updated_at?: string | null
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
                (item.branch_name ?? '').toLowerCase().includes(query)

            const matchesStatus =
                selectedStatus.value === 'all' || item.match_status === selectedStatus.value

            return matchesQuery && matchesStatus
        })
    })

    const totalAssignments = computed(() => props.assignments.length)
    const matchedAssignments = computed(() => props.assignments.filter((a) => a.match_status === 'matched').length)
    const pendingAssignments = computed(() => props.assignments.filter((a) => a.match_status === 'pending' || a.match_status === 'manual').length)
    const unmatchedAssignments = computed(() => props.assignments.filter((a) => a.match_status === 'unmatched').length)

    const statusClass = (status: AssignmentItem['match_status']) => {
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

    return {
        filters,
        selectedStatus,
        filteredAssignments,
        totalAssignments,
        matchedAssignments,
        pendingAssignments,
        unmatchedAssignments,
        statusClass,
    }
}
