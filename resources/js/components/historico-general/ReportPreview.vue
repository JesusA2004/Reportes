<script setup lang="ts">
import { computed, ref } from 'vue'
import EmptyState from './EmptyState.vue'
import SectionHeader from './SectionHeader.vue'

const props = defineProps<{ period: any; preview: any | null; config: any }>()
const tab = ref('metricas')
const tabs = [{ k: 'metricas', l: 'Métricas' }, { k: 'empleados', l: 'Empleados' }, { k: 'incidencias', l: 'Notas' }]
const money = (value: number) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(value || 0))
const metrics = computed(() => props.preview?.metrics ?? {})
</script>

<template>
    <section class="rounded-[2rem] border border-white/70 bg-white p-6 shadow-xl shadow-slate-200/70">
        <SectionHeader eyebrow="Etapa 5" title="Vista previa web" description="Revisa el reporte completo en pantalla antes de descargar. La vista respeta la configuración elegida y evita comparativos no solicitados." />
        <EmptyState v-if="!period?.radiography_ready" class="mt-6" title="Aún no hay reporte generado" description="Genera la Radiografía para habilitar la vista previa web y las exportaciones." />
        <div v-else class="mt-6 overflow-hidden rounded-[1.5rem] border border-slate-200">
            <div class="bg-slate-950 p-6 text-white">
                <p class="text-xs font-black uppercase tracking-[0.22em] text-indigo-200">Radiografía</p>
                <h3 class="mt-2 text-2xl font-black">{{ period.label }}</h3>
                <p class="mt-2 text-sm text-slate-300">Tipo: {{ config.report_type === 'simple' ? 'Radiografía simple' : 'Comparativo explícito' }} • Alcance: {{ config.scope }}</p>
            </div>
            <div class="flex gap-2 overflow-x-auto border-b border-slate-200 bg-slate-50 p-3">
                <button v-for="item in tabs" :key="item.k" type="button" class="rounded-xl px-4 py-2 text-sm font-black transition" :class="tab === item.k ? 'bg-white text-indigo-700 shadow' : 'text-slate-600 hover:bg-white'" @click="tab = item.k">{{ item.l }}</button>
            </div>
            <div class="p-5">
                <div v-if="tab === 'metricas'" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div v-for="item in [{l:'Empleados',v:metrics.total_empleados ?? 0},{l:'Pagos',v:money(metrics.pagos_total)},{l:'Gastos',v:money(metrics.gasto_total)},{l:'Neto',v:money(metrics.neto_total)}]" :key="item.l" class="rounded-2xl border border-slate-200 bg-slate-50 p-4"><p class="text-xs font-bold text-slate-500">{{ item.l }}</p><p class="mt-2 text-xl font-black text-slate-950">{{ item.v }}</p></div>
                </div>
                <div v-else-if="tab === 'empleados'" class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead><tr class="border-b text-left text-slate-500"><th class="px-3 py-3">Empleado</th><th class="px-3 py-3">Sucursal</th><th class="px-3 py-3 text-right">Pagos</th><th class="px-3 py-3 text-right">Gastos</th><th class="px-3 py-3 text-right">Neto</th></tr></thead>
                        <tbody><tr v-for="row in preview?.employees ?? []" :key="row.id" class="border-b last:border-b-0"><td class="px-3 py-3 font-bold">{{ row.employee_name }}</td><td class="px-3 py-3">{{ row.branch_name ?? 'Sin sucursal' }}</td><td class="px-3 py-3 text-right">{{ money(row.total_payments) }}</td><td class="px-3 py-3 text-right">{{ money(row.total_expenses) }}</td><td class="px-3 py-3 text-right font-black">{{ money(row.net_amount) }}</td></tr></tbody>
                    </table>
                </div>
                <div v-else class="rounded-2xl bg-slate-50 p-5 text-sm text-slate-600">Secciones reales cargadas: GLOBAL y Dashbord según la plantilla configurada. Los comparativos solo aparecen cuando el usuario elige un tipo comparativo.</div>
            </div>
        </div>
    </section>
</template>
