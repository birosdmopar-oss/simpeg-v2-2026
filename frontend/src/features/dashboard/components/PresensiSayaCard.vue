<script setup lang="ts">
/**
 * Presensi hari ini di beranda pegawai (Q7-42). Fase 5 tidak punya komponen kartu presensi terpisah (presensi menyatu di
 * `features/presensi/views/PresensiPage.vue` beserta geolokasi), dan modul itu tidak boleh diubah jalur lain (PRD v2.0 §3);
 * sesuai arahan reviewer 10-11-2026 dipakai tautan ke halaman "Presensi Saya" (route `presensi-saya`, role 2/6/7).
 */
import { Fingerprint } from 'lucide-vue-next'

import { UiStatTile } from '@/shared/ui'
</script>

<template>
  <RouterLink
    :to="{ name: 'presensi-saya' }"
    class="block rounded-card focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-tertiary"
    aria-label="Presensi Saya — rekam masuk atau pulang"
    data-testid="beranda-presensi-saya"
  >
    <UiStatTile label="Rekam masuk / pulang hari ini" value="Presensi Saya" :icon="Fingerprint" tone="info" />
  </RouterLink>
</template>
