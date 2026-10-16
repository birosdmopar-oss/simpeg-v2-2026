<script setup lang="ts">
/**
 * Dialog tambah/ubah satu riwayat (Radix Vue Dialog, pola sama dengan dialog Akun/Master Data). Isinya
 * RiwayatFieldForm yang dirender dari konfigurasi jenis. `initial` null = tambah, ada = ubah. `fields` menimpa isian
 * konfigurasi (mis. ditambah isian `status` untuk role yang memilih status saat ubah).
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'

import type { RiwayatValidationErrors } from '../services/apiErrors'

import RiwayatFieldForm from './RiwayatFieldForm.vue'
import type { FieldDef, PratinjauDef, RiwayatJenisConfig } from './riwayat.config'
import type { BerkasMap } from './riwayat.service'

defineProps<{
  open: boolean
  config: RiwayatJenisConfig
  initial: Record<string, string> | null
  /** Pratinjau isian turunan form tambah (MAKE-017). */
  pratinjau?: { def: PratinjauDef; muat: (query: Record<string, string>) => Promise<Record<string, unknown>> } | null
  /** Baris yang diedit (diteruskan ke isian hitung `compute`). */
  baris?: Readonly<Record<string, unknown>> | null
  fields?: FieldDef[]
  lampiranTersimpan?: Record<number, number>
  kolomTerisi?: Record<string, boolean>
  serverErrors?: RiwayatValidationErrors | null
  busy?: boolean
}>()
const emit = defineEmits<{
  'update:open': [value: boolean]
  submit: [values: Record<string, string>, berkas: BerkasMap, jamak: Record<string, string[]>]
}>()

/** Nilai awal mode tambah: objek tetap, bukan `{}` baru di setiap render (CR-055 Q4R2-F1). */
const KOSONG: Record<string, string> = {}
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
          :key="(fields ?? config.fields).map((f) => f.name).join()"
          :fields="fields ?? config.fields"
          :lampiran="config.lampiran"
          :mode="initial ? 'edit' : 'create'"
          :initial="initial ?? KOSONG"
          :baris="baris ?? null"
          :pratinjau="pratinjau ?? null"
          :lampiran-tersimpan="lampiranTersimpan ?? {}"
          :berkas-kolom="config.berkasKolom ?? []"
          :kolom-terisi="kolomTerisi ?? {}"
          :server-errors="serverErrors ?? null"
          :busy="busy"
          :submit-label="initial ? 'Simpan Perubahan' : 'Simpan'"
          cancel-label="Batal"
          testid="riwayat"
          @submit="(values, berkas, jamak) => emit('submit', values, berkas, jamak)"
          @cancel="emit('update:open', false)"
        />
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
