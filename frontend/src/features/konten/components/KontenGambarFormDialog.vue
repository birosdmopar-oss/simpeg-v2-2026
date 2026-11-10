<script setup lang="ts">
/**
 * Form tambah/ubah popup atau banner (role 1; PRD Fase 7 v2.0 §5.3-5.4): keterangan, tautan, periode (DATE), gambar
 * (wajib saat tambah, opsional saat ubah) dan target. Validasi di sini untuk UX; yang mengikat tetap di server
 * (tipe dari isi, ≤ 5 MB, periode, target tidak kosong).
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, reactive, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'
import { FORM_ACTIONS_CLASS, FORM_ALERT_CLASS, FORM_GRID_CLASS, FORM_WIDE_CLASS } from '@/shared/components/formLayout'
import type { SelectOption } from '@/shared/ui'
import { UiFileField } from '@/shared/ui'
import UiButton from '@/shared/ui/UiButton.vue'

import { GAMBAR_EKSTENSI, GAMBAR_MAKS_MB, kelolaService } from '../services/kelola.service'
import type { JenisKontenGambar, KontenGambarForm, KontenGambarRow, SpesifikasiTarget } from '../types'
import TargetPicker from './TargetPicker.vue'

const props = defineProps<{
  open: boolean
  jenis: JenisKontenGambar
  row: KontenGambarRow | null
  opsiUnitSatker: SelectOption[]
  opsiPegawai: SelectOption[]
}>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [row: KontenGambarRow] }>()

const nama = computed(() => (props.jenis === 'popup' ? 'Popup' : 'Banner'))
const isEdit = computed(() => props.row !== null)
const svc = computed(() => kelolaService(props.jenis))

const kosongTarget = (): SpesifikasiTarget => ({ unit_satker: [], pegawai: [], kecuali: [] })
const form = reactive<KontenGambarForm>({ keterangan: '', url: '', tgl_mulai: '', tgl_akhir: '', target: kosongTarget() })
const berkas = ref<File | null>(null)
const submitting = ref(false)
const submitted = ref(false)
const formError = ref('')
const serverErrors = ref<Record<string, string>>({})

watch(
  () => [props.open, props.row] as const,
  async ([open, row]) => {
    if (!open) return
    submitted.value = false
    formError.value = ''
    serverErrors.value = {}
    berkas.value = null
    form.keterangan = row?.keterangan ?? ''
    form.url = row?.url ?? ''
    form.tgl_mulai = row?.tgl_mulai ?? ''
    form.tgl_akhir = row?.tgl_akhir ?? ''
    form.target = kosongTarget()
    if (row) {
      try {
        form.target = await svc.value.target(row.id)
      } catch (err) {
        formError.value = `Gagal memuat target. ${isApiError(err) ? err.message : ''}`.trim()
      }
    }
  },
  { immediate: true },
)

const errors = computed(() => {
  const e: Record<string, string> = {}
  if (!form.tgl_mulai) e.tgl_mulai = 'Tanggal mulai wajib diisi.'
  if (!form.tgl_akhir) e.tgl_akhir = 'Tanggal selesai wajib diisi.'
  else if (form.tgl_mulai && form.tgl_akhir < form.tgl_mulai) e.tgl_akhir = 'Tanggal selesai tidak boleh sebelum tanggal mulai.'
  if (form.url.trim() !== '' && !/^https?:\/\//i.test(form.url.trim())) e.url = 'Tautan harus diawali http:// atau https://.'
  if (!isEdit.value && !berkas.value) e.berkas = 'Gambar wajib diunggah.'
  return e
})
const targetKosong = computed(() => form.target.unit_satker.length === 0 && form.target.pegawai.length === 0)

function err(field: string): string | undefined {
  return serverErrors.value[field] ?? (submitted.value ? errors.value[field] : undefined)
}

async function onSubmit(): Promise<void> {
  submitted.value = true
  formError.value = ''
  serverErrors.value = {}
  if (Object.keys(errors.value).length > 0 || targetKosong.value || submitting.value) return
  submitting.value = true
  try {
    const saved = props.row ? await svc.value.update(props.row.id, form, berkas.value) : await svc.value.create(form, berkas.value as File)
    emit('saved', saved)
    emit('update:open', false)
  } catch (e) {
    if (isApiError(e) && e.errors) {
      for (const [field, messages] of Object.entries(e.errors)) serverErrors.value[field] = messages[0]
    }
    formError.value = isApiError(e) ? e.message : 'Terjadi kesalahan. Silakan coba lagi.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
      >
        <div class="mb-4 flex items-start justify-between">
          <div>
            <DialogTitle class="text-h5 font-semibold text-slate-900">{{ isEdit ? `Ubah ${nama}` : `Tambah ${nama}` }}</DialogTitle>
            <DialogDescription class="text-sm text-slate-500">
              Tampil ke pegawai yang masuk target selama periode berlaku. Daftar penerima disimpan saat data disimpan.
            </DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" />
          </DialogClose>
        </div>

        <form :class="FORM_GRID_CLASS" novalidate data-testid="konten-gambar-form" @submit.prevent="onSubmit">
          <p v-if="formError" :class="FORM_ALERT_CLASS" role="alert">{{ formError }}</p>

          <FormField v-model="form.tgl_mulai" name="tgl_mulai" label="Tanggal Mulai" type="date" required :error="err('tgl_mulai')" />
          <FormField v-model="form.tgl_akhir" name="tgl_akhir" label="Tanggal Selesai" type="date" required :error="err('tgl_akhir')" />
          <FormField
            v-model="form.url"
            name="url"
            label="Tautan (opsional)"
            :class="FORM_WIDE_CLASS"
            hint="Dibuka saat gambar diklik."
            :error="err('url')"
          />
          <FormField
            v-model="form.keterangan"
            name="keterangan"
            label="Keterangan"
            type="textarea"
            :class="FORM_WIDE_CLASS"
            :error="err('keterangan')"
          />

          <div :class="FORM_WIDE_CLASS">
            <UiFileField
              label="Gambar"
              :allowed="GAMBAR_EKSTENSI"
              :max-mb="GAMBAR_MAKS_MB"
              :hint="isEdit ? 'Kosongkan bila gambar tidak diganti.' : 'Gambar yang ditampilkan'"
              :disabled="submitting"
              @change="berkas = $event"
            />
            <p v-if="err('berkas') || err('gambar')" class="mt-1 text-body2 text-danger" role="alert" data-testid="konten-gambar-berkas-error">
              {{ err('berkas') ?? err('gambar') }}
            </p>
          </div>

          <div :class="FORM_WIDE_CLASS">
            <TargetPicker
              v-model="form.target"
              :opsi-unit-satker="opsiUnitSatker"
              :opsi-pegawai="opsiPegawai"
              :disabled="submitting"
              :invalid="submitted"
            />
          </div>

          <div :class="FORM_ACTIONS_CLASS">
            <DialogClose as-child>
              <UiButton variant="secondary" appearance="soft">Batal</UiButton>
            </DialogClose>
            <UiButton type="submit" :disabled="submitting">{{ submitting ? 'Menyimpan...' : 'Simpan' }}</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
