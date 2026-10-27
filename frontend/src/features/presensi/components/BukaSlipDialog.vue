<script setup lang="ts">
/**
 * Modal konfirmasi buka slip [K `list.php:270-290`]: slip hanya dapat dibuka satu kali; tombol "Lanjutkan" aktif setelah
 * centang persetujuan. Dialog tidak bisa ditutup selama proses buka berjalan.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { ref, watch } from 'vue'

import { UiButton, UiCheckbox, UiNotice } from '@/shared/ui'

const props = defineProps<{ open: boolean; label: string; busy?: boolean; error?: string }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; confirm: [] }>()

const setuju = ref(false)

watch(
  () => props.open,
  (open) => {
    if (open) setuju.value = false
  },
)
</script>

<template>
  <DialogRoot :open="open" @update:open="(v) => !busy && emit('update:open', v)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="scrollbar-slim fixed left-1/2 top-1/2 z-50 max-h-[calc(100vh-2rem)] w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="buka-slip-dialog"
      >
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <p class="text-caption font-semibold uppercase tracking-wide text-amber-800">Perhatian Penting</p>
            <DialogTitle class="text-h5 text-slate-900">Slip gaji hanya dapat dibuka satu kali</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">Periode {{ label }}</DialogDescription>
          </div>
          <DialogClose :disabled="busy" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 disabled:opacity-50" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <p class="text-body1 text-slate-700">
          Setelah Anda memilih <strong>Lanjutkan</strong>, sistem mencatat bahwa slip ini telah diakses dan tidak dapat dibuka
          kembali tanpa bantuan Admin SIMPEG.
        </p>
        <ul class="mt-3 list-disc space-y-1 pl-5 text-body2 text-slate-700">
          <li>Segera unduh slip dengan tombol <strong>Cetak PDF</strong> setelah slip ditampilkan.</li>
          <li>Jangan menutup atau memuat ulang halaman sebelum slip selesai diunduh.</li>
        </ul>

        <UiNotice v-if="error" tone="danger" class="mt-4" data-testid="buka-slip-error">{{ error }}</UiNotice>

        <div class="mt-4" data-testid="buka-slip-setuju">
          <UiCheckbox v-model="setuju" label="Saya telah membaca dan memahami ketentuan di atas." :disabled="busy" />
        </div>

        <div class="mt-6 flex flex-wrap justify-end gap-2">
          <UiButton variant="secondary" appearance="outline" :disabled="busy" @click="emit('update:open', false)">Batal</UiButton>
          <UiButton :disabled="!setuju" :loading="busy" data-testid="buka-slip-lanjutkan" @click="emit('confirm')">Lanjutkan</UiButton>
        </div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
