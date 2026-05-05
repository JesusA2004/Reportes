<script setup lang="ts">
defineProps<{ eyebrow?: string; title: string; description?: string }>()
</script>

<template>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p v-if="eyebrow" class="text-xs font-black uppercase tracking-[0.22em] text-indigo-600">{{ eyebrow }}</p>
            <h2 class="mt-1 text-xl font-black tracking-tight text-slate-950 sm:text-2xl">{{ title }}</h2>
            <p v-if="description" class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ description }}</p>
        </div>
        <slot />
    </div>
</template>
