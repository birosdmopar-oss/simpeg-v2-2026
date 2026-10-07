<script setup lang="ts">
/**
 * Dialog Setujui/Tolak usulan atau riwayat (Modul B: proses riwayat, biodata B-04, Konket, LKH, Karpeg/Karis).
 * - `mode` = aksi yang dipilih dari menu ⋮ (item "Setujui" / "Tolak").
 * - Alasan (`reason_note`, kolom tinytext) WAJIB saat Tolak; opsional saat Setujui. Batas 255 byte.
 * - Slot `diff` untuk perbandingan data lama ↔ usulan (biodata B-04).
 * - Emit `confirm` dengan payload body `POST …/{id}/process`: `{ aksi, reason_note }`.
 * Dialog tidak menutup sendiri saat confirm: pemanggil menutup setelah request berhasil (pola ConfirmDialog).
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, ref, useId, watch } from 'vue'

import { UiButton } from '@/shared/ui'

export type ApprovalAksi = 'setujui' | 'tolak'

export interface ApprovalPayload {
  aksi: ApprovalAksi
  reason_note: string
}

const REASON_MAX_BYTES = 255

const props = withDefaults(
  defineProps<{
    open: boolean
    mode: ApprovalAksi
    /** Nama data yang diproses, mis. "Riwayat Pendidikan S2 — Budi". */
    subject?: string
    description?: string
    loading?: boolean
    /** Galat dari server (mis. 409/422) untuk ditampilkan di dialog. */
    error?: string
  }>(),
  { subject: '', description: '', loading: false, error: '' },
)

const emit = defineEmits<{ 'update:open': [value: boolean]; confirm: [payload: ApprovalPayload] }>()

const reason = ref('')
const touched = ref(false)
const reasonId = useId()
const errorId = useId()

// Setiap kali dialog dibuka (atau mode berganti), mulai dari alasan kosong.
watch(
  () => [props.open, props.mode] as const,
  ([open]) => {
    if (open) {
      reason.value = ''
      touched.value = false
    }
  },
)

const isTolak = computed(() => props.mode === 'tolak')
const title = computed(() => (isTolak.value ? 'Tolak' : 'Setujui') + (props.subject ? ` ${props.subject}` : ''))
const confirmLabel = computed(() => (isTolak.value ? 'Tolak' : 'Setujui'))

const reasonError = computed(() => {
  const value = reason.value.trim()
  if (isTolak.value && value === '') return 'Alasan penolakan wajib diisi.'
  if (new TextEncoder().encode(value).length > REASON_MAX_BYTES) {
    return `Alasan terlalu panjang (maksimal ${REASON_MAX_BYTES} byte; huruf khusus dihitung lebih dari satu).`
  }
  return ''
})

const shownError = computed(() => (touched.value ? reasonError.value : ''))

function submit(): void {
  touched.value = true
  if (reasonError.value || props.loading) return
  emit('confirm', { aksi: props.mode, reason_note: reason.value.trim() })
}
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        :data-mode="mode"
        data-testid="approval-dialog"
      >
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">{{ title }}</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">
              {{ description || (isTolak ? 'Tuliskan alasan penolakan; alasan dikirim ke pengusul.' : 'Periksa data sebelum menyetujui.') }}
            </DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <div v-if="$slots.diff" class="mb-4" data-testid="approval-diff">
          <slot name="diff" />
        </div>

        <form novalidate @submit.prevent="submit">
          <label :for="reasonId" class="mb-1.5 block text-body2 font-medium text-slate-700">
            Alasan<span v-if="isTolak" class="text-danger" aria-hidden="true"> *</span>
            <span v-else class="font-normal text-slate-400"> (opsional)</span>
          </label>
          <textarea
            :id="reasonId"
            v-model="reason"
            rows="3"
            class="w-full rounded-xl border px-3 py-2 text-body2 text-slate-800 focus:outline-none focus:ring-2"
            :class="shownError ? 'border-danger focus:ring-danger/30' : 'border-slate-300 focus:ring-brand-primary/30'"
            :aria-required="isTolak"
            :aria-invalid="shownError ? 'true' : undefined"
            :aria-describedby="shownError ? errorId : undefined"
            data-testid="approval-reason"
            @blur="touched = true"
          />
          <p v-if="shownError" :id="errorId" role="alert" class="mt-1 text-caption text-danger" data-testid="approval-reason-error">
            {{ shownError }}
          </p>
          <p v-if="error" role="alert" class="mt-3 rounded-lg bg-danger-soft px-3 py-2 text-body2 text-danger" data-testid="approval-error">
            {{ error }}
          </p>

          <div class="mt-6 flex justify-end gap-2">
            <UiButton type="button" appearance="soft" variant="secondary" :disabled="loading" @click="emit('update:open', false)">Batal</UiButton>
            <UiButton type="submit" :variant="isTolak ? 'error' : 'primary'" :loading="loading" data-testid="approval-confirm">
              {{ confirmLabel }}
            </UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
