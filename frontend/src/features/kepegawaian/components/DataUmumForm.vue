<script setup lang="ts">
/**
 * Form "Data Umum" (§4.1.6, Gambar 22): dua kolom, label di atas, tanda (*) merah, radio Jenis Kelamin/Status
 * Perkawinan, unggah berkas dengan petunjuk, dan tombol "Batalkan Perubahan" + "Simpan Perubahan" (hijau).
 * VeeValidate + Zod (ADR-026). Mode `readonly` (role tanpa hak ubah) menonaktifkan semua isian & menyembunyikan tombol.
 */
import { toTypedSchema } from '@vee-validate/zod'
import { useForm } from 'vee-validate'
import { type Ref, watch } from 'vue'

import { UiButton, UiFileField, UiRadioGroup, UiSelect, UiTextField } from '@/shared/ui'

import {
  AGAMA,
  JENIS_KELAMIN,
  JENIS_KERABAT,
  JENIS_PEGAWAI,
  JENIS_STATUS,
  KOTA_BY_PROVINSI,
  PROVINSI,
  STATUS_PEGAWAI,
  STATUS_PERKAWINAN,
} from '../options'
import { type DataUmumForm, dataUmumSchema } from '../schemas/dataUmum.schema'
import { LAMPIRAN_MAX_MB, type DataUmum } from '../types'

const props = defineProps<{ initial: DataUmum; readonly: boolean }>()
const emit = defineEmits<{ save: [values: DataUmumForm] }>()

const { errors, handleSubmit, resetForm, defineField } = useForm<DataUmumForm>({
  validationSchema: toTypedSchema(dataUmumSchema),
  initialValues: { ...props.initial },
})

watch(
  () => props.initial,
  (next) => resetForm({ values: { ...next } }),
)

type Field = keyof DataUmumForm

/**
 * Semua field didaftarkan ke VeeValidate lewat defineField — hanya field terdaftar yang menerima pesan error
 * dan ikut divalidasi/dikirim saat submit (setFieldValue saja tidak cukup).
 */
const models = {} as Record<Field, Ref<string>>
for (const key of Object.keys(dataUmumSchema.shape) as Field[]) models[key] = defineField(key)[0]

/** Menghubungkan komponen UI ke field VeeValidate (nilai, pembaruan, status error, teks bantu). */
function bind(name: Field, hint = '') {
  const error = errors.value[name]
  return {
    modelValue: models[name].value,
    'onUpdate:modelValue': (v: string) => {
      models[name].value = v
    },
    state: (error ? 'error' : 'default') as 'error' | 'default',
    helpText: error ?? hint,
    disabled: props.readonly,
  }
}

function onProvinsi(v: string): void {
  models.provinsi_lahir.value = v
  const valid = (KOTA_BY_PROVINSI[v] ?? []).some((o) => o.value === models.kota_lahir.value)
  if (!valid) models.kota_lahir.value = ''
}

const submit = handleSubmit((v) => emit('save', v))
</script>

<template>
  <form novalidate class="space-y-0" data-testid="data-umum-form" @submit.prevent="submit">
    <div class="grid gap-x-6 gap-y-5 px-5 py-5 md:grid-cols-2">
      <div class="md:col-span-2">
        <UiTextField v-bind="bind('nama')" label="Nama" required autocomplete="off" />
      </div>

      <UiTextField v-bind="bind('gelar_awal', 'Gelar sebelum nama')" label="Gelar Awal" placeholder="Dr, Drs, Dra, Prof..." />
      <UiTextField v-bind="bind('gelar_akhir', 'Gelar setelah nama')" label="Gelar Akhir" placeholder="S.Kom, M.Si..." />

      <div class="md:col-span-2">
        <UiTextField v-bind="bind('tanggal_lahir')" label="Tanggal Lahir" type="date" required />
      </div>

      <UiSelect
        :model-value="models.provinsi_lahir.value"
        label="Provinsi Lahir"
        required
        :options="PROVINSI"
        placeholder="Pilih provinsi..."
        :state="errors.provinsi_lahir ? 'error' : 'default'"
        :help-text="errors.provinsi_lahir"
        :disabled="readonly"
        @update:model-value="onProvinsi"
      />
      <UiSelect
        v-bind="bind('kota_lahir')"
        label="Kab. / Kota Lahir"
        required
        :options="KOTA_BY_PROVINSI[models.provinsi_lahir.value] ?? []"
        placeholder="Pilih kab./kota..."
        :disabled="readonly || !models.provinsi_lahir.value"
      />

      <hr class="border-slate-200 md:col-span-2" />

      <UiRadioGroup v-bind="bind('jenis_kelamin')" label="Jenis Kelamin" required name="jenis_kelamin" :options="JENIS_KELAMIN" />
      <UiRadioGroup v-bind="bind('status_perkawinan')" label="Status Perkawinan" required name="status_perkawinan" :options="STATUS_PERKAWINAN" />

      <UiTextField v-bind="bind('nik')" label="NIK" required inputmode="numeric" />
      <UiTextField v-bind="bind('npwp')" label="NPWP" required inputmode="numeric" />
      <UiTextField v-bind="bind('no_bpjs_kesehatan')" label="No. BPJS Kesehatan" required inputmode="numeric" />
      <UiTextField v-bind="bind('no_bpjs_ketenagakerjaan')" label="No. BPJS Ketenagakerjaan" required inputmode="numeric" />
      <UiTextField v-bind="bind('no_taspen')" label="No. Taspen" required inputmode="numeric" />
      <UiSelect v-bind="bind('agama')" label="Agama" required :options="AGAMA" placeholder="Pilih agama..." />
      <UiTextField v-bind="bind('email')" label="Email" type="email" required autocomplete="off" />
      <UiTextField v-bind="bind('no_hp')" label="No. HP" type="tel" required />
      <UiSelect v-bind="bind('jenis_kerabat')" label="Jenis Kerabat" required :options="JENIS_KERABAT" placeholder="Pilih jenis kerabat..." />
      <UiTextField v-bind="bind('no_telp_kerabat')" label="No. Telp. Kerabat" type="tel" required />

      <hr class="border-slate-200 md:col-span-2" />

      <UiSelect v-bind="bind('jenis_pegawai')" label="Jenis Pegawai" required :options="JENIS_PEGAWAI" placeholder="Pilih jenis pegawai..." />
      <UiSelect v-bind="bind('status_pegawai')" label="Status Pegawai" required :options="STATUS_PEGAWAI" placeholder="Pilih status..." />
      <UiSelect v-bind="bind('jenis_status')" label="Jenis Status" required :options="JENIS_STATUS" placeholder="Pilih jenis status..." />
      <UiTextField v-bind="bind('tmt_status')" label="TMT Status" placeholder="CPNS, PNS, PPPK..." />

      <hr class="border-slate-200 md:col-span-2" />

      <UiFileField
        label="File Arsip Status"
        hint="File yang diunggah Arsip status"
        :allowed="['jpeg', 'jpg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx']"
        :max-mb="LAMPIRAN_MAX_MB"
        :disabled="readonly"
      />
      <UiTextField label="Nama Arsip Status" placeholder="Nama arsip status file ini adalah..." :disabled="readonly" />
      <UiFileField
        label="File Foto"
        hint="File yang diunggah Foto Pegawai"
        :allowed="['jpeg', 'jpg', 'png']"
        :max-mb="LAMPIRAN_MAX_MB"
        :disabled="readonly"
      />
      <UiTextField label="Keterangan" placeholder="Keterangan dari file ini adalah..." :disabled="readonly" />
    </div>

    <footer v-if="!readonly" class="flex flex-wrap items-center justify-end gap-3 border-t border-slate-200 px-5 py-4">
      <UiButton variant="secondary" appearance="soft" data-testid="data-umum-cancel" @click="resetForm({ values: { ...initial } })">
        Batalkan Perubahan
      </UiButton>
      <UiButton type="submit" variant="success" data-testid="data-umum-save">Simpan Perubahan</UiButton>
    </footer>
  </form>
</template>
