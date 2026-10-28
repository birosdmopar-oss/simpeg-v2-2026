<script setup lang="ts">
/**
 * Badge status Modul C (cuti, TB/IB, PMK). Label dan nada berasal dari server (`label_status`, `nada_status`, PRD
 * §6.11.4); komponen hanya menampilkannya satu baris dengan teks lengkap di tooltip custom (bukan atribut `title`).
 * JANGAN diganti `StatusBadge`/`statusRiwayat.ts` — arti kode status berbeda.
 */
import { UiBadge, UiTooltip } from '@/shared/ui'

import type { NadaStatus } from '../types'

withDefaults(defineProps<{ label: string; nada?: NadaStatus }>(), { nada: 'secondary' })
</script>

<template>
  <UiTooltip :content="label">
    <UiBadge :tone="nada" dot class="max-w-full" :data-nada="nada" data-testid="status-layanan-badge">{{ label }}</UiBadge>
  </UiTooltip>
</template>
