<script setup lang="ts">
/**
 * Dialog proses PMK oleh PIC (PRD §6.9.2): "Setujui" dan "Tolak" membungkus `ApprovalDialog` (alasan wajib saat Tolak,
 * ≤ 255 byte; tidak diubah); "Proses" (0 → 3, tanpa alasan) memakai dialog konfirmasi sederhana di sini. Emit
 * `confirm` `{ reason_note? }`; aksinya ditentukan pemanggil. Pemanggil menutup dialog setelah request berhasil.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'

import ApprovalDialog from '@/shared/components/ApprovalDialog.vue'
import type { ApprovalPayload } from '@/shared/components/approvalActions'
import { UiButton } from '@/shared/ui'

import type { AksiProsesPmk } from '../types'

withDefaults(defineProps<{ open: boolean; aksi: AksiProsesPmk; subject?: string; loading?: boolean; error?: string }>(), {
  subject: '',
  loading: false,
  error: '',
})

const emit = defineEmits<{ 'update:open': [value: boolean]; confirm: [payload: { reason_note?: string }] }>()

function onApproval(payload: ApprovalPayload): void {
  emit('confirm', payload.reason_note ? { reason_note: payload.reason_note } : {})
}
</script>

<template>
  <DialogRoot v-if="aksi === 'proses'" :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="pmk-proses-dialog"
      >
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">Proses{{ subject ? ` ${subject}` : '' }}</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">Pengajuan ditandai sedang diproses PIC dan pemohon diberi tahu.</DialogDescription>
          </div>
          <DialogClose :disabled="loading" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>
        <p v-if="error" role="alert" class="mb-3 rounded-lg bg-danger-soft px-3 py-2 text-body2 text-danger" data-testid="pmk-proses-error">{{ error }}</p>
        <div class="flex justify-end gap-2">
          <UiButton type="button" appearance="soft" variant="secondary" :disabled="loading" @click="emit('update:open', false)">Batal</UiButton>
          <UiButton type="button" :loading="loading" data-testid="pmk-proses-confirm" @click="emit('confirm', {})">Proses</UiButton>
        </div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
  <ApprovalDialog
    v-else
    :open="open"
    :mode="aksi"
    :subject="subject"
    :loading="loading"
    :error="error"
    @update:open="emit('update:open', $event)"
    @confirm="onApproval"
  />
</template>
