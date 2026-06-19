<script setup lang="ts">
import { computed } from 'vue'
import VueApexCharts from 'vue3-apexcharts'

const props = defineProps<{
    title: string
    subtitle?: string
    type?: 'bar' | 'donut' | 'line' | 'area' | 'radialBar'
    height?: number
    series: any[]
    options: Record<string, any>
}>()

const isEmpty = computed(() => {
    if (!props.series || props.series.length === 0) return true
    const total = props.series.reduce((sum: number, s: any) => {
        const data = Array.isArray(s) ? s : (s?.data ?? [])
        return sum + (data as number[]).reduce((a: number, b: number) => a + Math.abs(Number(b) || 0), 0)
    }, 0)
    return total === 0
})
</script>

<template>
    <div class="rounded-2xl border bg-white p-5 shadow-sm transition hover:shadow-md">
        <div class="mb-3">
            <h3 class="text-xs font-black uppercase tracking-wider text-slate-500">{{ title }}</h3>
            <p v-if="subtitle" class="mt-0.5 text-[11px] text-slate-400">{{ subtitle }}</p>
        </div>
        <div v-if="isEmpty" class="flex items-center justify-center text-center text-xs text-slate-400" :style="{ height: (height ?? 260) + 'px' }">
            Sin datos disponibles para este periodo.
        </div>
        <VueApexCharts v-else :type="type ?? 'bar'" :height="height ?? 260" :options="options" :series="series" />
    </div>
</template>
