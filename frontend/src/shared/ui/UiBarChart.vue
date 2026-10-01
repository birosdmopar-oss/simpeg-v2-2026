<script setup lang="ts">
/**
 * Diagram batang berpasangan (Laporan Unit Kerja §4.1.3, Gambar 15): satu kelompok batang per kategori, label angka di
 * atas batang, garis bantu + sumbu Y, legenda di bawah. HTML/CSS murni (tanpa library chart), responsif: area diagram
 * bisa digulir mendatar bila kategorinya banyak. Data disajikan ulang sebagai tabel sr-only untuk pembaca layar (WCAG).
 *
 * Reusable.
 */
import { computed } from 'vue'

export type BarSeries = { label: string; color: string; values: number[] }

const props = withDefaults(
  defineProps<{
    categories: string[]
    series: BarSeries[]
    height?: number
    caption?: string
    /** Lebar minimum tiap kategori (px) agar label tidak menumpuk. */
    minCategoryWidth?: number
  }>(),
  { height: 280, caption: '', minCategoryWidth: 88 },
)

/** Batas atas "rapi" (1/2/5 × 10^n) agar garis bantu berupa bilangan bulat. */
const niceMax = computed(() => {
  const raw = Math.max(1, ...props.series.flatMap((s) => s.values))
  const pow = 10 ** Math.floor(Math.log10(raw))
  const step = [1, 2, 5, 10].find((m) => raw <= m * pow) ?? 10
  return step * pow
})

const ticks = computed(() => Array.from({ length: 6 }, (_, i) => Math.round((niceMax.value / 5) * i)))
const pct = (value: number): number => (value / niceMax.value) * 100
const width = computed(() => `${Math.max(props.categories.length * props.minCategoryWidth, 320)}px`)
</script>

<template>
  <figure class="w-full">
    <div class="scrollbar-slim overflow-x-auto pb-2">
      <div :style="{ minWidth: width }">
        <div class="relative pl-10 pt-6" :style="{ height: `${height}px` }" role="img" :aria-label="caption || 'Diagram batang'">
          <!-- Garis bantu + sumbu Y -->
          <div v-for="tick in ticks" :key="tick" class="pointer-events-none absolute inset-x-0 flex items-center" :style="{ bottom: `${pct(tick)}%` }">
            <span class="w-8 pr-2 text-right text-caption text-slate-400">{{ tick }}</span>
            <span class="h-px flex-1 bg-slate-100" />
          </div>

          <div class="relative flex h-full items-end">
            <div v-for="(category, ci) in categories" :key="category" class="flex h-full flex-1 items-end justify-center gap-1.5 px-2">
              <div
                v-for="s in series"
                :key="s.label"
                class="relative w-full max-w-9 rounded-t-md transition-[height] duration-500"
                :style="{ height: `${pct(s.values[ci] ?? 0)}%`, backgroundColor: s.color }"
              >
                <span class="absolute -top-5 left-1/2 -translate-x-1/2 whitespace-nowrap text-caption" :style="{ color: s.color }">{{ s.values[ci] ?? 0 }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Label kategori -->
        <div class="flex pl-10" aria-hidden="true">
          <p v-for="category in categories" :key="category" class="flex-1 px-1 pt-3 text-center text-caption leading-4 text-slate-500">{{ category }}</p>
        </div>
      </div>
    </div>

    <figcaption class="mt-4 flex flex-wrap items-center justify-center gap-3">
      <span v-for="s in series" :key="s.label" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-1 text-body2 text-slate-700">
        <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: s.color }" aria-hidden="true" />
        {{ s.label }}
      </span>
    </figcaption>

    <table class="sr-only">
      <caption>{{ caption || 'Data diagram batang' }}</caption>
      <thead>
        <tr>
          <th scope="col">Kategori</th>
          <th v-for="s in series" :key="s.label" scope="col">{{ s.label }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(category, ci) in categories" :key="category">
          <th scope="row">{{ category }}</th>
          <td v-for="s in series" :key="s.label">{{ s.values[ci] ?? 0 }}</td>
        </tr>
      </tbody>
    </table>
  </figure>
</template>
