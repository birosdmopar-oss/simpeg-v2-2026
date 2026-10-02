<script setup lang="ts">
/**
 * Kartu angka ringkas dengan ikon berlatar lembut — dipakai baris atas Dashboard Admin (§4.1.1,
 * Pensiun/Naik Pangkat/Tanda Jasa/KGB, dengan sparkline) dan kartu Pensiun/Naik Pangkat/Tanda Jasa/KGB
 * pada Dashboard Pengguna (§4.2.1, tanpa sparkline tapi dengan garis tepi berwarna).
 *
 * Reusable.
 */
import type { Component } from 'vue'

import UiSparkline from './UiSparkline.vue'

export type StatTone = 'violet' | 'success' | 'danger' | 'warning' | 'info' | 'primary'

const props = withDefaults(
  defineProps<{
    label: string
    value: string | number
    hint?: string
    icon?: Component
    tone?: StatTone
    /** Deret angka sparkline; kosong = kartu tanpa grafik. */
    trend?: number[]
    /** Garis tepi tipis berwarna senada (varian Dashboard Pengguna). */
    outlined?: boolean
    /** Posisi nilai: 'below' (admin) atau 'beside' ikon di kanan (pengguna). */
    layout?: 'stacked' | 'split'
  }>(),
  { hint: '', icon: undefined, tone: 'primary', trend: () => [], outlined: false, layout: 'stacked' },
)

const TONES: Record<StatTone, { chipBg: string; chipText: string; stroke: string; ring: string }> = {
  violet: { chipBg: 'bg-[#7367F0]/10', chipText: 'text-[#7367F0]', stroke: '#7367F0', ring: 'border-[#7367F0]/35' },
  success: { chipBg: 'bg-success-soft', chipText: 'text-success', stroke: '#28C76F', ring: 'border-success/35' },
  danger: { chipBg: 'bg-danger-soft', chipText: 'text-danger', stroke: '#EA5455', ring: 'border-danger/35' },
  warning: { chipBg: 'bg-warning-soft', chipText: 'text-warning', stroke: '#FF9F43', ring: 'border-warning/35' },
  info: { chipBg: 'bg-info-soft', chipText: 'text-info', stroke: '#00BAD1', ring: 'border-info/35' },
  primary: { chipBg: 'bg-brand-tertiary/10', chipText: 'text-brand-tertiary', stroke: '#217AFF', ring: 'border-brand-tertiary/35' },
}
</script>

<template>
  <article
    class="flex flex-col overflow-hidden rounded-card border bg-white shadow-card"
    :class="outlined ? TONES[tone].ring : 'border-slate-200'"
  >
    <div class="flex items-start justify-between gap-3 p-5" :class="layout === 'split' ? 'flex-row-reverse' : 'flex-col'">
      <span
        v-if="props.icon"
        class="inline-flex h-10 w-10 items-center justify-center rounded-lg"
        :class="[TONES[tone].chipBg, TONES[tone].chipText]"
        aria-hidden="true"
      >
        <component :is="props.icon" class="h-5 w-5" />
      </span>

      <div :class="layout === 'split' ? '' : 'mt-4'">
        <p v-if="layout === 'split'" class="text-body2 text-slate-500">{{ label }}</p>
        <p class="text-h4 text-slate-900">{{ value }}</p>
        <p v-if="layout === 'stacked'" class="text-body2 text-slate-500">{{ label }}</p>
        <p v-if="hint" class="mt-0.5 text-caption text-slate-400">{{ hint }}</p>
      </div>
    </div>

    <div v-if="trend.length > 1" class="h-16 w-full">
      <UiSparkline :points="trend" :color="TONES[tone].stroke" />
    </div>
  </article>
</template>
