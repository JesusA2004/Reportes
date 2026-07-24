<script setup lang="ts">
defineProps<{ name: string; desc: string }>()
</script>

<template>
    <div class="flex items-start gap-2.5 rounded-xl border border-slate-200 bg-white px-3 py-2.5">
        <span class="mt-0.5 rounded-lg bg-slate-900 px-2 py-1 text-[11px] font-black text-white">{{ name }}</span>
        <span class="text-xs leading-5 text-slate-500">{{ desc }}</span>
    </div>
</template>
