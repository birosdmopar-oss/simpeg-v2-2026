<script setup lang="ts">
/**
 * Dialog tambah/ubah satu riwayat (Radix Vue Dialog, pola sama dengan dialog Akun/Master Data). Isinya
 * RiwayatFieldForm yang dirender dari konfigurasi jenis. `initial` null = tambah, ada = ubah.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'

import type { RiwayatValidationErrors } from '../services/apiErrors'

import RiwayatFieldForm from './RiwayatFieldForm.vue'
import type { RiwayatJenisConfig } from './riwayat.config'
import type { BerkasMap } from './riwayat.service'

defineProps<{
  open: boolean
  config: RiwayatJenisConfig
  initial: Record<string, string> | null
  serverErrors?: RiwayatValidationErrors | null
  busy?: boolean
}>()
const emit = defineEmits<{ 'update:open': [value: boolean]; submit: [values: Record<string, string>, berkas: BerkasMap] }>()
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="riwayat-dialog"
      >
        <div class="mb-5 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">{{ initial ? 'Edit' : 'Tambah' }} {{ config.singular }}</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">Kolom bertanda (*) wajib diisi.</DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <RiwayatFieldForm
          :fields="config.fields"
          :lampiran="config.lampiran"
          :mode="initial ? 'edit' : 'create'"
          :initial="initial ?? {}"
          :server-errors="serverErrors ?? null"
          :busy="busy"
          :submit-label="initial ? 'Simpan Perubahan' : 'Simpan'"
          cancel-label="Batal"
          testid="riwayat"
          @submit="(values, berkas) => emit('submit', values, berkas)"
          @cancel="emit('update:open', false)"
        />
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
