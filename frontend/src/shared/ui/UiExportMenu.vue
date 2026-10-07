<script setup lang="ts">
/**
 * Tombol "Export" yang membuka popup pilihan format (Laporan Redesign §4.1.2, Gambar 14): "Export data pada
 * setiap halaman dibuat menjadi popup. Jenis export yang disajikan disesuaikan dengan ketersediaan dari jenis
 * file itu sendiri." — halaman menentukan `formats` sesuai yang benar-benar tersedia.
 *
 * Reusable. Komponen ini hanya memilih format; eksekusi ekspor tetap tanggung jawab halaman lewat event `select`.
 */
import { FileSpreadsheet, FileText, Upload } from 'lucide-vue-next'
import {
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuPortal,
  DropdownMenuRoot,
  DropdownMenuTrigger,
} from 'radix-vue'

import UiButton from './UiButton.vue'

export type ExportFormat = { key: 'pdf' | 'xlsx'; label: string }

withDefaults(defineProps<{ formats?: ExportFormat[]; label?: string; disabled?: boolean }>(), {
  formats: () => [{ key: 'pdf', label: 'Export PDF' }],
  label: 'Export',
  disabled: false,
})

defineEmits<{ select: [format: ExportFormat['key']] }>()
</script>

<template>
  <DropdownMenuRoot :modal="false">
    <DropdownMenuTrigger as-child>
      <UiButton variant="secondary" appearance="soft" :disabled="disabled" data-testid="export-trigger">
        <template #icon-left><Upload class="h-4 w-4" aria-hidden="true" /></template>
        {{ label }}
      </UiButton>
    </DropdownMenuTrigger>

    <DropdownMenuPortal>
      <DropdownMenuContent
        align="end"
        :side-offset="6"
        class="z-50 min-w-44 rounded-xl border border-slate-200 bg-white p-1 text-body2 shadow-panel"
        data-testid="export-menu"
      >
        <DropdownMenuItem
          v-for="format in formats"
          :key="format.key"
          class="flex cursor-pointer items-center gap-2.5 rounded-lg px-3 py-2 text-slate-700 outline-none data-[highlighted]:bg-slate-100"
          :data-format="format.key"
          @select="$emit('select', format.key)"
        >
          <FileText v-if="format.key === 'pdf'" class="h-4 w-4 text-danger" aria-hidden="true" />
          <FileSpreadsheet v-else class="h-4 w-4 text-success" aria-hidden="true" />
          {{ format.label }}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenuPortal>
  </DropdownMenuRoot>
</template>
