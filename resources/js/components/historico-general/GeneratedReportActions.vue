<script setup lang="ts">
import { computed } from 'vue'
import { Download, ExternalLink, FileSpreadsheet, FileText } from 'lucide-vue-next'
import SectionHeader from './SectionHeader.vue'
import StatusBadge from './StatusBadge.vue'

const props = defineProps<{ period: any; config?: any }>()

const canExport = computed(() => !!props.period?.can_export_radiography)
const isRunning = computed(() => !!props.period?.radiography_running)

// report_type distinto de 'simple' (comparativo mes/bimestre/trimestre vs vs) TAMBIÉN
// cuenta como "filtrado" — antes solo se miraba scope, así que un comparativo con
// scope=general terminaba usando la ruta plana del reporte simple.
const isComparative = computed(() => !!props.config?.report_type && props.config.report_type !== 'simple')
const isFiltered = computed(() =>
    props.config?.scope === 'branch' || props.config?.scope === 'employee' || isComparative.value
)

const filteredParams = computed(() => {
    if (!isFiltered.value) return ''
    const p = new URLSearchParams({ scope: props.config.scope ?? 'general', report_type: props.config.report_type ?? 'simple' })
    if (props.config.scope === 'branch'   && props.config.branch_id)   p.set('branch_id',   String(props.config.branch_id))
    if (props.config.scope === 'employee' && props.config.employee_id) p.set('employee_id', String(props.config.employee_id))
    if (isComparative.value && props.config.compare_period_id) p.set('compare_period_id', String(props.config.compare_period_id))
    return '?' + p.toString()
})

// El periodo ya trae el id del último run generado (period.radiography_run_id,
// calculado por ReportUploadController). Con él se puede resolver por identidad
// exacta (simple vs comparativo vs por sucursal/gestor) en vez de adivinar la ruta
// plana del reporte simple. Sin run_id todavía, se usa el endpoint de exportación
// filtrada bajo demanda como respaldo (ya corregido para incluir compare_period_id).
const runId = computed(() => props.period?.radiography_run_id ?? null)

const excelUrl = computed(() => {
    if (!props.period) return '#'
    if (isFiltered.value && runId.value) return `/reportes-mensuales/runs/${runId.value}/excel`
    return isFiltered.value
        ? `/reportes-mensuales/${props.period.id}/export-filtrado.xlsx${filteredParams.value}`
        : `/reportes-mensuales/${props.period.id}/radiografia.xlsx`
})

const pdfUrl = computed(() => {
    if (!props.period) return '#'
    if (isFiltered.value && runId.value) return `/reportes-mensuales/runs/${runId.value}/pdf`
    return isFiltered.value
        ? `/reportes-mensuales/${props.period.id}/export-filtrado.pdf${filteredParams.value}`
        : `/reportes-mensuales/${props.period.id}/radiografia.pdf`
})

const previewUrl = computed(() => {
    if (!props.period?.radiography_ready) return null
    if (isFiltered.value && runId.value) return `/reportes-mensuales/runs/${runId.value}/ver`
    const base = `/reportes-mensuales/${props.period.id}/preview`
    if (props.config?.scope === 'branch'   && props.config.branch_id)   return `${base}?scope=branch&branch_id=${props.config.branch_id}`
    if (props.config?.scope === 'employee' && props.config.employee_id) return `${base}?scope=employee&employee_id=${props.config.employee_id}`
    return base
})

const excelSubtitle = computed(() => {
    if (isComparative.value)                return 'Comparativo — archivo distinto al reporte simple'
    if (props.config?.scope === 'branch')   return 'Filtrado por sucursal · sin plantilla'
    if (props.config?.scope === 'employee') return 'Filtrado por gestor · sin plantilla'
    return 'Generado desde cero · sin plantilla'
})
const pdfSubtitle = computed(() => {
    if (isComparative.value)                return 'Comparativo — archivo distinto al reporte simple'
    if (props.config?.scope === 'branch')   return 'Diseño filtrado por sucursal'
    if (props.config?.scope === 'employee') return 'Diseño filtrado por gestor'
    return 'Diseño con tablas y métricas'
})
</script>

<template>
    <section class="rounded-[2rem] border border-white/70 bg-white p-6 shadow-xl shadow-slate-200/70">
        <SectionHeader
            eyebrow="Etapa 7"
            title="Exportación"
            description="Descarga Excel y PDF generados desde el mismo resultado del reporte. Ambos archivos reflejan exactamente lo que ves en la vista previa."
        />

        <div class="mt-6 grid gap-4 md:grid-cols-3">
            <!-- Excel -->
            <a
                :href="canExport ? excelUrl : '#'"
                class="flex items-center justify-between rounded-2xl border p-5 transition hover:-translate-y-0.5 hover:shadow-lg"
                :class="canExport
                    ? 'border-emerald-200 bg-emerald-50 cursor-pointer'
                    : 'border-slate-200 bg-slate-50 pointer-events-none opacity-50'"
            >
                <span>
                    <FileSpreadsheet class="mb-3 size-7 text-emerald-700" />
                    <span class="block font-black text-slate-950">Descargar Excel</span>
                    <span class="text-xs text-slate-600">{{ excelSubtitle }}</span>
                </span>
                <Download class="size-5 text-emerald-700" />
            </a>

            <!-- PDF -->
            <a
                :href="canExport ? pdfUrl : '#'"
                class="flex items-center justify-between rounded-2xl border p-5 transition hover:-translate-y-0.5 hover:shadow-lg"
                :class="canExport
                    ? 'border-rose-200 bg-rose-50 cursor-pointer'
                    : 'border-slate-200 bg-slate-50 pointer-events-none opacity-50'"
            >
                <span>
                    <FileText class="mb-3 size-7 text-rose-700" />
                    <span class="block font-black text-slate-950">Descargar PDF</span>
                    <span class="text-xs text-slate-600">{{ pdfSubtitle }}</span>
                </span>
                <Download class="size-5 text-rose-700" />
            </a>

            <!-- Preview page -->
            <a
                :href="previewUrl ?? '#'"
                class="flex items-center justify-between rounded-2xl border p-5 transition hover:-translate-y-0.5 hover:shadow-lg"
                :class="previewUrl
                    ? 'border-indigo-200 bg-indigo-50 cursor-pointer'
                    : 'border-slate-200 bg-slate-50 pointer-events-none opacity-50'"
            >
                <span>
                    <ExternalLink class="mb-3 size-7 text-indigo-700" />
                    <span class="block font-black text-slate-950">Ver reporte completo</span>
                    <span class="text-xs text-slate-600">Vista previa web con todas las secciones</span>
                </span>
                <ExternalLink class="size-5 text-indigo-700" />
            </a>
        </div>

        <div class="mt-5">
            <StatusBadge
                :status="canExport ? 'completed' : isRunning ? 'running' : 'blocked'"
                :label="canExport ? 'Archivos disponibles' : isRunning ? 'Generando archivos' : 'Sin reporte exportable'"
            />
        </div>
    </section>
</template>
