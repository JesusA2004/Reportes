<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import {
    CalendarDays,
    CheckCircle2,
    Clock3,
    FolderOpen,
    Lock,
    MoreHorizontal,
    Plus,
    Search,
    Unlock,
    XCircle,
} from 'lucide-vue-next'

import AppLayout from '@/layouts/AppLayout.vue'
import InputError from '@/components/InputError.vue'
import { usePeriodosIndex } from '@/composables/usePeriodosIndex'

const props = withDefaults(
    defineProps<{
        periods: Array<{
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
        }>
    }>(),
    {
        periods: () => [],
    },
)

defineOptions({
    layout: AppLayout,
})

const {
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
} = usePeriodosIndex(props)
</script>

<template>
    <Head title="Periodos" />

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
                                    <CalendarDays class="size-3.5" />
                                    Control mensual del sistema
                                </div>

                                <div>
                                    <h1 class="text-2xl font-extrabold tracking-tight sm:text-3xl">
                                        Periodos
                                    </h1>
                                    <p class="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground sm:text-base">
                                        Administra los meses de trabajo, consulta su avance y controla
                                        qué periodos permanecen abiertos o cerrados para captura.
                                    </p>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3 lg:w-[420px]">
                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <FolderOpen class="size-4" />
                                        Total
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ totalPeriods }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <Unlock class="size-4" />
                                        Abiertos
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ openPeriods }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <Lock class="size-4" />
                                        Cerrados
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ closedPeriods }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <CheckCircle2 class="size-4" />
                                        Activos
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ activePeriods }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-[380px_minmax(0,1fr)]">
                <section class="app-card overflow-hidden">
                    <div class="border-b px-4 py-4 sm:px-5">
                        <div class="flex items-center gap-2">
                            <Plus class="size-4 text-primary" />
                            <h2 class="text-lg font-bold tracking-tight">Nuevo periodo</h2>
                        </div>
                        <p class="mt-2 text-sm text-muted-foreground">
                            Crea un nuevo mes operativo para el histórico.
                        </p>
                    </div>

                    <form @submit.prevent="submitCreate" class="space-y-5 p-4 sm:p-5">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                <label class="text-sm font-semibold">Año</label>
                                <input
                                    v-model="form.year"
                                    type="number"
                                    class="app-input"
                                    placeholder="2026"
                                />
                                <InputError :message="form.errors.year" />
                            </div>

                            <div class="space-y-2">
                                <label class="text-sm font-semibold">Mes</label>
                                <select v-model="form.month" class="app-input">
                                    <option value="">Selecciona un mes</option>
                                    <option value="1">Enero</option>
                                    <option value="2">Febrero</option>
                                    <option value="3">Marzo</option>
                                    <option value="4">Abril</option>
                                    <option value="5">Mayo</option>
                                    <option value="6">Junio</option>
                                    <option value="7">Julio</option>
                                    <option value="8">Agosto</option>
                                    <option value="9">Septiembre</option>
                                    <option value="10">Octubre</option>
                                    <option value="11">Noviembre</option>
                                    <option value="12">Diciembre</option>
                                </select>
                                <InputError :message="form.errors.month" />
                            </div>
                        </div>

                        <div class="rounded-[26px] border border-border/70 bg-muted/20 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Vista previa
                            </p>
                            <p class="mt-2 text-lg font-bold">
                                {{ createLabel || 'Selecciona año y mes' }}
                            </p>
                        </div>

                        <div class="flex justify-end border-t pt-4">
                            <button
                                type="submit"
                                class="app-btn app-btn-primary h-11 px-5"
                                :disabled="form.processing"
                            >
                                {{ form.processing ? 'Creando...' : 'Crear periodo' }}
                            </button>
                        </div>
                    </form>
                </section>

                <section class="app-card overflow-hidden">
                    <div class="border-b px-4 py-4 sm:px-5">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <h2 class="text-lg font-bold tracking-tight">Listado de periodos</h2>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Periodos organizados por mes con su progreso y estado operativo.
                                </p>
                            </div>

                            <div class="relative w-full lg:max-w-sm">
                                <Search
                                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                />
                                <input
                                    v-model="filters.query"
                                    type="text"
                                    class="app-input h-10 pl-10"
                                    placeholder="Buscar periodo..."
                                />
                            </div>
                        </div>
                    </div>

                    <div v-if="filteredPeriods.length" class="grid gap-4 p-4 sm:p-5 lg:grid-cols-2 2xl:grid-cols-3">
                        <article
                            v-for="period in filteredPeriods"
                            :key="period.id"
                            class="rounded-[28px] border border-border/70 bg-background px-4 py-4 shadow-sm transition-all duration-200 hover:-translate-y-1 hover:shadow-md"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-base font-bold tracking-tight">
                                        {{ period.label }}
                                    </h3>
                                    <p class="mt-1 text-xs text-muted-foreground">
                                        Código: {{ period.code }}
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    class="rounded-xl p-2 text-muted-foreground transition hover:bg-muted hover:text-foreground"
                                >
                                    <MoreHorizontal class="size-4" />
                                </button>
                            </div>

                            <div class="mt-4 flex items-center gap-2">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="statusClass(period)"
                                >
                                    {{ period.is_closed ? 'Cerrado' : 'Abierto' }}
                                </span>

                                <span class="app-badge-muted">
                                    {{ period.uploaded_sources_count }} / {{ period.required_sources_count }}
                                </span>
                            </div>

                            <div class="mt-4 h-2 overflow-hidden rounded-full bg-muted">
                                <div
                                    class="h-full rounded-full bg-primary transition-all duration-300"
                                    :style="{ width: `${progressPercent(period)}%` }"
                                />
                            </div>

                            <div class="mt-4 grid grid-cols-3 gap-3">
                                <div class="rounded-2xl bg-muted/30 px-3 py-3">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                        Procesados
                                    </p>
                                    <p class="mt-2 text-lg font-extrabold">
                                        {{ period.processed_count ?? 0 }}
                                    </p>
                                </div>

                                <div class="rounded-2xl bg-muted/30 px-3 py-3">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                        Pendientes
                                    </p>
                                    <p class="mt-2 text-lg font-extrabold">
                                        {{ period.pending_count ?? 0 }}
                                    </p>
                                </div>

                                <div class="rounded-2xl bg-muted/30 px-3 py-3">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                        Errores
                                    </p>
                                    <p class="mt-2 text-lg font-extrabold">
                                        {{ period.failed_count ?? 0 }}
                                    </p>
                                </div>
                            </div>

                            <div class="mt-4 flex items-center justify-between border-t pt-4">
                                <p class="text-xs text-muted-foreground">
                                    Actualizado: {{ period.updated_at ?? '—' }}
                                </p>

                                <button
                                    type="button"
                                    class="app-btn app-btn-secondary h-10 px-4"
                                >
                                    {{ period.is_closed ? 'Abrir' : 'Cerrar' }}
                                </button>
                            </div>
                        </article>
                    </div>

                    <div v-else class="px-4 py-10 text-center sm:px-5">
                        <CalendarDays class="mx-auto size-6 text-muted-foreground" />
                        <p class="mt-3 text-sm font-semibold">No hay periodos</p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            No se encontraron resultados con esa búsqueda.
                        </p>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
