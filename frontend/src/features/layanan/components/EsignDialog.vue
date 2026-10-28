<script setup lang="ts">
/**
 * Dialog Terbitkan TTE surat cuti (PRD §6.5, C-05): konfirmasi + passphrase penanda tangan. Passphrase HANYA hidup di
 * variabel lokal dialog ini: tidak masuk store, storage, URL, atau log; dikosongkan segera setelah dikirim, saat dialog
 * ditutup, dan saat komponen dilepas. Input bertipe password (komponen `UiTextField`), tanpa isi-otomatis browser.
 * Galat server per kolom (`errors.passphrase`, `errors.nip`, `errors.status`) diteruskan pemanggil lewat `errors`/`umum`.
 * Pemanggil menutup dialog setelah request berhasil (pola ApprovalDialog).
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { onBeforeUnmount, ref, watch } from 'vue'

import { UiButton, UiTextField } from '@/shared/ui'

const props = withDefaults(
  defineProps<{ open: boolean; subject?: string; loading?: boolean; errors?: Record<string, string>; umum?: string }>(),
  { subject: '', loading: false, errors: () => ({}), umum: '' },
)

const emit = defineEmits<{ 'update:open': [value: boolean]; confirm: [passphrase: string] }>()

const passphrase = ref('')
const kosongError = ref('')
/** Naik setiap pembersihan: input dipasang ulang sehingga nilai asli di elemen DOM ikut hilang, bukan hanya state. */
const kunciInput = ref(0)

function bersihkan(): void {
  passphrase.value = ''
  kosongError.value = ''
  kunciInput.value++
}

watch(
  () => props.open,
  () => bersihkan(),
)
onBeforeUnmount(bersihkan)

function submit(): void {
  if (props.loading) return
  if (passphrase.value.trim() === '') {
    kosongError.value = 'Passphrase wajib diisi.'
    return
  }
  const nilai = passphrase.value
  bersihkan()
  emit('confirm', nilai)
}

function tutup(): void {
  if (!props.loading) emit('update:open', false)
}
</script>

<template>
  <DialogRoot :open="open" @update:open="(v) => (v ? emit('update:open', true) : tutup())">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="esign-dialog"
      >
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">Terbitkan TTE{{ subject ? ` ${subject}` : '' }}</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">
              Surat cuti akan ditandatangani secara elektronik oleh pejabat yang berwenang. Masukkan passphrase penanda tangan; passphrase tidak disimpan.
            </DialogDescription>
          </div>
          <DialogClose :disabled="loading" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <form novalidate autocomplete="off" @submit.prevent="submit">
          <UiTextField
            :key="kunciInput"
            v-model="passphrase"
            type="password"
            label="Passphrase"
            required
            autocomplete="off"
            :state="kosongError || errors.passphrase ? 'error' : 'default'"
            :help-text="kosongError || errors.passphrase"
            data-testid="esign-passphrase"
          />
          <p v-if="errors.nip" role="alert" class="mt-3 rounded-lg bg-danger-soft px-3 py-2 text-body2 text-danger" data-testid="esign-error-nip">{{ errors.nip }}</p>
          <p v-if="errors.status" role="alert" class="mt-3 rounded-lg bg-danger-soft px-3 py-2 text-body2 text-danger" data-testid="esign-error-status">{{ errors.status }}</p>
          <p v-if="umum" role="alert" class="mt-3 rounded-lg bg-danger-soft px-3 py-2 text-body2 text-danger" data-testid="esign-error">{{ umum }}</p>

          <div class="mt-6 flex justify-end gap-2">
            <UiButton type="button" appearance="soft" variant="secondary" :disabled="loading" @click="tutup">Batal</UiButton>
            <UiButton type="submit" :loading="loading" data-testid="esign-confirm">Terbitkan TTE</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
