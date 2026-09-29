<script setup lang="ts">
/**
 * Halaman Detail Pegawai (§4.1.6, Gambar 22–24) — Fase 3, B-20. Akses detail: semua role login (hr/employee/detail).
 * Hak ubah biodata: role 1 & 3; role 2/6/7 hanya untuk NIP-nya sendiri; role lain hanya melihat.
 * Cetak: role 1,2,3,4,5. Hapus: role 1.
 *
 * DATA CONTOH: memakai mock sampai B-03/B-20 tersedia. "Simpan Perubahan", cetak, arsip, dan hapus belum
 * tersambung — halaman memberi tahu dengan jelas, tidak berpura-pura berhasil. Hanya tab "Data Umum" yang berisi;
 * tab riwayat lain menyusul (task B-07…B-18) dan menampilkan keadaan kosong yang menyebutkan task-nya.
 */
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role, UL_PEGAWAI } from '@/features/auth/types'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiButton, UiCard, UiNotice } from '@/shared/ui'

import ArsipTable from '../components/ArsipTable.vue'
import DataUmumForm from '../components/DataUmumForm.vue'
import PegawaiHeaderCard from '../components/PegawaiHeaderCard.vue'
import RiwayatMenuTabs from '../components/RiwayatMenuTabs.vue'
import { RIWAYAT_MENUS } from '../options'
import { pegawaiService } from '../services/pegawai.service'
import type { DataUmumForm as DataUmumValues } from '../schemas/dataUmum.schema'
import type { ArsipItem, PegawaiDetail } from '../types'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const nip = computed(() => String(route.params.nip ?? ''))
const detail = ref<PegawaiDetail | null>(null)
const loading = ref(true)
const notice = ref<string | null>(null)
const confirmDelete = ref(false)

const role = computed(() => auth.role)
const canEdit = computed(() => {
  if (role.value === Role.SUPER_ADMIN || role.value === Role.ADMIN_SATKER) return true
  return role.value !== null && (UL_PEGAWAI as readonly number[]).includes(role.value) && auth.user?.nip === nip.value
})
const canPrint = computed(() => role.value !== null && [Role.SUPER_ADMIN, Role.PEGAWAI, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI].includes(role.value as 1 | 2 | 3 | 4 | 5))
const canDelete = computed(() => role.value === Role.SUPER_ADMIN)

const tab = computed<string>({
  get: () => {
    const q = String(route.query.tab ?? '')
    return RIWAYAT_MENUS.some((m) => m.key === q) ? q : 'data-umum'
  },
  set: (key) => void router.replace({ query: { ...route.query, tab: key } }),
})
const activeMenu = computed(() => RIWAYAT_MENUS.find((m) => m.key === tab.value) ?? RIWAYAT_MENUS[0])

let requestId = 0
async function load(): Promise<void> {
  const current = ++requestId
  loading.value = true
  const result = await pegawaiService.detail(nip.value)
  if (current !== requestId) return
  detail.value = result
  loading.value = false
}
watch(nip, load, { immediate: true })

function onSave(values: DataUmumValues): void {
  if (detail.value) detail.value = { ...detail.value, data_umum: { ...values } }
  notice.value = 'Perubahan hanya tersimpan sementara di halaman ini — belum tersambung ke backend (task B-03).'
}

function onArsipAction(key: 'buka' | 'edit' | 'hapus', item: ArsipItem): void {
  notice.value = `Aksi "${key}" untuk arsip ${item.jenis} belum tersambung ke backend (task B-18).`
}

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

      <UiCard v-else-if="!detail" title="Pegawai tidak ditemukan" data-testid="pegawai-not-found">
        <p class="text-body1 text-slate-600">Tidak ada pegawai dengan NIP <strong>{{ nip }}</strong>.</p>
        <UiButton class="mt-4" variant="primary" appearance="soft" :to="{ name: 'pegawai-list' }">Kembali ke Daftar Pegawai</UiButton>
      </UiCard>

      <template v-else>
        <PegawaiHeaderCard
          :detail="detail"
          :can-print="canPrint"
          :can-delete="canDelete"
          @arsip="notice = 'Arsip Kepegawaian belum tersambung ke backend (task B-18).'"
          @print="(kind) => (notice = `Cetak ${kind === 'drh' ? 'DRH' : 'Data Umum'} belum tersambung ke backend (task B-20).`)"
          @delete="confirmDelete = true"
        />

        <RiwayatMenuTabs v-model="tab" :menus="RIWAYAT_MENUS" />

        <template v-if="activeMenu.ready">
          <UiCard title="Data Umum" subtitle="Kolom bertanda (*) wajib diisi" flush>
            <div class="mt-3 border-t border-slate-200">
              <DataUmumForm :initial="detail.data_umum" :readonly="!canEdit" @save="onSave" />
            </div>
          </UiCard>
          <ArsipTable :items="detail.arsip" :readonly="!canEdit" @add="notice = 'Tambah arsip belum tersambung ke backend (task B-18).'" @action="onArsipAction" />
        </template>

        <UiCard v-else :title="activeMenu.label" data-testid="riwayat-placeholder">
          <p class="text-body1 text-slate-600">
            Bagian <strong>{{ activeMenu.label }}</strong> menyusul — dikerjakan pada task <strong>{{ activeMenu.task }}</strong>.
          </p>
        </UiCard>
      </template>
    </div>

    <ConfirmDialog
      :open="confirmDelete"
      title="Hapus pegawai?"
      :description="detail ? `Data ${detail.data_umum.nama} (${detail.nip}) akan dihapus. Tindakan ini tidak dapat dibatalkan.` : ''"
      confirm-label="Ya, hapus"
      danger
      @update:open="(v) => (confirmDelete = v)"
      @confirm="() => { confirmDelete = false; notice = 'Penghapusan pegawai belum tersambung ke backend (task B-05).' }"
    />
  </RedesignShell>
</template>
