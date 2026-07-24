<script setup lang="ts">
defineProps<{ steps: string[] }>()
</script>

<template>
    <ol class="mt-4 space-y-3">
        <li v-for="(step, i) in steps" :key="i" class="flex items-start gap-3">
            <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-black text-white">{{ i + 1 }}</span>
            <span class="pt-0.5 text-sm leading-6 text-slate-600">{{ step }}</span>
        </li>
    </ol>
</template>
