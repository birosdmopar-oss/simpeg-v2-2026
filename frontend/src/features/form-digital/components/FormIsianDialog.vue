<script setup lang="ts">
/**
 * Isian form digital pegawai (F-06, MAKE-083, PRD Fase 7 v2.0 §5.7). TIDAK BISA DITUTUP [K]: legacy membuka modal dengan
 * `backdrop:'static', keyboard:false` (`views/template/modal_test.php:246-284`) dan satu-satunya tombol "Simpan"
 * (`modal/formulir_digital.php:30`). Esc/klik luar ditahan; tanpa tombol X.
 *
 * - Pertanyaan dirender dari skema; label/bantuan/opsi sebagai TEKS (bukan HTML — menutup XSS formBuilder legacy).
 * - Validasi wajib di klien hanya untuk UX; validasi mengikat di server (422 per field ditampilkan di bawah pertanyaan).
 * - 201 → `selesai`. 409 `sudah_diisi` → `selesai` dengan pesan (form tidak ditampilkan lagi).
 */
import { DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, reactive, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'
import UiButton from '@/shared/ui/UiButton.vue'
import UiCheckbox from '@/shared/ui/UiCheckbox.vue'
import UiDatePicker from '@/shared/ui/UiDatePicker.vue'
import UiFileField from '@/shared/ui/UiFileField.vue'
import UiNotice from '@/shared/ui/UiNotice.vue'
import UiRadioGroup from '@/shared/ui/UiRadioGroup.vue'
import UiTextField from '@/shared/ui/UiTextField.vue'

import { formDigitalService } from '../services/formDigital.service'
import type { FormSaya, NilaiJawaban, Pertanyaan } from '../types'

const props = defineProps<{ form: FormSaya }>()
const emit = defineEmits<{ selesai: [pesan: string | null] }>()

const teks = reactive<Record<string, string>>({})
const ganda = reactive<Record<string, string[]>>({})
const berkas = reactive<Record<string, File | null>>({})
const galat = ref<Record<string, string>>({})
const umum = ref('')
const sibuk = ref(false)

const pertanyaan = computed<Pertanyaan[]>(() => props.form.form.pertanyaan)

watch(
  () => props.form.id,
  () => {
    for (const k of Object.keys(teks)) delete teks[k]
    for (const k of Object.keys(ganda)) delete ganda[k]
    for (const k of Object.keys(berkas)) delete berkas[k]
    for (const p of pertanyaan.value) {
      if (p.tipe === 'pilihan_ganda') ganda[p.id] = []
      else if (p.tipe === 'berkas') berkas[p.id] = null
      else teks[p.id] = ''
    }
    galat.value = {}
    umum.value = ''
  },
  { immediate: true },
)

function toggle(id: string, opsi: string, pilih: boolean): void {
  const daftar = ganda[id] ?? []
  ganda[id] = pilih ? [...daftar.filter((o) => o !== opsi), opsi] : daftar.filter((o) => o !== opsi)
}

function kosong(p: Pertanyaan): boolean {
  if (p.tipe === 'pilihan_ganda') return (ganda[p.id] ?? []).length === 0
  if (p.tipe === 'berkas') return berkas[p.id] == null
  return (teks[p.id] ?? '').trim() === ''
}

function nilai(p: Pertanyaan): NilaiJawaban {
  if (p.tipe === 'pilihan_ganda') return ganda[p.id] ?? []
  const v = (teks[p.id] ?? '').trim()
  if (v === '') return null
  return p.tipe === 'angka' && !Number.isNaN(Number(v)) ? Number(v) : v
}

async function simpan(): Promise<void> {
  const lokal: Record<string, string> = {}
  for (const p of pertanyaan.value) if (p.wajib && kosong(p)) lokal[p.id] = 'Wajib diisi.'
  galat.value = lokal
  umum.value = ''
  if (Object.keys(lokal).length > 0) return

  const jawaban: Record<string, NilaiJawaban> = {}
  const unggah: Record<string, File> = {}
  for (const p of pertanyaan.value) {
    if (p.tipe === 'berkas') {
      const f = berkas[p.id]
      if (f) unggah[p.id] = f
      continue
    }
    const v = nilai(p)
    if (v !== null && !(Array.isArray(v) && v.length === 0)) jawaban[p.id] = v
  }

  sibuk.value = true
  try {
    await formDigitalService.isi(props.form.id, jawaban, unggah)
    emit('selesai', null)
  } catch (e) {
    if (isApiError(e) && e.status === 409) {
      emit('selesai', 'Form ini sudah pernah Anda isi.')
    } else if (isApiError(e) && e.status === 422 && e.errors) {
      const per: Record<string, string> = {}
      for (const [k, v] of Object.entries(e.errors)) {
        const id = k.startsWith('jawaban.') ? k.slice(8) : k.startsWith('berkas_') ? k.slice(7) : null
        if (id !== null && pertanyaan.value.some((p) => p.id === id)) per[id] = v[0] ?? 'Tidak valid.'
        else umum.value = v[0] ?? e.message
      }
      galat.value = per
      if (umum.value === '' && Object.keys(per).length === 0) umum.value = e.message
    } else {
      umum.value = isApiError(e) ? e.message : 'Gagal menyimpan. Coba lagi.'
    }
  } finally {
    sibuk.value = false
  }
}

function tahan(e: Event): void {
  e.preventDefault()
}

const opsiRadio = (p: Pertanyaan) => (p.opsi ?? []).map((o) => ({ value: o, label: o }))
</script>

<template>
  <DialogRoot :open="true">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 flex max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl -translate-x-1/2 -translate-y-1/2 flex-col rounded-2xl bg-white shadow-panel"
        data-testid="form-isian-dialog"
        @escape-key-down="tahan"
        @pointer-down-outside="tahan"
        @interact-outside="tahan"
      >
        <div class="border-b border-slate-200 px-5 py-4">
          <DialogTitle class="text-h6 font-semibold text-slate-900">{{ form.judul_form }}</DialogTitle>
          <DialogDescription class="mt-1 whitespace-pre-line text-body2 text-slate-600">
            {{ form.keterangan || 'Mohon lengkapi form berikut.' }}
          </DialogDescription>
        </div>
        <form class="flex-1 space-y-5 overflow-y-auto px-5 py-4" novalidate @submit.prevent="simpan">
          <UiNotice v-if="umum" tone="danger" data-testid="form-isian-galat">{{ umum }}</UiNotice>
          <div v-for="p in pertanyaan" :key="p.id" :data-testid="`form-isian-q-${p.id}`">
            <FormField
              v-if="p.tipe === 'paragraf'"
              v-model="teks[p.id]"
              type="textarea"
              :label="p.label"
              :required="p.wajib"
              :name="p.id"
              :error="galat[p.id]"
              :hint="p.bantuan"
            />
            <template v-else-if="p.tipe === 'teks' || p.tipe === 'angka'">
              <UiTextField
                v-model="teks[p.id]"
                :label="p.label"
                :required="p.wajib"
                :type="p.tipe === 'angka' ? 'number' : 'text'"
                :inputmode="p.tipe === 'angka' ? 'decimal' : 'text'"
                :help-text="galat[p.id] ?? p.bantuan"
                :state="galat[p.id] ? 'error' : 'default'"
                :name="p.id"
              />
            </template>
            <UiRadioGroup
              v-else-if="p.tipe === 'pilihan_tunggal'"
              :model-value="teks[p.id]"
              :options="opsiRadio(p)"
              :label="p.label"
              :required="p.wajib"
              :name="p.id"
              :state="galat[p.id] ? 'error' : 'default'"
              :help-text="galat[p.id] ?? p.bantuan"
              direction="column"
              @update:model-value="(v: string) => (teks[p.id] = v)"
            />
            <fieldset v-else-if="p.tipe === 'pilihan_ganda'">
              <legend class="mb-2 text-body1 font-medium text-slate-800">
                {{ p.label }}<span v-if="p.wajib" class="text-danger"> *</span>
              </legend>
              <div class="space-y-2">
                <UiCheckbox
                  v-for="o in p.opsi ?? []"
                  :key="o"
                  :label="o"
                  :model-value="(ganda[p.id] ?? []).includes(o)"
                  :invalid="!!galat[p.id]"
                  @update:model-value="(v: boolean) => toggle(p.id, o, v)"
                />
              </div>
              <p v-if="galat[p.id] || p.bantuan" class="mt-1 text-body2" :class="galat[p.id] ? 'text-danger' : 'text-slate-500'">
                {{ galat[p.id] ?? p.bantuan }}
              </p>
            </fieldset>
            <div v-else-if="p.tipe === 'tanggal'">
              <label :for="`fi-${p.id}`" class="mb-1 block text-body1 font-medium text-slate-800">
                {{ p.label }}<span v-if="p.wajib" class="text-danger"> *</span>
              </label>
              <UiDatePicker :id="`fi-${p.id}`" v-model="teks[p.id]" :invalid="!!galat[p.id]" :required="p.wajib" />
              <p v-if="galat[p.id] || p.bantuan" class="mt-1 text-body2" :class="galat[p.id] ? 'text-danger' : 'text-slate-500'">
                {{ galat[p.id] ?? p.bantuan }}
              </p>
            </div>
            <div v-else-if="p.tipe === 'berkas'">
              <UiFileField
                :label="p.wajib ? `${p.label} *` : p.label"
                :allowed="p.berkas?.tipe ?? []"
                :max-mb="p.berkas?.maks_mb ?? 5"
                :hint="p.bantuan ?? ''"
                @change="(f: File | null) => (berkas[p.id] = f)"
              />
              <p v-if="galat[p.id]" class="mt-1 text-body2 text-danger">{{ galat[p.id] }}</p>
            </div>
          </div>
        </form>
        <div class="flex justify-end border-t border-slate-200 px-5 py-3">
          <UiButton :loading="sibuk" data-testid="form-isian-simpan" @click="simpan">Simpan</UiButton>
        </div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
