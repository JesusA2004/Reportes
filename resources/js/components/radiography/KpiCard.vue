<script setup lang="ts">
import type { Component } from 'vue'

const props = defineProps<{
    label: string
    value: string
    icon?: Component
    tone?: 'teal' | 'red' | 'amber' | 'green' | 'blue' | 'neutral'
    hint?: string
    active?: boolean
}>()

const toneIconClasses: Record<string, string> = {
    teal: 'bg-teal-50 text-teal-700 border-teal-100',
    red: 'bg-red-50 text-red-700 border-red-100',
    amber: 'bg-amber-50 text-amber-700 border-amber-100',
    green: 'bg-emerald-50 text-emerald-700 border-emerald-100',
    blue: 'bg-sky-50 text-sky-700 border-sky-100',
    neutral: 'bg-slate-50 text-slate-700 border-slate-100',
}

const toneValueClasses: Record<string, string> = {
    teal: 'text-slate-950',
    red: 'text-red-700',
    amber: 'text-amber-600',
    green: 'text-emerald-700',
    blue: 'text-slate-950',
    neutral: 'text-slate-950',
}
</script>

<template>
    <div
        class="rounded-2xl border bg-white p-4 shadow-sm transition hover:shadow-md"
        :class="active ? 'ring-2 ring-indigo-200 border-indigo-200' : ''"
    >
        <div class="flex items-center justify-between gap-2">
            <p class="truncate text-[10.5px] font-bold uppercase tracking-wider text-slate-400">{{ label }}</p>
            <span v-if="icon" class="flex size-7 shrink-0 items-center justify-center rounded-lg border" :class="toneIconClasses[tone ?? 'teal']">
                <component :is="icon" class="size-3.5" />
            </span>
        </div>
        <p class="mt-1.5 text-base font-black tabular-nums break-words sm:text-lg" :class="toneValueClasses[tone ?? 'teal']" :title="value">{{ value }}</p>
        <p v-if="hint" class="mt-0.5 truncate text-[10.5px] text-slate-400">{{ hint }}</p>
    </div>
</template>
