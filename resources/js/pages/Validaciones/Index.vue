<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import {
    AlertTriangle,
    CheckCircle2,
    Search,
    ShieldCheck,
    UserSearch,
    XCircle,
    CircleAlert,
} from 'lucide-vue-next'

import AppLayout from '@/layouts/AppLayout.vue'
import { useValidacionesIndex } from '@/composables/useValidacionesIndex'

const props = withDefaults(
    defineProps<{
        validations: Array<{
            id: number
            type: string
            title: string
            description?: string | null
            employee_name?: string | null
            period_label?: string | null
            severity: 'low' | 'medium' | 'high'
            status: 'open' | 'reviewed' | 'resolved'
            updated_at?: string | null
        }>
    }>(),
    {
        validations: () => [],
    },
)

defineOptions({
    layout: AppLayout,
})

const {
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
} = useValidacionesIndex(props)
</script>

<template>
    <Head title="Validaciones" />

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
                                    <ShieldCheck class="size-3.5" />
                                    Revisión de consistencia
                                </div>

                                <div>
                                    <h1 class="text-2xl font-extrabold tracking-tight sm:text-3xl">
                                        Validaciones
                                    </h1>
                                    <p class="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground sm:text-base">
                                        Consulta incidencias detectadas en nombres, sucursales, match
                                        y consistencia de información antes de consolidar.
                                    </p>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3 lg:w-[420px]">
                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <CircleAlert class="size-4" />
                                        Total
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ totalValidations }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <AlertTriangle class="size-4" />
                                        Abiertas
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ openValidations }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <XCircle class="size-4" />
                                        Altas
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ highValidations }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <CheckCircle2 class="size-4" />
                                        Resueltas
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ resolvedValidations }}</p>
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
                            <h2 class="text-lg font-bold tracking-tight">Incidencias detectadas</h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Filtra por severidad o estado para enfocarte en lo importante.
                            </p>
                        </div>

                        <div class="flex flex-col gap-3 sm:flex-row">
                            <div class="relative w-full sm:w-[260px]">
                                <Search
                                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                />
                                <input
                                    v-model="filters.query"
                                    type="text"
                                    class="app-input h-10 pl-10"
                                    placeholder="Buscar validación..."
                                />
                            </div>

                            <select v-model="selectedSeverity" class="app-input h-10 sm:w-[160px]">
                                <option value="all">Severidad</option>
                                <option value="low">Baja</option>
                                <option value="medium">Media</option>
                                <option value="high">Alta</option>
                            </select>

                            <select v-model="selectedStatus" class="app-input h-10 sm:w-[160px]">
                                <option value="all">Estado</option>
                                <option value="open">Abierta</option>
                                <option value="reviewed">Revisada</option>
                                <option value="resolved">Resuelta</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div v-if="filteredValidations.length" class="grid gap-4 p-4 sm:p-5 lg:grid-cols-2 2xl:grid-cols-3">
                    <article
                        v-for="item in filteredValidations"
                        :key="item.id"
                        class="rounded-[28px] border border-border/70 bg-background px-4 py-4 shadow-sm transition-all duration-200 hover:-translate-y-1 hover:shadow-md"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="text-base font-bold tracking-tight">
                                    {{ item.title }}
                                </h3>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ item.type }}
                                </p>
                            </div>

                            <div class="flex flex-col items-end gap-2">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="severityClass(item.severity)"
                                >
                                    {{ item.severity }}
                                </span>

                                <span
                                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="statusClass(item.status)"
                                >
                                    {{ item.status }}
                                </span>
                            </div>
                        </div>

                        <p class="mt-4 text-sm leading-6 text-muted-foreground">
                            {{ item.description || 'Sin descripción adicional.' }}
                        </p>

                        <div class="mt-4 space-y-2">
                            <div class="flex items-center gap-2 text-sm">
                                <UserSearch class="size-4 text-muted-foreground" />
                                <span>{{ item.employee_name || 'Sin empleado relacionado' }}</span>
                            </div>

                            <div class="flex items-center gap-2 text-sm text-muted-foreground">
                                <ShieldCheck class="size-4" />
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
                    <ShieldCheck class="mx-auto size-6 text-muted-foreground" />
                    <p class="mt-3 text-sm font-semibold">Sin incidencias</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        No se encontraron validaciones con los filtros actuales.
                    </p>
                </div>
            </section>
        </div>
    </div>
</template>
