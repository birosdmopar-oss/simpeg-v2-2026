<script setup lang="ts">
/**
 * Tarik ulang e-Kinerja (H-10, MAKE-064; halaman Admin → Integrasi MAKE-078) — memanggil endpoint yang SUDAH ada
 * `POST api/v1/integrasi/bkn/tarik` `{jenis, tahun?, bulan?}`. Backend hanya MENGANTRE job (202, antrean `integrasi`),
 * jadi hasilnya muncul di log integrasi setelah worker antrean memprosesnya.
 *
 * - `periode`: tarik daftar periode e-Kinerja (tanpa tahun/bulan).
 * - `skp_periodik`: tarik SKP periodik satu bulan; bulan kosong = periode terpilih otomatis (sama dengan cron).
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import { UiButton, UiRadioGroup, UiTextField } from '@/shared/ui'

import type { JenisTarikEkinerja, TarikEkinerjaHasil, TarikEkinerjaPayload } from '../integrasi.types'
import { logIntegrasiService } from '../services/logIntegrasi.service'

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ 'update:open': [open: boolean]; queued: [hasil: TarikEkinerjaHasil] }>()

const JENIS_OPTIONS = [
  { value: 'skp_periodik', label: 'SKP periodik (satu bulan)', testid: 'integrasi-tarik-jenis-skp_periodik' },
  { value: 'periode', label: 'Daftar periode penilaian', testid: 'integrasi-tarik-jenis-periode' },
]

const jenis = ref<JenisTarikEkinerja>('skp_periodik')
/** `YYYY-MM` dari UiMonthPicker; kosong = periode terpilih otomatis. */
const bulan = ref('')
const loading = ref(false)
const formError = ref('')
const bulanError = ref('')

watch(
  () => props.open,
  (open) => {
    if (open) {
      jenis.value = 'skp_periodik'
      bulan.value = ''
      formError.value = ''
      bulanError.value = ''
    }
  },
)

const pakaiBulan = computed(() => jenis.value === 'skp_periodik')

function payload(): TarikEkinerjaPayload {
  if (!pakaiBulan.value || bulan.value === '') return { jenis: jenis.value }
  const [tahun, bln] = bulan.value.split('-')
  return { jenis: jenis.value, tahun: Number(tahun), bulan: Number(bln) }
}

async function kirim(): Promise<void> {
  formError.value = ''
  bulanError.value = ''

  if (pakaiBulan.value && bulan.value !== '' && !/^\d{4}-(0[1-9]|1[0-2])$/.test(bulan.value)) {
    bulanError.value = 'Pilih bulan yang valid.'
    return
  }

  loading.value = true
  try {
    const hasil = await logIntegrasiService.tarikEkinerja(payload())
    emit('queued', hasil)
    emit('update:open', false)
  } catch (err) {
    if (isApiError(err) && err.status === 422 && err.errors) {
      const e: Partial<Record<string, string[]>> = err.errors
      bulanError.value = e.tahun?.[0] ?? e.bulan?.[0] ?? ''
      formError.value = e.jenis?.[0] ?? (bulanError.value ? '' : err.message)
    } else {
      formError.value = isApiError(err) ? err.message : 'Gagal mengantre tarik ulang e-Kinerja.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="integrasi-tarik-dialog"
      >
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 font-semibold text-slate-900">Tarik Ulang e-Kinerja</DialogTitle>
            <DialogDescription class="text-sm text-slate-500">
              Permintaan dimasukkan ke antrean dan diproses di latar belakang. Hasilnya tampil di log integrasi.
            </DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" />
          </DialogClose>
        </div>

        <form class="flex flex-col gap-4" novalidate data-testid="integrasi-tarik-form" @submit.prevent="kirim">
          <p v-if="formError" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</p>

          <UiRadioGroup
            :model-value="jenis"
            :options="JENIS_OPTIONS"
            name="jenis"
            label="Jenis tarik"
            required
            data-testid="integrasi-tarik-jenis"
            @update:model-value="jenis = $event as JenisTarikEkinerja"
          />

          <UiTextField
            v-if="pakaiBulan"
            v-model="bulan"
            type="month"
            label="Bulan"
            placeholder="bb/tttt"
            :state="bulanError ? 'error' : 'default'"
            :help-text="bulanError || 'Kosongkan untuk memakai periode terpilih otomatis (sama dengan jadwal harian).'"
            data-testid="integrasi-tarik-bulan"
          />

          <div class="flex justify-end gap-2">
            <DialogClose as-child>
              <UiButton variant="secondary" appearance="outline" :disabled="loading">Batal</UiButton>
            </DialogClose>
            <UiButton type="submit" :loading="loading" data-testid="integrasi-tarik-kirim">Antrekan</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
