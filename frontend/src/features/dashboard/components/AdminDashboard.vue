<script setup lang="ts">
/**
 * Beranda keluarga admin (role 1/3/4/5/8; F-13 MAKE-087, PRD Fase 7 v2.0 §5.10). Kerangka M1: kartu komposisi pegawai +
 * status hari ini (`KomposisiPegawaiCard`). Slot yang diisi di M2/M3 (urutan final §5.10): banner (`BannerCarousel`, Jalur A),
 * 4 kartu pengingat Pensiun/KP/TJ/KGB, kartu Butuh Approval (`useRingkasanNotifikasi`, Jalur C), masa kerja direktur
 * politeknik (role 1/4), berita terbaru (Jalur A), ulang tahun, ringkasan SKL (Jalur B).
 */
import type { RingkasanAdmin } from '../types'

import KomposisiPegawaiCard from './KomposisiPegawaiCard.vue'

defineProps<{ ringkasan: RingkasanAdmin }>()

const LABEL_LINGKUP = { kementerian: 'Kementerian', satker: 'Satker', unit: 'Unit' } as const
</script>

<template>
  <div class="flex flex-col gap-4" data-testid="beranda-admin">
    <p class="text-sm text-slate-500" data-testid="beranda-lingkup">Lingkup data: {{ LABEL_LINGKUP[ringkasan.lingkup.jenis] }}</p>
    <KomposisiPegawaiCard :ringkasan="ringkasan" />
  </div>
</template>
