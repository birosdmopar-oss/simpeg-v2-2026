<script setup lang="ts">
/**
 * Editor form digital (F-06, MAKE-083, role 1): judul, periode, urutan, keterangan, pertanyaan (builder custom — tambah,
 * Naikkan/Turunkan, hapus; tanpa pustaka seret), dan target lewat `TargetPicker` Jalur A (`@/features/konten`).
 * Form yang sudah memiliki jawaban TERKUNCI pertanyaannya (Q7-25): area pertanyaan baca-saja; server tetap menolak 409.
 * Label/opsi teks polos; validasi mengikat di server (422 per field ditampilkan).
 */
import { ArrowDown, ArrowUp, Plus, Trash2, X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, reactive, ref, watch } from 'vue'

import { pegawaiService } from '@/features/kepegawaian/services/pegawai.service'
import { TargetPicker } from '@/features/konten'
import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'
import type { SelectOption } from '@/shared/ui'
import UiButton from '@/shared/ui/UiButton.vue'
import UiCheckbox from '@/shared/ui/UiCheckbox.vue'
import UiNotice from '@/shared/ui/UiNotice.vue'
import UiSelect from '@/shared/ui/UiSelect.vue'
import UiTextField from '@/shared/ui/UiTextField.vue'

import { formDigitalService } from '../services/formDigital.service'
import { TIPE_BERKAS, TIPE_PERTANYAAN, type FormInput, type Pertanyaan, type TargetForm, type TipePertanyaan } from '../types'

const open = defineModel<boolean>('open', { required: true })
const props = defineProps<{ idForm: number | null }>()
const emit = defineEmits<{ saved: [] }>()

/** Draf pertanyaan di editor: opsi diedit sebagai teks satu baris per opsi. */
interface DrafPertanyaan {
  kunci: number
  id: string
  tipe: TipePertanyaan
  label: string
  wajib: boolean
  bantuan: string
  opsiTeks: string
  min: string
  maksAngka: string
  berkasTipe: string[]
  maksMb: string
}

const OPSI_TIPE: SelectOption[] = TIPE_PERTANYAAN.map((t) => ({ value: t.value, label: t.label }))
const OPSI_MB: SelectOption[] = ['1', '2', '5'].map((v) => ({ value: v, label: `${v} MB` }))

const form = reactive({ judul_form: '', tgl_mulai: '', tgl_akhir: '', order: '1', keterangan: '' })
const target = ref<TargetForm>({ unit_satker: [], pegawai: [], kecuali: [] })
const labelPegawai = ref<Record<string, string>>({})
const pertanyaan = ref<DrafPertanyaan[]>([])
const opsiUnitSatker = ref<SelectOption[]>([])
const terkunci = ref(false)
const memuat = ref(false)
const menyimpan = ref(false)
const galat = ref<Record<string, string>>({})
const formError = ref('')
let urut = 0

const isEdit = computed(() => props.idForm !== null)

function drafBaru(tipe: TipePertanyaan = 'teks'): DrafPertanyaan {
  return { kunci: ++urut, id: '', tipe, label: '', wajib: false, bantuan: '', opsiTeks: '', min: '', maksAngka: '', berkasTipe: ['pdf'], maksMb: '2' }
}

function dariPertanyaan(p: Pertanyaan): DrafPertanyaan {
  return {
    ...drafBaru(p.tipe),
    id: p.id,
    label: p.label,
    wajib: p.wajib,
    bantuan: p.bantuan ?? '',
    opsiTeks: (p.opsi ?? []).join('\n'),
    min: p.min === undefined ? '' : String(p.min),
    maksAngka: p.maks_angka === undefined ? '' : String(p.maks_angka),
    berkasTipe: p.berkas?.tipe ?? ['pdf'],
    maksMb: String(p.berkas?.maks_mb ?? 2),
  }
}

function kePertanyaan(d: DrafPertanyaan): Pertanyaan {
  const p: Pertanyaan = { id: d.id, tipe: d.tipe, label: d.label.trim(), wajib: d.wajib }
  if (d.bantuan.trim() !== '') p.bantuan = d.bantuan.trim()
  if (d.tipe === 'pilihan_tunggal' || d.tipe === 'pilihan_ganda') {
    p.opsi = d.opsiTeks.split('\n').map((o) => o.trim()).filter((o) => o !== '')
  }
  if (d.tipe === 'angka') {
    if (d.min.trim() !== '') p.min = Number(d.min)
    if (d.maksAngka.trim() !== '') p.maks_angka = Number(d.maksAngka)
  }
  if (d.tipe === 'berkas') p.berkas = { tipe: d.berkasTipe, maks_mb: Number(d.maksMb) }
  return p
}

async function cariPegawai(kata: string): Promise<{ value: string; label: string }[]> {
  const hasil = await pegawaiService.list({ page: 1, per_page: 10, search: kata })
  return hasil.items.map((p) => ({
    value: p.nip,
    label: `${p.nip} - ${[p.glr_awal, p.nama].filter(Boolean).join(' ')}${p.glr_akhir ? `, ${p.glr_akhir}` : ''}`,
  }))
}

watch(open, async (buka) => {
  if (!buka) return
  galat.value = {}
  formError.value = ''
  terkunci.value = false
  Object.assign(form, { judul_form: '', tgl_mulai: '', tgl_akhir: '', order: '1', keterangan: '' })
  target.value = { unit_satker: [], pegawai: [], kecuali: [] }
  labelPegawai.value = {}
  pertanyaan.value = [drafBaru()]
  memuat.value = true
  try {
    if (opsiUnitSatker.value.length === 0) opsiUnitSatker.value = await formDigitalService.opsiUnitSatker()
    if (props.idForm !== null) {
      const d = await formDigitalService.detail(props.idForm)
      Object.assign(form, { judul_form: d.judul_form, tgl_mulai: d.tgl_mulai, tgl_akhir: d.tgl_akhir, order: String(d.order), keterangan: d.keterangan ?? '' })
      target.value = { unit_satker: d.target.unit_satker, pegawai: d.target.pegawai, kecuali: d.target.kecuali }
      labelPegawai.value = d.target.label_pegawai ?? {}
      pertanyaan.value = d.form.pertanyaan.map(dariPertanyaan)
      terkunci.value = d.terkunci
    }
  } catch (e) {
    formError.value = isApiError(e) ? e.message : 'Gagal memuat form.'
  } finally {
    memuat.value = false
  }
})

function geser(i: number, arah: -1 | 1): void {
  const j = i + arah
  const daftar = [...pertanyaan.value]
  if (j < 0 || j >= daftar.length) return
  const a = daftar[i]
  const b = daftar[j]
  if (!a || !b) return
  daftar[i] = b
  daftar[j] = a
  pertanyaan.value = daftar
}

function hapus(i: number): void {
  pertanyaan.value = pertanyaan.value.filter((_, k) => k !== i)
}

function toggleBerkas(d: DrafPertanyaan, ext: string, pilih: boolean): void {
  d.berkasTipe = pilih ? [...new Set([...d.berkasTipe, ext])] : d.berkasTipe.filter((e) => e !== ext)
}

const err = (k: string): string | undefined => galat.value[k]

async function simpan(): Promise<void> {
  galat.value = {}
  formError.value = ''
  const input: FormInput = {
    judul_form: form.judul_form.trim(),
    tgl_mulai: form.tgl_mulai,
    tgl_akhir: form.tgl_akhir,
    order: Number(form.order) || 0,
    keterangan: form.keterangan.trim() === '' ? null : form.keterangan.trim(),
    form: pertanyaan.value.map(kePertanyaan),
    target: { unit_satker: target.value.unit_satker, pegawai: target.value.pegawai, kecuali: target.value.kecuali },
  }
  menyimpan.value = true
  try {
    if (props.idForm === null) await formDigitalService.buat(input)
    else await formDigitalService.ubah(props.idForm, input)
    open.value = false
    emit('saved')
  } catch (e) {
    if (isApiError(e) && e.errors) {
      const per: Record<string, string> = {}
      for (const [k, v] of Object.entries(e.errors)) per[k] = v[0] ?? e.message
      galat.value = per
      formError.value = e.status === 409 ? e.message : 'Periksa kembali isian yang ditandai.'
    } else {
      formError.value = isApiError(e) ? e.message : 'Gagal menyimpan form.'
    }
  } finally {
    menyimpan.value = false
  }
}
</script>

<template>
  <DialogRoot v-model:open="open">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-3xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="form-digital-editor"
      >
        <div class="mb-4 flex items-start justify-between">
          <div>
            <DialogTitle class="text-h5 font-semibold text-slate-900">{{ isEdit ? 'Ubah form digital' : 'Tambah form digital' }}</DialogTitle>
            <DialogDescription class="text-sm text-slate-500">
              Pegawai sasaran wajib mengisi form ini selama periode berlaku. Daftar penerima disimpan saat form disimpan.
            </DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" />
          </DialogClose>
        </div>

        <p v-if="memuat" class="py-6 text-center text-sm text-slate-500">Memuat...</p>
        <form v-else class="space-y-5" novalidate data-testid="form-digital-editor-form" @submit.prevent="simpan">
          <UiNotice v-if="formError" tone="danger">{{ formError }}</UiNotice>

          <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <FormField v-model="form.judul_form" name="judul_form" label="Judul form" required :error="err('judul_form')" />
            </div>
            <FormField v-model="form.tgl_mulai" name="tgl_mulai" label="Tanggal mulai" type="date" required :error="err('tgl_mulai')" />
            <FormField v-model="form.tgl_akhir" name="tgl_akhir" label="Tanggal akhir" type="date" required :error="err('tgl_akhir')" />
            <FormField v-model="form.order" name="order" label="Urutan" type="number" inputmode="numeric" :error="err('order')" />
            <div class="sm:col-span-2">
              <FormField v-model="form.keterangan" name="keterangan" label="Keterangan" type="textarea" :error="err('keterangan')" />
            </div>
          </div>

          <section aria-labelledby="fd-pertanyaan" class="space-y-3">
            <h3 id="fd-pertanyaan" class="text-body1 font-semibold text-slate-900">Pertanyaan</h3>
            <UiNotice v-if="terkunci" tone="warning" data-testid="form-digital-terkunci">
              Form ini sudah memiliki jawaban, sehingga pertanyaannya tidak dapat diubah. Judul, periode, urutan, keterangan, dan
              target tetap dapat diubah.
            </UiNotice>
            <p v-if="err('form.pertanyaan')" class="text-body2 text-danger">{{ err('form.pertanyaan') }}</p>
            <fieldset :disabled="terkunci" class="space-y-3">
              <div
                v-for="(d, i) in pertanyaan"
                :key="d.kunci"
                class="space-y-3 rounded-lg border border-slate-200 p-4"
                :data-testid="`form-digital-q-${i}`"
              >
                <div class="flex items-center justify-between gap-2">
                  <span class="text-body2 font-medium text-slate-600">Pertanyaan {{ i + 1 }}</span>
                  <div v-if="!terkunci" class="flex gap-1">
                    <UiButton appearance="ghost" size="sm" variant="secondary" :disabled="i === 0" :aria-label="`Naikkan urutan pertanyaan ${i + 1}`" data-q-aksi="naik" @click="geser(i, -1)">
                      <ArrowUp class="h-4 w-4" />
                    </UiButton>
                    <UiButton appearance="ghost" size="sm" variant="secondary" :disabled="i === pertanyaan.length - 1" :aria-label="`Turunkan urutan pertanyaan ${i + 1}`" data-q-aksi="turun" @click="geser(i, 1)">
                      <ArrowDown class="h-4 w-4" />
                    </UiButton>
                    <UiButton appearance="ghost" size="sm" variant="error" :disabled="pertanyaan.length === 1" :aria-label="`Hapus pertanyaan ${i + 1}`" data-q-aksi="hapus" @click="hapus(i)">
                      <Trash2 class="h-4 w-4" />
                    </UiButton>
                  </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                  <div class="sm:col-span-2">
                    <UiTextField v-model="d.label" label="Teks pertanyaan" required :disabled="terkunci" :state="err(`form.pertanyaan.${i}.label`) ? 'error' : 'default'" :help-text="err(`form.pertanyaan.${i}.label`)" />
                  </div>
                  <UiSelect v-model="d.tipe" label="Tipe" :options="OPSI_TIPE" :disabled="terkunci" />
                </div>
                <UiTextField v-model="d.bantuan" label="Petunjuk (opsional)" :disabled="terkunci" />
                <FormField
                  v-if="d.tipe === 'pilihan_tunggal' || d.tipe === 'pilihan_ganda'"
                  v-model="d.opsiTeks"
                  type="textarea"
                  label="Opsi jawaban (satu per baris)"
                  :disabled="terkunci"
                  :error="err(`form.pertanyaan.${i}.opsi`)"
                />
                <div v-if="d.tipe === 'angka'" class="grid gap-3 sm:grid-cols-2">
                  <UiTextField v-model="d.min" label="Nilai minimum (opsional)" type="number" :disabled="terkunci" />
                  <UiTextField
                    v-model="d.maksAngka"
                    label="Nilai maksimum (opsional)"
                    type="number"
                    :disabled="terkunci"
                    :state="err(`form.pertanyaan.${i}.maks_angka`) ? 'error' : 'default'"
                    :help-text="err(`form.pertanyaan.${i}.maks_angka`)"
                  />
                </div>
                <div v-if="d.tipe === 'berkas'" class="space-y-2">
                  <p class="text-body2 font-medium text-slate-700">Jenis berkas</p>
                  <div class="flex flex-wrap gap-3">
                    <UiCheckbox
                      v-for="ext in TIPE_BERKAS"
                      :key="ext"
                      :label="ext.toUpperCase()"
                      :model-value="d.berkasTipe.includes(ext)"
                      :disabled="terkunci"
                      @update:model-value="(v: boolean) => toggleBerkas(d, ext, v)"
                    />
                  </div>
                  <p v-if="err(`form.pertanyaan.${i}.berkas.tipe`)" class="text-body2 text-danger">{{ err(`form.pertanyaan.${i}.berkas.tipe`) }}</p>
                  <UiSelect v-model="d.maksMb" label="Ukuran maksimal" :options="OPSI_MB" :disabled="terkunci" class="w-40" />
                </div>
                <UiCheckbox v-model="d.wajib" label="Wajib diisi" :disabled="terkunci" />
              </div>
            </fieldset>
            <UiButton v-if="!terkunci" appearance="outline" size="sm" data-testid="form-digital-tambah-q" @click="pertanyaan = [...pertanyaan, drafBaru()]">
              <Plus class="h-4 w-4" /> Tambah pertanyaan
            </UiButton>
          </section>

          <section aria-labelledby="fd-target" class="space-y-2">
            <h3 id="fd-target" class="text-body1 font-semibold text-slate-900">Target</h3>
            <TargetPicker
              v-model="target"
              :opsi-unit-satker="opsiUnitSatker"
              :cari-pegawai="cariPegawai"
              :label-pegawai="labelPegawai"
              :invalid="!!err('target')"
            />
            <p v-if="err('target')" class="text-body2 text-danger">{{ err('target') }}</p>
          </section>

          <div class="flex justify-end gap-2 border-t border-slate-200 pt-4">
            <UiButton appearance="outline" variant="secondary" @click="open = false">Batal</UiButton>
            <UiButton type="submit" :loading="menyimpan" data-testid="form-digital-simpan">Simpan</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
