<script setup lang="ts">
/**
 * Indikator radius presensi (PRD 5A §7.8.1, ◻ Q5A-10): diagram SVG lingkaran radius lokasi terdekat dengan titik
 * lokasi (pusat) dan posisi perangkat relatif — tanpa peta tile pihak ketiga. Posisi relatif hanya visual (proyeksi
 * equirectangular lokal); putusan dalam/luar radius dan jarak yang ditampilkan berasal dari server (ADR-025).
 */
import { computed } from 'vue'

const props = defineProps<{
  /** Pusat lokasi (lat/lng) dan radius meter dari `hari-ini.lokasi`. */
  lokasi: { latitude: number; longitude: number; radius: number; nama: string }
  posisi: { latitude: number; longitude: number } | null
  /** Putusan server (`cek-lokasi`); null = belum dinilai. */
  dalamRadius: boolean | null
}>()

const UKURAN = 200
const PUSAT = UKURAN / 2
const R_EARTH = 6_371_000

/** Selisih meter (timur, utara) perangkat terhadap pusat lokasi; hanya untuk gambar. */
const selisih = computed(() => {
  if (!props.posisi) return null
  const rad = Math.PI / 180
  const dx = (props.posisi.longitude - props.lokasi.longitude) * rad * R_EARTH * Math.cos(props.lokasi.latitude * rad)
  const dy = (props.posisi.latitude - props.lokasi.latitude) * rad * R_EARTH
  return { dx, dy }
})

/** Skala: lingkaran radius selalu terlihat; perangkat jauh dijepit ke tepi diagram. */
const skala = computed(() => {
  const jauh = selisih.value ? Math.hypot(selisih.value.dx, selisih.value.dy) : 0
  const cakup = Math.max(props.lokasi.radius * 1.6, Math.min(jauh * 1.15, props.lokasi.radius * 6), 1)
  return (UKURAN / 2 - 8) / cakup
})

const rPx = computed(() => Math.max(props.lokasi.radius * skala.value, 6))

const titik = computed(() => {
  if (!selisih.value) return null
  let x = selisih.value.dx * skala.value
  let y = -selisih.value.dy * skala.value
  const batas = UKURAN / 2 - 8
  const d = Math.hypot(x, y)
  const dijepit = d > batas
  if (dijepit) {
    x = (x / d) * batas
    y = (y / d) * batas
  }
  return { x: PUSAT + x, y: PUSAT + y, dijepit }
})

const warna = computed(() => (props.dalamRadius === null ? '#64748b' : props.dalamRadius ? '#1c9b52' : '#c33a3b'))
</script>

<template>
  <figure class="flex flex-col items-center gap-2" data-testid="indikator-radius">
    <svg
      :viewBox="`0 0 ${UKURAN} ${UKURAN}`"
      class="h-44 w-44"
      role="img"
      :aria-label="`Diagram radius ${lokasi.nama} ${lokasi.radius} meter${posisi ? '' : ', posisi perangkat belum terbaca'}`"
    >
      <rect :width="UKURAN" :height="UKURAN" rx="16" fill="#f8fafc" />
      <circle :cx="PUSAT" :cy="PUSAT" :r="rPx" :fill="`${warna}1f`" :stroke="warna" stroke-width="2" stroke-dasharray="6 4" />
      <circle :cx="PUSAT" :cy="PUSAT" r="4" fill="#1e3a5f" />
      <template v-if="titik">
        <line :x1="PUSAT" :y1="PUSAT" :x2="titik.x" :y2="titik.y" :stroke="warna" stroke-width="1.5" :stroke-dasharray="titik.dijepit ? '3 3' : undefined" />
        <circle :cx="titik.x" :cy="titik.y" r="7" :fill="warna" stroke="#fff" stroke-width="2" data-testid="indikator-perangkat" />
      </template>
    </svg>
    <figcaption class="flex flex-wrap justify-center gap-x-3 text-caption text-slate-500">
      <span class="inline-flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-full bg-[#1e3a5f]" aria-hidden="true" />{{ lokasi.nama }}</span>
      <span class="inline-flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-full" :style="{ background: warna }" aria-hidden="true" />Posisi Anda</span>
      <span v-if="titik?.dijepit">(di luar skala)</span>
    </figcaption>
  </figure>
</template>
