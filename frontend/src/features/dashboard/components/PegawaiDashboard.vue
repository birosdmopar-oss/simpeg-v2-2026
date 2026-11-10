<script setup lang="ts">
/**
 * Beranda keluarga pegawai (role 2/6/7; F-13 MAKE-087, PRD Fase 7 v2.0 §5.10). Kerangka M1 tanpa data. Slot M2/M3: banner
 * (Jalur A), presensi hari ini (komponen Fase 5, Q7-42), kartu prediksi (role 2 penuh; 7 Pensiun + KGB; 6 tanpa) [K],
 * kelengkapan data, presensi & AK bawahan untuk atasan [K], ulang tahun, tautan update FK PTT (Q7-44), berita terbaru (Jalur A).
 */
import type { RingkasanPegawai } from '../types'

defineProps<{ ringkasan: RingkasanPegawai | null }>()
</script>

<template>
  <div class="flex flex-col gap-4" data-testid="beranda-pegawai" />
</template>
