<script setup lang="ts">
/**
 * Pengingat cuti (PRD §6.4.7): daftar pengajuan yang menunggu persetujuan pemanggil dan belum ditunda
 * (`GET cuti/pengingat`). Tiap baris: "Proses" (buka detail) dan "Tunda" (hanya bila `aksi.tunda` dari server).
 * Tombol di dalam dialog (bukan baris tabel), jadi bukan menu ⋮. Pemanggil menutup/menyaring daftar setelah Tunda.
 */
import { formatTanggalIndonesia as tgl } from '@/shared/utils/dateTime'
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'

import { UiButton } from '@/shared/ui'

import type { CutiItem } from '../types'

import StatusLayananBadge from './StatusLayananBadge.vue'

withDefaults(defineProps<{ open: boolean; items: CutiItem[]; busyId?: number | null; error?: string }>(), { busyId: null, error: '' })

const emit = defineEmits<{ 'update:open': [value: boolean]; proses: [row: CutiItem]; tunda: [row: CutiItem] }>()

const periode = (r: CutiItem): string => (r.tgl_mulai === r.tgl_akhir ? tgl(r.tgl_mulai) : `${tgl(r.tgl_mulai)} s.d. ${tgl(r.tgl_akhir)}`)
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="pengingat-cuti-dialog"
      >
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">Pengajuan Cuti Menunggu Persetujuan</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">
              Ada {{ items.length }} pengajuan cuti yang menunggu persetujuan Anda. Tunda untuk menyembunyikan pengingat sampai Anda login lagi.
            </DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <p v-if="error" role="alert" class="mb-3 rounded-lg bg-danger-soft px-3 py-2 text-body2 text-danger" data-testid="pengingat-error">{{ error }}</p>

        <ul class="scrollbar-slim max-h-[50vh] divide-y divide-slate-200 overflow-y-auto" data-testid="pengingat-list">
          <li v-for="row in items" :key="row.id" class="flex flex-wrap items-center justify-between gap-3 py-3" :data-testid="`pengingat-row-${row.id}`">
            <div class="min-w-0">
              <span class="block font-medium text-slate-900">{{ row.nama }}</span>
              <span class="block text-body2 text-slate-600">{{ row.nama_jenis_cuti }} · {{ periode(row) }} · {{ row.lama_cuti }} hari</span>
              <StatusLayananBadge class="mt-1" :label="row.label_status" :nada="row.nada_status" />
            </div>
            <div class="flex gap-2">
              <UiButton size="sm" :data-testid="`pengingat-proses-${row.id}`" @click="emit('proses', row)">Proses</UiButton>
              <UiButton
                v-if="row.aksi.tunda"
                size="sm"
                appearance="soft"
                variant="secondary"
                :loading="busyId === row.id"
                :data-testid="`pengingat-tunda-${row.id}`"
                @click="emit('tunda', row)"
              >
                Tunda
              </UiButton>
            </div>
          </li>
        </ul>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
