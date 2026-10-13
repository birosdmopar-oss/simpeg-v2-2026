<script setup lang="ts">
/**
 * Dialog arsip lampiran satu baris riwayat: satu ArsipTable (B-18, WS-2) per kode `jenis_rwy` jenis itu (unggah, unduh,
 * hapus). Hak dari descriptor tab, pola ArsipTable: unggah = `can_create`/`can_edit`, hapus = `can_delete`/`can_edit`;
 * server tetap menegakkan izin Definisi, lingkup, kunci baris, dan lampiran wajib terakhir.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'

import ArsipTable from '../components/ArsipTable.vue'
import type { RiwayatTabDescriptor } from '../types'

import type { RiwayatJenisConfig } from './riwayat.config'

defineProps<{
  open: boolean
  nip: string
  config: RiwayatJenisConfig
  descriptor: RiwayatTabDescriptor
  /** PK baris riwayat (`id_entri` lampiran). */
  idEntri: string
  subject: string
}>()
const emit = defineEmits<{ 'update:open': [value: boolean] }>()
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-3xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="riwayat-lampiran-dialog"
      >
        <div class="mb-5 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">Lampiran {{ subject }}</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">{{ config.title }}</DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <div class="space-y-6">
          <ArsipTable
            v-for="def in config.lampiran"
            :key="def.id_riwayat"
            :nip="nip"
            :target="{ id_riwayat: def.id_riwayat, id_entri: idEntri }"
            :aturan="def"
            :title="def.label"
            :can-upload="descriptor.can_create || descriptor.can_edit"
            :can-delete="descriptor.can_delete || descriptor.can_edit"
          />
        </div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
