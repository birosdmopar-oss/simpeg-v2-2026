<script setup lang="ts">
/** Popup Pengumuman (Gambar 27): muncul sekali per sesi setelah masuk dashboard; ditutup lewat tombol atau Esc. DATA CONTOH. */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { ref } from 'vue'

import { UiButton } from '@/shared/ui'

import { PENGUMUMAN } from '../dashboard.mock'

const KEY = 'simpeg.pengumuman.dilihat'
const seen = (): boolean => {
  try {
    return sessionStorage.getItem(KEY) === '1'
  } catch {
    return false
  }
}
const open = ref(!seen())
function onUpdate(v: boolean): void {
  open.value = v
  if (!v) {
    try {
      sessionStorage.setItem(KEY, '1')
    } catch {
      /* penyimpanan tidak tersedia — popup boleh muncul lagi */
    }
  }
}
</script>

<template>
  <DialogRoot :open="open" @update:open="onUpdate">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 w-[calc(100%-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 rounded-2xl bg-white p-6 shadow-panel"
        data-testid="announcement-popup"
      >
        <DialogClose class="absolute right-3 top-3 inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100" aria-label="Tutup">
          <X class="h-4 w-4" aria-hidden="true" />
        </DialogClose>
        <DialogTitle class="text-h3 font-semibold text-slate-900">{{ PENGUMUMAN.judul }}</DialogTitle>
        <DialogDescription class="mt-2 text-body1 text-slate-600">{{ PENGUMUMAN.isi }}</DialogDescription>
        <div class="mt-5 flex justify-end"><UiButton data-testid="announcement-close" @click="onUpdate(false)">Mengerti</UiButton></div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
