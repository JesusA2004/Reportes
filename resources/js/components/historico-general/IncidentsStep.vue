<script setup lang="ts">
import { computed, ref } from 'vue'
import { AlertTriangle, CheckCircle2, Search } from 'lucide-vue-next'
import EmptyState from './EmptyState.vue'
import SectionHeader from './SectionHeader.vue'
import StatusBadge from './StatusBadge.vue'

const props = defineProps<{ incidents: any[]; period: any }>()
const emit = defineEmits(['resolve', 'refresh'])
const query = ref('')
const filter = ref('all')

const summary = computed(() => ({
    critical: props.incidents.filter((item) => item.severity === 'high').length,
    warnings: props.incidents.filter((item) => item.severity === 'warning').length,
    resolved: props.incidents.filter((item) => item.severity === 'resolved').length,
    pending: props.incidents.filter((item) => item.severity !== 'resolved').length,
}))
const filtered = computed(() => props.incidents.filter((item) => {
    const matchesFilter = filter.value === 'all' || item.severity === filter.value
    const term = query.value.trim().toLowerCase()
    const matchesSearch = !term || [item.type, item.message, JSON.stringify(item.context ?? {})].join(' ').toLowerCase().includes(term)
    return matchesFilter && matchesSearch
}))
</script>

<template>
    <section class="rounded-[2rem] border border-white/70 bg-white p-6 shadow-xl shadow-slate-200/70">
        <SectionHeader eyebrow="Etapa 3" title="Incidencias integradas" description="Revisa y resuelve hallazgos críticos antes de configurar la generación del reporte." >
            <button type="button" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="emit('refresh')">Refrescar</button>
        </SectionHeader>

        <div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl border border-rose-100 bg-rose-50 p-4"><p class="text-xs font-bold text-rose-700">Críticas</p><p class="mt-1 text-2xl font-black text-rose-800">{{ summary.critical }}</p></div>
            <div class="rounded-2xl border border-amber-100 bg-amber-50 p-4"><p class="text-xs font-bold text-amber-700">Advertencias</p><p class="mt-1 text-2xl font-black text-amber-800">{{ summary.warnings }}</p></div>
            <div class="rounded-2xl border border-emerald-100 bg-emerald-50 p-4"><p class="text-xs font-bold text-emerald-700">Resueltas</p><p class="mt-1 text-2xl font-black text-emerald-800">{{ summary.resolved }}</p></div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4"><p class="text-xs font-bold text-slate-600">Pendientes</p><p class="mt-1 text-2xl font-black text-slate-900">{{ summary.pending }}</p></div>
        </div>

        <EmptyState v-if="!incidents.length" class="mt-6" title="Sin incidencias críticas" description="La base operativa no tiene incidencias pendientes para este periodo." />
        <div v-else class="mt-6 space-y-4">
            <div class="flex flex-col gap-3 md:flex-row">
                <label class="relative flex-1">
                    <Search class="pointer-events-none absolute left-4 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input v-model="query" class="h-11 w-full rounded-2xl border border-slate-200 bg-slate-50 pl-11 pr-4 text-sm outline-none focus:border-indigo-300 focus:ring-4 focus:ring-indigo-100" placeholder="Buscar incidencia..." />
                </label>
                <div class="flex flex-wrap gap-2">
                    <button v-for="option in [{k:'all',l:'Todas'},{k:'high',l:'Críticas'},{k:'warning',l:'Advertencias'},{k:'resolved',l:'Resueltas'}]" :key="option.k" type="button" class="rounded-xl px-4 py-2 text-sm font-bold transition" :class="filter === option.k ? 'bg-indigo-600 text-white' : 'border border-slate-200 text-slate-700 hover:bg-slate-50'" @click="filter = option.k">{{ option.l }}</button>
                </div>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200">
                <div v-for="item in filtered" :key="item.id" class="border-b border-slate-100 p-4 last:border-b-0">
                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div class="flex gap-3">
                            <AlertTriangle v-if="item.severity === 'high'" class="mt-1 size-5 shrink-0 text-rose-600" />
                            <CheckCircle2 v-else-if="item.severity === 'resolved'" class="mt-1 size-5 shrink-0 text-emerald-600" />
                            <AlertTriangle v-else class="mt-1 size-5 shrink-0 text-amber-600" />
                            <div>
                                <p class="font-black text-slate-950">{{ item.type }}</p>
                                <p class="mt-1 text-sm leading-6 text-slate-600">{{ item.message }}</p>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <StatusBadge :status="item.severity === 'resolved' ? 'resolved' : item.severity === 'high' ? 'error' : 'warning'" :label="item.severity === 'high' ? 'Crítica' : item.severity === 'resolved' ? 'Resuelta' : 'Advertencia'" />
                            <button v-if="item.severity !== 'resolved'" type="button" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-bold text-white transition hover:bg-slate-700" @click="emit('resolve', item.id)">Resolver</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
