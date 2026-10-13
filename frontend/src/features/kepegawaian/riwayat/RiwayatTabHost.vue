<script setup lang="ts">
/**
 * Penampung satu tab riwayat Detail Pegawai (props DIBEKUKAN Sprint 0-2: `nip`, `descriptor`, `config`; pemilik WS-1).
 * Tab engine → RiwayatListSection (data `riwayat/{jenis}`); tab non-engine (mis. `lkh`, milik WS-2) → keterangan
 * "belum tersedia" sampai halamannya dibangun pemiliknya.
 */
import { UiCard } from '@/shared/ui'

import type { RiwayatTabDescriptor } from '../types'

import RiwayatListSection from './RiwayatListSection.vue'
import type { RiwayatJenisConfig } from './riwayat.config'

defineProps<{ nip: string; descriptor: RiwayatTabDescriptor; config: RiwayatJenisConfig }>()
</script>

<template>
  <RiwayatListSection v-if="config.engine" :nip="nip" :config="config" :descriptor="descriptor" />
  <UiCard v-else :title="descriptor.label" :subtitle="config.subtitle" :data-testid="`riwayat-section-${config.jenis}`">
    <p class="text-body2 text-slate-600" data-testid="riwayat-belum-tersedia">Data {{ descriptor.label }} belum tersedia.</p>
  </UiCard>
</template>
