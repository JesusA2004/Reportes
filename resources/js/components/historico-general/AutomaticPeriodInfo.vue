<script setup lang="ts">
import { Layers3, LockKeyhole } from 'lucide-vue-next'
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
                    <p class="text-xs font-black uppercase tracking-[0.22em] text-violet-700">Periodo automático</p>
                    <h3 class="mt-1 text-xl font-black text-slate-950">Este periodo no recibe archivos directos</h3>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                        Sus datos se integran a partir de los periodos base que lo componen. Carga y procesa las semanas fuente; después podrás generar la Radiografía automática desde aquí.
                    </p>
                </div>
            </div>
            <StatusBadge :status="period.can_generate_radiography ? 'ready' : 'blocked'" :label="period.can_generate_radiography ? 'Listo para generar' : 'Faltan fuentes base'" />
        </div>

        <div class="mt-6 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <div v-for="source in period.source_periods ?? []" :key="source.id" class="rounded-2xl border bg-white/85 p-4 shadow-sm" :class="source.complete ? 'border-emerald-100' : 'border-amber-100'">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="font-black text-slate-950">{{ source.label }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ source.start_date }} → {{ source.end_date }}</p>
                    </div>
                    <StatusBadge :status="source.complete ? 'completed' : 'blocked'" :label="source.complete ? 'Completo' : 'Pendiente'" />
                </div>
                <p class="mt-3 text-xs text-slate-500">{{ source.uploaded_sources_count }}/{{ source.required_sources_count }} fuentes procesadas</p>
            </div>
        </div>

        <div v-if="period.blocking_reasons?.length" class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            <div class="flex gap-2 font-bold"><LockKeyhole class="size-4" /> Motivos de bloqueo</div>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                <li v-for="reason in period.blocking_reasons" :key="reason">{{ reason }}</li>
            </ul>
        </div>
    </section>
</template>
