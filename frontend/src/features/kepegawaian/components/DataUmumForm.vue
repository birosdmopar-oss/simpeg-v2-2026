<script setup lang="ts">
/**
 * Form ubah Data Umum (B-03, MAKE-010) tersambung `PUT pegawai/{nip}/biodata`. Isian per role dari `dataUmum.config.ts`
 * (salinan aturan backend), dirender lewat mesin form riwayat (`RiwayatFieldForm`). Foto profil = slot berkas
 * `FOTO_SLOT`, wajib bila pegawai belum punya foto (legacy).
 *
 * Jalur ditentukan backend: Super Admin → langsung tersimpan; role 2/3/6/7 → pengajuan menunggu verifikasi (B-04).
 * - Isian yang hanya boleh diubah Super Admin (nama, gelar, tanggal lahir) tampil READONLY untuk role lain, ikut form
 *   legacy (`views/hr/employee/form.php:147-190`).
 * - Super Admin + pengajuan pegawai yang masih menunggu: peringatan + ConfirmDialog sebelum simpan, karena backend
 *   menandai pengajuan itu Ditolak (legacy `L_employee.php:410-418`).
 * - Pengaju dengan pengajuan menunggu: pemberitahuan bahwa pengajuan itu akan diganti (satu pengajuan per NIP).
 * Galat 422 per kolom diteruskan ke form; `errors.foto` ditampilkan di isian foto.
 */
import { computed, ref } from 'vue'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { isApiError } from '@/lib/axios'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import { UiNotice, UiTextField } from '@/shared/ui'

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

const langsung = computed(() => props.detail.aksi.ubah_langsung)
const menunggu = computed(() => props.detail.pengajuan?.flag_update === 1)

/** Isian Super Admin yang tampil readonly bagi role lain (form legacy). */
const readonlyFields = computed(() => {
  if (langsung.value) return []
  const d = props.detail
  return [
    { key: 'nama', label: 'Nama', value: d.nama },
    { key: 'glr_awal', label: 'Gelar Depan', value: d.glr_awal ?? '' },
    { key: 'glr_akhir', label: 'Gelar Belakang', value: d.glr_akhir ?? '' },
    { key: 'tgl_lahir', label: 'Tanggal Lahir', value: d.tgl_lahir },
  ]
})

const busy = ref(false)
const serverErrors = ref<RiwayatValidationErrors | null>(null)
const confirmOpen = ref(false)
let tertunda: { values: Record<string, string>; berkas: BerkasMap } | null = null

function onSubmit(values: Record<string, string>, berkas: BerkasMap): void {
  if (langsung.value && menunggu.value) {
    tertunda = { values, berkas }
    confirmOpen.value = true
    return
  }
  void kirim(values, berkas)
}

function onConfirm(): void {
  confirmOpen.value = false
  if (tertunda) void kirim(tertunda.values, tertunda.berkas)
  tertunda = null
}

async function kirim(values: Record<string, string>, berkas: BerkasMap): Promise<void> {
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
      <template v-if="langsung">Perubahan langsung tersimpan ke data pegawai.</template>
      <template v-else>Perubahan diajukan dan berlaku setelah disetujui admin.</template>
      Kolom bertanda (*) wajib diisi.
    </p>

    <UiNotice v-if="langsung && menunggu" tone="warning" class="mb-4" data-testid="data-umum-peringatan-pengajuan">
      Pegawai ini punya pengajuan perubahan data umum yang menunggu verifikasi. Menyimpan perubahan langsung akan menolak
      pengajuan tersebut.
    </UiNotice>
    <UiNotice v-else-if="!langsung && menunggu" tone="info" class="mb-4" data-testid="data-umum-pengajuan-diganti">
      Pengajuan sebelumnya yang belum diverifikasi akan diganti oleh pengajuan ini.
    </UiNotice>

    <div v-if="readonlyFields.length" class="mb-5 grid gap-x-6 gap-y-5 md:grid-cols-2" data-testid="data-umum-readonly">
      <UiTextField
        v-for="f in readonlyFields"
        :key="f.key"
        :model-value="f.value"
        :label="f.label"
        :type="f.key === 'tgl_lahir' ? 'date' : 'text'"
        readonly
        help-text="Hanya dapat diubah Super Admin."
        :data-field="f.key"
      />
    </div>

    <RiwayatFieldForm
      :fields="fields"
      :lampiran="lampiran"
      mode="edit"
      :initial="initial"
      :lampiran-tersimpan="tersimpan"
      :server-errors="serverErrors"
      :busy="busy"
      :submit-label="langsung ? 'Simpan' : 'Ajukan Perubahan'"
      cancel-label="Batal"
      testid="data-umum"
      @submit="onSubmit"
      @cancel="emit('cancel')"
    />

    <ConfirmDialog
      :open="confirmOpen"
      title="Tolak pengajuan pegawai?"
      description="Menyimpan akan menolak pengajuan pegawai yang menunggu verifikasi. Lanjutkan?"
      confirm-label="Ya, simpan"
      danger
      @update:open="(v) => (confirmOpen = v)"
      @confirm="onConfirm"
    />
  </div>
</template>
