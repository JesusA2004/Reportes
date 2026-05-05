<script setup lang="ts">
import { Download, FileArchive, FileSpreadsheet } from 'lucide-vue-next'
import SectionHeader from './SectionHeader.vue'
import StatusBadge from './StatusBadge.vue'

defineProps<{ period: any }>()
</script>

<template>
    <section class="rounded-[2rem] border border-white/70 bg-white p-6 shadow-xl shadow-slate-200/70">
        <SectionHeader eyebrow="Etapa 6" title="Exportación / archivos generados" description="Al generar el reporte se guardan Excel, PDF y metadata para consulta posterior desde Reportes mensuales." />
        <div class="mt-6 grid gap-4 md:grid-cols-2">
            <a :href="period?.radiography_ready ? `/reportes-mensuales/${period.id}/radiografia.xlsx` : '#'" class="flex items-center justify-between rounded-2xl border border-emerald-100 bg-emerald-50 p-5 transition hover:-translate-y-0.5 hover:shadow-lg" :class="!period?.can_export_radiography ? 'pointer-events-none opacity-50' : ''">
                <span><FileSpreadsheet class="mb-3 size-7 text-emerald-700" /><span class="block font-black text-slate-950">Exportar Excel</span><span class="text-sm text-slate-600">Plantilla oficial generada.</span></span><Download class="size-5 text-emerald-700" />
            </a>
            <a :href="period?.radiography_ready ? `/reportes-mensuales/${period.id}/radiografia.pdf` : '#'" class="flex items-center justify-between rounded-2xl border border-rose-100 bg-rose-50 p-5 transition hover:-translate-y-0.5 hover:shadow-lg" :class="!period?.can_export_radiography ? 'pointer-events-none opacity-50' : ''">
                <span><FileArchive class="mb-3 size-7 text-rose-700" /><span class="block font-black text-slate-950">Exportar PDF</span><span class="text-sm text-slate-600">Versión para lectura y archivo.</span></span><Download class="size-5 text-rose-700" />
            </a>
        </div>
        <div class="mt-5"><StatusBadge :status="period?.can_export_radiography ? 'completed' : period?.radiography_running ? 'running' : 'blocked'" :label="period?.can_export_radiography ? 'Archivos disponibles' : period?.radiography_running ? 'Generando' : 'Sin reporte exportable'" /></div>
    </section>
</template>
