<script setup lang="ts">
/**
 * Breadcrumb "Home / Laporan / Unit Kerja" yang muncul di bawah topbar pada seluruh halaman bab 4
 * (mis. §4.1.2, §4.1.3, §4.1.5). Ruas terakhir tidak bisa diklik dan ditandai aria-current.
 *
 * Reusable.
 */
import { ChevronRight, House } from 'lucide-vue-next'
import type { RouteLocationRaw } from 'vue-router'

export type Crumb = { label: string; to?: RouteLocationRaw }

defineProps<{ items: Crumb[] }>()
</script>

<template>
  <nav aria-label="Breadcrumb">
    <ol class="flex flex-wrap items-center gap-1 text-body2">
      <li v-for="(item, index) in items" :key="index" class="flex items-center gap-1">
        <ChevronRight v-if="index > 0" class="h-4 w-4 text-slate-400" aria-hidden="true" />
        <RouterLink
          v-if="item.to && index < items.length - 1"
          :to="item.to"
          class="inline-flex items-center gap-1.5 rounded px-1 text-brand-tertiary hover:underline"
        >
          <House v-if="index === 0" class="h-4 w-4" aria-hidden="true" />
          {{ item.label }}
        </RouterLink>
        <span v-else class="inline-flex items-center gap-1.5 px-1 text-slate-600" :aria-current="index === items.length - 1 ? 'page' : undefined">
          <House v-if="index === 0" class="h-4 w-4" aria-hidden="true" />
          {{ item.label }}
        </span>
      </li>
    </ol>
  </nav>
</template>
