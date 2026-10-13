<script setup lang="ts">
/**
 * Verifikasi LKH oleh atasan langsung (B-12b, MAKE-013; legacy `form_process.php`): Setujui, Tolak, atau Revisi
 * (status 3 — kembali ke pegawai). Catatan wajib saat Tolak/Revisi (server menegakkan, 422 `catatan`).
 */
import { DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import { UiButton, UiNotice, UiRadioGroup } from '@/shared/ui'

import { describeApiError } from '../services/apiErrors'
import { lkhService, type AksiLkh } from '../services/lkh.service'
import type { LkhItem } from '../types'

const props = defineProps<{ open: boolean; row: LkhItem | null; aksiAwal: AksiLkh }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; processed: [row: LkhItem] }>()

const aksi = ref<AksiLkh>('setujui')
const catatan = ref('')
const error = ref('')
const busy = ref(false)

watch(
  () => props.open,
  (open) => {
    if (!open) return
    aksi.value = props.aksiAwal
    catatan.value = ''
    error.value = ''
  },
  { immediate: true },
)

async function kirim(): Promise<void> {
  if (!props.row) return
  if (aksi.value !== 'setujui' && catatan.value.trim() === '') {
    error.value = 'Catatan wajib diisi saat menolak atau meminta revisi.'
    return
  }
  busy.value = true
  error.value = ''
  try {
    const row = await lkhService.process(props.row.id, aksi.value, catatan.value.trim())
    emit('processed', row)
    emit('update:open', false)
  } catch (e) {
    error.value = (isApiError(e) ? Object.values(e.errors ?? {})[0]?.[0] : undefined) ?? describeApiError(e).message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <DialogRoot :open="open" @update:open="(v) => !busy && emit('update:open', v)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="lkh-proses-dialog"
      >
        <DialogTitle class="text-h5 text-slate-900">Verifikasi Laporan Kerja Harian</DialogTitle>
        <DialogDescription class="mb-4 text-body2 text-slate-500">
          {{ row ? `${row.nama} — ${row.tgl_laporan}` : '' }}
        </DialogDescription>
        <form class="space-y-4" @submit.prevent="kirim">
          <UiRadioGroup
            v-model="aksi"
            label="Keputusan"
            :options="[
              { value: 'setujui', label: 'Setujui' },
              { value: 'revisi', label: 'Revisi' },
              { value: 'tolak', label: 'Tolak' },
            ]"
          />
          <label class="block">
            <span class="mb-1 block text-body2 font-medium text-slate-700">Catatan{{ aksi === 'setujui' ? '' : ' *' }}</span>
            <textarea v-model="catatan" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-body1" data-testid="lkh-catatan" />
          </label>
          <UiNotice v-if="error" tone="danger" data-testid="lkh-proses-error">{{ error }}</UiNotice>
          <div class="flex justify-end gap-2">
            <UiButton type="button" variant="secondary" :disabled="busy" @click="emit('update:open', false)">Batal</UiButton>
            <UiButton type="submit" :loading="busy" :variant="aksi === 'tolak' ? 'error' : 'primary'" data-testid="lkh-proses-kirim">Proses</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
