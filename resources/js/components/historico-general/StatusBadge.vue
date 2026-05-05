<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{ status?: string | null; label?: string | null }>(), {
    status: 'pending',
    label: null,
})

const normalized = computed(() => props.status ?? 'pending')

const label = computed(() => props.label ?? ({
    pending: 'Pendiente',
    ready: 'Listo',
    blocked: 'Bloqueado',
    running: 'En proceso',
    completed: 'Completo',
    error: 'Con error',
    processed: 'Procesado',
    failed: 'Con error',
    queued: 'En cola',
    warning: 'Advertencia',
    automatic: 'Automático',
    resolved: 'Resuelto',
} as Record<string, string>)[normalized.value] ?? normalized.value)

const badgeClass = computed(() => ({
    pending: 'border-slate-200 bg-slate-50 text-slate-600',
    ready: 'border-sky-200 bg-sky-50 text-sky-700',
    blocked: 'border-amber-200 bg-amber-50 text-amber-700',
    running: 'border-indigo-200 bg-indigo-50 text-indigo-700',
    completed: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    processed: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    error: 'border-rose-200 bg-rose-50 text-rose-700',
    failed: 'border-rose-200 bg-rose-50 text-rose-700',
    queued: 'border-violet-200 bg-violet-50 text-violet-700',
    warning: 'border-orange-200 bg-orange-50 text-orange-700',
    automatic: 'border-violet-200 bg-violet-50 text-violet-700',
    resolved: 'border-emerald-200 bg-emerald-50 text-emerald-700',
} as Record<string, string>)[normalized.value] ?? 'border-slate-200 bg-slate-50 text-slate-600')
</script>

<template>
    <span class="inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-bold" :class="badgeClass">
        <span class="size-1.5 rounded-full bg-current" />
        {{ label }}
    </span>
</template>
