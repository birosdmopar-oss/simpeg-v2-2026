<script setup lang="ts">
/**
 * Chip status satu hari presensi (PRD 5C §7.2, AC-FE5C.3). Satu chip per kode kejadian (TL2, PSW1, TPM, …) atau satu
 * chip dari `status` bila hari itu tanpa kejadian. Teks kode SELALU tampil (warna tidak berdiri sendiri); label lengkap
 * ada di `aria-label` dan di legenda. Tidak memakai atribut `title` (AGENTS.md §3).
 */
import { computed } from 'vue'

import { chipHarian, NADA_STATUS } from '../statusPresensi'

const props = defineProps<{
  /** `harian[].status` (mis. `hadir`, `cuti`, `TPM`). */
  status: string
  /** `harian[].kode` (CR-075); kosong/absen = tidak ada kejadian. */
  kode?: readonly string[] | null
}>()

const chip = computed(() => chipHarian(props.status, props.kode))
</script>

<template>
  <span v-if="chip.length > 0" class="inline-flex flex-wrap items-center gap-1" data-testid="status-presensi-chip">
    <span
      v-for="c in chip"
      :key="c.kunci"
      class="inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-caption font-semibold"
      :class="NADA_STATUS[c.nada].chip"
      :data-kode="c.kunci"
      :aria-label="c.label"
      role="img"
    >
      {{ c.kode }}
    </span>
  </span>
</template>
