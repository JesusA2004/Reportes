<script setup lang="ts">
import { computed, ref } from 'vue'
import { FileCheck2, FileSpreadsheet, RefreshCw, Trash2, UploadCloud, X } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import StatusBadge from './StatusBadge.vue'

const props = defineProps<{ source: any; upload?: any; disabled?: boolean; selectedPeriodId: number | null }>()
const emit = defineEmits<{
    (event: 'upload', payload: { sourceId: number; file: File }): void
    (event: 'delete', id: number): void
    (event: 'reprocess', id: number): void
}>()

const file = ref<File | null>(null)
const dragActive = ref(false)
const inputRef = ref<HTMLInputElement | null>(null)

const isRequiredForDb = computed(() => ['noi_nomina', 'lendus_ingresos_cobranza'].includes(props.source.code))
const status = computed(() => props.upload?.status ?? 'pending')
const statusLabel = computed(() => ({ pending: props.upload ? 'Sin procesar' : 'Sin cargar', processing: 'Procesando', processed: 'Procesado', failed: 'Con error' } as Record<string, string>)[status.value] ?? 'Sin cargar')
const acceptText = '.xls, .xlsx, .xlsm'

const assignFile = async (selected: File | null) => {
    if (!selected) return
    const valid = /\.(xls|xlsx|xlsm)$/i.test(selected.name)
    if (!valid) {
        await Swal.fire({ title: 'Formato no válido', text: 'Carga únicamente archivos Excel: xls, xlsx o xlsm.', icon: 'error', confirmButtonText: 'Entendido' })
        return
    }
    file.value = selected
    await Swal.fire({ title: 'Archivo seleccionado', text: selected.name, icon: 'info', timer: 1500, showConfirmButton: false })
}

const onDrop = (event: DragEvent) => {
    dragActive.value = false
    if (props.disabled) return
    assignFile(event.dataTransfer?.files?.[0] ?? null)
}

const doUpload = () => {
    if (!file.value) return
    emit('upload', { sourceId: props.source.id, file: file.value })
    file.value = null
    if (inputRef.value) inputRef.value.value = ''
}
</script>

<template>
    <article class="group rounded-[1.75rem] border bg-white p-5 shadow-lg shadow-slate-200/60 transition duration-300 hover:-translate-y-1 hover:shadow-xl" :class="upload?.status === 'failed' ? 'border-rose-200' : 'border-slate-200'">
        <div class="flex items-start justify-between gap-4">
            <div class="flex gap-3">
                <div class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-slate-100 text-slate-700 group-hover:bg-indigo-100 group-hover:text-indigo-700">
                    <FileSpreadsheet class="size-5" />
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="font-black text-slate-950">{{ source.name }}</h3>
                        <span v-if="isRequiredForDb" class="rounded-full bg-indigo-50 px-2 py-1 text-[11px] font-black text-indigo-700">BD</span>
                        <span class="rounded-full bg-slate-100 px-2 py-1 text-[11px] font-black text-slate-600">Obligatoria Radiografía</span>
                    </div>
                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ source.description }}</p>
                </div>
            </div>
            <StatusBadge :status="upload?.status === 'failed' ? 'failed' : upload?.status === 'processed' ? 'processed' : upload ? 'queued' : 'pending'" :label="statusLabel" />
        </div>

        <div class="mt-4 rounded-2xl border border-slate-100 bg-slate-50 p-3">
            <div v-if="upload" class="flex items-start gap-3">
                <FileCheck2 class="mt-0.5 size-4 shrink-0 text-emerald-600" />
                <div class="min-w-0 text-xs">
                    <p class="truncate font-bold text-slate-800">{{ upload.original_name }}</p>
                    <p class="mt-1 text-slate-500">Cargado: {{ upload.uploaded_at ?? '—' }}</p>
                    <p v-if="upload.covered_period_labels?.length" class="mt-1 text-slate-500">Cobertura: {{ upload.covered_period_labels.join(', ') }}</p>
                    <p v-if="upload.notes" class="mt-2 rounded-xl bg-rose-50 p-2 text-rose-700">{{ upload.notes }}</p>
                </div>
            </div>
            <p v-else class="text-xs text-slate-500">Aún no se ha cargado archivo para esta fuente.</p>
        </div>

        <div
            class="mt-4 rounded-2xl border-2 border-dashed p-4 text-center transition"
            :class="dragActive ? 'border-indigo-400 bg-indigo-50' : 'border-slate-200 bg-white'"
            @dragenter.prevent="dragActive = true"
            @dragover.prevent="dragActive = true"
            @dragleave.prevent="dragActive = false"
            @drop.prevent="onDrop"
        >
            <UploadCloud class="mx-auto size-7 text-indigo-500" />
            <p class="mt-2 text-sm font-bold text-slate-800">Arrastra el Excel aquí</p>
            <p class="mt-1 text-xs text-slate-500">{{ acceptText }}</p>
            <button type="button" class="mt-3 rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 transition hover:border-indigo-200 hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-50" :disabled="disabled" @click="inputRef?.click()">Seleccionar archivo</button>
            <input ref="inputRef" type="file" class="hidden" accept=".xls,.xlsx,.xlsm" @change="assignFile(($event.target as HTMLInputElement).files?.[0] ?? null)" />
            <div v-if="file" class="mt-3 flex items-center justify-center gap-2 rounded-xl bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700">
                <span class="truncate">{{ file.name }}</span>
                <button type="button" @click="file = null"><X class="size-3" /></button>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            <button type="button" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-black text-white shadow-lg shadow-indigo-100 transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50" :disabled="disabled || !file" @click="doUpload">
                {{ upload ? 'Reemplazar' : 'Subir' }}
            </button>
            <button v-if="upload" type="button" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="emit('reprocess', upload.id)"><RefreshCw class="mr-1 inline size-4" /> Reprocesar</button>
            <button v-if="upload" type="button" class="rounded-xl border border-rose-200 px-4 py-2 text-sm font-bold text-rose-700 transition hover:bg-rose-50" @click="emit('delete', upload.id)"><Trash2 class="mr-1 inline size-4" /> Eliminar</button>
        </div>
    </article>
</template>
