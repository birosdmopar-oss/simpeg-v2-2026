<script setup lang="ts">
/**
 * Popup pengumuman beranda (F-04, PRD Fase 7 v2.0 §5.3, Q7-02): popup aktif milik aktor tampil SEKALI PER SESI LOGIN
 * sebagai carousel gambar (padanan flashdata `listPU` legacy). Tanpa props; memuat data sendiri. Daftar kosong, 403, atau
 * galat → tidak merender apa pun dan tidak memancarkan galat ke parent. Esc/Tutup menutup; gambar ber-URL menjadi tautan.
 * Tampil lewat antrean dialog (`minta('popup-konten')`): menunggu form digital dan pengingat cuti, dan hanya diminta bila ada popup.
 */
import { ChevronLeft, ChevronRight, X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, onMounted, ref } from 'vue'

import { useAntreanDialog } from '@/shared/composables/antreanDialog'

import GambarKonten from './GambarKonten.vue'
import { kontenService } from '../services/konten.service'
import { popupSudahDitampilkan, tandaiPopupDitampilkan } from '../sesi'
import type { PopupAktif } from '../types'

const { minta } = useAntreanDialog()
let selesai: (() => void) | null = null

const daftar = ref<PopupAktif[]>([])
const open = ref(false)
const indeks = ref(0)

const aktif = computed<PopupAktif | undefined>(() => daftar.value[indeks.value])
const terakhir = computed(() => indeks.value >= daftar.value.length - 1)

onMounted(async () => {
  if (popupSudahDitampilkan()) return
  try {
    const rows = await kontenService.popupAktif()
    if (rows.length === 0) return
    daftar.value = rows
    indeks.value = 0
    selesai = await minta('popup-konten')
    open.value = true
    tandaiPopupDitampilkan()
  } catch (e) {
    if (import.meta.env.DEV) console.warn('[konten] popup aktif gagal dimuat', e)
  }
})

/** Ikut legacy (`pu_image.php`): popup baru bisa ditutup pada gambar TERAKHIR; sebelum itu hanya "Berikutnya". */
function onUpdate(v: boolean): void {
  if (!v && !terakhir.value) return
  open.value = v
  if (!v) {
    selesai?.()
    selesai = null
  }
}

function tahan(e: Event): void {
  if (!terakhir.value) e.preventDefault()
}

function berikutnya(): void {
  if (!terakhir.value) indeks.value += 1
}

function sebelumnya(): void {
  if (indeks.value > 0) indeks.value -= 1
}
</script>

<template>
  <DialogRoot :open="open" @update:open="onUpdate">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        v-if="aktif"
        class="fixed left-1/2 top-1/2 z-50 w-[calc(100%-2rem)] max-w-xl -translate-x-1/2 -translate-y-1/2 rounded-2xl bg-white p-4 shadow-panel"
        data-testid="konten-popup-aktif"
        @escape-key-down="tahan"
        @pointer-down-outside="tahan"
        @interact-outside="tahan"
      >
        <DialogTitle class="sr-only">Pengumuman {{ indeks + 1 }} dari {{ daftar.length }}</DialogTitle>
        <DialogDescription class="sr-only">{{ aktif.keterangan ?? 'Pengumuman SIMPEG' }}</DialogDescription>
        <DialogClose
          v-if="terakhir"
          class="absolute right-3 top-3 inline-flex h-8 w-8 items-center justify-center rounded-lg bg-white/90 text-slate-600 shadow hover:bg-slate-100"
          aria-label="Tutup"
          data-testid="konten-popup-tutup"
        >
          <X class="h-4 w-4" aria-hidden="true" />
        </DialogClose>
        <a
          v-if="aktif.url"
          :href="aktif.url"
          target="_blank"
          rel="noopener noreferrer"
          data-testid="konten-popup-tautan"
        >
          <GambarKonten :path="aktif.gambar_url" :alt="aktif.keterangan ?? 'Pengumuman'" class="w-full rounded-xl" />
        </a>
        <GambarKonten v-else :path="aktif.gambar_url" :alt="aktif.keterangan ?? 'Pengumuman'" class="w-full rounded-xl" />
        <div v-if="daftar.length > 1" class="mt-3 flex items-center justify-between">
          <button
            type="button"
            class="inline-flex h-9 items-center gap-1 rounded-lg px-3 text-body1 text-slate-700 hover:bg-slate-100 disabled:opacity-40"
            :disabled="indeks === 0"
            data-testid="konten-popup-sebelumnya"
            @click="sebelumnya"
          >
            <ChevronLeft class="h-4 w-4" aria-hidden="true" />Sebelumnya
          </button>
          <span class="text-body2 text-slate-500" aria-live="polite">{{ indeks + 1 }} / {{ daftar.length }}</span>
          <button
            v-if="!terakhir"
            type="button"
            class="inline-flex h-9 items-center gap-1 rounded-lg px-3 text-body1 text-slate-700 hover:bg-slate-100"
            data-testid="konten-popup-berikutnya"
            @click="berikutnya"
          >
            Berikutnya<ChevronRight class="h-4 w-4" aria-hidden="true" />
          </button>
          <span v-else class="h-9 w-[7.5rem]" aria-hidden="true" />
        </div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
