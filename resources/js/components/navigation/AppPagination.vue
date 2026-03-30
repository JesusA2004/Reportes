<script setup lang="ts">
import { computed } from 'vue'
import {
  Pagination,
  PaginationContent,
  PaginationEllipsis,
  PaginationItem,
  PaginationNext,
  PaginationPrevious,
} from '@/components/ui/pagination'

const props = defineProps<{
  currentPage: number
  lastPage: number
}>()

const emit = defineEmits<{
  (e: 'change', page: number): void
}>()

const pages = computed(() => {
  const total = props.lastPage
  const current = props.currentPage

  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1)

  const items: (number | '...')[] = [1]

  if (current > 3) items.push('...')
  for (let i = Math.max(2, current - 1); i <= Math.min(total - 1, current + 1); i++) {
    items.push(i)
  }
  if (current < total - 2) items.push('...')
  items.push(total)

  return items
})

const go = (page: number) => {
  if (page < 1 || page > props.lastPage || page === props.currentPage) return
  emit('change', page)
}
</script>

<template>
  <Pagination
    :page="currentPage"
    :total="lastPage"
    class="w-full"
  >
    <PaginationContent class="flex flex-wrap items-center justify-center gap-2 p-4">
      <PaginationPrevious
        class="cursor-pointer rounded-2xl border bg-background hover:bg-muted"
        :disabled="currentPage <= 1"
        @click="go(currentPage - 1)"
      />

      <template
        v-for="item in pages"
        :key="String(item)"
      >
        <PaginationItem
          v-if="item !== '...'"
          :value="Number(item)"
          class="min-w-10 cursor-pointer rounded-2xl border px-3 py-2 text-sm font-semibold transition"
          :class="item === currentPage
            ? 'border-primary bg-primary text-primary-foreground'
            : 'bg-background hover:bg-muted'"
          @click="go(Number(item))"
        >
          {{ item }}
        </PaginationItem>

        <PaginationEllipsis
          v-else
          class="px-1 text-muted-foreground"
        />
      </template>

      <PaginationNext
        class="cursor-pointer rounded-2xl border bg-background hover:bg-muted"
        :disabled="currentPage >= lastPage"
        @click="go(currentPage + 1)"
      />
    </PaginationContent>
  </Pagination>
</template>
