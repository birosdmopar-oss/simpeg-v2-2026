<script setup lang="ts">
/** Badge status chatbot (F8-25/F8-38/F8-49): 1 Aktif, 2 Tidak Aktif, 10 Dihapus. Kolom Status hanya badge (AGENTS.md §1.5). */
import { computed } from 'vue'

import { UiBadge } from '@/shared/ui'

import type { StatusChatbot } from '../../types'

const props = defineProps<{ status: StatusChatbot }>()

const TAMPILAN: Record<StatusChatbot, { label: string; tone: 'success' | 'neutral' | 'danger' }> = {
  1: { label: 'Aktif', tone: 'success' },
  2: { label: 'Tidak Aktif', tone: 'neutral' },
  10: { label: 'Dihapus', tone: 'danger' },
}

const t = computed(() => TAMPILAN[props.status])
</script>

<template>
  <UiBadge :tone="t.tone" dot :data-status="status">{{ t.label }}</UiBadge>
</template>
