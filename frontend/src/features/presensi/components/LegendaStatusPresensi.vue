<script setup lang="ts">
/**
 * Legenda color-coding status presensi (PRD 5C §7.2), dua bagian dari satu sumber (`statusPresensi.ts`) supaya selalu
 * sama dengan tabel: "Warna baris" ([K] latar baris tabel harian laporan per pegawai, ikut legacy) dan "Kode" ([V2] chip
 * kejadian TL/PSW/TK/…). `ringkas` hanya menampilkan "Kode" (rekap yang tidak memuat status harian).
 */
import { legendaBaris, legendaKode, NADA_STATUS } from '../statusPresensi'

withDefaults(defineProps<{ ringkas?: boolean }>(), { ringkas: false })

const baris = legendaBaris()
const kode = legendaKode()
</script>

<template>
  <section class="flex flex-col gap-4" aria-label="Legenda status presensi" data-testid="legenda-status-presensi">
    <div v-if="!ringkas" data-testid="legenda-warna-baris">
      <h3 class="mb-2 text-body2 font-semibold text-slate-700">Warna baris</h3>
      <ul class="grid gap-x-6 gap-y-1.5 sm:grid-cols-2 xl:grid-cols-3">
        <li v-for="e in baris" :key="e.kunci" class="flex items-center gap-2 text-body2 text-slate-600" :data-kode="e.kunci">
          <span class="inline-flex min-w-[3.25rem] justify-center whitespace-nowrap rounded-full px-2 py-0.5 text-caption font-semibold" :class="NADA_STATUS[e.nada].chip">
            {{ e.kode }}
          </span>
          <span>{{ e.label }}</span>
        </li>
      </ul>
    </div>
    <div data-testid="legenda-kode">
      <h3 class="mb-2 text-body2 font-semibold text-slate-700">Kode</h3>
      <ul class="grid gap-x-6 gap-y-1.5 sm:grid-cols-2 xl:grid-cols-3">
        <li v-for="e in kode" :key="e.kunci" class="flex items-center gap-2 text-body2 text-slate-600" :data-kode="e.kunci">
          <span class="inline-flex min-w-[3.25rem] justify-center whitespace-nowrap rounded-full px-2 py-0.5 text-caption font-semibold" :class="NADA_STATUS[e.nada].chip">
            {{ e.kode }}
          </span>
          <span>{{ e.label }}</span>
        </li>
      </ul>
    </div>
  </section>
</template>
