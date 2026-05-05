<script setup lang="ts">
import { DatabaseZap, ShieldCheck } from 'lucide-vue-next'
import SectionHeader from './SectionHeader.vue'
import StatusBadge from './StatusBadge.vue'

defineProps<{ period: any; canUpdate: boolean; active?: boolean }>()
const emit = defineEmits(['update'])
</script>

<template>
    <section class="rounded-[2rem] border border-white/70 bg-white p-6 shadow-xl shadow-slate-200/70">
        <SectionHeader eyebrow="Etapa 2" title="Actualización de base de datos" description="Este paso actualiza la base operativa usando NOI Nómina y Lendus Ingresos Cobranza; también registra incidencias para revisión." />
        <div class="mt-6 grid gap-4 lg:grid-cols-[1fr_0.8fr]">
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                <div class="flex items-center gap-3">
                    <DatabaseZap class="size-6 text-indigo-600" />
                    <div>
                        <p class="font-black text-slate-950">Fuentes obligatorias para BD</p>
                        <p class="text-sm text-slate-500">NOI Nómina + Lendus Ingresos Cobranza</p>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <StatusBadge :status="period?.missing_database_sources?.includes('noi_nomina') ? 'blocked' : 'completed'" label="NOI Nómina" />
                    <StatusBadge :status="period?.missing_database_sources?.includes('lendus_ingresos_cobranza') ? 'blocked' : 'completed'" label="Cobranza" />
                </div>
                <ul v-if="period?.blocking_reasons?.length" class="mt-4 list-disc space-y-1 pl-5 text-sm text-slate-600">
                    <li v-for="reason in period.blocking_reasons.filter((r:string) => r.includes('BD') || r.includes('actualiza'))" :key="reason">{{ reason }}</li>
                </ul>
            </div>
            <div class="rounded-2xl border border-indigo-100 bg-indigo-50 p-5">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-sm font-black text-slate-950">Estado actual</p>
                        <p class="mt-1 text-sm text-slate-600">{{ period?.database_updated ? 'Base actualizada y vigente.' : canUpdate ? 'Lista para ejecutar.' : 'Aún faltan fuentes para continuar.' }}</p>
                    </div>
                    <StatusBadge :status="period?.database_updated ? 'completed' : canUpdate ? 'ready' : 'blocked'" />
                </div>
                <button type="button" class="mt-5 inline-flex h-12 w-full items-center justify-center rounded-2xl bg-indigo-600 px-5 text-sm font-black text-white shadow-lg shadow-indigo-200 transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-100 disabled:cursor-not-allowed disabled:opacity-50" :disabled="!canUpdate || period?.database_updated" @click="emit('update')">
                    <ShieldCheck class="mr-2 size-5" />
                    {{ period?.database_updated ? 'BD actualizada' : 'Actualizar base de datos' }}
                </button>
            </div>
        </div>
    </section>
</template>
