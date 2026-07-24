<script setup lang="ts">
defineProps<{ title: string }>()
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center gap-1.5 border-b border-slate-100 bg-slate-50 px-3 py-2">
            <span class="size-2 rounded-full bg-rose-300"></span>
            <span class="size-2 rounded-full bg-amber-300"></span>
            <span class="size-2 rounded-full bg-emerald-300"></span>
            <span class="ml-2 truncate text-[11px] font-semibold text-slate-400">{{ title }}</span>
        </div>
        <div class="bg-slate-50/60 p-4">
            <slot />
        </div>
    </div>
</template>
