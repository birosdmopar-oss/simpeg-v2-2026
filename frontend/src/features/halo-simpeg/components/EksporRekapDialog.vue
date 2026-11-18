<script setup lang="ts">
/**
 * Dialog ekspor rekap petugas Halo Simpeg (B4, MAKE-074 FE; E-09, AC-E08.14). Dibuka dari tab Antrean bila `bisa_ekspor`.
 * - Dua `UiTextField type="date"` (kalender lokal, nilai `YYYY-MM-DD`, tanpa `toISOString()`): maksimum = hari ini WIB [K
 *   legacy `endDate: '0'`]; urutan `ds` ≤ `de` dijaga silang oleh picker [K] (min/max). Tanggal ketikan di luar batas ditolak
 *   picker bersama (`UiDatePicker`: nilai dikosongkan + "Tanggal di luar rentang yang diizinkan." — pesan yang dilihat pengguna),
 *   jadi tidak terkirim.
 * - Validasi klien: kolom kosong → "Tanggal awal dan akhir wajib diisi." (teks BE §7.3a [V2]). Cabang `ds > de` hanya pengaman
 *   (tak terjangkau lewat picker, review B4 T4); teks BE "Tanggal awal tidak boleh melebihi tanggal akhir." tetap tampil di kolom
 *   bila 422 datang dari server.
 * - Hasil: kosong (200 JSON) → `UiNotice` pesan BE tanpa unduhan; berkas → `simpanBerkas` + dialog tertutup; 403 `bukan_petugas`
 *   → pesan BE + `petugas/saya` disegarkan; 422 per kolom → galat kolom; 422 `ekspor_terlalu_besar` → pesan BE.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, reactive, ref, watch } from 'vue'

import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import { isApiError } from '@/lib/axios'
import { FORM_ACTIONS_CLASS, FORM_GRID_CLASS } from '@/shared/components/formLayout'
import { UiButton, UiNotice, UiTextField } from '@/shared/ui'
import { galatPerKolom } from '@/shared/utils/galatKolom'

import { hariIniWib, TEKS_EKSPOR } from '../constants'
import { kodeGalat } from '../services/master.service'
import { tiketHaloService } from '../services/tiket.service'
import { muatPetugasSaya } from '../stores/haloSimpeg'

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; berhasil: [nama: string] }>()

const form = reactive({ ds: '', de: '' })
const mengunduh = ref(false)
const submitted = ref(false)
const kosong = ref('')
const galatUmum = ref('')
const galatServer = ref<Record<string, string>>({})
const maks = ref(hariIniWib())
/**
 * Review B4 T2: generasi naik tiap `open` berubah. Respons ekspor dari generasi lama (dialog sudah ditutup lewat Batal/X/Esc atau
 * dibuka ulang) diabaikan: tanpa unduhan, tanpa pesan. Penutupan tidak dikunci (Batal = batal).
 */
let generasi = 0

watch(
  () => props.open,
  (open) => {
    generasi += 1
    if (!open) return
    mengunduh.value = false
    form.ds = ''
    form.de = ''
    submitted.value = false
    kosong.value = ''
    galatUmum.value = ''
    galatServer.value = {}
    maks.value = hariIniWib()
  },
  { immediate: true },
)

// Batas silang antarpicker [K]: ds ≤ de ≤ hari ini.
const maksDs = computed(() => (form.de && form.de < maks.value ? form.de : maks.value))
const minDe = computed(() => form.ds || undefined)

const galatKlien = computed<Record<string, string>>(() => {
  if (!form.ds || !form.de) return { ...(form.ds ? {} : { ds: TEKS_EKSPOR.wajib }), ...(form.de ? {} : { de: TEKS_EKSPOR.wajib }) }
  if (form.ds > form.de) return { ds: TEKS_EKSPOR.urutan }
  return {}
})
const err = (f: 'ds' | 'de'): string | undefined => galatServer.value[f] ?? (submitted.value ? galatKlien.value[f] : undefined)

function ubah(f: 'ds' | 'de', v: string): void {
  form[f] = v
  delete galatServer.value[f]
  kosong.value = ''
  galatUmum.value = ''
}

async function onSubmit(): Promise<void> {
  submitted.value = true
  kosong.value = ''
  galatUmum.value = ''
  galatServer.value = {}
  if (Object.keys(galatKlien.value).length > 0 || mengunduh.value) return
  const gen = generasi
  mengunduh.value = true
  try {
    const hasil = await tiketHaloService.ekspor(form.ds, form.de)
    if (gen !== generasi) return
    if (hasil.jenis === 'kosong') {
      kosong.value = hasil.pesan
      return
    }
    simpanBerkas({ blob: hasil.blob, nama: hasil.nama })
    emit('berhasil', hasil.nama)
    emit('update:open', false)
  } catch (e) {
    if (gen !== generasi) return
    galatServer.value = galatPerKolom(e)
    const pesan = isApiError(e) ? e.message : 'Ekspor gagal. Silakan coba lagi.'
    galatUmum.value = Object.values(galatServer.value).includes(pesan) ? '' : pesan
    if (kodeGalat(e) === 'bukan_petugas') void muatPetugasSaya(true)
  } finally {
    if (gen === generasi) mengunduh.value = false
  }
}
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="halo-ekspor-dialog"
      >
        <div class="mb-4 flex items-start justify-between">
          <div>
            <DialogTitle class="text-h5 font-semibold text-slate-900">Ekspor Rekap</DialogTitle>
            <DialogDescription class="text-sm text-slate-500">Rekap pertanyaan dan jawaban Halo Simpeg per periode (Excel).</DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" />
          </DialogClose>
        </div>

        <form :class="FORM_GRID_CLASS" novalidate data-testid="halo-ekspor-form" @submit.prevent="onSubmit">
          <UiTextField
            :model-value="form.ds"
            name="ds"
            label="Tanggal Awal"
            type="date"
            required
            :max="maksDs"
            :state="err('ds') ? 'error' : 'default'"
            :help-text="err('ds')"
            @update:model-value="ubah('ds', $event)"
          />
          <UiTextField
            :model-value="form.de"
            name="de"
            label="Tanggal Akhir"
            type="date"
            required
            :min="minDe"
            :max="maks"
            :state="err('de') ? 'error' : 'default'"
            :help-text="err('de')"
            @update:model-value="ubah('de', $event)"
          />
          <UiNotice v-if="kosong" tone="info" class="sm:col-span-2" data-testid="halo-ekspor-kosong">{{ kosong }}</UiNotice>
          <!-- Review B4 T5: UiNotice danger sudah role="alert" (tanpa alert bersarang). -->
          <UiNotice v-if="galatUmum" tone="danger" class="sm:col-span-2" data-testid="halo-ekspor-galat">{{ galatUmum }}</UiNotice>
          <div :class="FORM_ACTIONS_CLASS">
            <DialogClose as-child>
              <UiButton variant="secondary" appearance="soft">Batal</UiButton>
            </DialogClose>
            <UiButton type="submit" :disabled="mengunduh" data-testid="halo-ekspor-kirim">{{ mengunduh ? 'Mengekspor...' : 'Ekspor Excel' }}</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
