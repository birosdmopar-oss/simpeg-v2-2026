<script setup lang="ts">
/**
 * Badge status verifikasi riwayat/usulan (generik, Modul B dan seterusnya): Disetujui hijau, Menunggu kuning,
 * Ditolak merah, Dihapus abu-abu. "Diproses" (3) hanya ditampilkan bila `diprosesEnabled` (flag, default nonaktif);
 * tanpa flag, status 3 ditampilkan sebagai Menunggu karena alurnya belum dibedakan.
 * Badge status master data (Aktif/Tidak Aktif) tetap memakai `features/master-data/components/StatusBadge.vue`.
 */
import { computed } from 'vue'

import { UiBadge } from '@/shared/ui'

import { STATUS_RIWAYAT, STATUS_RIWAYAT_LABELS, type StatusRiwayat } from './statusRiwayat'

const props = withDefaults(defineProps<{ status: StatusRiwayat | number; diprosesEnabled?: boolean }>(), {
  diprosesEnabled: false,
})

type Tone = 'success' | 'warning' | 'danger' | 'neutral' | 'info'

const TONES: Record<StatusRiwayat, Tone> = {
  0: 'warning',
  1: 'success',
  2: 'danger',
  3: 'info',
  10: 'neutral',
}

const effective = computed<StatusRiwayat | null>(() => {
  if (props.status === STATUS_RIWAYAT.DIPROSES && !props.diprosesEnabled) return STATUS_RIWAYAT.MENUNGGU
  return props.status in STATUS_RIWAYAT_LABELS ? (props.status as StatusRiwayat) : null
})

const tone = computed<Tone>(() => (effective.value === null ? 'neutral' : TONES[effective.value]))
const label = computed(() => (effective.value === null ? 'Tidak diketahui' : STATUS_RIWAYAT_LABELS[effective.value]))
</script>

<template>
  <UiBadge :tone="tone" dot :data-status="effective ?? ''" data-testid="status-badge">{{ label }}</UiBadge>
</template>
