<script setup lang="ts">
/**
 * Tambah Pegawai (B-05, MAKE-011; role 1) tersambung `POST pegawai` (multipart, foto wajib). Isian = NIP + isian Super
 * Admin Data Umum (`tambahPegawaiFields`). Sukses → langkah kedua menampilkan akun yang dibuat: username = NIP dan
 * password awal = NIP (ikut legacy, keputusan user 13-10-2026) yang harus segera diganti pemilik akun, plus peringatan
 * bila email tidak disimpan di akun karena sudah dipakai akun lain. Galat 422 per kolom diteruskan ke form (`nip`, `foto`, …).
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import { UiButton, UiNotice } from '@/shared/ui'

import { FOTO_SLOT, fotoLampiran, tambahPegawaiFields } from '../dataUmum.config'
import RiwayatFieldForm from '../riwayat/RiwayatFieldForm.vue'
import type { BerkasMap } from '../riwayat/riwayat.service'
import { describeApiError, splitValidationErrors, type RiwayatValidationErrors } from '../services/apiErrors'
import { pegawaiService } from '../services/pegawai.service'
import type { TambahPegawaiResult } from '../types'

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; created: [result: TambahPegawaiResult] }>()

const fields = tambahPegawaiFields()
const lampiran = [fotoLampiran()]
const busy = ref(false)
const serverErrors = ref<RiwayatValidationErrors | null>(null)
const hasil = ref<TambahPegawaiResult | null>(null)
const formKey = ref(0)

watch(
  () => props.open,
  (open) => {
    if (!open) return
    hasil.value = null
    serverErrors.value = null
    formKey.value++
  },
)

const judul = computed(() => (hasil.value ? 'Pegawai berhasil ditambahkan' : 'Tambah Pegawai'))

function submit(values: Record<string, string>, berkas: BerkasMap): void {
  void kirim(values, berkas)
}

async function kirim(values: Record<string, string>, berkas: BerkasMap): Promise<void> {
  const foto = berkas[FOTO_SLOT]
  if (!foto) return
  busy.value = true
  serverErrors.value = null
  try {
    hasil.value = await pegawaiService.tambah(values, foto)
    emit('created', hasil.value)
  } catch (error) {
    const failure = describeApiError(error)
    const split = splitValidationErrors(error, fields.map((f) => f.name))
    const fotoError = isApiError(error) ? error.errors?.foto?.[0] : undefined
    serverErrors.value = {
      fields: split.fields,
      berkas: fotoError ? { [FOTO_SLOT]: fotoError } : {},
      general: failure.kind === 'validation' ? split.general.filter((m) => m !== fotoError) : [failure.message],
    }
  } finally {
    busy.value = false
  }
}

function tutup(): void {
  if (!busy.value) emit('update:open', false)
}
</script>

<template>
  <DialogRoot :open="open" @update:open="(v) => (v ? emit('update:open', true) : tutup())">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="tambah-pegawai-dialog"
      >
        <div class="mb-5 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">{{ judul }}</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">
              <template v-if="hasil">Akun login pegawai sudah dibuat.</template>
              <template v-else>Akun login pegawai dibuat otomatis (username = NIP). Kolom bertanda (*) wajib diisi.</template>
            </DialogDescription>
          </div>
          <DialogClose :disabled="busy" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <div v-if="hasil" data-testid="tambah-pegawai-hasil">
          <dl class="grid gap-3 text-body1 md:grid-cols-2">
            <div>
              <dt class="text-body2 text-slate-500">NIP / Username</dt>
              <dd class="font-medium text-slate-900">{{ hasil.akun.username }}</dd>
            </div>
            <div v-if="hasil.akun.initial_password">
              <dt class="text-body2 text-slate-500">Password awal</dt>
              <dd class="font-mono font-medium text-slate-900" data-testid="tambah-pegawai-password">{{ hasil.akun.initial_password }}</dd>
            </div>
          </dl>
          <UiNotice v-if="hasil.akun.created" tone="warning" class="mt-4" data-testid="tambah-pegawai-ganti-password">
            Password awal sama dengan NIP. Minta pegawai segera masuk dan mengganti password.
          </UiNotice>
          <UiNotice v-else tone="info" class="mt-4">Akun untuk NIP ini sudah ada sebelumnya; password tidak diubah.</UiNotice>
          <UiNotice v-if="hasil.akun.email_skipped" tone="warning" class="mt-4" data-testid="tambah-pegawai-email-skipped">
            Email sudah dipakai akun lain, sehingga tidak disimpan di akun pegawai ini.
          </UiNotice>
          <div class="mt-6 flex justify-end">
            <UiButton data-testid="tambah-pegawai-selesai" @click="emit('update:open', false)">Selesai</UiButton>
          </div>
        </div>

        <RiwayatFieldForm
          v-else
          :key="formKey"
          :fields="fields"
          :lampiran="lampiran"
          mode="create"
          :server-errors="serverErrors"
          :busy="busy"
          submit-label="Simpan"
          cancel-label="Batal"
          testid="tambah-pegawai"
          @submit="submit"
          @cancel="tutup"
        />
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
