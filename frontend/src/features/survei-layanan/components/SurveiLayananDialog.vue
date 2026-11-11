<script setup lang="ts">
/**
 * Survei Kepuasan Layanan setelah login (F-11, MAKE-084): satu butir per layanan yang sudah disetujui tetapi belum dinilai,
 * ditampilkan berurutan [K `views/template/modal/survey_kepuasan.php`]. TIDAK BISA DITUTUP [K] (`backdrop:'static'`, satu-satunya
 * tombol "Simpan"): Esc/klik luar ditahan, tanpa X.
 * - Tingkat kepuasan 1–5 wajib (radio group custom, dapat dioperasikan keyboard) dengan label legacy; kritik & saran wajib
 *   di form seperti legacy (`required`), server tetap menerima kosong.
 * - 201/200 (kirim ulang = sukses, Q7-21) → butir berikutnya; butir terakhir → `selesai`. Galat → pesan, tetap di butir itu.
 */
import { DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, ref } from 'vue'

import { isApiError } from '@/lib/axios'
import FormField from '@/shared/components/FormField.vue'
import UiButton from '@/shared/ui/UiButton.vue'
import UiNotice from '@/shared/ui/UiNotice.vue'
import UiRadioGroup from '@/shared/ui/UiRadioGroup.vue'

import { surveiLayananService } from '../services/surveiLayanan.service'
import { LABEL_RATING, type TagihanSkl } from '../types'

const props = defineProps<{ tagihan: TagihanSkl[] }>()
const emit = defineEmits<{ selesai: [] }>()

const indeks = ref(0)
const rate = ref('')
const kritik = ref('')
const galat = ref<{ rate?: string; kritik?: string }>({})
const umum = ref('')
const sibuk = ref(false)

const butir = computed<TagihanSkl | undefined>(() => props.tagihan[indeks.value])
const OPSI = [5, 4, 3, 2, 1].map((n) => ({ value: String(n), label: `${n} — ${LABEL_RATING[n]}`, testid: `skl-rate-${n}` }))

async function simpan(): Promise<void> {
  const b = butir.value
  if (!b) return
  const lokal: { rate?: string; kritik?: string } = {}
  if (rate.value === '') lokal.rate = 'Pilih tingkat kepuasan.'
  if (kritik.value.trim() === '') lokal.kritik = 'Kritik & saran wajib diisi.'
  galat.value = lokal
  umum.value = ''
  if (lokal.rate || lokal.kritik) return

  sibuk.value = true
  try {
    await surveiLayananService.kirim({ jenis_rwy: b.jenis_rwy, id_rwy: b.id_rwy, rate: Number(rate.value), kritik_saran: kritik.value.trim() })
    rate.value = ''
    kritik.value = ''
    if (indeks.value >= props.tagihan.length - 1) emit('selesai')
    else indeks.value += 1
  } catch (e) {
    umum.value = isApiError(e) ? e.message : 'Gagal menyimpan penilaian. Coba lagi.'
  } finally {
    sibuk.value = false
  }
}

function tahan(e: Event): void {
  e.preventDefault()
}
</script>

<template>
  <DialogRoot :open="!!butir">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        v-if="butir"
        class="fixed left-1/2 top-1/2 z-50 w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 rounded-2xl bg-white shadow-panel"
        data-testid="skl-dialog"
        @escape-key-down="tahan"
        @pointer-down-outside="tahan"
        @interact-outside="tahan"
      >
        <div class="border-b border-slate-200 px-5 py-4">
          <DialogTitle class="text-h6 font-semibold text-slate-900">Survei Kepuasan Layanan Kepegawaian</DialogTitle>
          <p v-if="tagihan.length > 1" class="text-body2 text-slate-500" aria-live="polite">{{ indeks + 1 }} dari {{ tagihan.length }} layanan</p>
        </div>
        <form class="space-y-4 px-5 py-4" novalidate @submit.prevent="simpan">
          <DialogDescription class="text-body1 text-slate-700" data-testid="skl-info">{{ butir.info }}</DialogDescription>
          <UiNotice v-if="umum" tone="danger">{{ umum }}</UiNotice>
          <UiRadioGroup
            v-model="rate"
            :options="OPSI"
            label="Tingkat Kepuasan"
            required
            :name="`skl-${butir.jenis_rwy}-${butir.id_rwy}`"
            :state="galat.rate ? 'error' : 'default'"
            :help-text="galat.rate"
            direction="column"
          />
          <FormField v-model="kritik" type="textarea" label="Kritik & Saran" required name="kritik_saran" :error="galat.kritik" />
        </form>
        <div class="flex justify-end border-t border-slate-200 px-5 py-3">
          <UiButton :loading="sibuk" data-testid="skl-simpan" @click="simpan">Simpan</UiButton>
        </div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
