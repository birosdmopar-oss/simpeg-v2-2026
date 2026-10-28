<script setup lang="ts">
/**
 * Kelola Slip Gaji (D-10/D-11 jalur admin, PRD Fase 5A §7.8.1; role 1, 3) [K `admin_list.php`, `admin_import.php`]:
 * tab Monitoring (filter, ringkasan, tabel + ⋮ Edit/Lihat detail/Cetak PDF/Buka ulang) dan tab Impor (unggah →
 * pratinjau → simpan). Role 3 hanya melihat satkernya (dipaksa server); filter satker hanya untuk role 1. Tab terpilih
 * tersimpan di query `?tab=` (bukan data slip). Halaman tanpa frame Figma → pola komponen Fase 3 (AGENTS §3.6).
 */
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { Role } from '@/features/auth/types'
import { useAuthStore } from '@/features/auth/stores/auth.store'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiTabs } from '@/shared/ui'
import type { TabItem } from '@/shared/ui'

import SlipGajiImpor from '../components/SlipGajiImpor.vue'
import SlipGajiMonitoring from '../components/SlipGajiMonitoring.vue'

const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Presensi' }, { label: 'Kelola Slip Gaji' }]
const tabs: TabItem[] = [
  { value: 'monitoring', label: 'Monitoring' },
  { value: 'impor', label: 'Impor' },
]

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const tab = computed<string>({
  get: () => (route.query.tab === 'impor' ? 'impor' : 'monitoring'),
  set: (v) => void router.replace({ query: { ...route.query, tab: v === 'impor' ? 'impor' : undefined } }),
})
const bolehFilterSatker = computed(() => auth.role === Role.SUPER_ADMIN)

/** Periode hasil impor terakhir → Monitoring dibuka pada periode itu [K tombol "Lihat Rekap" `admin_import.php:180`]. */
const periodeImpor = ref<string | undefined>(undefined)
const kunciMonitoring = ref(0)

function onImporSelesai(periode: string | null): void {
  if (!periode) return
  periodeImpor.value = periode
  kunciMonitoring.value += 1
}
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <!-- Hanya tab Monitoring (tabel) yang mengisi tinggi; tab Impor (formulir) tetap menggulir normal (CR-069). -->
    <div :class="tab === 'monitoring' ? 'page-fill flex flex-col gap-5' : 'space-y-5'" data-testid="slip-gaji-kelola-page">
      <div>
        <h1 class="text-h2 font-semibold text-slate-900">Kelola Slip Gaji</h1>
        <p class="text-body2 text-slate-500">
          {{ auth.role === Role.ADMIN_SATKER ? 'Monitor dan kelola slip gaji pegawai di satker Anda.' : 'Monitor dan kelola slip gaji seluruh pegawai.' }}
        </p>
      </div>

      <UiTabs v-model="tab" :items="tabs" aria-label="Kelola Slip Gaji" data-testid="slip-kelola-tabs" />

      <SlipGajiMonitoring v-if="tab === 'monitoring'" :key="kunciMonitoring" :boleh-filter-satker="bolehFilterSatker" :awal="periodeImpor" />
      <SlipGajiImpor v-else @selesai="onImporSelesai" />
    </div>
  </RedesignShell>
</template>
