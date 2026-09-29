<script setup lang="ts">
/**
 * Sparkline (garis tren kecil berisi gradasi) pada empat kartu teratas Dashboard Admin §4.1.1
 * — Pensiun, Naik Pangkat, Tanda Jasa, KGB.
 *
 * Digambar sebagai SVG inline (bukan library chart) supaya tidak menambah dependensi dan tetap
 * tajam di semua kepadatan layar. Dekoratif: aria-hidden, angka aslinya sudah ada di teks kartu.
 */
import { computed, useId } from 'vue'

const props = withDefaults(
  defineProps<{
    points: number[]
    color?: string
    height?: number
  }>(),
  { color: '#217AFF', height: 64 },
)

const gradientId = useId()
const WIDTH = 240

/** Kurva Catmull-Rom sederhana → path SVG halus seperti pada mockup. */
const path = computed(() => {
  const values = props.points
  if (values.length < 2) return ''
  const min = Math.min(...values)
  const max = Math.max(...values)
  const span = max - min || 1
  const step = WIDTH / (values.length - 1)
  const pad = 6
  const usable = props.height - pad * 2

  const xy = values.map((v, i) => [i * step, pad + usable - ((v - min) / span) * usable] as const)

  let d = `M ${xy[0][0]} ${xy[0][1]}`
  for (let i = 0; i < xy.length - 1; i += 1) {
    const [x0, y0] = xy[i]
    const [x1, y1] = xy[i + 1]
    const cx = (x0 + x1) / 2
    d += ` C ${cx} ${y0}, ${cx} ${y1}, ${x1} ${y1}`
  }
  return d
})

const area = computed(() => (path.value ? `${path.value} L ${WIDTH} ${props.height} L 0 ${props.height} Z` : ''))
</script>

<template>
  <svg
    :viewBox="`0 0 ${WIDTH} ${height}`"
    preserveAspectRatio="none"
    class="h-full w-full"
    aria-hidden="true"
    focusable="false"
  >
    <defs>
      <linearGradient :id="gradientId" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" :stop-color="color" stop-opacity="0.22" />
        <stop offset="100%" :stop-color="color" stop-opacity="0" />
      </linearGradient>
    </defs>
    <path :d="area" :fill="`url(#${gradientId})`" />
    <path :d="path" fill="none" :stroke="color" stroke-width="2.5" stroke-linecap="round" vector-effect="non-scaling-stroke" />
  </svg>
</template>
