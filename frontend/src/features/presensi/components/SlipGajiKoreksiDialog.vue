<script setup lang="ts">
/**
 * Koreksi 28 komponen slip (`PUT slip-gaji/{id}`, PRD 5A §7.3.4) [K `admin_list.php:208-268` "Koreksi Data Komponen
 * Gaji"]: GPP kiri, TK kanan (satu kolom ≤ 600 px). Nilai = desimal titik kanonik server (mis. "4500000.00"); validasi
 * yang mengikat (wajib, non-negatif, format) di server dan galat 422 ditampilkan per kolom. Status dibuka, token QR, dan
 * uang makan tidak bisa dikoreksi [K `Lsl_gaji.php:810`].
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import { UiButton, UiNotice, UiTextField } from '@/shared/ui'

import { describeApiError } from '@/features/kepegawaian/services/apiErrors'

import { labelPeriode, slipGajiAdminService } from '../services/slipGajiAdmin.service'
import { KOLOM_GPP, KOLOM_TK, LABEL_KOLOM, type SlipAdmin } from '../types'

const props = defineProps<{ open: boolean; slip: SlipAdmin | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [slip: SlipAdmin] }>()

const nilai = ref<Record<string, string>>({})
const errors = ref<Record<string, string>>({})
const formError = ref('')
const menyimpan = ref(false)

watch(
  () => [props.open, props.slip] as const,
  ([open, slip]) => {
    if (open && slip) {
      nilai.value = Object.fromEntries([...KOLOM_GPP, ...KOLOM_TK].map((k) => [k, slip.rincian[k] ?? '0.00']))
      errors.value = {}
      formError.value = ''
    }
  },
  { immediate: true },
)

async function simpan(event: Event): Promise<void> {
  event.preventDefault()
  if (!props.slip || menyimpan.value) return
  menyimpan.value = true
  errors.value = {}
  formError.value = ''
  try {
    const hasil = await slipGajiAdminService.koreksi(props.slip.id, { ...nilai.value })
    emit('saved', hasil)
    emit('update:open', false)
  } catch (err) {
    if (isApiError(err) && err.status === 422 && err.errors) {
      errors.value = Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, Array.isArray(v) ? String(v[0] ?? '') : String(v)]))
      formError.value = 'Periksa kembali isian yang ditandai.'
    } else {
      formError.value = describeApiError(err).message
    }
  } finally {
    menyimpan.value = false
  }
}
</script>

<template>
  <DialogRoot :open="open" @update:open="(v) => !menyimpan && emit('update:open', v)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="scrollbar-slim fixed left-1/2 top-1/2 z-50 max-h-[calc(100vh-2rem)] w-[calc(100%-2rem)] max-w-3xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="slip-koreksi-dialog"
      >
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">Koreksi Data Komponen Gaji</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">
              {{ slip?.pegawai.nama ?? '-' }} · NIP {{ slip?.nip }} · {{ slip ? slip.label || labelPeriode(slip.periode) : '' }}
            </DialogDescription>
          </div>
          <DialogClose :disabled="menyimpan" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 disabled:opacity-50" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <form novalidate data-testid="slip-koreksi-form" @submit="simpan">
          <UiNotice v-if="formError" tone="danger" class="mb-4" data-testid="slip-koreksi-error">{{ formError }}</UiNotice>

          <div class="grid gap-6 min-[601px]:grid-cols-2">
            <fieldset>
              <legend class="mb-2 w-full border-b border-slate-200 pb-1 text-caption font-semibold uppercase tracking-wide text-brand-primary">GPP (Rupiah)</legend>
              <div class="space-y-2">
                <UiTextField
                  v-for="k in KOLOM_GPP"
                  :key="k"
                  v-model="nilai[k]"
                  :name="k"
                  :label="LABEL_KOLOM[k]"
                  inputmode="decimal"
                  required
                  :state="errors[k] ? 'error' : 'default'"
                  :help-text="errors[k] ?? ''"
                  :disabled="menyimpan"
                  :data-testid="`slip-koreksi-${k}`"
                />
              </div>
            </fieldset>
            <fieldset>
              <legend class="mb-2 w-full border-b border-slate-200 pb-1 text-caption font-semibold uppercase tracking-wide text-success">TK (Rupiah)</legend>
              <div class="space-y-2">
                <UiTextField
                  v-for="k in KOLOM_TK"
                  :key="k"
                  v-model="nilai[k]"
                  :name="k"
                  :label="LABEL_KOLOM[k]"
                  inputmode="decimal"
                  required
                  :state="errors[k] ? 'error' : 'default'"
                  :help-text="errors[k] ?? ''"
                  :disabled="menyimpan"
                  :data-testid="`slip-koreksi-${k}`"
                />
              </div>
              <UiNotice tone="info" class="mt-4">
                Uang makan dihitung otomatis dari presensi SIMPEG (bulan sebelumnya) saat slip dibuka dan tidak dapat dikoreksi di sini.
                Format nilai: angka dengan titik desimal, mis. 4500000.00.
              </UiNotice>
            </fieldset>
          </div>

          <div class="mt-6 flex flex-wrap justify-end gap-2">
            <UiButton variant="secondary" appearance="outline" :disabled="menyimpan" @click="emit('update:open', false)">Batal</UiButton>
            <UiButton type="submit" :loading="menyimpan" data-testid="slip-koreksi-simpan">Simpan Perubahan</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
