<script setup lang="ts">
import { computed, ref } from 'vue';
const props = defineProps<{ source: any; upload?: any; selectedPeriodId: number | null }>();
const emit = defineEmits<{ (e: 'upload', payload: { sourceId: number; file: File }): void; (e: 'delete', id: number): void }>();
const file = ref<File | null>(null);
const statusLabel = computed(() => ({ pending:'Pendiente de procesar', processing:'Procesando', processed:'Procesado', failed:'Error al procesar' } as any)[props.upload?.status] ?? 'Pendiente');
</script>
<template>
  <div class="rounded-xl border bg-white p-4">
    <h3 class="font-semibold">{{ source.name }}</h3>
    <p class="text-xs text-slate-500">{{ statusLabel }}</p>
    <p class="mt-2 text-sm">{{ upload?.original_name ?? 'Sin archivo cargado' }}</p>
    <p class="text-xs text-slate-500">{{ upload?.uploaded_at ?? '—' }}</p>
    <p v-if="upload?.notes" class="mt-2 text-xs text-rose-600">{{ upload.notes }}</p>
    <div class="mt-3 flex gap-2">
      <input type="file" class="text-xs" @change="file = (($event.target as HTMLInputElement).files?.[0] ?? null)" />
      <button class="rounded bg-indigo-600 px-3 py-1 text-xs text-white disabled:opacity-50" :disabled="!file || !selectedPeriodId" @click="file && emit('upload', { sourceId: source.id, file: file })">{{ upload ? 'Reemplazar' : 'Subir' }}</button>
      <button v-if="upload" class="rounded border px-3 py-1 text-xs" @click="emit('delete', upload.id)">Eliminar</button>
    </div>
  </div>
</template>
