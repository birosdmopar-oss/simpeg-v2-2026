<script setup lang="ts">
/**
 * Form ubah Data Umum (B-03, MAKE-010) tersambung `PUT pegawai/{nip}/biodata`. Isian per role dari `dataUmum.config.ts`
 * (salinan aturan backend), dirender lewat mesin form riwayat (`RiwayatFieldForm`). Foto profil = slot berkas
 * `FOTO_SLOT`, wajib bila pegawai belum punya foto (legacy).
 *
 * Jalur ditentukan backend: Super Admin → langsung tersimpan; role 2/3/6/7 → pengajuan menunggu verifikasi (B-04).
 * Galat 422 per kolom diteruskan ke form; `errors.foto` ditampilkan di isian foto.
 */
import { computed, ref } from 'vue'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { isApiError } from '@/lib/axios'

import { dataUmumFields, dataUmumInitial, FOTO_SLOT, fotoLampiran } from '../dataUmum.config'
import RiwayatFieldForm from '../riwayat/RiwayatFieldForm.vue'
import type { BerkasMap } from '../riwayat/riwayat.service'
import { describeApiError, splitValidationErrors, type RiwayatValidationErrors } from '../services/apiErrors'
import { pegawaiService } from '../services/pegawai.service'
import type { BiodataResult, PegawaiDetail } from '../types'

const props = defineProps<{ detail: PegawaiDetail }>()
const emit = defineEmits<{ saved: [result: BiodataResult]; cancel: [] }>()

const auth = useAuthStore()

const fields = computed(() => dataUmumFields(auth.role))
const initial = computed(() => dataUmumInitial(props.detail, props.detail.email, fields.value))
const lampiran = computed(() => [fotoLampiran()])
const tersimpan = computed(() => ({ [FOTO_SLOT]: props.detail.ada_foto ? 1 : 0 }))

const busy = ref(false)
const serverErrors = ref<RiwayatValidationErrors | null>(null)

async function submit(values: Record<string, string>, berkas: BerkasMap): Promise<void> {
  busy.value = true
  serverErrors.value = null
  try {
    const result = await pegawaiService.updateBiodata(props.detail.nip, values, berkas[FOTO_SLOT] ?? null)
    emit('saved', result)
  } catch (error) {
    const failure = describeApiError(error)
    const split = splitValidationErrors(error, fields.value.map((f) => f.name))
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
</script>

<template>
  <div data-testid="data-umum-form">
    <p class="mb-4 text-body2 text-slate-500">
      <template v-if="detail.aksi.ubah_langsung">Perubahan langsung tersimpan ke data pegawai.</template>
      <template v-else>Perubahan diajukan dan berlaku setelah disetujui admin.</template>
      Kolom bertanda (*) wajib diisi.
    </p>
    <RiwayatFieldForm
      :fields="fields"
      :lampiran="lampiran"
      mode="edit"
      :initial="initial"
      :lampiran-tersimpan="tersimpan"
      :server-errors="serverErrors"
      :busy="busy"
      :submit-label="detail.aksi.ubah_langsung ? 'Simpan' : 'Ajukan Perubahan'"
      cancel-label="Batal"
      testid="data-umum"
      @submit="submit"
      @cancel="emit('cancel')"
    />
  </div>
</template>
