<script setup lang="ts">
/**
 * Diagram donat + legenda — komposisi pegawai pada Dashboard Admin (§4.1.1) dan dua donat
 * "Unit Kerja" / "Jenis Pegawai" pada Laporan Unit Kerja (§4.1.3).
 *
 * SVG inline, tanpa library chart. Data disajikan ulang sebagai tabel tersembunyi (sr-only) supaya
 * pembaca layar tetap mendapat angkanya — prinsip aksesibilitas §1.2 (WCAG 2.1 AA).
 */
import { computed } from 'vue'

export type DonutSlice = { label: string; value: number; color: string }

const props = withDefaults(
  defineProps<{
    slices: DonutSlice[]
    /** Tebal cincin dalam satuan viewBox 100x100. */
    thickness?: number
    legend?: 'right' | 'bottom' | 'none'
    caption?: string
  }>(),
  { thickness: 20, legend: 'right', caption: '' },
)

const total = computed(() => props.slices.reduce((sum, s) => sum + s.value, 0))

const radius = computed(() => 50 - props.thickness / 2)
const circumference = computed(() => 2 * Math.PI * radius.value)

/** Tiap potongan = lingkaran penuh dengan stroke-dasharray + offset; celah 1.5% agar terlihat terpisah. */
const arcs = computed(() => {
  if (total.value <= 0) return []
  let acc = 0
  return props.slices.map((slice) => {
    const fraction = slice.value / total.value
    const arc = {
      color: slice.color,
      dash: `${Math.max(0, fraction * circumference.value - circumference.value * 0.005)} ${circumference.value}`,
      offset: -acc * circumference.value,
    }
    acc += fraction
    return arc
  })
})

function percent(value: number): string {
  if (total.value <= 0) return '0%'
  return `${((value / total.value) * 100).toFixed(1)}%`
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-6" :class="legend === 'bottom' ? 'flex-col' : ''">
    <svg viewBox="0 0 100 100" class="h-44 w-44 shrink-0 -rotate-90" role="img" :aria-label="caption || 'Diagram donat'">
      <circle cx="50" cy="50" :r="radius" fill="none" stroke="#F1F5F9" :stroke-width="thickness" />
      <circle
        v-for="(arc, i) in arcs"
        :key="i"
        cx="50"
        cy="50"
        :r="radius"
        fill="none"
        :stroke="arc.color"
        :stroke-width="thickness"
        :stroke-dasharray="arc.dash"
        :stroke-dashoffset="arc.offset"
        stroke-linecap="butt"
      />
    </svg>

    <ul v-if="legend !== 'none'" class="min-w-0 space-y-2.5">
      <li v-for="slice in slices" :key="slice.label" class="flex items-center gap-2.5 text-body2 text-slate-600">
        <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: slice.color }" aria-hidden="true" />
        <span class="truncate">{{ slice.label }}</span>
      </li>
    </ul>

    <table class="sr-only">
      <caption>
        {{ caption || 'Rincian diagram' }}
      </caption>
      <thead>
        <tr><th scope="col">Kategori</th><th scope="col">Jumlah</th><th scope="col">Persentase</th></tr>
      </thead>
      <tbody>
        <tr v-for="slice in slices" :key="slice.label">
          <th scope="row">{{ slice.label }}</th>
          <td>{{ slice.value }}</td>
          <td>{{ percent(slice.value) }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
