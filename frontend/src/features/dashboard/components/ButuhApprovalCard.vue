<script setup lang="ts">
/**
 * Kartu "Butuh Approval" beranda (F-13, kontrak Jalur C §7 dari `@/features/notifikasi`): angka `ringkasan.butuh_approval`
 * dari `useRingkasanNotifikasi()` (singleton bersama lonceng topbar), bertautan ke Pusat Notifikasi tab Butuh Approval.
 * "—" bila ringkasan belum/gagal dimuat (`null`). Dipasang hanya untuk `BUTUH_APPROVAL_ROLES` (pemanggil yang menyaring).
 * Ikut aturan lonceng Jalur C: selama `ACTIVE_PHASE < FASE_LONCENG` kartu tampil "—" tanpa tautan dan tanpa polling.
 */
import { ClipboardCheck } from 'lucide-vue-next'
import { computed } from 'vue'

import { FASE_LONCENG, NOTIFIKASI_ROUTE, useRingkasanNotifikasi } from '@/features/notifikasi'
import { ACTIVE_PHASE } from '@/shared/layouts/nav.config'
import { UiStatTile } from '@/shared/ui'

const aktif = ACTIVE_PHASE >= FASE_LONCENG
const sumber = aktif ? useRingkasanNotifikasi() : null
const tujuan = { name: NOTIFIKASI_ROUTE, query: { tab: 'butuh-approval' } } as const

const nilai = computed(() => {
  const r = sumber?.ringkasan.value
  return r ? new Intl.NumberFormat('id-ID').format(r.butuh_approval) : '—'
})
</script>

<template>
  <div data-testid="beranda-butuh-approval">
    <RouterLink
      v-if="aktif"
      :to="tujuan"
      class="block rounded-card focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-tertiary"
      :aria-label="`Butuh Approval: ${nilai} — buka daftar`"
    >
      <UiStatTile label="Butuh Approval" :value="nilai" :icon="ClipboardCheck" tone="success" />
    </RouterLink>
    <UiStatTile v-else label="Butuh Approval" :value="nilai" :icon="ClipboardCheck" tone="success" />
  </div>
</template>
