<script setup lang="ts">
/**
 * Badge status master (G-TC #7), status legacy: Aktif = hijau, Tidak Aktif = abu, Dihapus = merah.
 */
import { computed } from 'vue'

import { MASTER_STATUS_LABELS, type MasterStatus } from '../types'

const props = defineProps<{ status: MasterStatus | number }>()

const value = computed<MasterStatus>(() => {
  const s = String(props.status)
  return s === '2' || s === '10' ? s : '1'
})

const CLASSES: Record<MasterStatus, string> = {
  '1': 'bg-green-100 text-green-800',
  '2': 'bg-slate-200 text-slate-600',
  '10': 'bg-red-100 text-red-700',
}
</script>

<template>
  <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="CLASSES[value]" :data-status="value">
    {{ MASTER_STATUS_LABELS[value] }}
  </span>
</template>
