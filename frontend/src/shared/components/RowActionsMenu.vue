<script setup lang="ts">
/**
 * Menu aksi baris tabel (tombol ⋮ "titik tiga") — SATU-SATUNYA pola aksi per baris di semua tabel (aturan UI
 * "Aksi baris lewat menu titik tiga", lihat CLAUDE.md / AGENTS.md). Dibangun di atas Radix Vue DropdownMenu
 * (ADR-020): bisa dipakai keyboard (Enter/Spasi membuka, panah memilih, Esc menutup) dan terbaca pembaca layar.
 *
 * Urutan item mengikuti `actions` apa adanya. Item `danger` (Hapus) diletakkan terakhir oleh pemanggil dan
 * otomatis diberi pemisah di atasnya. Item `hidden` tidak dirender; item `disabled` tetap tampil tetapi tidak bisa
 * dipilih. Konfirmasi (ConfirmDialog) tetap tanggung jawab halaman pemanggil lewat event `select`.
 */
import { computed } from 'vue'
import { MoreVertical } from 'lucide-vue-next'
import {
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuPortal,
  DropdownMenuRoot,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from 'radix-vue'

import type { RowAction } from './rowActions'

const props = defineProps<{
  actions: RowAction[]
  /** Nama baris untuk aria-label tombol, mis. "Aksi untuk Islam". */
  label: string
  disabled?: boolean
  testid?: string
}>()
const emit = defineEmits<{ select: [key: string] }>()

const visible = computed(() => props.actions.filter((a) => !a.hidden))
</script>

<template>
  <DropdownMenuRoot :modal="false">
    <DropdownMenuTrigger
      :disabled="disabled || visible.length === 0"
      :aria-label="label"
      :title="label"
      :data-testid="testid"
      class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-brand-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-tertiary/60 disabled:opacity-40 data-[state=open]:bg-slate-100 data-[state=open]:text-brand-primary"
    >
      <MoreVertical class="h-4 w-4" aria-hidden="true" />
    </DropdownMenuTrigger>
    <DropdownMenuPortal>
      <DropdownMenuContent
        align="end"
        :side-offset="4"
        class="z-50 min-w-44 rounded-lg border border-slate-200 bg-white p-1 text-sm shadow-lg"
        :data-testid="testid ? `${testid}-menu` : undefined"
      >
        <template v-for="(action, i) in visible" :key="action.key">
          <DropdownMenuSeparator v-if="action.danger && i > 0" class="my-1 h-px bg-slate-200" />
          <DropdownMenuItem
            :disabled="action.disabled"
            :data-action="action.key"
            class="flex cursor-pointer select-none items-center gap-2 rounded-md px-2.5 py-1.5 outline-none data-[disabled]:cursor-not-allowed data-[disabled]:opacity-40"
            :class="action.danger ? 'text-red-600 data-[highlighted]:bg-red-50' : 'text-slate-700 data-[highlighted]:bg-slate-100'"
            @select="emit('select', action.key)"
          >
            <component :is="action.icon" v-if="action.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ action.label }}
          </DropdownMenuItem>
        </template>
      </DropdownMenuContent>
    </DropdownMenuPortal>
  </DropdownMenuRoot>
</template>
