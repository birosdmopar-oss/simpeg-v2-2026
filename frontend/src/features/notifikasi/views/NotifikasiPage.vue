<script setup lang="ts">
/**
 * Pusat Notifikasi F-08 (MAKE-085): satu halaman bertab, tab per role ikut legacy (Q7-29) —
 * "Notifikasi" (2, 3, 6, 7), "Butuh Approval" dan "Histori Approval" (1, 2, 3). Tab aktif di `?tab=`.
 * Tanpa frame Figma: mengikuti pola halaman daftar lain (AGENTS.md §3.6, dicatat di README fitur).
 */
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiCard, UiTabs, type TabItem } from '@/shared/ui'

import ButuhApprovalTab from '../components/ButuhApprovalTab.vue'
import DaftarNotifikasiTab from '../components/DaftarNotifikasiTab.vue'
import HistoriApprovalTab from '../components/HistoriApprovalTab.vue'
import { useRingkasanNotifikasi } from '../composables/useRingkasanNotifikasi'
import { NOTIFIKASI_APPROVAL_ROLES, NOTIFIKASI_DAFTAR_ROLES, NOTIFIKASI_HAPUS_ROLES } from '../roles'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const { ringkasan } = useRingkasanNotifikasi()

const crumbs = [{ label: 'Beranda', to: { name: 'home' } }, { label: 'Notifikasi' }]

const role = computed(() => auth.role)
const punya = (daftar: readonly number[]) => role.value !== null && daftar.includes(role.value)

const tabs = computed<TabItem[]>(() => [
  ...(punya(NOTIFIKASI_DAFTAR_ROLES)
    ? [{ value: 'notifikasi', label: 'Notifikasi', badge: ringkasan.value?.notifikasi_belum_dibaca || undefined }]
    : []),
  ...(punya(NOTIFIKASI_APPROVAL_ROLES)
    ? [
        { value: 'butuh-approval', label: 'Butuh Approval', badge: ringkasan.value?.butuh_approval || undefined },
        { value: 'histori-approval', label: 'Histori Approval' },
      ]
    : []),
])

const tab = computed<string>({
  get: () => {
    const q = String(route.query.tab ?? '')
    return tabs.value.some((t) => t.value === q) ? q : (tabs.value[0]?.value ?? '')
  },
  set: (v) => void router.replace({ query: { ...route.query, tab: v } }),
})

const bolehHapus = computed(() => punya(NOTIFIKASI_HAPUS_ROLES))
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <UiCard title="Notifikasi" class="page-fill" fill data-testid="notifikasi-page">
      <UiTabs v-if="tabs.length > 1" v-model="tab" :items="tabs" aria-label="Pusat notifikasi" class="mb-4" data-testid="notifikasi-tabs" />

      <DaftarNotifikasiTab v-if="tab === 'notifikasi'" :boleh-hapus="bolehHapus" />
      <ButuhApprovalTab v-else-if="tab === 'butuh-approval'" />
      <HistoriApprovalTab v-else-if="tab === 'histori-approval'" />
    </UiCard>
  </RedesignShell>
</template>
