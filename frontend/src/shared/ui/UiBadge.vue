<script setup lang="ts">
/**
 * Badge/chip status — memakai warna semantik §3.2.2. Dipakai untuk "Aktif" pada header Detail Pegawai
 * (§4.1.6), jam masuk/pulang pada Riwayat Kehadiran (§4.2.1), dan hitungan menu sidebar.
 *
 * Reusable. Selalu satu baris (CR-054, QA R2-I1): teks panjang tidak terbungkus di dalam pil; bila wadahnya lebih sempit,
 * badge dibatasi lebar wadah (`max-w-full`) dan teksnya dipotong elipsis, jadi pemakai memberi `title` berisi teks penuh.
 */
withDefaults(
  defineProps<{
    tone?: 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info' | 'secondary'
    size?: 'sm' | 'md'
    /** Titik kecil di depan teks (dipakai daftar Kategori Berita §4.2.3). */
    dot?: boolean
  }>(),
  { tone: 'neutral', size: 'sm', dot: false },
)

const TONES = {
  neutral: 'bg-slate-100 text-slate-600',
  primary: 'bg-brand-tertiary/10 text-brand-tertiary',
  success: 'bg-success-soft text-[#1c9b52]',
  warning: 'bg-warning-soft text-[#c96e17]',
  danger: 'bg-danger-soft text-[#c33a3b]',
  info: 'bg-info-soft text-[#00808f]',
  secondary: 'bg-brand-secondary/20 text-[#8a6413]',
} as const

const DOTS = {
  neutral: 'bg-slate-400',
  primary: 'bg-brand-tertiary',
  success: 'bg-success',
  warning: 'bg-warning',
  danger: 'bg-danger',
  info: 'bg-info',
  secondary: 'bg-brand-secondary',
} as const
</script>

<template>
  <span
    class="inline-flex max-w-full items-center gap-1.5 whitespace-nowrap rounded-full font-medium"
    :class="[TONES[tone], size === 'sm' ? 'px-2 py-0.5 text-caption' : 'px-2.5 py-1 text-body2']"
  >
    <span v-if="dot" class="h-1.5 w-1.5 rounded-full" :class="DOTS[tone]" aria-hidden="true" />
    <span class="min-w-0 truncate"><slot /></span>
  </span>
</template>
