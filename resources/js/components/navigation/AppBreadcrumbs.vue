<script setup lang="ts">
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from '@/components/ui/breadcrumb'

defineProps<{
  items: Array<{
    label: string
    href?: string | null
  }>
}>()
</script>

<template>
  <Breadcrumb>
    <BreadcrumbList>
      <template
        v-for="(item, index) in items"
        :key="`${item.label}-${index}`"
      >
        <BreadcrumbItem>
          <BreadcrumbLink
            v-if="item.href && index !== items.length - 1"
            :href="item.href"
          >
            {{ item.label }}
          </BreadcrumbLink>

          <BreadcrumbPage v-else>
            {{ item.label }}
          </BreadcrumbPage>
        </BreadcrumbItem>

        <BreadcrumbSeparator v-if="index !== items.length - 1" />
      </template>
    </BreadcrumbList>
  </Breadcrumb>
</template>
