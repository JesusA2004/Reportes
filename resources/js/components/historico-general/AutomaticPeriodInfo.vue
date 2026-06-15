<script setup lang="ts">
import { Info, Layers3, LockKeyhole } from 'lucide-vue-next'
import StatusBadge from './StatusBadge.vue'

defineProps<{ period: any }>()
</script>

<template>
    <section class="rounded-[2rem] border border-violet-100 bg-gradient-to-br from-violet-50 via-white to-indigo-50 p-6 shadow-xl shadow-slate-200/70">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex gap-4">
                <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-violet-600 text-white shadow-lg shadow-violet-200">
                    <Layers3 class="size-6" />
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <p class="text-xs font-black uppercase tracking-[0.22em] text-violet-700">Periodo automático</p>
                        <span class="group relative cursor-help">
                            <Info class="size-3.5 text-violet-400 hover:text-violet-600 transition-colors" />
                            <div class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-2 w-72 -translate-x-1/2 rounded-2xl bg-slate-900 px-4 py-3 text-xs leading-5 text-white opacity-0 shadow-xl transition-opacity group-hover:opacity-100">
                                Bimestres, trimestres, semestres y años se generan automáticamente a partir de meses operativos. No aceptan carga directa de archivos.
                            </div>
                        </span>
                    </div>
                    <h3 class="mt-1 text-xl font-black text-slate-950">Este periodo no requiere carga directa de archivos</h3>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                        Se construye con la información procesada de sus meses operativos. Primero carga y genera los reportes de los meses que lo componen; después podrás consultar el reporte consolidado de este periodo.
                    </p>
                </div>
            </div>
            <StatusBadge
                :status="period.radiography_ready ? 'consolidated' : period.can_generate_automatic ? 'ready_to_consolidate' : 'waiting'"
                :label="period.radiography_ready ? 'Consolidado' : period.can_generate_automatic ? 'Listo para consolidar' : 'Esperando meses'"
            />
        </div>

        <!-- Meses componentes -->
        <div v-if="period.automatic_components?.length" class="mt-6 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <div
                v-for="comp in period.automatic_components"
                :key="comp.id"
                class="rounded-2xl border bg-white/85 p-4 shadow-sm"
                :class="comp.has_report ? 'border-emerald-100' : 'border-amber-100'"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="font-black text-slate-950">{{ comp.label }}</p>
                        <p class="mt-1 text-xs text-slate-500">Mes operativo</p>
                    </div>
                    <StatusBadge
                        :status="comp.has_report ? 'generated' : comp.report_status === 'database_updated' ? 'ready' : 'pending'"
                        :label="comp.has_report ? 'Reporte generado' : comp.report_status === 'database_updated' ? 'BD cargada' : 'Sin reporte'"
                    />
                </div>
            </div>
        </div>

        <!-- Fallback: sin datos de componentes -->
        <div v-else-if="period.component_labels?.length" class="mt-6 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <div v-for="label in period.component_labels" :key="label" class="rounded-2xl border border-slate-200 bg-white/85 p-4 shadow-sm">
                <p class="font-black text-slate-950">{{ label }}</p>
                <p class="mt-1 text-xs text-slate-500">Mes componente</p>
            </div>
        </div>

        <div v-if="period.missing_component_months?.length" class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            <div class="flex gap-2 font-bold"><LockKeyhole class="size-4" /> Faltan reportes mensuales</div>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                <li v-for="m in period.missing_component_months" :key="m">{{ m }}</li>
            </ul>
        </div>

        <div v-else-if="period.blocking_reasons?.length" class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            <div class="flex gap-2 font-bold"><LockKeyhole class="size-4" /> Motivos de bloqueo</div>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                <li v-for="reason in period.blocking_reasons" :key="reason">{{ reason }}</li>
            </ul>
        </div>
    </section>
</template>
