<script setup lang="ts">
/**
 * Panel hasil kalkulator cuti (PRD §6.11.3): lama cuti, tanggal akhir (jenis Besar), hari libur dalam rentang, dan
 * kuota jenis Tahunan. SEMUA angka dari `POST cuti/hitung-lama`; komponen hanya menampilkan. Tidak mengunci tombol Simpan.
 */
import { formatTanggalIndonesia as tgl } from '@/shared/utils/dateTime'

import { UiNotice } from '@/shared/ui'

import type { HitungLamaCuti } from '../types'

defineProps<{ hasil: HitungLamaCuti | null; memuat: boolean; galatUmum: string; tampilkanTglAkhir: boolean }>()
</script>

<template>
  <div class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4" data-testid="kalkulator-cuti" aria-live="polite">
    <p v-if="memuat && !hasil" class="text-body2 text-slate-500" data-testid="kalkulator-memuat">Menghitung…</p>
    <p v-else-if="!hasil && !galatUmum" class="text-body2 text-slate-500" data-testid="kalkulator-kosong">
      Pilih jenis cuti dan tanggal untuk melihat lama cuti dan sisa kuota.
    </p>
    <UiNotice v-if="galatUmum" tone="warning" data-testid="kalkulator-galat">{{ galatUmum }}</UiNotice>

    <template v-if="hasil">
      <dl class="grid gap-3 text-body2 sm:grid-cols-2">
        <div>
          <dt class="text-slate-500">Lama Cuti</dt>
          <dd class="font-semibold text-slate-900" data-testid="kalkulator-lama">{{ hasil.lama_cuti }} {{ hasil.satuan_lama_cuti }}</dd>
        </div>
        <div v-if="tampilkanTglAkhir && hasil.tgl_akhir">
          <dt class="text-slate-500">Tanggal Akhir</dt>
          <dd class="font-semibold text-slate-900" data-testid="kalkulator-tgl-akhir">{{ tgl(hasil.tgl_akhir) }}</dd>
        </div>
      </dl>

      <div v-if="hasil.hari_libur.length" data-testid="kalkulator-libur">
        <p class="text-body2 text-slate-500">Hari libur dalam rentang</p>
        <ul class="mt-1 list-inside list-disc text-body2 text-slate-700">
          <li v-for="h in hasil.hari_libur" :key="h.tanggal">{{ tgl(h.tanggal) }} — {{ h.keterangan }}</li>
        </ul>
      </div>

      <div v-if="hasil.kuota" data-testid="kalkulator-kuota">
        <p class="mb-1 text-body2 font-medium text-slate-900">Sisa Kuota Cuti Tahunan {{ hasil.kuota.tahun }}</p>
        <dl class="grid grid-cols-3 gap-2 text-body2">
          <div><dt class="text-slate-500">N</dt><dd class="text-slate-900">{{ hasil.kuota.sisa_cuti }}</dd></div>
          <div><dt class="text-slate-500">N-1</dt><dd class="text-slate-900">{{ hasil.kuota.sisa_cuti_n1 }}</dd></div>
          <div><dt class="text-slate-500">N-2</dt><dd class="text-slate-900">{{ hasil.kuota.sisa_cuti_n2 }}</dd></div>
          <div><dt class="text-slate-500">Dalam Proses</dt><dd class="text-slate-900">{{ hasil.kuota.menunggu_tahun_ini }}</dd></div>
          <div><dt class="text-slate-500">Diajukan Sekarang</dt><dd class="text-slate-900">{{ hasil.kuota.diajukan_tahun_ini }}</dd></div>
          <div><dt class="text-slate-500">Total Saldo</dt><dd class="text-slate-900" data-testid="kalkulator-saldo">{{ hasil.kuota.saldo_tahun_ini }}</dd></div>
        </dl>
        <UiNotice v-if="!hasil.kuota.cukup" tone="warning" class="mt-2" data-testid="kalkulator-tidak-cukup">
          {{ hasil.kuota.pesan || 'Kuota cuti tidak mencukupi.' }}
        </UiNotice>
        <p v-else-if="hasil.kuota.pesan" class="mt-2 text-body2 text-slate-600" data-testid="kalkulator-pesan">{{ hasil.kuota.pesan }}</p>
      </div>
    </template>
  </div>
</template>
