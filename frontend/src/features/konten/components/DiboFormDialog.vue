<script setup lang="ts">
/**
 * Form tambah/ubah DIBO (role 1; PRD Fase 7 v2.0 §5.6, Q7-11/12/14): judul, jenis (master `jenis_dibo`), keterangan,
 * berkas (wajib saat tambah; unduh terautentikasi di server). Validasi di sini untuk UX; yang mengikat di server.
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

import { diboKelolaService } from '../services/kelolaTeks.service'
import type { DiboForm, DiboRow } from '../types'

const props = defineProps<{ open: boolean; row: DiboRow | null; opsiJenis: SelectOption[] }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [row: DiboRow] }>()

const BERKAS_EKSTENSI = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png']
const BERKAS_MAKS_MB = 5

const isEdit = computed(() => props.row !== null)
const form = reactive<DiboForm>({ judul: '', id_jenis: '', keterangan: '' })
const berkas = ref<File | null>(null)
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
    berkas.value = null
    form.judul = row?.judul ?? ''
    form.id_jenis = row?.id_jenis ?? ''
    form.keterangan = row?.keterangan ?? ''
  },
  { immediate: true },
)

const errors = computed(() => {
  const e: Record<string, string> = {}
  if (form.judul.trim() === '') e.judul = 'Judul wajib diisi.'
  if (form.id_jenis === '') e.id_jenis = 'Jenis wajib dipilih.'
  if (!isEdit.value && !berkas.value) e.berkas = 'Berkas wajib diunggah.'
  return e
})

function err(f: string): string | undefined {
  return serverErrors.value[f] ?? (submitted.value ? errors.value[f] : undefined)
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
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
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
          <FormField v-model="form.judul" name="judul" label="Judul" required :class="FORM_WIDE_CLASS" :error="err('judul')" />
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
          <FormField v-model="form.keterangan" name="keterangan" label="Keterangan" type="textarea" :class="FORM_WIDE_CLASS" :error="err('keterangan')" />
          <div :class="FORM_WIDE_CLASS">
            <UiFileField
              label="Berkas"
              :allowed="BERKAS_EKSTENSI"
              :max-mb="BERKAS_MAKS_MB"
              :hint="isEdit ? 'Kosongkan bila berkas tidak diganti.' : 'Berkas yang dibagikan'"
              :disabled="submitting"
              @change="berkas = $event"
            />
            <p v-if="err('berkas')" class="mt-1 text-body2 text-danger" role="alert">{{ err('berkas') }}</p>
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
