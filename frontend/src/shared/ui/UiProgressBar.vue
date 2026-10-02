<script setup lang="ts">
/**
 * Garis progres tipis — dipakai kartu "Total PNS / PPPK / PTT ..." dan "Total Pegawai" (§4.1.1),
 * progres KGB pada sambutan pengguna (§4.2.1), kolom AK (§4.2.1) dan kolom jumlah pegawai (§4.1.3).
 *
 * Reusable.
 */
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    value: number
    max?: number
    tone?: 'primary' | 'info' | 'success' | 'warning' | 'danger' | 'violet'
    size?: 'xs' | 'sm' | 'md'
    label?: string
  }>(),
  { max: 100, tone: 'primary', size: 'sm', label: '' },
)

const TONES = {
  primary: 'bg-brand-tertiary',
  info: 'bg-info',
  success: 'bg-success',
  warning: 'bg-warning',
  danger: 'bg-danger',
  violet: 'bg-[#7367F0]',
} as const

const HEIGHTS = { xs: 'h-1', sm: 'h-1.5', md: 'h-2.5' } as const

const percent = computed(() => {
  if (props.max <= 0) return 0
  return Math.min(100, Math.max(0, (props.value / props.max) * 100))
})
</script>

<template>
  <div
    class="w-full overflow-hidden rounded-full bg-slate-200/70"
    :class="HEIGHTS[size]"
    role="progressbar"
    :aria-valuenow="Math.round(percent)"
    aria-valuemin="0"
    aria-valuemax="100"
    :aria-label="label || undefined"
  >
    <div class="h-full rounded-full transition-[width] duration-500" :class="TONES[tone]" :style="{ width: `${percent}%` }" />
  </div>
</template>
