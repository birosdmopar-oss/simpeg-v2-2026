<script setup lang="ts">
/**
 * Form tambah/ubah berita (role 1 dan 3; PRD Fase 7 v2.0 §5.5, Q7-07/10): judul, kategori, isi (editor teks sederhana →
 * HTML terbatas, server membersihkan ulang), tanggal terbit, dan target. Berita buatan role 3 masuk status menunggu
 * persetujuan role 1 (diberi tahu di deskripsi). Validasi di sini untuk UX; yang mengikat di server.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, reactive, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'
import { FORM_ACTIONS_CLASS, FORM_ALERT_CLASS, FORM_GRID_CLASS, FORM_WIDE_CLASS } from '@/shared/components/formLayout'
import type { SelectOption } from '@/shared/ui'
import { UiTeksSederhana } from '@/shared/ui'
import UiButton from '@/shared/ui/UiButton.vue'

import { beritaKelolaService } from '../services/kelolaTeks.service'
import type { BeritaForm, BeritaKelolaRow } from '../types'
import TargetPicker from './TargetPicker.vue'

const props = defineProps<{
  open: boolean
  row: BeritaKelolaRow | null
  opsiKategori: SelectOption[]
  opsiUnitSatker: SelectOption[]
  opsiPegawai: SelectOption[]
  /** Role 3: berita menunggu persetujuan. */
  perluPersetujuan?: boolean
}>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [row: BeritaKelolaRow] }>()

const isEdit = computed(() => props.row !== null)
const kosong = (): BeritaForm => ({ judul: '', id_kategori: '', isi: '', publish_at: '', target: { unit_satker: [], pegawai: [], kecuali: [] } })
const form = reactive<BeritaForm>(kosong())
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
    Object.assign(form, kosong())
    if (row) {
      try {
        Object.assign(form, await beritaKelolaService.detail(row.id))
      } catch (e) {
        formError.value = `Gagal memuat berita. ${isApiError(e) ? e.message : ''}`.trim()
      }
    }
  },
  { immediate: true },
)

const errors = computed(() => {
  const e: Record<string, string> = {}
  if (form.judul.trim() === '') e.judul = 'Judul wajib diisi.'
  if (form.id_kategori === '') e.id_kategori = 'Kategori wajib dipilih.'
  if (form.isi.trim() === '') e.isi = 'Isi berita wajib diisi.'
  if (form.publish_at === '') e.publish_at = 'Tanggal terbit wajib diisi.'
  return e
})
const targetKosong = computed(() => form.target.unit_satker.length === 0 && form.target.pegawai.length === 0)

function err(f: string): string | undefined {
  return serverErrors.value[f] ?? (submitted.value ? errors.value[f] : undefined)
}

async function onSubmit(): Promise<void> {
  submitted.value = true
  formError.value = ''
  serverErrors.value = {}
  if (Object.keys(errors.value).length > 0 || targetKosong.value || submitting.value) return
  submitting.value = true
  try {
    const saved = props.row ? await beritaKelolaService.update(props.row.id, form) : await beritaKelolaService.create(form)
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
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-3xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
      >
        <div class="mb-4 flex items-start justify-between">
          <div>
            <DialogTitle class="text-h5 font-semibold text-slate-900">{{ isEdit ? 'Ubah Berita' : 'Tambah Berita' }}</DialogTitle>
            <DialogDescription class="text-sm text-slate-500">
              {{ perluPersetujuan ? 'Berita akan menunggu persetujuan admin sebelum tampil.' : 'Berita tampil ke pegawai yang masuk target setelah tanggal terbit.' }}
            </DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" />
          </DialogClose>
        </div>

        <form :class="FORM_GRID_CLASS" novalidate data-testid="berita-form" @submit.prevent="onSubmit">
          <p v-if="formError" :class="FORM_ALERT_CLASS" role="alert">{{ formError }}</p>

          <FormField v-model="form.judul" name="judul" label="Judul" required :class="FORM_WIDE_CLASS" :error="err('judul')" />
          <FormField
            v-model="form.id_kategori"
            name="id_kategori"
            label="Kategori"
            type="select"
            required
            placeholder="Pilih kategori"
            :options="opsiKategori.map((o) => ({ value: o.value, label: o.label }))"
            :error="err('id_kategori')"
          />
          <FormField v-model="form.publish_at" name="publish_at" label="Tanggal Terbit" type="date" required :error="err('publish_at')" />

          <div :class="FORM_WIDE_CLASS">
            <UiTeksSederhana v-model="form.isi" label="Isi berita" :invalid="submitted && !!errors.isi" :disabled="submitting" />
            <p v-if="err('isi')" class="mt-1 text-body2 text-danger" role="alert">{{ err('isi') }}</p>
          </div>

          <div :class="FORM_WIDE_CLASS">
            <TargetPicker v-model="form.target" :opsi-unit-satker="opsiUnitSatker" :opsi-pegawai="opsiPegawai" :disabled="submitting" :invalid="submitted" />
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
