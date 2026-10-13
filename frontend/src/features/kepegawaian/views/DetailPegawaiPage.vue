<script setup lang="ts">
/**
 * Halaman Detail Pegawai (§4.1.6) — Fase 3, B-20 (pemilik WS-2). Data dari `GET pegawai/{nip}`.
 *
 * Tab: "Data Umum" (biodata; tombol "Ubah Data Umum" bila `aksi.ubah_biodata`, form DataUmumForm B-03 MAKE-010:
 * Super Admin langsung, role lain lewat pengajuan + status pengajuan dari `pengajuan`) lalu tab riwayat dari DESCRIPTOR
 * backend (`data.tabs`, urutan backend) yang punya berkas registry `riwayat/jenis/<slug>.ts` dan `can_view = true`.
 * Tombol tambah/ubah/hapus/proses di setiap tab mengikuti `can_*` descriptor (RiwayatTabHost → RiwayatListSection).
 * Koreksi NIP (B-06, `aksi.koreksi_nip`) lewat menu ⋯ kartu identitas; sukses → pindah ke URL NIP baru.
 *
 * Galat: 403 (izin/lingkup — termasuk NIP yang tidak ada bagi role ber-lingkup), 404, 501 (stub) ditampilkan sebagai
 * keadaan halaman, tidak pernah sebagai data contoh. Cetak, arsip, dan hapus pegawai belum tersambung (B-20/B-05).
 */
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role } from '@/features/auth/types'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiButton, UiCard, UiNotice } from '@/shared/ui'

import DataUmumForm from '../components/DataUmumForm.vue'
import DataUmumView from '../components/DataUmumView.vue'
import KoreksiNipDialog from '../components/KoreksiNipDialog.vue'
import PegawaiHeaderCard from '../components/PegawaiHeaderCard.vue'
import RiwayatMenuTabs from '../components/RiwayatMenuTabs.vue'
import RiwayatTabHost from '../riwayat/RiwayatTabHost.vue'
import { tabsFromDescriptors } from '../riwayat/registry'
import { describeApiError, type ApiFailure } from '../services/apiErrors'
import { pegawaiService } from '../services/pegawai.service'
import type { BiodataResult, KoreksiNipResult, PegawaiDetail, RiwayatMenu } from '../types'

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
const editing = ref(false)
const koreksiOpen = ref(false)

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
watch(nip, () => {
  editing.value = false
  void load()
}, { immediate: true })

const pengajuan = computed(() => detail.value?.pengajuan ?? null)

function onSaved(result: BiodataResult): void {
  editing.value = false
  notice.value = result.jalur === 'langsung'
    ? 'Data umum pegawai berhasil disimpan.'
    : 'Perubahan data umum berhasil diajukan dan menunggu verifikasi admin.'
  void load()
}

function onKoreksi(result: KoreksiNipResult): void {
  notice.value = `NIP berhasil dikoreksi dari ${result.nip_lama} menjadi ${result.nip_baru}.`
  void router.replace({ name: 'pegawai-detail', params: { nip: result.nip_baru }, query: route.query })
}

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
          :can-koreksi-nip="detail.aksi?.koreksi_nip ?? false"
          @koreksi-nip="koreksiOpen = true"
          @arsip="notice = 'Arsip Kepegawaian belum tersedia (task B-18).'"
          @print="(kind) => (notice = `Cetak ${kind === 'drh' ? 'DRH' : 'Data Umum'} belum tersedia (task B-20).`)"
          @delete="confirmDelete = true"
        />

        <RiwayatMenuTabs v-model="tab" :menus="menus" />

        <UiCard v-if="tab === DATA_UMUM" title="Data Umum" subtitle="Biodata pegawai" flush>
          <template v-if="detail.aksi?.ubah_biodata && !editing" #actions>
            <UiButton data-testid="btn-ubah-data-umum" @click="editing = true">Ubah Data Umum</UiButton>
          </template>
          <div class="mt-3 border-t border-slate-200">
            <UiNotice v-if="pengajuan?.flag_update === 1" tone="info" class="mx-5 mt-4" data-testid="pengajuan-status">
              Pengajuan perubahan data umum sedang menunggu verifikasi admin.
            </UiNotice>
            <UiNotice v-else-if="pengajuan?.flag_update === 2" tone="danger" class="mx-5 mt-4" data-testid="pengajuan-status">
              Pengajuan perubahan data umum ditolak{{ pengajuan.reason_note ? `: ${pengajuan.reason_note}` : '.' }}
            </UiNotice>
            <div v-if="editing" class="px-5 py-5">
              <DataUmumForm :detail="detail" @saved="onSaved" @cancel="editing = false" />
            </div>
            <DataUmumView v-else :pegawai="detail" />
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

    <KoreksiNipDialog
      v-if="detail"
      v-model:open="koreksiOpen"
      :nip="detail.nip"
      :nama="detail.nama"
      @done="onKoreksi"
    />

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
