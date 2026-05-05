<script setup lang="ts">
import { Clock3, MailCheck } from 'lucide-vue-next'
import StatusBadge from './StatusBadge.vue'

defineProps<{ period: any }>()
</script>

<template>
    <section class="rounded-[2rem] border border-white/70 bg-white p-6 shadow-xl shadow-slate-200/70">
        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div class="flex gap-4">
                <div class="flex size-12 items-center justify-center rounded-2xl bg-slate-950 text-white"><Clock3 class="size-6" /></div>
                <div>
                    <p class="text-xs font-black uppercase tracking-[0.22em] text-indigo-600">Estado de generación</p>
                    <h3 class="mt-1 text-lg font-black text-slate-950">{{ period?.radiography_run_log ?? 'Sin generación en curso' }}</h3>
                    <p class="mt-1 text-sm text-slate-600">Cuando el job termine, el usuario recibirá un correo y el reporte quedará en Reportes mensuales.</p>
                </div>
            </div>
            <StatusBadge :status="period?.radiography_running ? 'running' : period?.radiography_ready ? 'completed' : period?.radiography_run_status === 'failed' ? 'error' : 'pending'" :label="period?.radiography_run_status ?? (period?.radiography_ready ? 'success' : 'Pendiente')" />
        </div>
        <div class="mt-5 flex items-center gap-2 rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-sm text-emerald-800">
            <MailCheck class="size-5" />
            Te avisaremos por correo cuando la Radiografía esté lista o si falla.
        </div>
    </section>
</template>
