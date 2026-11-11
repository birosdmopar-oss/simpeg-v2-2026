<script setup lang="ts">
/**
 * Form tambah/ubah DIBO (role 1; PRD Fase 7 v2.0 §5.6, Q7-11/12/14; legacy `L_dibo.php`): jenis (master `jenis_dibo` aktif), nomor,
 * tahun, tentang, keterangan, dan berkas 1–3 (PDF/XLS/XLSX/DOC/DOCX ≤ 5 MB). Berkas 1 wajib saat tambah; saat ubah, berkas yang
 * tidak dipilih dipertahankan, berkas 2/3 bisa dihapus lewat kotak centang (padanan `deleted_file[]` legacy). Validasi di sini
 * untuk UX; yang mengikat di server.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, reactive, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'
import { FORM_ACTIONS_CLASS, FORM_ALERT_CLASS, FORM_GRID_CLASS, FORM_WIDE_CLASS } from '@/shared/components/formLayout'
import type { SelectOption } from '@/shared/ui'
import { UiCheckbox, UiFileField } from '@/shared/ui'
import UiButton from '@/shared/ui/UiButton.vue'

import { diboKelolaService } from '../services/kelolaTeks.service'
import type { DiboBerkasInput, DiboForm, DiboRow } from '../types'

const props = defineProps<{ open: boolean; row: DiboRow | null; opsiJenis: SelectOption[] }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [row: DiboRow] }>()

const BERKAS_EKSTENSI = ['pdf', 'xls', 'xlsx', 'doc', 'docx']
const BERKAS_MAKS_MB = 5
const NOMOR_BERKAS = [1, 2, 3] as const

const isEdit = computed(() => props.row !== null)
const kosong = (): DiboForm => ({ id_jenis: '', nomor: '', tahun: '', tentang: '', keterangan: '' })
const form = reactive<DiboForm>(kosong())
const berkas = ref<DiboBerkasInput>({ 1: null, 2: null, 3: null, hapus2: false, hapus3: false })
const submitting = ref(false)
const submitted = ref(false)
const formError = ref('')
const serverErrors = ref<Record<string, string>>({})

watch(
  () => [props.open, props.row] as const,
  ([open, row]) => {
    if (!open) return
    submitted.value = false
    formError.value = ''
    serverErrors.value = {}
    berkas.value = { 1: null, 2: null, 3: null, hapus2: false, hapus3: false }
    Object.assign(form, kosong(), row ? { id_jenis: row.id_jenis, nomor: row.nomor, tahun: row.tahun, tentang: row.tentang, keterangan: row.keterangan } : {})
  },
  { immediate: true },
)

const ada = (no: number): boolean => props.row?.berkas.includes(no) ?? false

const errors = computed(() => {
  const e: Record<string, string> = {}
  if (form.id_jenis === '') e.id_jenis = 'Jenis wajib dipilih.'
  if (form.nomor.trim() === '') e.nomor = 'Nomor wajib diisi.'
  if (!/^\d{4}$/.test(form.tahun.trim())) e.tahun = 'Tahun harus 4 digit.'
  if (form.tentang.trim() === '') e.tentang = 'Tentang wajib diisi.'
  if (!isEdit.value && !berkas.value[1]) e.file_1 = 'Berkas 1 wajib diunggah.'
  return e
})

function err(f: string): string | undefined {
  return serverErrors.value[f] ?? (submitted.value ? errors.value[f] : undefined)
}

function pilih(no: 1 | 2 | 3, f: File | null): void {
  berkas.value = { ...berkas.value, [no]: f }
}

async function onSubmit(): Promise<void> {
  submitted.value = true
  formError.value = ''
  serverErrors.value = {}
  if (Object.keys(errors.value).length > 0 || submitting.value) return
  submitting.value = true
  try {
    const saved = await diboKelolaService.simpan(props.row?.id ?? null, form, berkas.value)
    emit('saved', saved)
    emit('update:open', false)
  } catch (e) {
    if (isApiError(e) && e.errors) for (const [f, m] of Object.entries(e.errors)) serverErrors.value[f] = m[0]
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
            <DialogTitle class="text-h5 font-semibold text-slate-900">{{ isEdit ? 'Ubah DIBO' : 'Tambah DIBO' }}</DialogTitle>
            <DialogDescription class="text-sm text-slate-500">Pegawai hanya melihat DIBO berstatus Aktif.</DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" />
          </DialogClose>
        </div>

        <form :class="FORM_GRID_CLASS" novalidate data-testid="dibo-form" @submit.prevent="onSubmit">
          <p v-if="formError" :class="FORM_ALERT_CLASS" role="alert">{{ formError }}</p>

          <FormField
            v-model="form.id_jenis"
            name="id_jenis"
            label="Jenis"
            type="select"
            required
            placeholder="Pilih jenis"
            :class="FORM_WIDE_CLASS"
            :options="opsiJenis.map((o) => ({ value: o.value, label: o.label }))"
            :error="err('id_jenis')"
          />
          <FormField v-model="form.nomor" name="nomor" label="Nomor" required :error="err('nomor')" />
          <FormField v-model="form.tahun" name="tahun" label="Tahun" required inputmode="numeric" :error="err('tahun')" />
          <FormField v-model="form.tentang" name="tentang" label="Tentang" type="textarea" required :class="FORM_WIDE_CLASS" :error="err('tentang')" />
          <FormField v-model="form.keterangan" name="keterangan" label="Keterangan" type="textarea" :class="FORM_WIDE_CLASS" :error="err('keterangan')" />

          <div v-for="no in NOMOR_BERKAS" :key="no" :class="FORM_WIDE_CLASS" :data-testid="`dibo-berkas-${no}`">
            <UiFileField
              :label="`Berkas ${no}${no === 1 ? '' : ' (opsional)'}`"
              :allowed="BERKAS_EKSTENSI"
              :max-mb="BERKAS_MAKS_MB"
              :hint="isEdit && ada(no) ? 'Sudah ada berkas. Pilih berkas baru untuk menggantinya.' : 'PDF, XLS, XLSX, DOC, atau DOCX'"
              :disabled="submitting"
              @change="pilih(no, $event)"
            />
            <UiCheckbox
              v-if="isEdit && ada(no) && no !== 1"
              :model-value="no === 2 ? berkas.hapus2 : berkas.hapus3"
              :label="`Hapus berkas ${no}`"
              :disabled="submitting"
              @update:model-value="berkas = { ...berkas, [no === 2 ? 'hapus2' : 'hapus3']: $event === true }"
            />
            <p v-if="err(`file_${no}`)" class="mt-1 text-body2 text-danger" role="alert">{{ err(`file_${no}`) }}</p>
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
