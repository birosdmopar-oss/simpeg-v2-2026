<script setup lang="ts">
/**
 * Dialog berkas kolom satu baris riwayat (hook `kolomBerkas()`, MAKE-007/008): daftar kolom berkas Definisi dengan
 * tombol Unduh (`GET pegawai/{nip}/riwayat/{jenis}/{id}/berkas/{kolom}`) untuk kolom yang terisi. Dipakai usulan
 * Karpeg/Karis dan tab riwayat ber-`berkasKolom` (SKP periodik, AK).
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { ref } from 'vue'

import { UiButton, UiNotice } from '@/shared/ui'

import { describeApiError } from '../services/apiErrors'
import type { JenisEngine, RiwayatRow } from '../types'

import { riwayatService } from './riwayat.service'
import type { BerkasKolomDef } from './usulan.config'

const props = defineProps<{
  open: boolean
  nip: string
  jenis: JenisEngine
  /** PK baris (`{id}`). */
  idBaris: string
  row: RiwayatRow
  berkas: BerkasKolomDef[]
  subject: string
}>()
const emit = defineEmits<{ 'update:open': [value: boolean] }>()

const galat = ref('')

async function unduh(kolom: string, label: string): Promise<void> {
  galat.value = ''
  try {
    const blob = await riwayatService.unduhKolomBerkas(props.nip, props.jenis, props.idBaris, kolom)
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `${label}.${String(props.row[kolom] ?? '').split('.').pop() ?? 'bin'}`
    document.body.appendChild(link)
    link.click()
    link.remove()
    // Dicabut tertunda: sebagian browser baru membaca blob setelah click() kembali.
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (error) {
    galat.value = describeApiError(error).message
  }
}
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="berkas-kolom-dialog"
        :aria-describedby="undefined"
      >
        <div class="mb-4 flex items-start justify-between gap-3">
          <DialogTitle class="text-h5 text-slate-900">Berkas {{ subject }}</DialogTitle>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>
        <UiNotice v-if="galat" tone="danger" class="mb-3" data-testid="berkas-kolom-galat">{{ galat }}</UiNotice>
        <ul class="divide-y divide-slate-200 rounded-lg border border-slate-200">
          <li v-for="b in berkas" :key="b.kolom" class="flex items-center justify-between gap-3 px-4 py-2.5 text-body2">
            <span class="text-slate-800">{{ b.label }}</span>
            <UiButton v-if="row[b.kolom]" variant="secondary" appearance="soft" :data-testid="`berkas-unduh-${b.kolom}`" @click="unduh(b.kolom, b.label)">
              Unduh
            </UiButton>
            <span v-else class="text-slate-400">—</span>
          </li>
        </ul>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
