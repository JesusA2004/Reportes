<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import {
    AlertCircle,
    CalendarDays,
    CheckCircle2,
    ChevronRight,
    Clock3,
    FileSpreadsheet,
    FolderOpen,
    LoaderCircle,
    Search,
    Trash2,
    PlayCircle,
    UploadCloud,
    Inbox,
    Sparkles,
    FileUp,
    ArrowUpRight,
} from 'lucide-vue-next'

import InputError from '@/components/InputError.vue'
import { useHistoricoGeneralIndex } from '@/composables/useHistoricoGeneralIndex'
import AppLayout from '@/layouts/AppLayout.vue'

const props = withDefaults(
    defineProps<{
        periods: Array<{
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
        }>
        sources: Array<{
            id: number
            code: string
            name: string
            description?: string | null
        }>
        groupedUploads?: Array<{
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
            uploads: Array<{
                id: number
                original_name: string
                status: 'pending' | 'processing' | 'processed' | 'failed'
                uploaded_at?: string | null
                notes?: string | null
                source_code?: string | null
                source_name?: string | null
                last_process_run?: {
                    status?: 'pending' | 'running' | 'success' | 'failed' | string
                    rows_read?: number
                    rows_inserted?: number
                    rows_with_errors?: number
                    finished_at?: string | null
                } | null
            }>
        }>
        currentPeriodId?: number | null
    }>(),
    {
        groupedUploads: () => [],
        currentPeriodId: null,
    },
)

defineOptions({
    layout: AppLayout,
})

const {
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
    analyzeUpload,
    isDeletingId,
    quickFilter,
} = useHistoricoGeneralIndex(props)
</script>

<template>
    <Head title="Histórico general" />

    <div class="app-page px-3 py-3 sm:px-4 sm:py-4 md:px-5 lg:px-6 xl:px-7 2xl:px-8">
        <div class="space-y-6">
            <section class="app-card overflow-hidden">
                <div class="relative">
                    <div class="absolute inset-0 bg-gradient-to-br from-primary/10 via-transparent to-primary/5" />

                    <div class="relative p-4 sm:p-5 lg:p-6">
                        <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                            <div class="space-y-4">
                                <div
                                    class="inline-flex w-fit items-center gap-2 rounded-full border border-primary/15 bg-primary/5 px-3 py-1.5 text-xs font-semibold text-primary"
                                >
                                    <Sparkles class="size-3.5" />
                                    Gestión mensual de cargas
                                </div>

                                <div class="space-y-2">
                                    <h1 class="text-2xl font-extrabold tracking-tight sm:text-3xl lg:text-4xl">
                                        Histórico General
                                    </h1>
                                    <p class="max-w-3xl text-sm leading-6 text-muted-foreground sm:text-base">
                                        Selecciona un periodo, carga sus archivos fuente, revisa su
                                        estado y elimina documentos si hubo error para volver a subirlos.
                                    </p>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3 lg:w-[460px]">
                                <div class="app-card-soft px-4 py-3 transition-all duration-200 hover:-translate-y-0.5">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <CalendarDays class="size-4" />
                                        Periodos
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ totalPeriods }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3 transition-all duration-200 hover:-translate-y-0.5">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <FileSpreadsheet class="size-4" />
                                        Archivos
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ totalUploads }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3 transition-all duration-200 hover:-translate-y-0.5">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <CheckCircle2 class="size-4" />
                                        Completos
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ completePeriods }}</p>
                                </div>

                                <div class="app-card-soft px-4 py-3 transition-all duration-200 hover:-translate-y-0.5">
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <AlertCircle class="size-4" />
                                        Pendientes
                                    </div>
                                    <p class="mt-2 text-xl font-extrabold">{{ incompletePeriods }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-[300px_minmax(0,1fr)]">
                <!-- SIDEBAR PERIODOS -->
                <aside class="space-y-4">
                    <section class="app-card overflow-hidden">
                        <div class="border-b px-4 py-4">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <h2 class="text-base font-bold tracking-tight">Periodos</h2>
                                    <p class="mt-1 text-xs text-muted-foreground">
                                        Elige un mes y trabaja solo sobre él.
                                    </p>
                                </div>

                                <span class="app-badge-muted">
                                    {{ filteredPeriods.length }}
                                </span>
                            </div>

                            <div class="relative mt-4">
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

                        <div class="max-h-[560px] overflow-y-auto p-3">
                            <button
                                v-for="period in filteredPeriods"
                                :key="period.id"
                                type="button"
                                @click="selectPeriod(period.id)"
                                class="group mb-2 w-full rounded-[22px] border px-4 py-3 text-left shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md"
                                :class="
                                    selectedPeriodRow?.id === period.id
                                        ? 'border-primary/30 bg-primary/5'
                                        : 'border-border bg-background hover:bg-muted/30'
                                "
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold">
                                            {{ period.label }}
                                        </p>
                                        <p class="mt-1 text-xs text-muted-foreground">
                                            {{ period.uploaded_sources_count }} de
                                            {{ period.required_sources_count }} archivos base
                                        </p>
                                    </div>

                                    <ChevronRight
                                        class="mt-0.5 size-4 shrink-0 text-muted-foreground transition-transform duration-200 group-hover:translate-x-0.5"
                                    />
                                </div>

                                <div class="mt-3 flex items-center justify-between gap-3">
                                    <div class="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                        <div
                                            class="h-full rounded-full bg-primary transition-all duration-300"
                                            :style="{
                                                width: `${Math.round(
                                                    (period.uploaded_sources_count / Math.max(period.required_sources_count, 1)) * 100,
                                                )}%`,
                                            }"
                                        />
                                    </div>

                                    <span
                                        class="inline-flex rounded-full px-2 py-1 text-[11px] font-semibold"
                                        :class="currentStatusClass(period)"
                                    >
                                        {{ currentStatusLabel(period) }}
                                    </span>
                                </div>
                            </button>

                            <div
                                v-if="!filteredPeriods.length"
                                class="rounded-[22px] border border-dashed bg-background px-4 py-8 text-center"
                            >
                                <Inbox class="mx-auto size-5 text-muted-foreground" />
                                <p class="mt-3 text-sm font-semibold">Sin coincidencias</p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Ajusta tu búsqueda para encontrar otro periodo.
                                </p>
                            </div>
                        </div>
                    </section>
                </aside>

                <!-- CONTENIDO PRINCIPAL -->
                <section class="space-y-6">
                    <section v-if="selectedPeriodRow" class="app-card overflow-hidden">
                        <div class="border-b px-4 py-4 sm:px-5">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h2 class="text-xl font-bold tracking-tight">
                                            {{ selectedPeriodRow.label }}
                                        </h2>

                                        <span
                                            class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold"
                                            :class="currentStatusClass(selectedPeriodRow)"
                                        >
                                            <component :is="currentStatusIcon(selectedPeriodRow)" class="size-3.5" />
                                            {{ currentStatusLabel(selectedPeriodRow) }}
                                        </span>
                                    </div>

                                    <p class="mt-2 text-sm text-muted-foreground">
                                        Administra las fuentes del periodo actual desde un solo lugar.
                                    </p>
                                </div>

                                <div class="grid gap-3 sm:grid-cols-3 lg:w-[430px]">
                                    <div class="rounded-2xl border bg-background px-3 py-3">
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                            Cargados
                                        </p>
                                        <p class="mt-2 text-xl font-extrabold">
                                            {{ selectedPeriodRow.uploaded_sources_count }}
                                        </p>
                                    </div>

                                    <div class="rounded-2xl border bg-background px-3 py-3">
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                            Faltantes
                                        </p>
                                        <p class="mt-2 text-xl font-extrabold">
                                            {{ selectedPeriodRow.missing_sources_count }}
                                        </p>
                                    </div>

                                    <div class="rounded-2xl border bg-background px-3 py-3">
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                            Avance
                                        </p>
                                        <p class="mt-2 text-xl font-extrabold">{{ currentProgress }}%</p>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 h-2 overflow-hidden rounded-full bg-muted">
                                <div
                                    class="h-full rounded-full bg-primary transition-all duration-500"
                                    :style="{ width: `${currentProgress}%` }"
                                />
                            </div>
                        </div>

                        <div class="grid gap-6 p-4 sm:p-5 2xl:grid-cols-[minmax(0,1fr)_320px]">
                            <!-- PANEL SUBIDA -->
                            <div class="space-y-5">
                                <div class="grid gap-4 md:grid-cols-[1fr_1fr_auto]">
                                    <div class="space-y-2">
                                        <label class="text-sm font-semibold">Periodo seleccionado</label>
                                        <div class="app-input flex items-center">
                                            {{ selectedPeriodRow.label }}
                                        </div>
                                    </div>

                                    <div class="space-y-2">
                                        <label for="data_source_id" class="text-sm font-semibold">Fuente</label>
                                        <select
                                            id="data_source_id"
                                            v-model="form.data_source_id"
                                            class="app-input"
                                            :disabled="!canUploadCurrentPeriod || form.processing"
                                        >
                                            <option value="">Selecciona una fuente</option>
                                            <option
                                                v-for="source in sourceOptions"
                                                :key="source.id"
                                                :value="source.id"
                                                :disabled="source.disabled"
                                            >
                                                {{ source.name }}{{ source.disabled ? ' · ya cargada' : '' }}
                                            </option>
                                        </select>
                                        <InputError :message="form.errors.data_source_id" />
                                    </div>

                                    <div class="flex items-end">
                                        <button
                                            type="button"
                                            class="app-btn app-btn-secondary h-11 w-full px-4 md:w-auto"
                                            @click="openFileDialog"
                                            :disabled="!canUploadCurrentPeriod || form.processing"
                                        >
                                            <FileUp class="size-4" />
                                            Elegir
                                        </button>
                                    </div>
                                </div>

                                <div
                                    class="rounded-[30px] border border-dashed px-5 py-6 shadow-sm transition-all duration-200"
                                    :class="
                                        dragActive
                                            ? 'border-primary bg-primary/5 ring-2 ring-primary/10'
                                            : 'border-border bg-background hover:border-primary/30 hover:bg-muted/20'
                                    "
                                    @drop.prevent="onDrop"
                                    @dragenter.prevent="onDragEnter"
                                    @dragleave.prevent="onDragLeave"
                                    @dragover.prevent="onDragOver"
                                >
                                    <input
                                        ref="fileInputRef"
                                        type="file"
                                        class="hidden"
                                        accept=".xls,.xlsx,.xlsm"
                                        @change="onFileChange"
                                    />

                                    <div class="flex flex-col items-center justify-center text-center">
                                        <div
                                            class="flex size-16 items-center justify-center rounded-3xl bg-primary/10 text-primary transition-transform duration-200"
                                            :class="{ 'scale-110': dragActive || form.processing }"
                                        >
                                            <LoaderCircle
                                                v-if="form.processing"
                                                class="size-7 animate-spin"
                                            />
                                            <UploadCloud
                                                v-else
                                                class="size-7"
                                            />
                                        </div>

                                        <h3 class="mt-4 text-base font-bold tracking-tight">
                                            {{
                                                form.processing
                                                    ? 'Subiendo archivo...'
                                                    : dragActive
                                                      ? 'Suelta tu archivo aquí'
                                                      : 'Arrastra y suelta tu archivo'
                                            }}
                                        </h3>

                                        <p class="mt-2 max-w-xl text-sm text-muted-foreground">
                                            También puedes hacer clic en “Elegir” o en esta zona.
                                            Formatos permitidos: .xls, .xlsx, .xlsm
                                        </p>

                                        <button
                                            type="button"
                                            class="mt-4 text-sm font-semibold text-primary transition hover:opacity-80"
                                            @click="openFileDialog"
                                            :disabled="!canUploadCurrentPeriod || form.processing"
                                        >
                                            {{ selectedFileName }}
                                        </button>

                                        <p
                                            v-if="isCurrentPeriodComplete"
                                            class="mt-4 rounded-full bg-emerald-100 px-3 py-1.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"
                                        >
                                            Este periodo ya está completo. Si necesitas volver a subir algo,
                                            elimina primero el archivo correspondiente.
                                        </p>

                                        <p
                                            v-else-if="uploadDisabledReason"
                                            class="mt-4 rounded-full bg-muted px-3 py-1.5 text-xs font-semibold text-muted-foreground"
                                        >
                                            {{ uploadDisabledReason }}
                                        </p>
                                    </div>
                                </div>

                                <div class="space-y-2">
                                    <label for="notes" class="text-sm font-semibold">Notas</label>
                                    <textarea
                                        id="notes"
                                        v-model="form.notes"
                                        rows="3"
                                        class="app-textarea"
                                        placeholder="Observaciones opcionales del archivo o del periodo."
                                        :disabled="form.processing"
                                    />
                                    <InputError :message="form.errors.notes" />
                                    <InputError :message="form.errors.file" />
                                </div>

                                <div class="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="text-xs text-muted-foreground">
                                        {{ selectedUploads.length }} archivo{{ selectedUploads.length === 1 ? '' : 's' }}
                                        cargado{{ selectedUploads.length === 1 ? '' : 's' }} en este periodo
                                    </div>

                                    <button
                                        type="button"
                                        class="app-btn app-btn-primary h-11 px-5 transition-all duration-200 hover:-translate-y-0.5"
                                        :disabled="!canUploadCurrentPeriod || form.processing"
                                        @click="submit"
                                    >
                                        <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                                        <UploadCloud v-else class="size-4" />
                                        {{ submitLabel }}
                                    </button>
                                </div>
                            </div>

                            <!-- RESUMEN LATERAL -->
                            <aside class="space-y-4">
                                <div class="rounded-[28px] border border-border/70 bg-background px-4 py-4 shadow-sm">
                                    <p class="text-sm font-semibold">Fuentes del periodo</p>

                                    <div class="mt-4 space-y-3">
                                        <div
                                            v-for="source in selectedSourceCards"
                                            :key="source.id"
                                            class="rounded-2xl border border-border/70 bg-muted/20 px-3 py-3 transition-all duration-200 hover:bg-muted/30"
                                        >
                                            <div class="flex items-start justify-between gap-3">
                                                <div class="min-w-0">
                                                    <p class="text-sm font-semibold">{{ source.name }}</p>
                                                    <p class="mt-1 text-xs text-muted-foreground">
                                                        {{ source.description || 'Fuente mensual' }}
                                                    </p>
                                                </div>

                                                <span
                                                    class="inline-flex rounded-full px-2 py-1 text-[11px] font-semibold"
                                                    :class="source.statusClass"
                                                >
                                                    {{ source.statusLabel }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="rounded-[28px] border border-border/70 bg-background px-4 py-4 shadow-sm">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-sm font-semibold">Salida final</p>
                                        <ArrowUpRight class="size-4 text-muted-foreground" />
                                    </div>

                                    <p class="mt-2 text-sm text-muted-foreground">
                                        Cuando las fuentes estén completas podrás pasar al reporte final.
                                    </p>

                                    <a
                                        :href="`/reportes-mensuales?period=${selectedPeriodRow.id}`"
                                        class="app-btn app-btn-secondary mt-4 h-11 w-full justify-between px-4 transition-all duration-200 hover:-translate-y-0.5"
                                    >
                                        <span>Ir a reporte final</span>
                                        <ChevronRight class="size-4" />
                                    </a>
                                </div>
                            </aside>
                        </div>
                    </section>

                    <!-- ARCHIVOS DEL PERIODO -->
                    <section v-if="selectedPeriodRow" class="app-card overflow-hidden">
                        <div class="border-b px-4 py-4 sm:px-5">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                <div>
                                        <h3 class="text-lg font-bold tracking-tight">Archivos del periodo</h3>
                                    <p class="mt-1 text-sm text-muted-foreground">
                                        Analiza cada archivo para convertirlo en mini reporte y luego consolidar la radiografía.
                                    </p>
                                </div>

                                <div class="relative w-full lg:max-w-sm">
                                    <Search
                                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                    />
                                    <input
                                        v-model="quickFilter"
                                        type="text"
                                        class="app-input h-10 pl-10"
                                        placeholder="Buscar archivo o fuente..."
                                    />
                                </div>
                            </div>
                        </div>

                        <div v-if="selectedUploads.length" class="grid gap-3 p-4 sm:p-5 md:grid-cols-2 2xl:grid-cols-3">
                            <article
                                v-for="upload in selectedUploads"
                                :key="upload.id"
                                class="group rounded-[26px] border border-border/70 bg-background px-4 py-4 shadow-sm transition-all duration-200 hover:-translate-y-1 hover:shadow-md"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold">
                                            {{ upload.source_name || 'Sin fuente' }}
                                        </p>
                                        <p class="mt-1 truncate text-xs text-muted-foreground">
                                            {{ upload.original_name }}
                                        </p>
                                    </div>

                                    <span
                                        class="inline-flex rounded-full px-2 py-1 text-[11px] font-semibold"
                                        :class="uploadStatusClass(upload.status)"
                                    >
                                        {{ formatUploadStatus(upload.status) }}
                                    </span>
                                </div>

                                <div class="mt-4 flex items-center gap-2 text-xs text-muted-foreground">
                                    <Clock3 class="size-3.5" />
                                    {{ upload.uploaded_at ?? 'Sin fecha registrada' }}
                                </div>

                                <p
                                    v-if="upload.notes"
                                    class="mt-3 line-clamp-2 text-xs leading-5 text-muted-foreground"
                                >
                                    {{ upload.notes }}
                                </p>

                                <div
                                    v-if="upload.last_process_run"
                                    class="mt-3 rounded-xl border border-border/70 bg-muted/20 px-3 py-2 text-[11px] text-muted-foreground"
                                >
                                    <p>
                                        Análisis: {{ upload.last_process_run.status || '—' }} ·
                                        Leídas {{ upload.last_process_run.rows_read ?? 0 }} ·
                                        Insertadas {{ upload.last_process_run.rows_inserted ?? 0 }} ·
                                        Errores {{ upload.last_process_run.rows_with_errors ?? 0 }}
                                    </p>
                                </div>

                                <div class="mt-4 flex items-center justify-end gap-2 border-t pt-4">
                                    <button
                                        type="button"
                                        class="app-btn h-10 border border-sky-200 bg-sky-50 px-4 text-sky-700 transition-all duration-200 hover:-translate-y-0.5 hover:bg-sky-100 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300"
                                        @click="analyzeUpload(upload.id)"
                                    >
                                        <PlayCircle class="size-4" />
                                        Analizar
                                    </button>

                                    <button
                                        type="button"
                                        class="app-btn h-10 border border-rose-200 bg-rose-50 px-4 text-rose-700 transition-all duration-200 hover:-translate-y-0.5 hover:bg-rose-100 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300"
                                        :disabled="isDeletingId(upload.id)"
                                        @click="deleteUpload(upload.id)"
                                    >
                                        <LoaderCircle
                                            v-if="isDeletingId(upload.id)"
                                            class="size-4 animate-spin"
                                        />
                                        <Trash2 v-else class="size-4" />
                                        Quitar
                                    </button>
                                </div>
                            </article>
                        </div>

                        <div
                            v-else
                            class="px-4 py-10 text-center sm:px-5"
                        >
                            <FolderOpen class="mx-auto size-6 text-muted-foreground" />
                            <p class="mt-3 text-sm font-semibold">Aún no hay archivos cargados</p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Sube primero una fuente para comenzar este periodo.
                            </p>
                        </div>

                        <div v-if="selectedPeriodRow" class="border-t px-4 py-4 sm:px-5">
                            <a
                                class="app-btn app-btn-primary h-11 px-5"
                                :href="`/reportes-mensuales/${selectedPeriodRow.id}/radiografia.xlsx`"
                            >
                                Generar radiografía (Excel/CSV)
                                <ArrowUpRight class="size-4" />
                            </a>
                        </div>
                    </section>
                </section>
            </div>
        </div>
    </div>
</template>
