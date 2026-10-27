<script setup lang="ts">
/**
 * Slip Gaji pegawai (D-11, PRD Fase 5A §7.8.1; role 2, 6, 7). Pilih periode dari `GET slip-gaji/saya` (tanpa nominal)
 * lalu empat keadaan legacy [K `list.php`]: tidak ada periode / data belum tersedia / tersedia belum dibuka (tombol
 * "Lihat Slip Gaji" → modal konfirmasi sekali buka) / sudah dibuka pada kunjungan berikutnya (waktu dibuka WIB, tanpa
 * nominal & tanpa Cetak PDF [K `cfa66c4`]). Rincian + "Cetak PDF" hanya pada sesi buka yang sama; nominal tidak disimpan
 * di storage browser. Gerbang rilis periode, status dibuka, dan isi slip seluruhnya dari server. Tata letak acuan PNG
 * Figma "UI Slip Gaji".
 */
import { CalendarX2, FileSearch, FileText, Lock, Printer } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiButton, UiNotice, UiSelect } from '@/shared/ui'
import type { SelectOption } from '@/shared/ui'

import { describeApiError } from '@/features/kepegawaian/services/apiErrors'
import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'

import BukaSlipDialog from '../components/BukaSlipDialog.vue'
import SlipGajiKartu from '../components/SlipGajiKartu.vue'
import { slipGajiService } from '../services/slipGaji.service'
import type { PeriodeSlip, SlipTerbuka } from '../types'

const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Presensi' }, { label: 'Slip Gaji' }]

const items = ref<PeriodeSlip[]>([])
const dipilih = ref<string>('')
const terbuka = ref<SlipTerbuka | null>(null)
const memuat = ref(true)
const failure = ref<string | null>(null)
const dialog = ref(false)
const membuka = ref(false)
const galatBuka = ref('')
const mencetak = ref(false)
const galatCetak = ref<string | null>(null)

const options = computed<SelectOption[]>(() => items.value.map((p) => ({ value: p.periode, label: p.label })))
const periode = computed(() => items.value.find((p) => p.periode === dipilih.value) ?? null)
const slip = computed(() => (terbuka.value && terbuka.value.periode === dipilih.value ? terbuka.value : null))

type Keadaan = 'memuat' | 'kosong' | 'belum-tersedia' | 'terbuka' | 'sudah-dibuka' | 'tersedia'
const keadaan = computed<Keadaan>(() => {
  if (memuat.value) return 'memuat'
  if (!periode.value) return 'kosong'
  if (slip.value) return 'terbuka'
  if (!periode.value.ada_data) return 'belum-tersedia'
  return periode.value.dibuka ? 'sudah-dibuka' : 'tersedia'
})

/** Pesan galat siap tampil: 404/422 memakai pesan server (mis. "Slip gaji periode … belum tersedia."), lainnya baku. */
function pesanGalat(error: unknown): string {
  if (isApiError(error) && (error.status === 404 || error.status === 422) && error.message) return error.message
  return describeApiError(error).message
}

async function muat(pilih?: string): Promise<void> {
  memuat.value = true
  failure.value = null
  try {
    const daftar = await slipGajiService.daftar()
    items.value = daftar.items
    const ada = (k: string | null | undefined): k is string => !!k && daftar.items.some((p) => p.periode === k)
    dipilih.value = ada(pilih) ? pilih : ada(daftar.periode_terbaru) ? daftar.periode_terbaru : (daftar.items[0]?.periode ?? '')
  } catch (error) {
    failure.value = pesanGalat(error)
  } finally {
    memuat.value = false
  }
}

async function buka(): Promise<void> {
  if (!periode.value) return
  membuka.value = true
  galatBuka.value = ''
  try {
    terbuka.value = await slipGajiService.buka(periode.value.periode)
    const p = periode.value
    p.dibuka = true
    p.viewed_at = terbuka.value.viewed_at
    p.viewed_at_wib = terbuka.value.viewed_at_wib
    dialog.value = false
  } catch (error) {
    if (isApiError(error) && error.status === 422) {
      // Mis. sudah dibuka di tab/perangkat lain atau periode belum dirilis: tutup modal, segarkan status dari server.
      dialog.value = false
      await muat(dipilih.value)
      failure.value = pesanGalat(error)
    } else {
      galatBuka.value = pesanGalat(error)
    }
  } finally {
    membuka.value = false
  }
}

async function cetak(): Promise<void> {
  if (!slip.value) return
  mencetak.value = true
  galatCetak.value = null
  try {
    simpanBerkas(await slipGajiService.pdf(slip.value.periode))
  } catch (error) {
    galatCetak.value = pesanGalat(error)
  } finally {
    mencetak.value = false
  }
}

watch(dipilih, () => {
  galatCetak.value = null
})

onMounted(() => muat())
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="space-y-5" data-testid="slip-gaji-page" :aria-busy="memuat || membuka">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-h2 font-semibold text-slate-900">Slip Gaji</h1>
          <p class="text-body2 text-slate-500">Lihat rincian penghasilan bersih Anda.</p>
        </div>
        <UiButton
          v-if="keadaan === 'terbuka'"
          variant="secondary"
          appearance="outline"
          :loading="mencetak"
          data-testid="slip-cetak-pdf"
          @click="cetak"
        >
          <Printer class="h-4 w-4" aria-hidden="true" /> Cetak PDF
        </UiButton>
      </div>

      <UiNotice tone="info">
        <strong>Informasi Slip Gaji.</strong> Data pada slip gaji berasal dari Biro Perencanaan Keuangan. Jika terdapat
        ketidaksesuaian data gaji, silahkan hubungi Biro Perencanaan Keuangan.
      </UiNotice>
      <UiNotice tone="warning">
        <strong>Perhatian.</strong> Slip gaji hanya bisa diakses 1x per bulan dan dapat diunduh dalam format PDF.
      </UiNotice>
      <UiNotice v-if="failure" tone="danger" data-testid="slip-failure">{{ failure }}</UiNotice>
      <UiNotice v-if="galatCetak" tone="danger" data-testid="slip-cetak-error">{{ galatCetak }}</UiNotice>

      <div v-if="options.length" class="max-w-xs" data-testid="slip-periode">
        <UiSelect v-model="dipilih" label="Bulan" :options="options" :disabled="membuka" />
      </div>

      <p v-if="keadaan === 'memuat'" class="py-10 text-center text-slate-500">Memuat…</p>

      <div v-else-if="keadaan === 'kosong' && !failure" class="flex flex-col items-center py-12 text-center" data-testid="slip-kosong">
        <CalendarX2 class="h-12 w-12 text-slate-300" aria-hidden="true" />
        <p class="mt-3 text-slate-500">Tidak ada periode slip gaji yang tersedia saat ini.</p>
      </div>

      <div v-else-if="keadaan === 'belum-tersedia'" class="flex flex-col items-center py-12 text-center" data-testid="slip-belum-tersedia">
        <span class="flex h-16 w-16 items-center justify-center rounded-full bg-warning-soft text-amber-700"><FileSearch class="h-8 w-8" aria-hidden="true" /></span>
        <h2 class="mt-4 text-h4 font-semibold text-slate-900">Data Belum Tersedia</h2>
        <p class="mt-2 max-w-sm text-body1 text-slate-500">
          Data slip gaji untuk periode <strong>{{ periode?.label }}</strong> belum tersedia. Silahkan pilih periode lain atau
          hubungi <strong>Admin SIMPEG</strong>.
        </p>
      </div>

      <div v-else-if="keadaan === 'sudah-dibuka'" class="flex flex-col items-center py-12 text-center" data-testid="slip-sudah-dibuka">
        <span class="flex h-16 w-16 items-center justify-center rounded-full bg-warning-soft text-amber-700"><Lock class="h-8 w-8" aria-hidden="true" /></span>
        <h2 class="mt-4 text-h4 font-semibold text-slate-900">Slip Gaji Sudah Dibuka</h2>
        <p class="mt-2 text-body1 text-slate-500">Slip gaji periode <strong>{{ periode?.label }}</strong> sudah pernah diakses pada</p>
        <p class="mt-3 rounded-full bg-slate-100 px-4 py-1.5 text-body1 font-semibold text-slate-800" data-testid="slip-waktu-dibuka">
          {{ periode?.viewed_at_wib }}
        </p>
        <p class="mt-3 max-w-sm text-body1 text-slate-500">
          Slip gaji hanya bisa dibuka 1 (satu) kali. Untuk membuka kembali, silahkan hubungi <strong>Admin SIMPEG</strong>.
        </p>
      </div>

      <div v-else-if="keadaan === 'tersedia'" class="flex flex-col items-center py-12 text-center" data-testid="slip-tersedia">
        <span class="flex h-16 w-16 items-center justify-center rounded-full bg-success-soft text-green-700"><FileText class="h-8 w-8" aria-hidden="true" /></span>
        <h2 class="mt-4 text-h4 font-semibold text-slate-900">Data Slip Gaji Tersedia</h2>
        <p class="mt-2 text-body1 text-slate-500">Slip gaji periode <strong>{{ periode?.label }}</strong> siap untuk diakses.</p>
        <p class="mt-2 font-semibold text-amber-800">Perhatian: Slip hanya dapat dibuka 1 kali.</p>
        <UiButton class="mt-5" data-testid="slip-lihat" @click="dialog = true">Lihat Slip Gaji</UiButton>
      </div>

      <template v-else-if="keadaan === 'terbuka' && slip">
        <SlipGajiKartu :slip="slip" />
        <UiNotice tone="info">Slip gaji ini bersifat otomatis dan dihasilkan oleh sistem.</UiNotice>
      </template>
    </div>

    <BukaSlipDialog v-model:open="dialog" :label="periode?.label ?? ''" :busy="membuka" :error="galatBuka" @confirm="buka" />
  </RedesignShell>
</template>
