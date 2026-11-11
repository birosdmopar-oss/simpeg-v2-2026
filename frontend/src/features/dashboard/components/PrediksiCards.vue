<script setup lang="ts">
/**
 * Kartu prediksi beranda pegawai (`views/user/dashboard_pns.php:40-96`, `dashboard_pppk.php:45-54`) [K]: judul = "Bulan Tahun"
 * atau "-", label kartu, keterangan = sisa waktu atau "-". Naik Pangkat JF: "AK sekarang / target" + "Kurang N AK"; non-JF tanpa
 * tanggal di pangkat puncak: "Sudah di Pangkat Puncak". Angka AK sekarang ikut `decimalToUser` legacy (koma desimal).
 */
import { UiCard } from '@/shared/ui'

import type { KartuPrediksi } from '../types'

defineProps<{ kartu: KartuPrediksi[] }>()

/** `decimalToUser` legacy: angka apa adanya, titik desimal → koma. */
const desimal = (n: number): string => String(n).replace('.', ',')

function tampil(k: KartuPrediksi): { judul: string; label: string; ket: string } {
  if (k.kode === 'kp' && k.jenis === 'JF' && k.ak) {
    return { judul: `${desimal(k.ak.sekarang)} / ${k.ak.target}`, label: k.label, ket: `Kurang ${desimal(k.ak.kurang)} AK` }
  }
  if (k.kode === 'kp' && !k.teks && k.pangkat_puncak) {
    return { judul: '', label: 'Sudah di Pangkat Puncak', ket: '' }
  }
  return { judul: k.teks ?? '-', label: k.label, ket: k.teks ? (k.sisa ?? '-') || '-' : '-' }
}
</script>

<template>
  <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="beranda-prediksi">
    <li v-for="k in kartu" :key="k.kode" :data-testid="`beranda-prediksi-${k.kode}`">
      <UiCard>
        <div class="flex min-h-[7rem] flex-col gap-1 px-5 pb-5 pt-5">
          <p class="text-h4 text-slate-900" data-testid="judul">{{ tampil(k).judul || ' ' }}</p>
          <p class="text-body2 font-medium text-slate-600" data-testid="label">{{ tampil(k).label }}</p>
          <p class="text-caption text-slate-400" data-testid="ket">{{ tampil(k).ket }}</p>
        </div>
      </UiCard>
    </li>
  </ul>
</template>
