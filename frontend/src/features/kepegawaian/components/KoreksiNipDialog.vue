<script setup lang="ts">
/**
 * Dialog Koreksi NIP (B-06, MAKE-010; role 1) tersambung `POST pegawai/{nip}/koreksi-nip`. Aksi berisiko: seluruh data
 * riwayat/snapshot berpindah ke NIP baru dalam satu transaksi, username akun menjadi NIP baru, password akun direset ke
 * NIP baru, dan sesi akun itu dicabut (ikut legacy `update_nip`, keputusan user 13-10-2026; pemilik akun diminta segera
 * mengganti password — penanda wajib ganti password menyusul DBV-020). Dialog tidak bisa ditutup selama proses. Konfirmasi dua langkah:
 * isi NIP baru → tinjau ringkasan → Ya, koreksi. Galat 422 `errors.nip_baru` ditampilkan di isian.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import { UiButton, UiNotice, UiTextField } from '@/shared/ui'

import { describeApiError } from '../services/apiErrors'
import { pegawaiService } from '../services/pegawai.service'
import type { KoreksiNipResult } from '../types'

const props = defineProps<{ open: boolean; nip: string; nama: string }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; done: [result: KoreksiNipResult] }>()

const nipBaru = ref('')
const langkah = ref<'isi' | 'tinjau'>('isi')
const busy = ref(false)
const fieldError = ref('')
const generalError = ref('')

const formatValid = computed(() => /^\d{1,18}$/.test(nipBaru.value.trim()) && nipBaru.value.trim() !== props.nip)

watch(
  () => props.open,
  (open) => {
    if (!open) return
    nipBaru.value = ''
    langkah.value = 'isi'
    fieldError.value = ''
    generalError.value = ''
  },
)

function lanjut(): void {
  fieldError.value = formatValid.value ? '' : 'NIP baru wajib angka 1–18 digit dan berbeda dari NIP lama.'
  if (!fieldError.value) langkah.value = 'tinjau'
}

async function koreksi(): Promise<void> {
  busy.value = true
  generalError.value = ''
  try {
    emit('done', await pegawaiService.koreksiNip(props.nip, nipBaru.value.trim()))
    emit('update:open', false)
  } catch (error) {
    const pesan = isApiError(error) ? error.errors?.nip_baru?.[0] : undefined
    if (pesan) {
      fieldError.value = pesan
      langkah.value = 'isi'
    } else {
      generalError.value = describeApiError(error).message
    }
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
        data-testid="koreksi-nip-dialog"
      >
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">Koreksi NIP</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">{{ nama }} — NIP sekarang {{ nip }}</DialogDescription>
          </div>
          <DialogClose :disabled="busy" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 disabled:opacity-50" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <form v-if="langkah === 'isi'" novalidate @submit.prevent="lanjut">
          <UiTextField
            v-model="nipBaru"
            label="NIP Baru"
            required
            inputmode="numeric"
            autocomplete="off"
            :state="fieldError ? 'error' : 'default'"
            :help-text="fieldError || 'Angka saja, maksimal 18 digit.'"
            data-testid="koreksi-nip-input"
          />
          <div class="mt-6 flex justify-end gap-3">
            <UiButton variant="secondary" appearance="soft" @click="emit('update:open', false)">Batal</UiButton>
            <UiButton type="submit" data-testid="koreksi-nip-lanjut">Lanjut</UiButton>
          </div>
        </form>

        <div v-else data-testid="koreksi-nip-tinjau">
          <UiNotice tone="warning">
            NIP {{ nip }} akan diganti menjadi <strong>{{ nipBaru.trim() }}</strong>. Seluruh data riwayat dan lampiran ikut
            berpindah. Username akun pegawai menjadi NIP baru, password akun direset ke NIP baru, dan semua sesi login akun
            itu diakhiri. Beri tahu pemilik akun agar segera mengganti password setelah masuk dengan NIP baru.
          </UiNotice>
          <p v-if="generalError" role="alert" class="mt-3 text-body2 text-danger" data-testid="koreksi-nip-error">{{ generalError }}</p>
          <div class="mt-6 flex justify-end gap-3">
            <UiButton variant="secondary" appearance="soft" :disabled="busy" @click="langkah = 'isi'">Kembali</UiButton>
            <UiButton variant="error" :loading="busy" data-testid="koreksi-nip-konfirmasi" @click="koreksi">Ya, koreksi NIP</UiButton>
          </div>
        </div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
