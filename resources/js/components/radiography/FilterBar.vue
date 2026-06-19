<script setup lang="ts">
import { computed, ref } from 'vue'
import { Filter, Search, X } from 'lucide-vue-next'

defineProps<{
    branchOptions: string[]
    productOptions: string[]
    bucketOptions: string[]
    gestorOptions: string[]
    categoriaOptions: string[]
}>()

const branch = defineModel<string>('branch', { default: '' })
const product = defineModel<string>('product', { default: '' })
const bucket = defineModel<string>('bucket', { default: '' })
const gestor = defineModel<string>('gestor', { default: '' })
const categoria = defineModel<string>('categoria', { default: '' })

const gestorSearch = ref('')

const activeCount = computed(() => [branch.value, product.value, bucket.value, gestor.value, categoria.value].filter(Boolean).length)

function clearAll() {
    branch.value = ''
    product.value = ''
    bucket.value = ''
    gestor.value = ''
    categoria.value = ''
    gestorSearch.value = ''
}

const selectClass =
    'rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100'
</script>

<template>
    <div class="rounded-2xl border bg-white p-4 shadow-sm">
        <div class="flex flex-wrap items-center gap-2.5">
            <span class="flex items-center gap-1.5 text-xs font-black uppercase tracking-wider text-slate-500">
                <Filter class="size-3.5 text-indigo-500" /> Filtros
            </span>

            <select v-model="branch" :class="selectClass">
                <option value="">Todas las sucursales</option>
                <option v-for="b in branchOptions" :key="b" :value="b">{{ b }}</option>
            </select>

            <select v-model="product" :class="selectClass">
                <option value="">Todos los productos</option>
                <option v-for="p in productOptions" :key="p" :value="p">{{ p }}</option>
            </select>

            <select v-model="bucket" :class="selectClass">
                <option value="">Toda la mora</option>
                <option v-for="b in bucketOptions" :key="b" :value="b">{{ b }}</option>
            </select>

            <select v-model="categoria" :class="selectClass">
                <option value="">Todas las categorías</option>
                <option v-for="c in categoriaOptions" :key="c" :value="c">{{ c }}</option>
            </select>

            <div class="relative">
                <select v-model="gestor" :class="[selectClass, 'max-w-[180px]']">
                    <option value="">Sin gestor seleccionado</option>
                    <option v-for="g in gestorOptions.filter((o) => !gestorSearch.trim() || o.toLowerCase().includes(gestorSearch.trim().toLowerCase()))" :key="g" :value="g">
                        {{ g }}
                    </option>
                </select>
            </div>

            <div v-if="gestorOptions.length > 12" class="relative">
                <Search class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-slate-400" />
                <input
                    v-model="gestorSearch"
                    type="text"
                    placeholder="Buscar gestor…"
                    class="w-36 rounded-xl border border-slate-200 bg-white py-1.5 pl-8 pr-2 text-xs focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100"
                />
            </div>

            <span v-if="activeCount > 0" class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2.5 py-1 text-[11px] font-black text-indigo-700">
                {{ activeCount }} filtro{{ activeCount > 1 ? 's' : '' }} activo{{ activeCount > 1 ? 's' : '' }}
            </span>

            <button
                v-if="activeCount > 0"
                type="button"
                @click="clearAll"
                class="ml-auto inline-flex items-center gap-1.5 rounded-xl bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-600 transition hover:bg-slate-200"
            >
                <X class="size-3.5" /> Limpiar filtros
            </button>
        </div>
    </div>
</template>
