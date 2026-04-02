<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import {
    ArrowRightLeft,
    Building2,
    CheckCircle2,
    Search,
    UserRound,
    AlertTriangle,
    Link2,
    GitCompareArrows,
} from 'lucide-vue-next'

import AppLayout from '@/layouts/AppLayout.vue'
import { useAsignacionSucursalIndex } from '@/composables/useAsignacionSucursalIndex'

const props = withDefaults(
    defineProps<{
        assignments: Array<{
            id: number
            employee_name: string
            normalized_name?: string | null
            branch_name?: string | null
            source_name?: string | null
            match_status: 'matched' | 'pending' | 'manual' | 'unmatched'
            period_label?: string | null
            updated_at?: string | null
        }>
        branches: Array<{
            id: number
            name: string
        }>
    }>(),
    {
        assignments: () => [],
        branches: () => [],
    },
)

defineOptions({
    layout: AppLayout,
})

const {
    filters,
    selectedStatus,
    filteredAssignments,
    totalAssignments,
    matchedAssignments,
    pendingAssignments,
    unmatchedAssignments,
    statusClass,
} = useAsignacionSucursalIndex(props)
</script>

<template>
    <Head title="Asignación sucursal" />

    <div class="app-page px-3 py-3 sm:px-4 sm:py-4 md:px-5 lg:px-6 xl:px-7 2xl:px-8">
        <div class="space-y-6">
            <section class="app-card overflow-hidden">
                <div class="relative">
                    <div class="absolute inset-0 bg-gradient-to-br from-primary/10 via-transparent to-primary/5" />

                    <div class="relative p-4 sm:p-5 lg:p-6">
                        <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                            <div class="space-y-3">
                                <div
                                    class="inline-flex w-fit items-center gap-2 rounded-full border border-primary/15 bg-primary/5 px-3 py-1.5 text-xs font-semibold text-primary"
                                >
                                    <GitCompareArrows class="size-3.5" />
                                    Cruce NOI ↔ operación
                                </div>

                                <div>
                                    <h1 class="text-2xl font-extrabold tracking-tight sm:text-3xl">
                                        Asignación sucursal
                                    </h1>
                                    <p class="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground sm:text-base">
                                        Revisa el match de empleados contra sucursales operativas y
                                        detecta casos que todavía requieren validación manual.
                                    </p>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3 lg:w-[420px]">
                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <UserRound class="size-4" />
                                        Registros
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ totalAssignments }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <CheckCircle2 class="size-4" />
                                        Matched
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ matchedAssignments }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <AlertTriangle class="size-4" />
                                        Pendientes
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ pendingAssignments }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <ArrowRightLeft class="size-4" />
                                        Sin match
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ unmatchedAssignments }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="app-card overflow-hidden">
                <div class="border-b px-4 py-4 sm:px-5">
                    <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                        <div>
                            <h2 class="text-lg font-bold tracking-tight">Registros de asignación</h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Filtra por nombre o estado para revisar rápidamente.
                            </p>
                        </div>

                        <div class="flex flex-col gap-3 sm:flex-row">
                            <div class="relative w-full sm:w-[280px]">
                                <Search
                                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                />
                                <input
                                    v-model="filters.query"
                                    type="text"
                                    class="app-input h-10 pl-10"
                                    placeholder="Buscar empleado..."
                                />
                            </div>

                            <select
                                v-model="selectedStatus"
                                class="app-input h-10 sm:w-[180px]"
                            >
                                <option value="all">Todos</option>
                                <option value="matched">Matched</option>
                                <option value="manual">Manual</option>
                                <option value="pending">Pendiente</option>
                                <option value="unmatched">Sin match</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div v-if="filteredAssignments.length" class="grid gap-4 p-4 sm:p-5 lg:grid-cols-2 2xl:grid-cols-3">
                    <article
                        v-for="item in filteredAssignments"
                        :key="item.id"
                        class="rounded-[28px] border border-border/70 bg-background px-4 py-4 shadow-sm transition-all duration-200 hover:-translate-y-1 hover:shadow-md"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="text-base font-bold tracking-tight">
                                    {{ item.employee_name }}
                                </h3>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ item.normalized_name || 'Sin nombre normalizado' }}
                                </p>
                            </div>

                            <span
                                class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                :class="statusClass(item.match_status)"
                            >
                                {{ item.match_status }}
                            </span>
                        </div>

                        <div class="mt-4 space-y-3">
                            <div class="flex items-center gap-2 text-sm">
                                <Building2 class="size-4 text-muted-foreground" />
                                <span class="font-medium">{{ item.branch_name || 'Sin sucursal asignada' }}</span>
                            </div>

                            <div class="flex items-center gap-2 text-sm">
                                <Link2 class="size-4 text-muted-foreground" />
                                <span>{{ item.source_name || 'Sin fuente' }}</span>
                            </div>

                            <div class="flex items-center gap-2 text-sm text-muted-foreground">
                                <GitCompareArrows class="size-4" />
                                <span>{{ item.period_label || 'Sin periodo' }}</span>
                            </div>
                        </div>

                        <div class="mt-4 flex items-center justify-between border-t pt-4">
                            <p class="text-xs text-muted-foreground">
                                Actualizado: {{ item.updated_at ?? '—' }}
                            </p>

                            <button
                                type="button"
                                class="app-btn app-btn-secondary h-10 px-4"
                            >
                                Revisar
                            </button>
                        </div>
                    </article>
                </div>

                <div v-else class="px-4 py-10 text-center sm:px-5">
                    <UserRound class="mx-auto size-6 text-muted-foreground" />
                    <p class="mt-3 text-sm font-semibold">No hay registros</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        No se encontraron coincidencias con los filtros actuales.
                    </p>
                </div>
            </section>
        </div>
    </div>
</template>
