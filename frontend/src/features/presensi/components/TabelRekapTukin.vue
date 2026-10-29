<script setup lang="ts">
/**
 * Tabel Rekap Tukin densitas tinggi (PRD 5C §7.4; 16 kolom): urutan kolom mengikuti XLSX rekap (PRD 5B §7.3.4) —
 * NO, NAMA, NIP, UNIT ES.I, UNIT ES.II, JUMLAH PENGURANGAN (%) TL/PSW/TK/TA/CUTI/TB, TOTAL PRESENSI, TOTAL SKP, TOTAL
 * POTONGAN, KETERANGAN. Kolom No/Nama/NIP menempel di kiri saat digulir (`col-sticky-kiri`), header menempel di atas
 * (`table-fill`), angka rata kanan. Tanpa warna baris (legacy rekap tanpa warna); baris galat tetap tampil dengan penanda
 * dan pesan, tanpa menghilangkan baris lain. Blok penanda tangan hanya ada di berkas (Q5C-08).
 */
import { UiBadge } from '@/shared/ui'

import { angkaId } from '../format'
import type { BarisRekapTukin, PenguranganRekap } from '../types.laporan.rekap'

withDefaults(defineProps<{ items: BarisRekapTukin[]; memuat?: boolean; mulaiNomor?: number }>(), { memuat: false, mulaiNomor: 1 })

const KOLOM: (keyof PenguranganRekap)[] = ['TL', 'PSW', 'TK', 'TA', 'CUTI', 'TB']
// Offset kiri kolom sticky = jumlah lebar kolom sebelumnya (No 3rem, Nama 14rem).
const OFFSET_NAMA = { '--sticky-kiri': '3rem' }
const OFFSET_NIP = { '--sticky-kiri': '17rem' }
</script>

<template>
  <div class="table-fill scrollbar-slim overflow-x-auto" data-testid="tabel-rekap-tukin">
    <table class="w-full min-w-[88rem] border-collapse text-left text-body2">
      <thead>
        <tr class="border-b border-slate-200 font-semibold text-slate-900">
          <th scope="col" rowspan="2" class="col-sticky-kiri w-12 min-w-[3rem] px-3 py-2.5 text-center">No</th>
          <th scope="col" rowspan="2" class="col-sticky-kiri w-56 min-w-[14rem] px-3 py-2.5" :style="OFFSET_NAMA">Nama</th>
          <th scope="col" rowspan="2" class="col-sticky-kiri w-44 min-w-[11rem] px-3 py-2.5" :style="OFFSET_NIP">NIP</th>
          <th scope="col" rowspan="2" class="px-3 py-2.5">Unit Es. I</th>
          <th scope="col" rowspan="2" class="px-3 py-2.5">Unit Es. II</th>
          <th scope="colgroup" colspan="6" class="h-10 px-3 py-2 text-center">Jumlah Pengurangan (%)</th>
          <th scope="col" rowspan="2" class="px-3 py-2.5 text-right">Total Pengurangan Presensi (%)</th>
          <th scope="col" rowspan="2" class="px-3 py-2.5 text-right">Total Pengurangan SKP (%)</th>
          <th scope="col" rowspan="2" class="px-3 py-2.5 text-right">Total Potongan (%)</th>
          <th scope="col" rowspan="2" class="min-w-[16rem] px-3 py-2.5">Keterangan</th>
        </tr>
        <tr class="border-b border-slate-200 font-semibold text-slate-900">
          <th v-for="k in KOLOM" :key="k" scope="col" class="top-10 px-3 py-2 text-right">{{ k }}</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-200">
        <tr v-if="memuat && items.length === 0">
          <td colspan="16" class="px-3 py-8 text-center text-slate-500">Memuat…</td>
        </tr>
        <tr v-else-if="items.length === 0">
          <td colspan="16" class="px-3 py-10 text-center text-slate-500" data-testid="rekap-tukin-kosong">Tidak ada data untuk filter ini.</td>
        </tr>
        <tr v-for="(row, i) in items" v-else :key="row.nip" class="align-top" :data-testid="`rekap-row-${row.nip}`" :data-galat="row.galat ? row.galat.code : undefined">
          <td class="col-sticky-kiri px-3 py-2 text-center tabular-nums text-slate-600">{{ mulaiNomor + i }}</td>
          <td class="col-sticky-kiri px-3 py-2 font-medium text-slate-900" :style="OFFSET_NAMA">{{ row.nama }}</td>
          <td class="col-sticky-kiri px-3 py-2 tabular-nums text-slate-700" :style="OFFSET_NIP">{{ row.nip }}</td>
          <td class="px-3 py-2 text-slate-700">{{ row.unit_es1 || '-' }}</td>
          <td class="px-3 py-2 text-slate-700">{{ row.unit_es2 || '-' }}</td>
          <td v-for="k in KOLOM" :key="k" class="px-3 py-2 text-right tabular-nums text-slate-700" :data-kolom="k">{{ row.pengurangan ? angkaId(row.pengurangan[k]) : '—' }}</td>
          <td class="px-3 py-2 text-right tabular-nums text-slate-700" data-kolom="total_presensi">{{ row.galat ? '—' : angkaId(row.total_presensi) }}</td>
          <td class="px-3 py-2 text-right tabular-nums text-slate-700" data-kolom="total_skp">{{ row.galat ? '—' : angkaId(row.total_skp) }}</td>
          <td class="px-3 py-2 text-right font-semibold tabular-nums text-slate-900" data-kolom="total_potongan">{{ row.galat ? '—' : angkaId(row.total_potongan) }}</td>
          <td class="px-3 py-2 text-slate-700">
            <div v-if="row.galat" class="flex flex-col items-start gap-1" :data-testid="`rekap-galat-${row.nip}`">
              <UiBadge tone="danger">Galat</UiBadge>
              <span>{{ row.galat.message }}</span>
            </div>
            <span v-else class="whitespace-pre-line">{{ row.keterangan || '-' }}</span>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
