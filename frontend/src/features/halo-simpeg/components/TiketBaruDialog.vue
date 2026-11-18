<script setup lang="ts">
/**
 * Form tiket baru (E-03, B2): kategori aktif (`GET kategori/opsi`), subjek ≤ 250, pesan ≤ 5.000, lampiran opsional
 * (9 ekstensi, ≤ 5 MB). Hanya dipasang untuk role 2/6/7 (pemanggil). Validasi klien = batas & teks BE (`tiketBaruFormSchema`);
 * 422 BE per kolom (`id_question_category`, `subject`, `msg`, `arsip`) dan 429 throttle ditampilkan dari amplop.
 * Pesan galat yang sudah tampil di kolom tidak diulang di notice atas form (pola CR-134 T-02).
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, reactive, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'
import { FORM_ACTIONS_CLASS, FORM_ALERT_CLASS, FORM_GRID_CLASS, FORM_WIDE_CLASS } from '@/shared/components/formLayout'
import { UiButton, UiFileField } from '@/shared/ui'
import { galatPerKolom } from '@/shared/utils/galatKolom'

import { BATAS_TIKET, EKSTENSI_LAMPIRAN } from '../constants'
import { tiketBaruFormSchema } from '../schemas'
import { kategoriHaloService } from '../services/master.service'
import { buatTiket } from '../stores/tiket'
import type { KategoriOpsi, TiketDetail } from '../types'
import { TEKS_SUKSES_TIKET_BARU } from '../constants'

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; dibuat: [tiket: TiketDetail, pesan: string] }>()

const form = reactive({ id_kategori: '', subjek: '', pesan: '' })
const arsip = ref<File | null>(null)
const kunciBerkas = ref(0)
const opsi = ref<KategoriOpsi[]>([])
const memuatOpsi = ref(false)
const galatOpsi = ref('')
const submitting = ref(false)
const submitted = ref(false)
/** Review B2 Q3: sesudah tiket berhasil dibuat, fokus dipindah ke judul thread baru — FocusScope Radix tidak boleh mengembalikan
 * fokus ke tombol "Tambah Pertanyaan" saat dialog ditutup. */
const berhasil = ref(false)
const formError = ref('')
const serverErrors = ref<Record<string, string>>({})

const opsiKategori = computed(() => opsi.value.map((k) => ({ value: String(k.id), label: k.nama })))

async function muatOpsi(): Promise<void> {
  memuatOpsi.value = true
  galatOpsi.value = ''
  try {
    opsi.value = await kategoriHaloService.opsi()
  } catch (e) {
    galatOpsi.value = isApiError(e) ? e.message : 'Pilihan kategori tidak dapat dimuat.'
  } finally {
    memuatOpsi.value = false
  }
}

watch(
  () => props.open,
  (open) => {
    if (!open) return
    berhasil.value = false
    form.id_kategori = ''
    form.subjek = ''
    form.pesan = ''
    arsip.value = null
    kunciBerkas.value += 1
    submitted.value = false
    formError.value = ''
    serverErrors.value = {}
    void muatOpsi()
  },
  { immediate: true },
)

const isian = computed(() => ({
  id_kategori: form.id_kategori === '' ? null : Number(form.id_kategori),
  subjek: form.subjek,
  pesan: form.pesan,
  arsip: arsip.value,
}))

const galatKlien = computed<Record<string, string>>(() => {
  const r = tiketBaruFormSchema.safeParse(isian.value)
  if (r.success) return {}
  const e: Record<string, string> = {}
  for (const i of r.error.issues) {
    const k = String(i.path[0] ?? '')
    if (!(k in e)) e[k] = i.message
  }
  return e
})

/** Nama kolom BE → field form. */
const PETA: Record<string, string> = { id_question_category: 'id_kategori', subject: 'subjek', msg: 'pesan', arsip: 'arsip' }

function err(f: string): string | undefined {
  return serverErrors.value[f] ?? (submitted.value ? galatKlien.value[f] : undefined)
}

function pilihBerkas(f: File | null): void {
  arsip.value = f
  delete serverErrors.value.arsip
}

async function onSubmit(): Promise<void> {
  submitted.value = true
  formError.value = ''
  serverErrors.value = {}
  if (Object.keys(galatKlien.value).length > 0 || submitting.value) return
  submitting.value = true
  try {
    const hasil = await buatTiket(tiketBaruFormSchema.parse(isian.value))
    berhasil.value = hasil !== null
    // CR-142 T-B1: notice sukses = `message` BE (CR-143), cadangan teks legacy bila kosong.
    if (hasil) emit('dibuat', hasil.tiket, hasil.pesan ?? TEKS_SUKSES_TIKET_BARU)
    emit('update:open', false)
  } catch (e) {
    serverErrors.value = galatPerKolom(e, (k) => PETA[k] ?? k)
    const pesan = isApiError(e) ? e.message : 'Terjadi kesalahan. Silakan coba lagi.'
    formError.value = Object.values(serverErrors.value).includes(pesan) ? '' : pesan
    // Review B2 P5 (b): kategori dinonaktifkan/dihapus di sela → kosongkan pilihan dan muat ulang opsi (galat tetap tampil).
    if (serverErrors.value.id_kategori) {
      form.id_kategori = ''
      void muatOpsi()
    }
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
        data-testid="halo-tiket-baru-dialog"
        @close-auto-focus="(e: Event) => berhasil && e.preventDefault()"
      >
        <div class="mb-4 flex items-start justify-between">
          <div>
            <DialogTitle class="text-h5 font-semibold text-slate-900">Tambah Pertanyaan</DialogTitle>
            <DialogDescription class="text-sm text-slate-500">Pertanyaan dikirim ke petugas Halo Simpeg sesuai kategori.</DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" />
          </DialogClose>
        </div>

        <form :class="FORM_GRID_CLASS" novalidate data-testid="halo-tiket-baru-form" @submit.prevent="onSubmit">
          <p v-if="formError" :class="FORM_ALERT_CLASS" role="alert" data-testid="halo-tiket-baru-galat">{{ formError }}</p>
          <p v-if="galatOpsi" :class="FORM_ALERT_CLASS" role="alert">{{ galatOpsi }}</p>
          <FormField
            v-model="form.id_kategori"
            name="id_kategori"
            label="Kategori"
            type="select"
            required
            :placeholder="memuatOpsi ? 'Memuat...' : 'Pilih kategori'"
            :options="opsiKategori"
            :error="err('id_kategori')"
            :class="FORM_WIDE_CLASS"
          />
          <FormField v-model="form.subjek" name="subjek" label="Subjek" required :error="err('subjek')" :class="FORM_WIDE_CLASS" />
          <FormField
            v-model="form.pesan"
            name="pesan"
            label="Pesan"
            type="textarea"
            required
            :hint="`Maksimal ${BATAS_TIKET.pesan.toLocaleString('id-ID')} karakter.`"
            :error="err('pesan')"
            :class="FORM_WIDE_CLASS"
          />
          <div :class="FORM_WIDE_CLASS">
            <UiFileField
              :key="kunciBerkas"
              label="Lampiran (opsional)"
              :allowed="[...EKSTENSI_LAMPIRAN]"
              :max-mb="BATAS_TIKET.lampiranMb"
              :disabled="submitting"
              @change="pilihBerkas"
            />
            <p v-if="err('arsip')" class="mt-1 text-caption text-danger" role="alert" data-testid="halo-tiket-baru-galat-arsip">{{ err('arsip') }}</p>
          </div>
          <div :class="FORM_ACTIONS_CLASS">
            <DialogClose as-child>
              <UiButton variant="secondary" appearance="soft">Batal</UiButton>
            </DialogClose>
            <UiButton type="submit" :disabled="submitting" data-testid="halo-tiket-baru-kirim">{{ submitting ? 'Mengirim...' : 'Kirim' }}</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
