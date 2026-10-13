<script setup lang="ts">
/**
 * Halaman riwayat pegawai SEMENTARA (WS-1 M3 MAKE-006) — dipakai sampai `GET pegawai/{nip}` (detail + `data.tabs`,
 * MAKE-010 WS-2) ada di `main`. Disetujui reviewer CR 12-10-2026.
 *
 * Tab riwayat dari `GET pegawai/{nip}/riwayat` (`{ tabs }` = RiwayatRegistry::descriptorUntuk, sumber yang sama dengan
 * `data.tabs` Detail Pegawai), dirender dengan komponen yang sama dengan Detail Pegawai (RiwayatMenuTabs +
 * RiwayatTabHost). NIP dari route; tidak memuat biodata.
 *
 * TODO(MAKE-010): setelah Detail Pegawai live, alihkan route ini ke Detail Pegawai (tab yang sama) atau hapus
 * halaman ini — oleh WS-1 di milestone berjalan (catatan progres B-09).
 */
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiButton, UiCard, UiNotice } from '@/shared/ui'

import RiwayatMenuTabs from '../components/RiwayatMenuTabs.vue'
import RiwayatTabHost from '../riwayat/RiwayatTabHost.vue'
import { tabsFromDescriptors } from '../riwayat/registry'
import { riwayatService } from '../riwayat/riwayat.service'
import { describeApiError, type ApiFailure } from '../services/apiErrors'
import type { RiwayatMenu, RiwayatTabDescriptor } from '../types'

const route = useRoute()
const router = useRouter()

const nip = computed(() => String(route.params.nip ?? ''))
const descriptors = ref<RiwayatTabDescriptor[]>([])
const loading = ref(true)
const failure = ref<ApiFailure | null>(null)

const tabs = computed(() => tabsFromDescriptors(descriptors.value))
const menus = computed<RiwayatMenu[]>(() => tabs.value.map((t) => ({ key: t.descriptor.jenis, label: t.descriptor.label })))

const tab = computed<string>({
  get: () => {
    const q = String(route.query.tab ?? '')
    return menus.value.some((m) => m.key === q) ? q : (menus.value[0]?.key ?? '')
  },
  set: (key) => void router.replace({ query: { ...route.query, tab: key } }),
})
const activeTab = computed(() => tabs.value.find((t) => t.descriptor.jenis === tab.value) ?? null)

let requestId = 0
async function load(): Promise<void> {
  const current = ++requestId
  loading.value = true
  failure.value = null
  try {
    const result = await riwayatService.tabs(nip.value)
    if (current !== requestId) return
    descriptors.value = result
  } catch (error) {
    if (current !== requestId) return
    descriptors.value = []
    failure.value = describeApiError(error)
  } finally {
    if (current === requestId) loading.value = false
  }
}
watch(nip, load, { immediate: true })

const failureTitle = computed(() => {
  switch (failure.value?.kind) {
    case 'not-found':
      return 'Pegawai tidak ditemukan'
    case 'forbidden':
      return 'Akses ditolak'
    default:
      return 'Riwayat pegawai gagal dimuat'
  }
})

const crumbs = computed(() => [{ label: 'Home', to: { name: 'home' } }, { label: 'Riwayat Pegawai' }])
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="space-y-4" data-testid="riwayat-pegawai-page">
      <UiCard title="Riwayat Pegawai" :subtitle="`NIP ${nip}`">
        <p class="text-body2 text-slate-500">Tampilan sementara riwayat pegawai sampai halaman Detail Pegawai tersedia.</p>
      </UiCard>

      <div v-if="loading" class="h-40 animate-pulse rounded-card bg-white/70" aria-busy="true" aria-label="Memuat riwayat pegawai" />

      <UiCard v-else-if="failure" :title="failureTitle" data-testid="riwayat-pegawai-failure" :data-kind="failure.kind">
        <p class="text-body1 text-slate-600">{{ failure.message }}</p>
        <UiButton v-if="failure.kind === 'other' || failure.kind === 'network'" class="mt-4" data-testid="riwayat-pegawai-retry" @click="load">Coba lagi</UiButton>
      </UiCard>

      <UiNotice v-else-if="menus.length === 0" tone="info" data-testid="riwayat-pegawai-kosong">Tidak ada riwayat yang dapat Anda lihat untuk pegawai ini.</UiNotice>

      <template v-else>
        <RiwayatMenuTabs v-model="tab" :menus="menus" />
        <RiwayatTabHost
          v-if="activeTab"
          :key="`${nip}-${activeTab.descriptor.jenis}`"
          :nip="nip"
          :descriptor="activeTab.descriptor"
          :config="activeTab.config"
        />
      </template>
    </div>
  </RedesignShell>
</template>
