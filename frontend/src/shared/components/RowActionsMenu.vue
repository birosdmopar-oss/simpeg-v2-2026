<script setup lang="ts">
/**
 * Menu aksi baris tabel (tombol ⋮ "titik tiga") — SATU-SATUNYA pola aksi per baris di semua tabel (aturan UI
 * "Aksi baris lewat menu titik tiga", lihat CLAUDE.md / AGENTS.md). Dibangun di atas Radix Vue DropdownMenu
 * (ADR-020): bisa dipakai keyboard (Enter/Spasi membuka, panah memilih, Esc menutup) dan terbaca pembaca layar.
 *
 * Urutan item mengikuti `actions` apa adanya. Item `danger` (Hapus) diletakkan terakhir oleh pemanggil dan
 * otomatis diberi pemisah di atasnya. Item `hidden` tidak dirender; item `disabled` tetap tampil tetapi tidak bisa
 * dipilih. Konfirmasi (ConfirmDialog) tetap tanggung jawab halaman pemanggil lewat event `select`.
 *
 * Item `disabled` yang punya `keterangan` (MAKE-073 review S1) TIDAK memakai prop `disabled` radix (radix melewati item itu
 * saat navigasi panah, jadi alasannya tak pernah terbaca): item tetap bisa difokus, `aria-disabled="true"`, nama = label
 * (`aria-labelledby`), keterangan lewat `aria-describedby` tanpa opacity; `pilih()` menolak aksinya (menu tetap terbuka, tanpa
 * event). Item `disabled` tanpa keterangan tetap dilewati seperti sebelumnya.
 */
import { computed, onBeforeUnmount, ref, useId } from 'vue'
import { MoreVertical } from 'lucide-vue-next'
import {
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuPortal,
  DropdownMenuRoot,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from 'radix-vue'

import UiTooltip from '@/shared/ui/UiTooltip.vue'

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
const pemicu = ref<HTMLButtonElement | null>(null)

const uid = useId()
/** id elemen keterangan item (untuk `aria-describedby`). */
const idKeterangan = (key: string): string => `${uid}-ket-${key}`
const idLabel = (key: string): string => `${uid}-label-${key}`
/** Nonaktif tetapi tetap bisa difokus (punya keterangan alasan). */
const nonaktifTerfokus = (a: RowAction): boolean => Boolean(a.disabled && a.keterangan)

/**
 * CR-078 (T-06): fokus pindah ke ⋮ SEBELUM aksi dipancarkan. Dialog konfirmasi yang dibuka aksi ini menyimpan elemen
 * yang sedang fokus sebagai tujuan kembali; tanpa ini, itu item menu yang lepas saat menu menutup, dan fokus jatuh ke
 * body setelah dialog ditutup.
 */
function pilih(action: RowAction, e: Event): void {
  if (nonaktifTerfokus(action)) {
    // S1: item nonaktif berketerangan menolak dipilih — menu tetap terbuka, tanpa event.
    e.preventDefault()
    return
  }
  pemicu.value?.focus()
  emit('select', action.key)
}

/**
 * CR-078 (T-06): baris yang memegang fokus dihapus (mis. setelah Hapus dikonfirmasi) → fokus pindah ke ⋮ baris
 * sesudahnya, atau sebelumnya bila ini baris terakhir.
 */
onBeforeUnmount(() => {
  const el = pemicu.value
  if (!el || document.activeElement !== el) return
  const baris = el.closest('tr')
  const tetangga = baris?.nextElementSibling ?? baris?.previousElementSibling
  tetangga?.querySelector<HTMLButtonElement>('button[aria-haspopup="menu"]')?.focus()
})
</script>

<template>
  <DropdownMenuRoot :modal="false">
    <!-- Tooltip custom (UiTooltip), bukan atribut `title` bawaan browser (AGENTS.md bagian 3). CR-068: pemicu
         dropdown DI LUAR UiTooltip (as-child), agar anchor popper dropdown tidak terdaftar ke konteks popper tooltip.
         `data-state` tombol milik tooltip; status buka menu dibaca dari `aria-expanded`. -->
    <DropdownMenuTrigger as-child :disabled="disabled || visible.length === 0">
      <UiTooltip :content="label">
        <button
          ref="pemicu"
          type="button"
          :disabled="disabled || visible.length === 0"
          :aria-label="label"
          :data-testid="testid"
          class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-brand-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-tertiary/60 disabled:opacity-40 aria-expanded:bg-slate-100 aria-expanded:text-brand-primary"
        >
          <MoreVertical class="h-4 w-4" aria-hidden="true" />
        </button>
      </UiTooltip>
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
          <!-- as-child: props anak menang atas props radix (PrimitiveSlot), jadi `aria-disabled` item nonaktif berketerangan
               dipasang di anak; item lain tidak membawa atribut tambahan (DOM tetap satu div menuitem). -->
          <DropdownMenuItem
            as-child
            :disabled="action.disabled && !action.keterangan"
            :data-action="action.key"
            :aria-labelledby="action.keterangan ? idLabel(action.key) : undefined"
            :aria-describedby="action.keterangan ? idKeterangan(action.key) : undefined"
            class="flex cursor-pointer select-none items-center gap-2 rounded-md px-2.5 py-1.5 outline-none data-[disabled]:cursor-not-allowed data-[disabled]:opacity-40 data-[nonaktif]:cursor-not-allowed"
            :class="action.danger ? 'text-red-600 data-[highlighted]:bg-red-50' : 'text-slate-700 data-[highlighted]:bg-slate-100'"
            @select="pilih(action, $event)"
          >
            <div v-bind="nonaktifTerfokus(action) ? { 'aria-disabled': 'true', 'data-nonaktif': '' } : {}">
              <component :is="action.icon" v-if="action.icon" class="h-4 w-4 shrink-0" :class="nonaktifTerfokus(action) ? 'opacity-40' : ''" aria-hidden="true" />
              <template v-if="action.keterangan">
                <span class="flex min-w-0 flex-col">
                  <span :id="idLabel(action.key)" :class="nonaktifTerfokus(action) ? 'opacity-40' : ''">{{ action.label }}</span>
                  <span :id="idKeterangan(action.key)" class="max-w-64 whitespace-normal text-xs text-slate-600" data-keterangan>{{ action.keterangan }}</span>
                </span>
              </template>
              <template v-else>{{ action.label }}</template>
            </div>
          </DropdownMenuItem>
        </template>
      </DropdownMenuContent>
    </DropdownMenuPortal>
  </DropdownMenuRoot>
</template>
