<script setup lang="ts">
/**
 * Tabel harian Laporan Tukin per pegawai (PRD 5C §7.3). Latar baris ikut legacy per `status` ([K] `laporan_tukin_skp.php`
 * :235-262: libur biru, akhir pekan abu, konket jingga, cuti ungu, TB merah muda, di luar masa kerja abu; baris presensi
 * tanpa warna) dan CHIP kode kejadian palet Q5C-03 ([V2]); teks kode selalu tampil. Baris non-kerja (`libur`,
 * `akhir_pekan`, `di_luar_masa_kerja`) tampil bila API mengirimnya; tabel tidak mengarang tanggal yang tidak ada di `harian[]`.
 * Wadah gulir `isolate` (CR-077 T-03): header dan kolom sticky menumpuk di dalam wadah ini, tidak di atas navbar sticky.
 */
import { formatTanggalIndonesia } from '@/shared/utils/dateTime'

import { persenId } from '../format'
import { formatJam } from '../services/presensi.service'
import { nadaBaris, NADA_STATUS } from '../statusPresensi'
import type { HarianLaporan } from '../types.laporan'

import StatusPresensiChip from './StatusPresensiChip.vue'

defineProps<{ harian: HarianLaporan[] }>()

function kelasBaris(h: HarianLaporan): string {
  const nada = nadaBaris(h.status, h.tanggal)
  if (nada === null) return ''
  // `sel` = latar solid untuk sel sticky Tanggal (CR-077 T-02): warna baris tampil penuh tanpa tembus saat digulir ke samping.
  return `${NADA_STATUS[nada].baris} ${NADA_STATUS[nada].sel}`.trim()
}
</script>

<template>
  <div class="scrollbar-slim isolate max-h-[36rem] overflow-auto" data-testid="tabel-harian-tukin">
    <table class="w-full min-w-[44rem] border-collapse text-left text-body2">
      <thead>
        <tr class="font-semibold text-slate-900">
          <th scope="col" class="sticky top-0 col-sticky-kiri border-b border-slate-200 px-3 py-2.5">Tanggal</th>
          <th scope="col" class="sticky top-0 z-10 border-b border-slate-200 bg-slate-50 px-3 py-2.5">Status</th>
          <th scope="col" class="sticky top-0 z-10 border-b border-slate-200 bg-slate-50 px-3 py-2.5 text-center">Jam Masuk</th>
          <th scope="col" class="sticky top-0 z-10 border-b border-slate-200 bg-slate-50 px-3 py-2.5 text-center">Jam Pulang</th>
          <th scope="col" class="sticky top-0 z-10 border-b border-slate-200 bg-slate-50 px-3 py-2.5">Keterangan</th>
          <th scope="col" class="sticky top-0 z-10 border-b border-slate-200 bg-slate-50 px-3 py-2.5 text-right">Potongan</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-200">
        <tr v-if="harian.length === 0">
          <td colspan="6" class="px-3 py-8 text-center text-slate-500" data-testid="tabel-harian-kosong">Tidak ada data harian pada periode ini.</td>
        </tr>
        <tr v-for="h in harian" v-else :key="h.tanggal" :class="kelasBaris(h)" :data-testid="`harian-${h.tanggal}`" :data-status="h.status">
          <td class="col-sticky-kiri whitespace-nowrap px-3 py-2 font-medium text-slate-900">{{ formatTanggalIndonesia(h.tanggal) }}</td>
          <td class="px-3 py-2"><StatusPresensiChip :status="h.status" :kode="h.kode" /></td>
          <td class="px-3 py-2 text-center tabular-nums text-slate-700">{{ formatJam(h.jam_masuk) }}</td>
          <td class="px-3 py-2 text-center tabular-nums text-slate-700">{{ formatJam(h.jam_pulang) }}</td>
          <td class="px-3 py-2 text-slate-700">{{ h.keterangan || '-' }}</td>
          <td class="px-3 py-2 text-right tabular-nums text-slate-700">{{ persenId(h.potongan) }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
