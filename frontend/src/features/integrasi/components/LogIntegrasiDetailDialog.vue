<script setup lang="ts">
/**
 * Detail satu baris log integrasi (MAKE-078, ISSUE-058) — dibuka dari aksi baris "Lihat detail" di menu ⋮. Baca-saja;
 * isi hanya kolom `integrasi_log` dari backend (`pesan` sudah teredaksi saat ditulis, BP-5).
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed } from 'vue'

import { UiBadge } from '@/shared/ui'
import { formatApiDateTime } from '@/shared/utils/dateTime'

import { LABEL_ARAH, LABEL_STATUS, labelLayanan, type LogIntegrasiRow } from '../integrasi.types'

const props = defineProps<{ open: boolean; row: LogIntegrasiRow | null }>()
const emit = defineEmits<{ 'update:open': [open: boolean] }>()

const kosong = '—'

const rincian = computed<{ label: string; nilai: string }[]>(() => {
  const r = props.row
  if (!r) return []
  return [
    { label: 'Waktu', nilai: formatApiDateTime(r.created_at) ?? r.created_at },
    { label: 'Layanan', nilai: labelLayanan(r.layanan) },
    { label: 'Arah', nilai: LABEL_ARAH[r.arah] ?? String(r.arah) },
    { label: 'Operasi', nilai: r.operasi },
    { label: 'HTTP', nilai: r.http_status === null ? 'Tanpa respons' : String(r.http_status) },
    { label: 'Kode', nilai: r.kode ?? kosong },
    { label: 'Durasi', nilai: r.durasi_ms === null ? kosong : `${r.durasi_ms} ms` },
    { label: 'Percobaan ke', nilai: String(r.percobaan) },
    { label: 'Rujukan', nilai: r.ref_id ?? kosong },
    { label: 'ID klien', nilai: r.id_klien === null ? kosong : String(r.id_klien) },
  ]
})
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="integrasi-log-detail"
      >
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 font-semibold text-slate-900">Detail Log Integrasi</DialogTitle>
            <DialogDescription class="text-sm text-slate-500">
              Pesan sudah disamarkan saat dicatat (tanpa token, passphrase, atau data rahasia).
            </DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" />
          </DialogClose>
        </div>

        <template v-if="row">
          <UiBadge :tone="row.status === 1 ? 'success' : 'danger'" dot>{{ LABEL_STATUS[row.status] }}</UiBadge>
          <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div v-for="item in rincian" :key="item.label">
              <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ item.label }}</dt>
              <dd class="break-words text-slate-800">{{ item.nilai }}</dd>
            </div>
          </dl>
          <div class="mt-4">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Pesan</p>
            <p class="mt-1 whitespace-pre-wrap break-words rounded-md bg-slate-50 p-3 text-sm text-slate-800" data-testid="integrasi-log-detail-pesan">
              {{ row.pesan ?? kosong }}
            </p>
          </div>
        </template>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
