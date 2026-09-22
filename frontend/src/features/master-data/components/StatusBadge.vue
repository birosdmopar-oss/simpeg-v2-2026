<script setup lang="ts">
/**
 * Badge status master (G-TC #7): Aktif = hijau, Non-aktif = abu.
 */
import type { MasterStatus } from '../types'

defineProps<{ status: MasterStatus }>()
</script>

<template>
  <span
    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
    :class="status === '1' ? 'bg-green-100 text-green-800' : 'bg-slate-200 text-slate-600'"
    :data-status="status"
  >
    {{ status === '1' ? 'Aktif' : 'Non-aktif' }}
  </span>
</template>
