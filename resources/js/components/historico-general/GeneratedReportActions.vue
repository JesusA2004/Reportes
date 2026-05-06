<script setup lang="ts">
import { computed } from 'vue'
import { Download, ExternalLink, FileSpreadsheet, FileText } from 'lucide-vue-next'
import SectionHeader from './SectionHeader.vue'
import StatusBadge from './StatusBadge.vue'

const props = defineProps<{ period: any }>()

const canExport  = computed(() => !!props.period?.can_export_radiography)
const isRunning  = computed(() => !!props.period?.radiography_running)
const previewUrl = computed(() =>
    props.period?.radiography_ready ? `/reportes-mensuales/${props.period.id}/preview` : null
)
const excelUrl = computed(() => props.period ? `/reportes-mensuales/${props.period.id}/radiografia.xlsx` : '#')
const pdfUrl   = computed(() => props.period ? `/reportes-mensuales/${props.period.id}/radiografia.pdf` : '#')
</script>

<template>
    <section class="rounded-[2rem] border border-white/70 bg-white p-6 shadow-xl shadow-slate-200/70">
        <SectionHeader
            eyebrow="Etapa 6"
            title="Exportación / archivos generados"
            description="Descarga el Excel y PDF generados desde cero, o abre la vista previa completa del reporte."
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
                    <span class="text-xs text-slate-600">Generado desde cero · sin plantilla</span>
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
                    <span class="text-xs text-slate-600">Diseño con tablas y métricas</span>
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
