<script setup lang="ts">
/**
 * Komposisi pegawai + status hari ini di beranda admin (F-13, Q7-36).
 *
 * - `donut` (bawaan [V2]): `UiDonutChart` atas tile kelompok `komposisi` (jenis pegawai) + baris tile angka status hari
 *   ini (TB, IB, CLTN, Cuti Online, Presensi Online).
 * - `angka` (persis legacy `views/user/dashboard_ad.php:46-200`): semua tile berurutan seperti legacy (judul, angka, bar
 *   persen terhadap total aktif), tanpa chart.
 * Mode datang dari backend (`tampilan_komposisi`); datanya sama.
 */
import { computed } from 'vue'

import { UiCard, UiDonutChart, UiProgressBar } from '@/shared/ui'

import { PALET_KOMPOSISI, type RingkasanAdmin, type TileBeranda } from '../types'

const props = defineProps<{ ringkasan: RingkasanAdmin }>()

const angka = new Intl.NumberFormat('id-ID')

const komposisi = computed(() => props.ringkasan.tile.filter((t) => t.kelompok === 'komposisi'))
const hariIni = computed(() => props.ringkasan.tile.filter((t) => t.kelompok === 'hari_ini'))

const slices = computed(() =>
  komposisi.value.map((t, i) => ({ label: t.label.replace(/^Total /, ''), value: t.jumlah, color: PALET_KOMPOSISI[i % PALET_KOMPOSISI.length] ?? '#1e3a8a' })),
)

function format(t: TileBeranda): string {
  return angka.format(t.jumlah)
}
</script>

<template>
  <UiCard title="Komposisi Pegawai" :subtitle="`Total pegawai aktif ${angka.format(ringkasan.total_aktif)}`" data-testid="beranda-komposisi">
    <div v-if="ringkasan.tampilan_komposisi === 'donut'" data-testid="beranda-komposisi-donut" class="flex flex-col gap-6">
      <UiDonutChart :slices="slices" legend="right" caption="Komposisi pegawai aktif per jenis pegawai" />
      <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
        <li v-for="t in hariIni" :key="t.kode" class="rounded-lg border border-slate-200 p-3" :data-testid="`beranda-tile-${t.kode}`">
          <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ t.label }}</p>
          <p class="mt-1 text-xl font-semibold text-slate-900">{{ format(t) }}</p>
        </li>
      </ul>
    </div>

    <ul v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6" data-testid="beranda-komposisi-angka">
      <li v-for="t in ringkasan.tile" :key="t.kode" class="flex flex-col gap-1.5" :data-testid="`beranda-tile-${t.kode}`">
        <p class="text-xs font-medium text-slate-500">{{ t.label }}</p>
        <p class="text-xl font-semibold text-slate-900">{{ format(t) }}</p>
        <UiProgressBar :value="t.persen" size="xs" :label="`${t.label} ${t.persen}% dari total pegawai aktif`" />
      </li>
    </ul>
  </UiCard>
</template>
