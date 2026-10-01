<script setup lang="ts">
/**
 * Laporan statistik (§4.1.3, Gambar 15/16) — Fase 7 (perkiraan). Satu halaman untuk tiga tipe laporan (`/laporan/:tipe`):
 * diagram batang, dua donat, dan tabel dengan bilah progres + baris Subtotal. Export lewat popup (PDF / Excel / Cetak).
 * Akses: role 1, 3, 4, 5, 8. DATA CONTOH: angka dari laporan.mock.ts; Export belum tersambung ke backend.
 */
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'

import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiBarChart, UiCard, UiDonutChart, UiExportMenu, UiNotice, UiProgressBar, UiSearchInput, UiSelect } from '@/shared/ui'
import type { ExportFormat } from '@/shared/ui/UiExportMenu.vue'

import { DONAT_PALET } from '../laporan.mock'
import { isLaporanTipe, laporanService } from '../laporan.service'
import type { LaporanData } from '../types'

const route = useRoute()
const data = ref<LaporanData | null>(null)
const unit = ref('')
const search = ref('')
const notice = ref<string | null>(null)
const unitOptions = ref<Array<{ value: string; label: string }>>([])

const FORMATS: ExportFormat[] = [
  { key: 'pdf', label: 'Export PDF' },
  { key: 'xlsx', label: 'Export Excel' },
  { key: 'print', label: 'Cetak Dokumen' },
]
const nf = new Intl.NumberFormat('id-ID')

async function load(): Promise<void> {
  const tipe = isLaporanTipe(route.params.tipe) ? route.params.tipe : 'unit-kerja'
  data.value = await laporanService.get(tipe, unit.value || undefined)
  unitOptions.value = tipe === 'struktural' ? [] : await laporanService.unitOptions()
}
watch(() => route.params.tipe, () => { unit.value = ''; search.value = ''; void load() }, { immediate: true })
watch(unit, () => void load())

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  return (data.value?.baris ?? []).filter((b) => b.label.toLowerCase().includes(q))
})
const totalOf = (values: number[]): number => values.reduce((a, b) => a + b, 0)
const seriesTotals = computed(() => (data.value?.series ?? []).map((_, i) => (data.value?.baris ?? []).reduce((a, b) => a + b.values[i], 0)))
const grand = computed(() => totalOf(seriesTotals.value))
const barSeries = computed(() => (data.value?.series ?? []).map((s, i) => ({ label: s.label, color: s.color, values: (data.value?.baris ?? []).map((b) => b.values[i]) })))
const donutBaris = computed(() => (data.value?.baris ?? []).map((b, i) => ({ label: b.label, value: totalOf(b.values), color: DONAT_PALET[i % DONAT_PALET.length] })))
const donutSeries = computed(() => (data.value?.series ?? []).map((s, i) => ({ label: s.label, value: seriesTotals.value[i], color: s.color })))
const crumbs = computed(() => [{ label: 'Home', to: { name: 'home' } }, { label: 'Laporan' }, { label: data.value?.title ?? 'Laporan' }])
const onExport = (): void => { notice.value = 'Export belum tersambung ke backend — data contoh.' }
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <UiNotice v-if="notice" tone="info" dismissible class="mb-4" @dismiss="notice = null">{{ notice }}</UiNotice>
    <div v-if="data" class="space-y-5" data-testid="laporan-page">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-h2 font-semibold text-slate-900">{{ data.title }}</h1>
        <UiExportMenu :formats="FORMATS" @select="onExport" />
      </div>

      <UiCard title="Jumlah Pegawai" :subtitle="`Per ${data.kolomLabel.toLowerCase()}`">
        <div v-if="unitOptions.length" class="mb-4 max-w-md">
          <UiSelect v-model="unit" label="Unit/Satker" :options="unitOptions" placeholder="Semua unit kerja" clearable />
        </div>
        <UiBarChart :categories="data.baris.map((b) => b.label)" :series="barSeries" :caption="data.title" />
      </UiCard>

      <div class="grid gap-5 lg:grid-cols-2">
        <UiCard :title="data.donatBaris.title" :subtitle="data.donatBaris.subtitle">
          <UiDonutChart :slices="donutBaris" legend="bottom" :caption="data.donatBaris.title" />
        </UiCard>
        <UiCard :title="data.donatSeries.title" :subtitle="data.donatSeries.subtitle">
          <UiDonutChart :slices="donutSeries" legend="bottom" :caption="data.donatSeries.title" />
        </UiCard>
      </div>

      <UiCard :title="`Tabel ${data.title}`" flush>
        <template #actions><UiExportMenu :formats="FORMATS" label="Export Table" @select="onExport" /></template>
        <div class="space-y-4 pb-5">
          <div class="w-64 max-w-full px-5"><UiSearchInput v-model="search" placeholder="Search" label="Cari baris laporan" /></div>
          <div class="scrollbar-slim overflow-x-auto">
            <table class="w-full min-w-[40rem] border-collapse text-left text-body1" data-testid="laporan-table">
              <thead>
                <tr class="border-y border-slate-200 text-body2 font-semibold text-slate-900">
                  <th scope="col" class="px-5 py-3">{{ data.kolomLabel }}</th>
                  <th v-for="s in data.series" :key="s.label" scope="col" class="px-5 py-3">{{ s.label }}</th>
                  <th scope="col" class="px-5 py-3 text-right">Total</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-200">
                <tr v-for="b in filtered" :key="b.label" :data-testid="`laporan-row-${b.label}`">
                  <td class="px-5 py-3 text-slate-800">{{ b.label }}</td>
                  <td v-for="(s, i) in data.series" :key="s.label" class="px-5 py-3">
                    <div class="flex items-center gap-3">
                      <span class="w-12 tabular-nums">{{ nf.format(b.values[i]) }}</span>
                      <UiProgressBar class="w-28" :value="b.values[i]" :max="Math.max(1, ...data.baris.map((x) => x.values[i]))" :tone="s.tone" :label="`${s.label} ${b.label}`" />
                    </div>
                  </td>
                  <td class="px-5 py-3 text-right font-medium tabular-nums">{{ nf.format(totalOf(b.values)) }}</td>
                </tr>
                <tr v-if="filtered.length === 0"><td :colspan="data.series.length + 2" class="px-5 py-10 text-center text-slate-500" data-testid="laporan-empty">Tidak ada baris yang cocok</td></tr>
                <tr class="bg-brand-primary/5 font-semibold text-slate-900" data-testid="laporan-subtotal">
                  <td class="px-5 py-3">Subtotal</td>
                  <td v-for="(s, i) in data.series" :key="s.label" class="px-5 py-3 tabular-nums">{{ nf.format(seriesTotals[i]) }}</td>
                  <td class="px-5 py-3 text-right tabular-nums">{{ nf.format(grand) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </UiCard>
    </div>
  </RedesignShell>
</template>
