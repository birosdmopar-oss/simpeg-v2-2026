<script setup lang="ts">
/**
 * Tombol — Laporan Redesign §3.3 (Gambar "Tombol"): 6 varian warna (Primary, Secondary, Error, Warning,
 * Info, Success) x 2 tampilan (Default/solid dan Outline).
 *
 * Catatan pemetaan: "Primary" pada dokumen tombol memakai biru #217AFF (warna tertiary palet), bukan navy.
 * Navy #1C3964 dipakai untuk elemen struktural (topbar, sidebar aktif) dan tersedia sebagai varian `brand`.
 *
 * Reusable: dipakai seluruh halaman redesign. Render sebagai <button> atau <RouterLink>/<a> lewat prop `to`/`href`.
 */
import { computed } from 'vue'
import type { RouteLocationRaw } from 'vue-router'

const props = withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'error' | 'warning' | 'info' | 'success' | 'brand'
    appearance?: 'solid' | 'outline' | 'ghost' | 'soft'
    size?: 'sm' | 'md' | 'lg'
    type?: 'button' | 'submit' | 'reset'
    disabled?: boolean
    loading?: boolean
    block?: boolean
    to?: RouteLocationRaw
    href?: string
  }>(),
  {
    variant: 'primary',
    appearance: 'solid',
    size: 'md',
    type: 'button',
    disabled: false,
    loading: false,
    block: false,
    to: undefined,
    href: undefined,
  },
)

const SOLID: Record<NonNullable<typeof props.variant>, string> = {
  primary: 'bg-brand-tertiary text-white hover:bg-[#1667e0] active:bg-[#1259c4]',
  secondary: 'bg-muted text-white hover:bg-[#6f7378] active:bg-[#5d6166]',
  error: 'bg-danger text-white hover:bg-[#d84445] active:bg-[#c33a3b]',
  warning: 'bg-warning text-white hover:bg-[#f08f32] active:bg-[#dd8028]',
  info: 'bg-info text-white hover:bg-[#00a5ba] active:bg-[#008fa1]',
  success: 'bg-success text-white hover:bg-[#21b25f] active:bg-[#1c9b52]',
  brand: 'bg-brand-primary text-white hover:bg-[#16304f] active:bg-[#112640]',
}

const OUTLINE: Record<NonNullable<typeof props.variant>, string> = {
  primary: 'border border-brand-tertiary text-brand-tertiary hover:bg-brand-tertiary/10',
  secondary: 'border border-slate-300 text-muted hover:bg-muted-soft',
  error: 'border border-danger text-danger hover:bg-danger-soft',
  warning: 'border border-warning text-warning hover:bg-warning-soft',
  info: 'border border-info text-info hover:bg-info-soft',
  success: 'border border-success text-success hover:bg-success-soft',
  brand: 'border border-brand-primary text-brand-primary hover:bg-brand-primary/10',
}

const GHOST: Record<NonNullable<typeof props.variant>, string> = {
  primary: 'text-brand-tertiary hover:bg-brand-tertiary/10',
  secondary: 'text-slate-600 hover:bg-slate-100',
  error: 'text-danger hover:bg-danger-soft',
  warning: 'text-warning hover:bg-warning-soft',
  info: 'text-info hover:bg-info-soft',
  success: 'text-success hover:bg-success-soft',
  brand: 'text-brand-primary hover:bg-brand-primary/10',
}

/** Latar abu/tint lembut tanpa border — tombol "Export" / "Cetak" pada mockup bab 4. */
const SOFT: Record<NonNullable<typeof props.variant>, string> = {
  primary: 'bg-brand-tertiary/10 text-brand-tertiary hover:bg-brand-tertiary/20',
  secondary: 'bg-slate-100 text-slate-600 hover:bg-slate-200',
  error: 'bg-danger-soft text-danger hover:bg-danger/20',
  warning: 'bg-warning-soft text-warning hover:bg-warning/20',
  info: 'bg-info-soft text-info hover:bg-info/20',
  success: 'bg-success-soft text-success hover:bg-success/20',
  brand: 'bg-brand-primary/10 text-brand-primary hover:bg-brand-primary/20',
}

const SIZES = {
  sm: 'h-8 gap-1.5 rounded-lg px-3 text-body2',
  md: 'h-10 gap-2 rounded-lg px-4 text-body1',
  lg: 'h-12 gap-2 rounded-xl px-5 text-body1',
} as const

const classes = computed(() => [
  'inline-flex select-none items-center justify-center font-medium transition',
  'disabled:cursor-not-allowed disabled:opacity-50 aria-disabled:cursor-not-allowed aria-disabled:opacity-50',
  SIZES[props.size],
  { solid: SOLID, outline: OUTLINE, ghost: GHOST, soft: SOFT }[props.appearance][props.variant],
  props.block ? 'w-full' : '',
])

const inert = computed(() => props.disabled || props.loading)
</script>

<template>
  <RouterLink v-if="to && !inert" :to="to" :class="classes">
    <slot name="icon-left" />
    <slot />
    <slot name="icon-right" />
  </RouterLink>

  <a v-else-if="href && !inert" :href="href" :class="classes">
    <slot name="icon-left" />
    <slot />
    <slot name="icon-right" />
  </a>

  <button v-else :type="type" :class="classes" :disabled="inert" :aria-busy="loading || undefined">
    <svg v-if="loading" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25" />
      <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
    </svg>
    <slot v-else name="icon-left" />
    <slot />
    <slot name="icon-right" />
  </button>
</template>
