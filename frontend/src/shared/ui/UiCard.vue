<script setup lang="ts">
/**
 * Kartu/panel putih — bentuk dasar hampir semua blok konten di bab 4 Laporan Redesign
 * (radius besar, border tipis, bayangan halus). Header opsional: judul + subjudul + aksi di kanan.
 *
 * Reusable.
 */
withDefaults(
  defineProps<{
    title?: string
    subtitle?: string
    /** `flush` = tanpa padding badan, untuk kartu yang isinya tabel penuh lebar. */
    flush?: boolean
    /** Garis aksen tipis di dasar kartu (dipakai kartu "Berulang Tahun" §4.1.1). */
    accent?: 'none' | 'warning' | 'success' | 'primary'
  }>(),
  { title: '', subtitle: '', flush: false, accent: 'none' },
)

const ACCENTS = {
  none: '',
  warning: 'border-b-2 border-b-warning',
  success: 'border-b-2 border-b-success',
  primary: 'border-b-2 border-b-brand-tertiary',
} as const
</script>

<template>
  <section class="overflow-hidden rounded-card border border-slate-200 bg-white shadow-card" :class="ACCENTS[accent]">
    <header
      v-if="title || $slots.header || $slots.actions"
      class="flex flex-wrap items-start justify-between gap-3 px-5 pt-5"
      :class="flush ? 'pb-4' : ''"
    >
      <div>
        <slot name="header">
          <h2 class="text-h5 text-slate-900">{{ title }}</h2>
          <p v-if="subtitle" class="mt-0.5 text-body2 text-slate-500">{{ subtitle }}</p>
        </slot>
      </div>
      <div v-if="$slots.actions" class="flex items-center gap-2">
        <slot name="actions" />
      </div>
    </header>

    <div :class="flush ? '' : 'p-5'">
      <slot />
    </div>

    <footer v-if="$slots.footer" class="border-t border-slate-100 px-5 py-3">
      <slot name="footer" />
    </footer>
  </section>
</template>
