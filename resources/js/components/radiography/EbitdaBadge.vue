<script setup lang="ts">
defineProps<{ categoria: string }>()

const styles: Record<string, string> = {
    DIAMANTE:  'bg-sky-100 text-sky-700',
    MASTER:    'bg-violet-100 text-violet-700',
    SENIOR:    'bg-emerald-100 text-emerald-700',
    JUNIOR:    'bg-amber-100 text-amber-700',
    MANTENIDO: 'bg-red-100 text-red-700',
}
</script>

<template>
    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10.5px] font-black tracking-wide" :class="styles[categoria] ?? styles.MANTENIDO">
        {{ categoria }}
    </span>
</template>
