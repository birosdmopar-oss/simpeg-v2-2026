<script setup lang="ts">
/**
 * Dialog "Tangguhkan" cuti tahunan — pembungkus Modul C di samping `ApprovalDialog` (yang hanya Setujui/Tolak dan
 * tidak diubah, PRD §6.11.5). Alasan opsional kecuali `alasanWajib` (mis. proses atas nama), batas 255 byte.
 * Emit `confirm` = body `POST cuti/{id}/process`: `{ aksi: 'tangguhkan', reason_note }`. Pemanggil menutup dialog
 * setelah request berhasil (pola ApprovalDialog/ConfirmDialog).
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, ref, useId, watch } from 'vue'

import { UiButton } from '@/shared/ui'

const REASON_MAX_BYTES = 255

const props = withDefaults(
  defineProps<{
    open: boolean
    subject?: string
    alasanWajib?: boolean
    loading?: boolean
    error?: string
    disabled?: boolean
  }>(),
  { subject: '', alasanWajib: false, loading: false, error: '', disabled: false },
)

const emit = defineEmits<{
  'update:open': [value: boolean]
  confirm: [payload: { aksi: 'tangguhkan'; reason_note: string }]
}>()

const reason = ref('')
const touched = ref(false)
const reasonId = useId()
const errorId = useId()

watch(
  () => props.open,
  (open) => {
    if (open) {
      reason.value = ''
      touched.value = false
    }
  },
)

const reasonError = computed(() => {
  const value = reason.value.trim()
  if (props.alasanWajib && value === '') return 'Alasan penangguhan wajib diisi.'
  if (new TextEncoder().encode(value).length > REASON_MAX_BYTES) {
    return `Alasan terlalu panjang (maksimal ${REASON_MAX_BYTES} byte; huruf khusus dihitung lebih dari satu).`
  }
  return ''
})
const shownError = computed(() => (touched.value ? reasonError.value : ''))

function submit(): void {
  touched.value = true
  if (reasonError.value || props.loading || props.disabled) return
  emit('confirm', { aksi: 'tangguhkan', reason_note: reason.value.trim() })
}
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-mode="tangguhkan"
        data-testid="tangguhkan-dialog"
      >
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">Tangguhkan{{ subject ? ` ${subject}` : '' }}</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">
              Cuti ditangguhkan tanpa memotong saldo; pemohon dapat mengajukan ulang.
            </DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <form novalidate @submit.prevent="submit">
          <label :for="reasonId" class="mb-1.5 block text-body2 font-medium text-slate-700">
            Alasan<span v-if="alasanWajib" class="text-danger" aria-hidden="true"> *</span>
            <span v-else class="font-normal text-slate-400"> (opsional)</span>
          </label>
          <textarea
            :id="reasonId"
            v-model="reason"
            rows="3"
            class="w-full rounded-xl border px-3 py-2 text-body2 text-slate-800 focus:outline-none focus:ring-2"
            :class="shownError ? 'border-danger focus:ring-danger/30' : 'border-slate-300 focus:ring-brand-primary/30'"
            :aria-required="alasanWajib"
            :aria-invalid="shownError ? 'true' : undefined"
            :aria-describedby="shownError ? errorId : undefined"
            data-testid="tangguhkan-reason"
            @blur="touched = true"
          />
          <p v-if="shownError" :id="errorId" role="alert" class="mt-1 text-caption text-danger" data-testid="tangguhkan-reason-error">
            {{ shownError }}
          </p>
          <p v-if="error" role="alert" class="mt-3 rounded-lg bg-danger-soft px-3 py-2 text-body2 text-danger" data-testid="tangguhkan-error">
            {{ error }}
          </p>

          <div class="mt-6 flex justify-end gap-2">
            <UiButton type="button" appearance="soft" variant="secondary" :disabled="loading" @click="emit('update:open', false)">Batal</UiButton>
            <UiButton type="submit" variant="primary" :loading="loading" :disabled="disabled" data-testid="tangguhkan-confirm">Tangguhkan</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
