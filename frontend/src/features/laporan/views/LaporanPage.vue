<script setup lang="ts">
/**
 * Laporan statistik kepegawaian F-03 (MAKE-036; mockup §4.1.3 Gambar 15/16). Satu halaman untuk 7 tipe
 * (`/laporan/:tipe`): pemilih lingkup (dari `statistik/lingkup`), diagram batang, dua donat, tabel dengan bilah progres
 * + baris Subtotal (dari server), ekspor Excel (`statistik/{jenis}/xlsx`). Akses: role 1, 3, 4, 5, 8; role 3 hanya
 * lingkup miliknya (pilihan lain tidak ditawarkan; backend tetap 403). Data dari API, tidak ada data contoh.
 */
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'

import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiBarChart, UiCard, UiDonutChart, UiExportMenu, UiNotice, UiProgressBar, UiSearchInput, UiSelect } from '@/shared/ui'
import type { ExportFormat, SelectOption } from '@/shared/ui'

import { describeApiError } from '@/features/kepegawaian/services/apiErrors'
import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'

import { isLaporanTipe, laporanService } from '../laporan.service'
import { DONAT_PALET, LAPORAN_LABEL, PROGRES_TONE, SERI_PALET, type LaporanTipe, type LingkupPilihan, type LingkupTersedia, type StatistikData } from '../types'

const route = useRoute()
const data = ref<StatistikData | null>(null)
const lingkupTersedia = ref<LingkupTersedia | null>(null)
const pilihan = ref<LingkupPilihan>('')
const search = ref('')
const notice = ref<string | null>(null)
const failure = ref<string | null>(null)
const memuat = ref(false)
const mengekspor = ref(false)

const FORMATS: ExportFormat[] = [{ key: 'xlsx', label: 'Ekspor Excel' }]
const nf = new Intl.NumberFormat('id-ID')
const tipe = computed<LaporanTipe>(() => (isLaporanTipe(route.params.tipe) ? route.params.tipe : 'unit-kerja'))

/** Opsi pemilih lingkup: kementerian (bila boleh), tiap unit, lalu satker di bawahnya (indentasi). */
const lingkupOptions = computed<SelectOption[]>(() => {
  const l = lingkupTersedia.value
  if (!l) return []
  const opsi: SelectOption[] = []
  if (l.kementerian) opsi.push({ value: 'kementerian', label: `${l.kementerian.nama} (per unit)` })
  for (const u of l.unit) {
    if (u.pilih_unit !== false) opsi.push({ value: `unit:${u.id}`, label: u.nama })
    for (const s of u.satker) opsi.push({ value: `satker:${s.id}`, label: `— ${s.nama}` })
  }
  return opsi
})

async function muatLingkup(): Promise<void> {
  try {
    lingkupTersedia.value = await laporanService.lingkup()
  } catch (error) {
    failure.value = describeApiError(error).message
  }
}

async function load(): Promise<void> {
  memuat.value = true
  failure.value = null
  try {
    data.value = await laporanService.statistik(tipe.value, pilihan.value)
  } catch (error) {
    data.value = null
    failure.value = describeApiError(error).message
  } finally {
    memuat.value = false
  }
}

void muatLingkup()
watch(tipe, () => { search.value = ''; void load() }, { immediate: true })
watch(pilihan, () => void load())

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  return (data.value?.baris ?? []).filter((b) => b.label.toLowerCase().includes(q))
})
const mencari = computed(() => search.value.trim() !== '')
/** Subtotal mengikuti pencarian (CR-064): dijumlah dari baris yang tampil; tanpa pencarian = angka server. */
const subtotalTampil = computed(() => {
  const d = data.value
  if (!d) return { nilai: [] as number[], total: 0 }
  if (!mencari.value) return { nilai: d.subtotal, total: d.total }
  return {
    nilai: d.seri.map((_, i) => filtered.value.reduce((jumlah, b) => jumlah + (b.nilai[i] ?? 0), 0)),
    total: filtered.value.reduce((jumlah, b) => jumlah + b.total, 0),
  }
})
const kosong = computed(() => data.value !== null && data.value.baris.length === 0)
const seri = computed(() => data.value?.seri ?? [])
const warna = (i: number): string => SERI_PALET[i % SERI_PALET.length] ?? '#1e3a8a'
const tone = (i: number) => PROGRES_TONE[i % PROGRES_TONE.length] ?? 'primary'
const maksSeri = computed(() => seri.value.map((_, i) => Math.max(1, ...(data.value?.baris ?? []).map((b) => b.nilai[i] ?? 0))))
const barSeries = computed(() => seri.value.map((s, i) => ({ label: s.label, color: warna(i), values: (data.value?.baris ?? []).map((b) => b.nilai[i] ?? 0) })))
const donutBaris = computed(() => (data.value?.baris ?? []).map((b, i) => ({ label: b.label, value: b.total, color: DONAT_PALET[i % DONAT_PALET.length] ?? '#1e3a8a' })))
const donutSeri = computed(() => seri.value.map((s, i) => ({ label: s.label, value: data.value?.subtotal[i] ?? 0, color: warna(i) })))
const judul = computed(() => data.value?.judul ?? `Laporan ${LAPORAN_LABEL[tipe.value]}`)
const crumbs = computed(() => [{ label: 'Home', to: { name: 'home' } }, { label: 'Laporan' }, { label: judul.value }])
const periode = computed(() => (data.value ? new Date(`${data.value.periode}T00:00:00`).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : ''))

async function onExport(format: ExportFormat['key']): Promise<void> {
  if (format !== 'xlsx' || mengekspor.value) return
  mengekspor.value = true
  try {
    simpanBerkas(await laporanService.xlsx(tipe.value, pilihan.value))
  } catch (error) {
    notice.value = `Gagal mengekspor: ${describeApiError(error).message}`
  } finally {
    mengekspor.value = false
  }
}
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <UiNotice v-if="notice" tone="info" dismissible class="mb-4" @dismiss="notice = null">{{ notice }}</UiNotice>
    <UiNotice v-if="failure" tone="danger" class="mb-4" data-testid="laporan-failure">{{ failure }}</UiNotice>

    <div class="page-fill flex flex-col gap-5" data-testid="laporan-page" :aria-busy="memuat">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 class="text-h2 font-semibold text-slate-900">{{ judul }}</h1>
          <p v-if="data" class="text-body2 text-slate-500">{{ data.lingkup.nama }} · Data per {{ periode }}</p>
        </div>
        <div data-testid="laporan-export">
          <UiExportMenu :formats="FORMATS" :disabled="!data || mengekspor" @select="onExport" />
        </div>
      </div>

      <UiCard title="Jumlah Pegawai" :subtitle="data ? `Per ${data.label_kategori}` : ''">
        <div v-if="lingkupOptions.length" class="mb-4 max-w-md" data-testid="laporan-lingkup">
          <UiSelect v-model="pilihan" label="Lingkup" :options="lingkupOptions" placeholder="Lingkup bawaan akun" clearable />
        </div>
        <p v-if="kosong" class="py-10 text-center text-slate-500" data-testid="laporan-kosong">Tidak ada data pada lingkup ini</p>
        <UiBarChart v-else-if="data" :categories="data.baris.map((b) => b.label)" :series="barSeries" :caption="judul" />
        <p v-else-if="!failure" class="py-10 text-center text-slate-500">Memuat…</p>
      </UiCard>

      <div v-if="data && !kosong" class="grid gap-5 lg:grid-cols-2">
        <UiCard :title="`Komposisi per ${data.label_kategori}`" subtitle="Total pegawai per baris">
          <UiDonutChart :slices="donutBaris" legend="bottom" :caption="`Komposisi per ${data.label_kategori}`" />
        </UiCard>
        <UiCard title="Komposisi per Seri" subtitle="Subtotal tiap seri">
          <UiDonutChart :slices="donutSeri" legend="bottom" caption="Komposisi per seri" />
        </UiCard>
      </div>

      <UiCard v-if="data && !kosong" :title="`Tabel ${judul}`" flush fill>
        <div class="flex min-h-0 flex-1 flex-col gap-4 pb-5">
          <div class="w-64 max-w-full px-5"><UiSearchInput v-model="search" placeholder="Cari baris" label="Cari baris laporan" /></div>
          <div class="table-fill scrollbar-slim overflow-x-auto">
            <table class="w-full min-w-[40rem] border-collapse text-left text-body1" data-testid="laporan-table">
              <thead>
                <tr class="border-y border-slate-200 text-body2 font-semibold text-slate-900">
                  <th scope="col" class="px-5 py-3">{{ data.label_kategori }}</th>
                  <th v-for="s in seri" :key="s.key" scope="col" class="px-5 py-3">{{ s.label }}</th>
                  <th scope="col" class="px-5 py-3 text-right">Total</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-200">
                <tr v-for="b in filtered" :key="b.kategori_id" :data-testid="`laporan-row-${b.kategori_id}`">
                  <td class="px-5 py-3 text-slate-800">{{ b.label }}</td>
                  <td v-for="(s, i) in seri" :key="s.key" class="px-5 py-3">
                    <div class="flex items-center gap-3">
                      <span class="w-12 tabular-nums">{{ nf.format(b.nilai[i] ?? 0) }}</span>
                      <UiProgressBar class="w-28" :value="b.nilai[i] ?? 0" :max="maksSeri[i] ?? 1" :tone="tone(i)" :label="`${s.label} ${b.label}`" />
                    </div>
                  </td>
                  <td class="px-5 py-3 text-right font-medium tabular-nums">{{ nf.format(b.total) }}</td>
                </tr>
                <tr v-if="filtered.length === 0">
                  <td :colspan="seri.length + 2" class="px-5 py-10 text-center text-slate-500" data-testid="laporan-empty">
                    Tidak ada baris yang cocok
                  </td>
                </tr>
                <tr class="bg-brand-primary/5 font-semibold text-slate-900" data-testid="laporan-subtotal">
                  <td class="px-5 py-3">{{ mencari ? `Subtotal hasil pencarian (${filtered.length} baris)` : 'Subtotal' }}</td>
                  <td v-for="(s, i) in seri" :key="s.key" class="px-5 py-3 tabular-nums">{{ nf.format(subtotalTampil.nilai[i] ?? 0) }}</td>
                  <td class="px-5 py-3 text-right tabular-nums">{{ nf.format(subtotalTampil.total) }}</td>
                </tr>
                <tr v-if="mencari" class="text-body2 text-slate-600" data-testid="laporan-total-keseluruhan">
                  <td class="px-5 py-3">Total keseluruhan lingkup</td>
                  <td v-for="(s, i) in seri" :key="s.key" class="px-5 py-3 tabular-nums">{{ nf.format(data.subtotal[i] ?? 0) }}</td>
                  <td class="px-5 py-3 text-right tabular-nums">{{ nf.format(data.total) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </UiCard>
    </div>
  </RedesignShell>
</template>
