<script setup lang="ts">
/**
 * Kartu "Butuh Approval" beranda (F-13, kontrak Jalur C §7): angka `ringkasan.butuh_approval` dari `useRingkasanNotifikasi()`;
 * "—" bila ringkasan belum/gagal dimuat. Dipasang hanya untuk `BUTUH_APPROVAL_ROLES` (pemanggil yang menyaring). Tautan ke
 * halaman Butuh Approval Jalur C hanya bila route-nya sudah terdaftar (F-08 di `main`).
 */
import { ClipboardCheck } from 'lucide-vue-next'
import { computed } from 'vue'
import { useRouter } from 'vue-router'

import { UiStatTile } from '@/shared/ui'

import { BUTUH_APPROVAL_ROUTE, useRingkasanNotifikasi } from '../composables/ringkasanNotifikasi'

const { ringkasan } = useRingkasanNotifikasi()
const router = useRouter()

const nilai = computed(() => (ringkasan.value ? new Intl.NumberFormat('id-ID').format(ringkasan.value.butuh_approval) : '—'))
const adaHalaman = computed(() => router.hasRoute(BUTUH_APPROVAL_ROUTE.name))
</script>

<template>
  <div data-testid="beranda-butuh-approval">
    <RouterLink
      v-if="adaHalaman"
      :to="BUTUH_APPROVAL_ROUTE"
      class="block rounded-card focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-tertiary"
      :aria-label="`Butuh Approval: ${nilai} — buka daftar`"
    >
      <UiStatTile label="Butuh Approval" :value="nilai" :icon="ClipboardCheck" tone="success" />
    </RouterLink>
    <UiStatTile v-else label="Butuh Approval" :value="nilai" :icon="ClipboardCheck" tone="success" />
  </div>
</template>
