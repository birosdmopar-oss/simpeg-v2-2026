<script setup lang="ts">
/**
 * Kartu rincian slip gaji (D-11): identitas, A. GPP (penghasilan/potongan per kelompok), B. Tunjangan Kinerja, C. Uang
 * Makan, dan ringkasan total — susunan & label [K `_slip_content.php`], tata letak acuan PNG Figma "UI Slip Gaji"
 * (identitas dua kolom, lalu kolom Penghasilan · Potongan · ringkasan). Semua angka dari server (string desimal);
 * komponen hanya memformat, tidak menghitung.
 */
import { computed } from 'vue'

import { formatRupiah } from '../services/slipGaji.service'
import { KELOMPOK_GPP, LABEL_GPP_BERSIH, LABEL_KOLOM, LABEL_TK_BERSIH, type KelompokSlip, type SlipTerbuka } from '../types'

const props = defineProps<{ slip: SlipTerbuka }>()

const BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember']

const [tahun, bulan] = props.slip.periode.split('-')
const namaBulan = BULAN[Number(bulan) - 1] ?? bulan ?? ''

const identitas = computed(() => [
  ['Nama', props.slip.pegawai.nama ?? '-'],
  ['NIP', props.slip.pegawai.nip],
  ['Pangkat / Gol', props.slip.pegawai.pangkat],
  ['Jabatan', props.slip.pegawai.jabatan],
  ['Satuan Organisasi', props.slip.pegawai.unit],
  ['Satuan Kerja', props.slip.pegawai.satker],
])

const nilai = (kolom: string): string => props.slip.rincian[kolom] ?? '0.00'

/** Kelompok yang berisi lebih dari satu kolom (atau judul ≠ label kolom) diberi subjudul [K `$flat`]. */
const datar = (k: KelompokSlip): boolean => k.kolom.length === 1 && k.judul === LABEL_KOLOM[k.kolom[0] ?? '']

const catatanUangMakan = computed(() => {
  const um = props.slip.uang_makan
  if (!um.tersedia || !um.periode_kehadiran) return ''
  const [t, b] = um.periode_kehadiran.split('-')
  const teks = `Presensi ${BULAN[Number(b) - 1] ?? b} ${t}`
  return um.hari === null ? teks : `${teks} · ${um.hari} hari`
})
</script>

<template>
  <div class="rounded-2xl border border-slate-200 bg-white" data-testid="slip-kartu">
    <div class="grid gap-x-10 gap-y-1 p-5 text-body2 md:grid-cols-[minmax(0,7fr)_minmax(0,5fr)] sm:p-6">
      <dl class="grid grid-cols-[8.5rem_0.75rem_minmax(0,1fr)] gap-y-1.5" data-testid="slip-identitas">
        <template v-for="[label, isi] in identitas" :key="label">
          <dt class="text-slate-500">{{ label }}</dt>
          <dd class="text-slate-500">:</dd>
          <dd class="break-words text-slate-900">{{ isi }}</dd>
        </template>
      </dl>
      <dl class="grid grid-cols-[5rem_0.75rem_minmax(0,1fr)] content-center gap-y-1.5">
        <dt class="text-slate-500">Bulan</dt>
        <dd class="text-slate-500">:</dd>
        <dd class="font-semibold text-slate-900">{{ namaBulan }}</dd>
        <dt class="text-slate-500">Tahun</dt>
        <dd class="text-slate-500">:</dd>
        <dd class="font-semibold text-slate-900">{{ tahun }}</dd>
      </dl>
    </div>

    <div class="grid border-t border-slate-200 lg:grid-cols-3 lg:divide-x lg:divide-slate-200">
      <!-- A. GPP — Penghasilan -->
      <section class="flex flex-col p-5 sm:p-6" aria-labelledby="slip-penghasilan">
        <h3 id="slip-penghasilan" class="mb-1 font-semibold text-brand-primary">Penghasilan</h3>
        <p class="mb-3 text-caption font-semibold uppercase tracking-wide text-slate-500">A. Gaji Pokok &amp; Pendapatan (GPP)</p>
        <div v-for="k in KELOMPOK_GPP.penghasilan" :key="k.judul" class="mb-2">
          <p v-if="!datar(k)" class="text-body2 font-semibold text-slate-700">{{ k.judul }}</p>
          <div v-for="kolom in k.kolom" :key="kolom" class="flex justify-between gap-3 py-0.5 text-body2" :data-testid="`slip-${kolom}`">
            <span :class="datar(k) ? '' : 'pl-3'" class="text-slate-700">{{ LABEL_KOLOM[kolom] }}</span>
            <span class="flex min-w-[8rem] justify-between gap-3 tabular-nums text-slate-900"><span>Rp.</span><span>{{ formatRupiah(nilai(kolom)) }}</span></span>
          </div>
        </div>
        <div class="mt-auto flex justify-between gap-3 border-t border-slate-200 pt-3 text-body2 font-semibold text-slate-900">
          <span>Jumlah Penghasilan</span>
          <span class="flex min-w-[8rem] justify-between gap-3 tabular-nums" data-testid="slip-jumlah-penghasilan"><span>Rp.</span><span>{{ formatRupiah(slip.ringkasan.jumlah_penghasilan_gpp) }}</span></span>
        </div>
      </section>

      <!-- A. GPP — Potongan -->
      <section class="flex flex-col border-t border-slate-200 p-5 sm:p-6 lg:border-t-0" aria-labelledby="slip-potongan">
        <h3 id="slip-potongan" class="mb-1 font-semibold text-red-700">Potongan</h3>
        <p class="mb-3 text-caption font-semibold uppercase tracking-wide text-slate-500">A. Gaji Pokok &amp; Pendapatan (GPP)</p>
        <div v-for="k in KELOMPOK_GPP.potongan" :key="k.judul" class="mb-2">
          <p class="text-body2 font-semibold text-slate-700">{{ k.judul }}</p>
          <div v-for="kolom in k.kolom" :key="kolom" class="flex justify-between gap-3 py-0.5 text-body2" :data-testid="`slip-${kolom}`">
            <span class="pl-3 text-slate-700">{{ LABEL_KOLOM[kolom] }}</span>
            <span class="flex min-w-[8rem] justify-between gap-3 tabular-nums text-red-700"><span>Rp.</span><span>- {{ formatRupiah(nilai(kolom)) }}</span></span>
          </div>
        </div>
        <div class="mt-auto flex justify-between gap-3 border-t border-slate-200 pt-3 text-body2 font-semibold text-slate-900">
          <span>Jumlah Potongan</span>
          <span class="flex min-w-[8rem] justify-between gap-3 tabular-nums text-red-700" data-testid="slip-jumlah-potongan"><span>Rp.</span><span>- {{ formatRupiah(slip.ringkasan.jumlah_potongan_gpp) }}</span></span>
        </div>
        <div class="mt-3 flex justify-between gap-3 rounded-lg bg-slate-100 px-3 py-2 text-body2 font-semibold text-slate-900">
          <span>{{ LABEL_GPP_BERSIH }}</span>
          <span class="tabular-nums" data-testid="slip-bersih-gpp-kolom">Rp. {{ formatRupiah(nilai('bersih_gpp')) }}</span>
        </div>
      </section>

      <!-- B. TK, C. Uang Makan, ringkasan -->
      <section class="flex flex-col border-t border-slate-200 p-5 sm:p-6 lg:border-t-0" aria-labelledby="slip-tk">
        <h3 id="slip-tk" class="mb-3 text-caption font-semibold uppercase tracking-wide text-slate-500">B. Tunjangan Kinerja (TK)</h3>
        <!-- TK tanpa baris Pajak / Tunjangan Pajak [K _slip_content.php @0cf7e67 :43-46] (CR-067). -->
        <div v-for="kolom in ['kotor', 'potongan', 'bersih_tk']" :key="kolom" class="flex justify-between gap-3 py-0.5 text-body2" :data-testid="`slip-${kolom}`">
          <span class="text-slate-700">{{ LABEL_KOLOM[kolom] }}</span>
          <span class="flex min-w-[8rem] justify-between gap-3 tabular-nums" :class="kolom === 'potongan' ? 'text-red-700' : 'text-slate-900'">
            <span>Rp.</span><span>{{ kolom === 'potongan' ? '- ' : '' }}{{ formatRupiah(nilai(kolom)) }}</span>
          </span>
        </div>
        <div class="mt-2 flex justify-between gap-3 rounded-lg bg-slate-100 px-3 py-2 text-body2 font-semibold text-slate-900">
          <span>{{ LABEL_TK_BERSIH }}</span>
          <span class="tabular-nums" data-testid="slip-bersih-2">Rp. {{ formatRupiah(nilai('bersih_2')) }}</span>
        </div>

        <h3 class="mb-2 mt-5 text-caption font-semibold uppercase tracking-wide text-slate-500">C. Uang Makan</h3>
        <div class="flex justify-between gap-3 text-body2">
          <span class="text-slate-700">Uang Makan</span>
          <span class="flex min-w-[8rem] justify-between gap-3 tabular-nums text-slate-900" data-testid="slip-uang-makan"><span>Rp.</span><span>{{ formatRupiah(slip.uang_makan.nominal) }}</span></span>
        </div>
        <p v-if="catatanUangMakan" class="text-caption text-slate-500" data-testid="slip-uang-makan-catatan">{{ catatanUangMakan }}</p>

        <div class="mt-5 overflow-hidden rounded-xl border-2 border-brand-primary" data-testid="slip-ringkasan">
          <div class="flex justify-between gap-3 px-4 py-2 text-body2">
            <span>{{ LABEL_GPP_BERSIH }}</span><span class="whitespace-nowrap tabular-nums">Rp. {{ formatRupiah(slip.ringkasan.bersih_gpp) }}</span>
          </div>
          <div class="flex justify-between gap-3 px-4 py-2 text-body2">
            <span>Bersih (TK)</span><span class="whitespace-nowrap tabular-nums">Rp. {{ formatRupiah(slip.ringkasan.bersih_tk) }}</span>
          </div>
          <div class="flex justify-between gap-3 px-4 py-2 text-body2">
            <span>Uang Makan</span><span class="whitespace-nowrap tabular-nums">Rp. {{ formatRupiah(slip.ringkasan.uang_makan) }}</span>
          </div>
          <div class="flex items-baseline justify-between gap-3 bg-brand-primary px-4 py-3 font-semibold text-white">
            <span>Total Penghasilan {{ slip.label }}</span>
            <span class="whitespace-nowrap text-h5 tabular-nums" data-testid="slip-total">Rp. {{ formatRupiah(slip.ringkasan.total_penghasilan) }}</span>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>
