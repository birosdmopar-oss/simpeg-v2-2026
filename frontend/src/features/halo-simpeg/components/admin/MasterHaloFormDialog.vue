<script setup lang="ts">
/**
 * Form Tambah/Edit master Kategori atau Kontak Halo Simpeg (B3, E-02/E-07; role 1). Edit memakai baris daftar (S9, tanpa GET
 * detail). Status (Aktif/Tidak Aktif) hanya saat Edit; POST tanpa status = bawaan 1 (S11). Validasi klien = batas & teks BE
 * (`kategoriFormSchema` / `kontakFormSchema`); 422 BE per kolom (`question_category`, `name`, `contact`, `email`) dan
 * duplikat (`kategori_duplikat`, `kontak_duplikat`) tampil di kolom, tanpa notice ganda (pola CR-134 T-02).
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, reactive, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'
import { FORM_ACTIONS_CLASS, FORM_ALERT_CLASS, FORM_GRID_CLASS, FORM_WIDE_CLASS } from '@/shared/components/formLayout'
import { UiButton, UiRadioGroup } from '@/shared/ui'
import { galatPerKolom } from '@/shared/utils/galatKolom'

import { kategoriFormSchema, kontakFormSchema } from '../../schemas'
import { kategoriHaloService, kontakHaloService } from '../../services/master.service'
import type { Kategori, Kontak, StatusMaster } from '../../types'

export type JenisMasterHalo = 'kategori' | 'kontak'

const props = defineProps<{ open: boolean; jenis: JenisMasterHalo; row: Kategori | Kontak | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const STATUS = [
  { value: '1', label: 'Aktif' },
  { value: '0', label: 'Tidak Aktif' },
]
const JUDUL = { kategori: 'Kategori', kontak: 'Kontak' } as const

const isEdit = computed(() => props.row !== null)
const form = reactive({ nama: '', kontak: '', email: '', status: '1' })
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
    form.nama = row?.nama ?? ''
    form.kontak = row && 'kontak' in row ? row.kontak : ''
    form.email = row && 'email' in row ? (row.email ?? '') : ''
    form.status = row?.status === 0 ? '0' : '1'
  },
  { immediate: true },
)

const isian = computed(() => {
  const status = Number(form.status) as StatusMaster
  return props.jenis === 'kategori' ? { nama: form.nama, status } : { nama: form.nama, kontak: form.kontak, email: form.email, status }
})

const galatKlien = computed<Record<string, string>>(() => {
  const r = (props.jenis === 'kategori' ? kategoriFormSchema : kontakFormSchema).safeParse(isian.value)
  if (r.success) return {}
  const e: Record<string, string> = {}
  for (const i of r.error.issues) e[String(i.path[0] ?? '')] ??= i.message
  return e
})

/** Nama kolom BE → field form. */
const PETA: Record<string, string> = { question_category: 'nama', name: 'nama', contact: 'kontak', email: 'email', status: 'status' }
const err = (f: string): string | undefined => serverErrors.value[f] ?? (submitted.value ? galatKlien.value[f] : undefined)

async function onSubmit(): Promise<void> {
  submitted.value = true
  formError.value = ''
  serverErrors.value = {}
  if (Object.keys(galatKlien.value).length > 0 || submitting.value) return
  submitting.value = true
  try {
    const id = props.row?.id ?? null
    if (props.jenis === 'kategori') {
      const f = { nama: form.nama, status: Number(form.status) as StatusMaster }
      if (id === null) await kategoriHaloService.tambah(f)
      else await kategoriHaloService.ubah(id, f)
    } else {
      const f = { nama: form.nama, kontak: form.kontak, email: form.email, status: Number(form.status) as StatusMaster }
      if (id === null) await kontakHaloService.tambah(f)
      else await kontakHaloService.ubah(id, f)
    }
    emit('saved')
    emit('update:open', false)
  } catch (e) {
    serverErrors.value = galatPerKolom(e, (k) => PETA[k] ?? k)
    const pesan = isApiError(e) ? e.message : 'Terjadi kesalahan. Silakan coba lagi.'
    formError.value = Object.values(serverErrors.value).includes(pesan) ? '' : pesan
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
        :data-testid="`halo-${jenis}-dialog`"
      >
        <div class="mb-4 flex items-start justify-between">
          <div>
            <DialogTitle class="text-h5 font-semibold text-slate-900">{{ isEdit ? 'Edit' : 'Tambah' }} {{ JUDUL[jenis] }}</DialogTitle>
            <DialogDescription class="text-sm text-slate-500">Master {{ JUDUL[jenis].toLowerCase() }} Halo Simpeg.</DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" />
          </DialogClose>
        </div>

        <form :class="FORM_GRID_CLASS" novalidate :data-testid="`halo-${jenis}-form`" @submit.prevent="onSubmit">
          <p v-if="formError" :class="FORM_ALERT_CLASS" role="alert" :data-testid="`halo-${jenis}-form-galat`">{{ formError }}</p>
          <FormField v-model="form.nama" name="nama" :label="jenis === 'kategori' ? 'Nama Kategori' : 'Nama'" required :error="err('nama')" :class="FORM_WIDE_CLASS" />
          <template v-if="jenis === 'kontak'">
            <FormField v-model="form.kontak" name="kontak" label="Nomor Kontak" required :error="err('kontak')" />
            <FormField v-model="form.email" name="email" label="Email" :error="err('email')" />
          </template>
          <div v-if="isEdit" :class="FORM_WIDE_CLASS">
            <UiRadioGroup v-model="form.status" name="status" label="Status" :options="STATUS" />
          </div>
          <div :class="FORM_ACTIONS_CLASS">
            <DialogClose as-child>
              <UiButton variant="secondary" appearance="soft">Batal</UiButton>
            </DialogClose>
            <UiButton type="submit" :disabled="submitting" :data-testid="`halo-${jenis}-simpan`">{{ submitting ? 'Menyimpan...' : 'Simpan' }}</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
