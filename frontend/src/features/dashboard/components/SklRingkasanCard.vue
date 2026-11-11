<script setup lang="ts">
/**
 * Ringkasan Survei Kepuasan Layanan beranda admin (Q7-26 [V2], role 1; F-13 MAKE-087 M3 bagian B). Memuat sendiri
 * `GET survei-layanan/ringkasan` (bawaan 12 bulan terakhir): rata-rata total (dibobot jumlah) + rata-rata per jenis layanan,
 * skala 1–5 dengan satu desimal; `sebaran` tidak ditampilkan. Total penilaian 0 → "Belum ada penilaian". Galat/403 → kartu
 * tidak dirender (beranda tetap tampil). Figma kartu ini belum ada; tampilan mengikuti kartu tabel beranda (`DirekturCard`).
 */
import { onMounted, ref } from 'vue'

import { UiCard } from '@/shared/ui'

import { berandaService } from '../services/beranda.service'
import type { RingkasanSkl } from '../types'

const data = ref<RingkasanSkl | null>(null)

const ANGKA = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 })
const JUMLAH = new Intl.NumberFormat('id-ID')
const TANGGAL = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' })

function nilai(v: number | null): string {
  return v === null ? '—' : ANGKA.format(v)
}

function tanggal(ymd: string): string {
  const [y, m, d] = ymd.split('-').map(Number)
  if (!y || !m || !d) return ymd
  return TANGGAL.format(new Date(Date.UTC(y, m - 1, d)))
}

onMounted(async () => {
  try {
    data.value = await berandaService.ringkasanSkl()
  } catch (e) {
    if (import.meta.env.DEV) console.warn('[dashboard] ringkasan SKL gagal dimuat', e)
  }
})
</script>

<template>
  <UiCard
    v-if="data"
    title="Survei Kepuasan Layanan"
    :subtitle="`Periode ${tanggal(data.periode.dari)} – ${tanggal(data.periode.sampai)}`"
    flush
    data-testid="beranda-skl"
  >
    <p v-if="data.total.jumlah === 0" class="px-5 pb-5 text-sm text-slate-500" data-testid="beranda-skl-kosong">Belum ada penilaian</p>
    <template v-else>
      <div class="flex items-baseline gap-2 px-5 pb-4">
        <span class="text-h3 font-semibold text-slate-900" data-testid="beranda-skl-total">{{ nilai(data.total.rata_rata) }}</span>
        <span class="text-sm text-slate-500">dari 5 · {{ JUMLAH.format(data.total.jumlah) }} penilaian</span>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-5 py-3">Jenis Layanan</th>
              <th class="px-5 py-3 text-right">Penilaian</th>
              <th class="px-5 py-3 text-right">Rata-rata</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="j in data.per_jenis" :key="j.jenis_rwy" :data-testid="`beranda-skl-jenis-${j.jenis_rwy}`">
              <td class="px-5 py-3 font-medium text-slate-800">{{ j.jenis_layanan }}</td>
              <td class="px-5 py-3 text-right text-slate-600">{{ JUMLAH.format(j.jumlah) }}</td>
              <td class="px-5 py-3 text-right text-slate-600">{{ nilai(j.rata_rata) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </UiCard>
</template>
