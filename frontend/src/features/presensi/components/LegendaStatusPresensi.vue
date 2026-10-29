<script setup lang="ts">
/**
 * Legenda color-coding status presensi (PRD 5C §7.2): kode → arti, urut dari yang terberat. Daftar dari satu sumber
 * (`statusPresensi.ts`) supaya selalu sama dengan chip di tabel. `ringkas` menampilkan hanya kode kejadian potongan
 * (untuk rekap yang tidak memuat status harian).
 */
import { computed } from 'vue'

import { entriLegenda, NADA_STATUS } from '../statusPresensi'

const props = withDefaults(defineProps<{ ringkas?: boolean }>(), { ringkas: false })

const entri = computed(() => (props.ringkas ? entriLegenda().filter((e) => /^(TK|TPM|TPP|CS|TL\d|PSW\d)$/.test(e.kunci)) : entriLegenda()))
</script>

<template>
  <section aria-label="Legenda status presensi" data-testid="legenda-status-presensi">
    <h3 class="mb-2 text-body2 font-semibold text-slate-700">Keterangan kode</h3>
    <ul class="grid gap-x-6 gap-y-1.5 sm:grid-cols-2 xl:grid-cols-3">
      <li v-for="e in entri" :key="e.kunci" class="flex items-center gap-2 text-body2 text-slate-600" :data-kode="e.kunci">
        <span class="inline-flex min-w-[3.25rem] justify-center whitespace-nowrap rounded-full px-2 py-0.5 text-caption font-semibold" :class="NADA_STATUS[e.nada].chip">
          {{ e.kode }}
        </span>
        <span>{{ e.label }}</span>
      </li>
    </ul>
  </section>
</template>
