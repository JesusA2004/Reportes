<script setup lang="ts">
import { Inbox } from 'lucide-vue-next'

defineProps<{ title: string; description?: string }>()
</script>

<template>
    <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 p-8 text-center">
        <div class="mx-auto flex size-11 items-center justify-center rounded-2xl bg-white text-slate-400 shadow-sm">
            <Inbox class="size-5" />
        </div>
        <h3 class="mt-3 text-sm font-black text-slate-900">{{ title }}</h3>
        <p v-if="description" class="mx-auto mt-1.5 max-w-md text-xs leading-5 text-slate-500">{{ description }}</p>
    </div>
</template>
