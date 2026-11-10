<script setup lang="ts">
/**
 * Bawahan atasan di beranda pegawai role 2 (`views/user/dashboard_pns.php:207-232`, :331-385) [K]:
 * - Presensi bawahan hari ini: Nama, Jam Masuk, Jam Pulang (kosong bila belum presensi);
 * - AK bawahan JF (bila ada): Order, Nama, NIP, KP (Gol), Satker, Jabatan, Total AK, AK KP, Kurang AK.
 * Hanya dirender bila akun memang atasan (`bawahan.atasan`).
 */
import { UiCard } from '@/shared/ui'

import type { BawahanBeranda } from '../types'

defineProps<{ bawahan: BawahanBeranda }>()

/** Angka AK apa adanya dengan koma desimal (`decimalToUser`-gaya). */
const angka = (n: number): string => String(n).replace('.', ',')
</script>

<template>
  <div class="flex flex-col gap-4" data-testid="beranda-bawahan">
    <UiCard title="Presensi Bawahan Hari Ini" flush data-testid="beranda-bawahan-presensi">
      <div class="max-h-96 overflow-auto">
        <table class="min-w-full text-sm">
          <thead class="sticky top-0 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-5 py-3">Nama</th>
              <th class="px-5 py-3 text-center">Jam Masuk</th>
              <th class="px-5 py-3 text-center">Jam Pulang</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="(b, i) in bawahan.presensi" :key="i" :data-testid="`beranda-bawahan-presensi-${i}`">
              <td class="px-5 py-3 text-slate-800">{{ b.nama }}</td>
              <td class="px-5 py-3 text-center text-slate-600">{{ b.jam_masuk }}</td>
              <td class="px-5 py-3 text-center text-slate-600">{{ b.jam_pulang }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </UiCard>

    <UiCard v-if="bawahan.ak.length > 0" title="AK Bawahan" flush data-testid="beranda-bawahan-ak">
      <div class="max-h-96 overflow-auto">
        <table class="min-w-full text-sm">
          <thead class="sticky top-0 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-4 py-3">Order</th>
              <th class="px-4 py-3">Nama</th>
              <th class="px-4 py-3">NIP</th>
              <th class="px-4 py-3">KP (Gol)</th>
              <th class="px-4 py-3">Satker</th>
              <th class="px-4 py-3">Jabatan</th>
              <th class="px-4 py-3 text-right">Total AK</th>
              <th class="px-4 py-3 text-right">AK KP</th>
              <th class="px-4 py-3 text-right">Kurang AK</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="a in bawahan.ak" :key="a.nip" :data-testid="`beranda-bawahan-ak-${a.nip}`">
              <td class="px-4 py-3 text-slate-600">{{ angka(a.order) }}</td>
              <td class="px-4 py-3 text-slate-800">{{ a.nama }}</td>
              <td class="px-4 py-3 text-slate-600">{{ a.nip }}</td>
              <td class="px-4 py-3 text-slate-600">{{ a.gol_ruang }}</td>
              <td class="px-4 py-3 text-slate-600">{{ a.satker }}</td>
              <td class="px-4 py-3 text-slate-600">{{ a.jabatan }}</td>
              <td class="px-4 py-3 text-right text-slate-600">{{ angka(a.ak_now) }}</td>
              <td class="px-4 py-3 text-right text-slate-600">{{ angka(a.ak_kp) }}</td>
              <td class="px-4 py-3 text-right text-slate-600">{{ angka(a.kurang) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </UiCard>
  </div>
</template>
