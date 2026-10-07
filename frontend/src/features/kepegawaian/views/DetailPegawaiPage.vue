<script setup lang="ts">
/**
 * Halaman Detail Pegawai (§4.1.6) — Fase 3, B-20 (pemilik WS-2). Data dari `GET pegawai/{nip}`.
 *
 * Tab: "Data Umum" (biodata, hanya-lihat sampai B-03/B-04) lalu tab riwayat dari DESCRIPTOR backend (`data.tabs`,
 * urutan backend) yang punya berkas registry `riwayat/jenis/<slug>.ts` dan `can_view = true`. Tombol tambah/ubah/
 * hapus/proses di setiap tab mengikuti `can_*` descriptor (RiwayatTabHost → RiwayatListSection).
 *
 * Galat: 403 (izin/lingkup — termasuk NIP yang tidak ada bagi role ber-lingkup), 404, 501 (stub) ditampilkan sebagai
 * keadaan halaman, tidak pernah sebagai data contoh. Cetak, arsip, dan hapus pegawai belum tersambung (B-18/B-20/B-05).
 */
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role } from '@/features/auth/types'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiButton, UiCard, UiNotice } from '@/shared/ui'

import DataUmumView from '../components/DataUmumView.vue'
import PegawaiHeaderCard from '../components/PegawaiHeaderCard.vue'
import RiwayatMenuTabs from '../components/RiwayatMenuTabs.vue'
import RiwayatTabHost from '../riwayat/RiwayatTabHost.vue'
import { tabsFromDescriptors } from '../riwayat/registry'
import { describeApiError, type ApiFailure } from '../services/apiErrors'
import { pegawaiService } from '../services/pegawai.service'
import type { PegawaiDetail, RiwayatMenu } from '../types'

const DATA_UMUM = 'data-umum'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const nip = computed(() => String(route.params.nip ?? ''))
const detail = ref<PegawaiDetail | null>(null)
const loading = ref(true)
const failure = ref<ApiFailure | null>(null)
const notice = ref<string | null>(null)
const confirmDelete = ref(false)

const role = computed(() => auth.role)
const canPrint = computed(() => role.value !== null && [Role.SUPER_ADMIN, Role.PEGAWAI, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI].includes(role.value as 1 | 2 | 3 | 4 | 5))
const canDelete = computed(() => role.value === Role.SUPER_ADMIN)

const riwayatTabs = computed(() => tabsFromDescriptors(detail.value?.tabs ?? []))
const menus = computed<RiwayatMenu[]>(() => [
  { key: DATA_UMUM, label: 'Data Umum' },
  ...riwayatTabs.value.map((t) => ({ key: t.descriptor.jenis, label: t.descriptor.label })),
])

const tab = computed<string>({
  get: () => {
    const q = String(route.query.tab ?? '')
    return menus.value.some((m) => m.key === q) ? q : DATA_UMUM
  },
  set: (key) => void router.replace({ query: { ...route.query, tab: key } }),
})
const activeTab = computed(() => riwayatTabs.value.find((t) => t.descriptor.jenis === tab.value) ?? null)

let requestId = 0
async function load(): Promise<void> {
  const current = ++requestId
  loading.value = true
  failure.value = null
  try {
    const result = await pegawaiService.detail(nip.value)
    if (current !== requestId) return
    detail.value = result
  } catch (error) {
    if (current !== requestId) return
    detail.value = null
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
    case 'unavailable':
      return 'Data pegawai belum tersedia'
    default:
      return 'Data pegawai gagal dimuat'
  }
})

const crumbs = computed(() => [
  { label: 'Home', to: { name: 'home' } },
  { label: 'Daftar Pegawai', to: { name: 'pegawai-list' } },
  { label: 'Data Pegawai' },
])
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="space-y-4">
      <UiNotice v-if="notice" tone="info" dismissible @dismiss="notice = null">{{ notice }}</UiNotice>

      <div v-if="loading" class="h-40 animate-pulse rounded-card bg-white/70" aria-busy="true" aria-label="Memuat data pegawai" />

      <UiCard v-else-if="failure || !detail" :title="failureTitle" data-testid="pegawai-failure" :data-kind="failure?.kind">
        <p class="text-body1 text-slate-600">{{ failure?.message }}</p>
        <div class="mt-4 flex flex-wrap gap-2">
          <UiButton v-if="failure?.kind === 'other' || failure?.kind === 'network'" variant="primary" data-testid="pegawai-retry" @click="load">Coba lagi</UiButton>
          <UiButton variant="primary" appearance="soft" :to="{ name: 'pegawai-list' }">Kembali ke Daftar Pegawai</UiButton>
        </div>
      </UiCard>

      <template v-else>
        <PegawaiHeaderCard
          :detail="detail"
          :can-print="canPrint"
          :can-delete="canDelete"
          @arsip="notice = 'Arsip Kepegawaian belum tersedia (task B-18).'"
          @print="(kind) => (notice = `Cetak ${kind === 'drh' ? 'DRH' : 'Data Umum'} belum tersedia (task B-20).`)"
          @delete="confirmDelete = true"
        />

        <RiwayatMenuTabs v-model="tab" :menus="menus" />

        <UiCard v-if="tab === DATA_UMUM" title="Data Umum" subtitle="Biodata pegawai" flush>
          <div class="mt-3 border-t border-slate-200">
            <DataUmumView :pegawai="detail" />
          </div>
        </UiCard>

        <RiwayatTabHost
          v-else-if="activeTab"
          :key="`${detail.nip}-${activeTab.descriptor.jenis}`"
          :nip="detail.nip"
          :descriptor="activeTab.descriptor"
          :config="activeTab.config"
        />
      </template>
    </div>

    <ConfirmDialog
      :open="confirmDelete"
      title="Hapus pegawai?"
      :description="detail ? `Data ${detail.nama} (${detail.nip}) akan dihapus.` : ''"
      confirm-label="Ya, hapus"
      danger
      @update:open="(v) => (confirmDelete = v)"
      @confirm="() => { confirmDelete = false; notice = 'Penghapusan pegawai belum tersedia (task B-05).' }"
    />
  </RedesignShell>
</template>
