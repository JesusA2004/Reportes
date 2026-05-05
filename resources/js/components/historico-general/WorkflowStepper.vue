<script setup lang="ts">
import { Check, Lock, Loader2 } from 'lucide-vue-next'
import StatusBadge from './StatusBadge.vue'

type Step = { key: string; label: string; description: string; status: 'pending' | 'ready' | 'blocked' | 'running' | 'completed' | 'error' }

defineProps<{ steps: Step[]; current: string }>()
const emit = defineEmits<{ (event: 'select', key: string): void }>()
</script>

<template>
    <section class="rounded-[2rem] border border-white/70 bg-white/90 p-4 shadow-xl shadow-slate-200/70 backdrop-blur sm:p-5">
        <div class="grid gap-3 lg:grid-cols-6">
            <button
                v-for="(step, index) in steps"
                :key="step.key"
                type="button"
                class="group relative overflow-hidden rounded-2xl border p-4 text-left transition duration-300 focus:outline-none focus:ring-4 focus:ring-indigo-100"
                :class="[
                    current === step.key
                        ? 'border-indigo-300 bg-gradient-to-br from-indigo-50 to-white shadow-lg shadow-indigo-100'
                        : 'border-slate-200 bg-white hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-md',
                    step.status === 'blocked' ? 'cursor-not-allowed opacity-75' : '',
                ]"
                :disabled="step.status === 'blocked'"
                @click="emit('select', step.key)"
            >
                <div class="flex items-start justify-between gap-3">
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl text-sm font-black transition"
                        :class="current === step.key ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600 group-hover:bg-indigo-100 group-hover:text-indigo-700'"
                    >
                        <Check v-if="step.status === 'completed'" class="size-4" />
                        <Loader2 v-else-if="step.status === 'running'" class="size-4 animate-spin" />
                        <Lock v-else-if="step.status === 'blocked'" class="size-4" />
                        <span v-else>{{ index + 1 }}</span>
                    </div>
                    <StatusBadge :status="step.status" />
                </div>
                <h3 class="mt-4 text-sm font-black text-slate-950">{{ step.label }}</h3>
                <p class="mt-1 line-clamp-2 text-xs leading-5 text-slate-500">{{ step.description }}</p>
            </button>
        </div>
    </section>
</template>
