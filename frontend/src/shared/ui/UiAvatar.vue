<script setup lang="ts">
/**
 * Avatar — Laporan Redesign §3.7. Tiga baris pada gambar: tanpa status, status hijau (online),
 * status merah (offline/tidak aktif); dua bentuk isi: inisial dan foto/ilustrasi.
 *
 * Reusable. Kalau `src` gagal dimuat, otomatis jatuh ke inisial supaya tidak pernah tampil kotak rusak.
 *
 * ASSET SEMENTARA: foto pegawai belum ada di sistem. Halaman pemanggil boleh mengisi `src` dengan
 * URL placeholder (lihat src/shared/ui/placeholderAssets.ts) — komponen ini sendiri tidak memuat aset apa pun.
 */
import { computed, ref, watch } from 'vue'

const props = withDefaults(
  defineProps<{
    name?: string
    src?: string | null
    size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl'
    status?: 'none' | 'online' | 'offline'
    /** Alt kosong = avatar dekoratif (nama sudah ditulis di sebelahnya). */
    alt?: string
  }>(),
  { name: '', src: null, size: 'md', status: 'none', alt: undefined },
)

const SIZES = {
  xs: { box: 'h-7 w-7 text-[0.625rem]', dot: 'h-2 w-2' },
  sm: { box: 'h-9 w-9 text-caption', dot: 'h-2.5 w-2.5' },
  md: { box: 'h-11 w-11 text-body2', dot: 'h-3 w-3' },
  lg: { box: 'h-14 w-14 text-body1', dot: 'h-3.5 w-3.5' },
  xl: { box: 'h-20 w-20 text-h5', dot: 'h-4 w-4' },
} as const

const failed = ref(false)
watch(
  () => props.src,
  () => {
    failed.value = false
  },
)

const initials = computed(() =>
  props.name
    .split(/\s+/)
    .filter((part) => /[a-z]/i.test(part[0] ?? ''))
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('') || '?',
)

const showImage = computed(() => Boolean(props.src) && !failed.value)
</script>

<template>
  <span class="relative inline-flex shrink-0">
    <img
      v-if="showImage"
      :src="src ?? undefined"
      :alt="alt ?? name"
      loading="lazy"
      class="rounded-full bg-slate-100 object-cover ring-2 ring-white"
      :class="SIZES[size].box"
      @error="failed = true"
    />
    <span
      v-else
      class="inline-flex items-center justify-center rounded-full bg-brand-tertiary/10 font-semibold text-brand-tertiary ring-2 ring-white"
      :class="SIZES[size].box"
      :aria-label="name || undefined"
      role="img"
    >
      {{ initials }}
    </span>

    <span
      v-if="status !== 'none'"
      class="absolute bottom-0 right-0 rounded-full ring-2 ring-white"
      :class="[SIZES[size].dot, status === 'online' ? 'bg-success' : 'bg-danger']"
      :title="status === 'online' ? 'Aktif' : 'Tidak aktif'"
    />
  </span>
</template>
