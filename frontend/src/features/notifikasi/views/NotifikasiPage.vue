<script setup lang="ts">
/**
 * Pusat Notifikasi F-08 (MAKE-085): satu halaman bertab, tab per role ikut legacy (Q7-29) —
 * "Notifikasi" (2, 3, 6, 7), "Butuh Approval" dan "Histori Approval" (1, 2, 3). Tab aktif di `?tab=`.
 * Tanpa frame Figma: mengikuti pola halaman daftar lain (AGENTS.md §3.6, dicatat di README fitur).
 * Di atas tab: entri "Tiket Halo Simpeg (n)" menuju helpdesk (H8-15 (b), MAKE-073).
 */
import { MessageCircle } from 'lucide-vue-next'
import { computed } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { tujuanEntriTiket } from '@/features/halo-simpeg/entriTiket'
import { haloState } from '@/features/halo-simpeg/stores/haloSimpeg'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiCard, UiTabs, type TabItem } from '@/shared/ui'

import ButuhApprovalTab from '../components/ButuhApprovalTab.vue'
import DaftarNotifikasiTab from '../components/DaftarNotifikasiTab.vue'
import HistoriApprovalTab from '../components/HistoriApprovalTab.vue'
import { useRingkasanNotifikasi } from '../composables/useRingkasanNotifikasi'
import { NOTIFIKASI_APPROVAL_ROLES, NOTIFIKASI_DAFTAR_ROLES, NOTIFIKASI_HAPUS_ROLES, NOTIFIKASI_ROLES } from '../roles'

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

/**
 * Entri "Tiket Halo Simpeg (n)" (✔ H8-15 (b), MAKE-073): angka = `tiket_belum_dibaca` ringkasan lonceng; tampil hanya bila
 * > 0 (null = sumber belum aktif) dan role ∈ NOTIFIKASI_ROLES. Bukan tab baru: tab per role (Q7-29) tetap.
 */
const jumlahTiket = computed(() => (punya(NOTIFIKASI_ROLES) ? (ringkasan.value?.tiket_belum_dibaca ?? 0) : 0))
const tujuanTiket = computed(() => tujuanEntriTiket(haloState.ringkasan))
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <UiCard title="Notifikasi" class="page-fill" fill data-testid="notifikasi-page">
      <RouterLink
        v-if="jumlahTiket > 0"
        :to="tujuanTiket"
        class="mb-4 flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-body2 font-medium text-slate-700 transition-colors hover:border-brand-tertiary hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-tertiary"
        data-testid="notifikasi-entri-tiket"
      >
        <MessageCircle class="h-5 w-5 shrink-0 text-brand-primary" aria-hidden="true" />
        <span class="flex-1">Tiket Halo Simpeg ({{ jumlahTiket }})</span>
      </RouterLink>
      <UiTabs v-if="tabs.length > 1" v-model="tab" :items="tabs" aria-label="Pusat notifikasi" class="mb-4" data-testid="notifikasi-tabs" />

      <DaftarNotifikasiTab v-if="tab === 'notifikasi'" :boleh-hapus="bolehHapus" />
      <ButuhApprovalTab v-else-if="tab === 'butuh-approval'" />
      <HistoriApprovalTab v-else-if="tab === 'histori-approval'" />
    </UiCard>
  </RedesignShell>
</template>
