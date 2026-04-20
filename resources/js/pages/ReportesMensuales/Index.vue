<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'

withDefaults(
    defineProps<{
        periods: Array<{
            id: number
            label: string
            code: string
            type: string
            start_date: string | null
            end_date: string | null
            is_closed: boolean
        }>
        selectedPeriodId?: number | null
        message: string
    }>(),
    {
        periods: () => [],
        selectedPeriodId: null,
    },
)

defineOptions({
    layout: AppLayout,
})

const goToPeriod = (periodId: number) => {
    router.get('/reportes-mensuales', { period: periodId }, { preserveScroll: true, preserveState: true })
}
</script>

<template>
    <Head title="Reportes por periodo" />

    <div class="app-page px-4 py-4 sm:px-6">
        <div class="app-card p-5 sm:p-6">
            <h1 class="text-2xl font-extrabold tracking-tight">Reportes por periodo</h1>
            <p class="mt-2 text-sm text-muted-foreground">{{ message }}</p>

            <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <button
                    v-for="period in periods"
                    :key="period.id"
                    type="button"
                    class="rounded-2xl border border-border/70 bg-background p-4 text-left transition hover:-translate-y-0.5 hover:shadow"
                    :class="selectedPeriodId === period.id ? 'ring-2 ring-primary/35' : ''"
                    @click="goToPeriod(period.id)"
                >
                    <p class="text-sm font-bold">{{ period.label }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">{{ period.code }} • {{ period.type }}</p>
                    <p class="mt-2 text-xs text-muted-foreground">{{ period.start_date }} → {{ period.end_date }}</p>
                    <p class="mt-2 text-xs" :class="period.is_closed ? 'text-slate-500' : 'text-emerald-600'">
                        {{ period.is_closed ? 'Cerrado' : 'Abierto' }}
                    </p>
                </button>
            </div>

            <div v-if="selectedPeriodId" class="mt-5 border-t pt-4">
                <a
                    class="app-btn app-btn-primary h-11 px-5"
                    :href="`/reportes-mensuales/${selectedPeriodId}/radiografia.xlsx`"
                >
                    Descargar radiografía (Excel/CSV)
                </a>
            </div>
        </div>
    </div>
</template>
