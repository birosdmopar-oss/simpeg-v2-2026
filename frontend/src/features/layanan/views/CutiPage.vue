<script setup lang="ts">
/**
 * Cuti Online (Modul C, PRD §6.11.1): tab Cuti Saya (role 2/6/7), Perlu Persetujuan (semua role route, badge jumlah),
 * Monitoring (role 1/3). Tabel + ⋮ (`cuti-actions-<id>`), pencarian/status, paginasi. Hak aksi per baris dari `aksi {}`
 * server. Tombol "Ajukan Cuti" dan aksi Edit ditambahkan paket form (Paket 2), Terbitkan TTE paket e-sign (Paket 3).
 * Pengingat persetujuan tampil sekali per sesi tab bila `GET cuti/pengingat` tidak kosong (reset listener `auth.login`).
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Plus } from 'lucide-vue-next'
import { useRoute, useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { describeApiError } from '@/features/kepegawaian/services/apiErrors'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiButton, UiCard, UiNotice, UiPagination, UiSearchInput, UiSelect, UiTabs } from '@/shared/ui'

import CutiFormDialog from '../components/CutiFormDialog.vue'
import CutiProsesDialog from '../components/CutiProsesDialog.vue'
import EsignDialog from '../components/EsignDialog.vue'
import CutiTable from '../components/CutiTable.vue'
import PengingatCutiDialog from '../components/PengingatCutiDialog.vue'
import { useCutiAksi } from '../composables/useCutiAksi'
import { CUTI_ADMIN_ROLES, CUTI_SAYA_ROLES } from '../roles'
import { LAYANAN_ROUTE } from '../routes.layanan'
import { cutiService } from '../services/cuti.service'
import type { CutiItem, CutiListResponse, ModeCuti } from '../types'

const PER_PAGE = 20
const KUNCI_PENGINGAT = 'layanan-pengingat-cuti-tampil'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Cuti' }]

const adaSaya = computed(() => auth.role !== null && CUTI_SAYA_ROLES.includes(auth.role))
const adaMonitoring = computed(() => auth.role !== null && CUTI_ADMIN_ROLES.includes(auth.role))

const jumlahPersetujuan = ref<number | null>(null)
const tabs = computed(() => [
  ...(adaSaya.value ? [{ value: 'saya', label: 'Cuti Saya' }] : []),
  { value: 'persetujuan', label: 'Perlu Persetujuan', badge: jumlahPersetujuan.value ?? undefined },
  ...(adaMonitoring.value ? [{ value: 'admin', label: 'Monitoring' }] : []),
])

const tab = computed<ModeCuti>({
  get: () => {
    const q = String(route.query.tab ?? '')
    const ada = tabs.value.some((t) => t.value === q)
    return (ada ? q : (tabs.value[0]?.value ?? 'persetujuan')) as ModeCuti
  },
  set: (v) => void router.replace({ query: { ...route.query, tab: v } }),
})

const data = ref<CutiListResponse | null>(null)
const page = ref(1)
const search = ref('')
const status = ref('')
const memuat = ref(false)
const errorMuat = ref('')
let token = 0
let timer: ReturnType<typeof setTimeout> | undefined

async function muat(): Promise<void> {
  const saya = ++token
  memuat.value = true
  errorMuat.value = ''
  try {
    const hasil = await cutiService.list({ mode: tab.value, page: page.value, per_page: PER_PAGE, status: status.value, search: search.value.trim() })
    if (saya !== token) return
    data.value = hasil
    if (hasil.mode === 'persetujuan' && status.value === '' && search.value.trim() === '') jumlahPersetujuan.value = hasil.total
  } catch (e) {
    if (saya !== token) return
    data.value = null
    errorMuat.value = describeApiError(e).message
  } finally {
    if (saya === token) memuat.value = false
  }
}

async function muatJumlahPersetujuan(): Promise<void> {
  if (tab.value === 'persetujuan') return
  try {
    jumlahPersetujuan.value = (await cutiService.list({ mode: 'persetujuan', page: 1, per_page: 1 })).total
  } catch {
    jumlahPersetujuan.value = null
  }
}

const aksi = useCutiAksi(async () => {
  await muat()
  void muatJumlahPersetujuan()
})

const pengingatOpen = ref(false)
const pengingat = ref<CutiItem[]>([])
const pengingatError = ref('')
const pengingatBusy = ref<number | null>(null)

function sudahDitampilkan(): boolean {
  try {
    return window.sessionStorage.getItem(KUNCI_PENGINGAT) === '1'
  } catch {
    return false
  }
}

function tandaiDitampilkan(): void {
  try {
    window.sessionStorage.setItem(KUNCI_PENGINGAT, '1')
  } catch {
    // Penyimpanan tidak tersedia → pengingat bisa tampil lagi pada muat berikutnya; tidak fatal.
  }
}

async function muatPengingat(): Promise<void> {
  if (sudahDitampilkan()) return
  try {
    const hasil = await cutiService.pengingat()
    tandaiDitampilkan()
    if (hasil.items.length > 0) {
      pengingat.value = hasil.items
      pengingatOpen.value = true
    }
  } catch {
    // Pengingat gagal dimuat → abaikan; antrean tetap ada di tab Perlu Persetujuan.
  }
}

async function tundaPengingat(row: CutiItem): Promise<void> {
  pengingatBusy.value = row.id
  pengingatError.value = ''
  try {
    await cutiService.tunda(row.id)
    pengingat.value = pengingat.value.filter((p) => p.id !== row.id)
    if (pengingat.value.length === 0) pengingatOpen.value = false
  } catch (e) {
    pengingatError.value = describeApiError(e).message
  } finally {
    pengingatBusy.value = null
  }
}

function lihatDetail(row: CutiItem): void {
  void router.push({ name: LAYANAN_ROUTE.CUTI_DETAIL, params: { id: row.id } })
}

const formOpen = ref(false)
const formRow = ref<CutiItem | null>(null)

function ajukan(): void {
  formRow.value = null
  formOpen.value = true
}

function onAction(key: string, row: CutiItem): void {
  if (key === 'detail') lihatDetail(row)
  else if (key === 'edit') {
    formRow.value = row
    formOpen.value = true
  } else aksi.pilih(key, row)
}

function onSaved(): void {
  aksi.notice.value = { tone: 'success', text: 'Pengajuan cuti disimpan.' }
  void muat()
  void muatJumlahPersetujuan()
}

onMounted(() => {
  void muat()
  void muatJumlahPersetujuan()
  void muatPengingat()
})
watch(tab, () => {
  page.value = 1
  status.value = ''
  search.value = ''
  void muat()
})
watch(page, () => void muat())
watch(status, () => {
  page.value = 1
  void muat()
})
watch(search, () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    page.value = 1
    void muat()
  }, 300)
})
onBeforeUnmount(() => clearTimeout(timer))

const STATUS_OPSI = [
  { value: '0', label: 'Menunggu' },
  { value: '3', label: 'Menunggu PyB' },
  { value: '1', label: 'Disetujui' },
  { value: '2', label: 'Ditolak' },
  { value: '4', label: 'Ditangguhkan' },
]
const tampilkanPegawai = computed(() => tab.value !== 'saya')
const subjekProses = computed(() => (aksi.prosesRow.value ? `cuti ${aksi.prosesRow.value.nama} (${aksi.prosesRow.value.nama_jenis_cuti})` : ''))
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="page-fill flex flex-col gap-4" data-testid="cuti-page">
    <UiCard title="Cuti" class="flex min-h-0 flex-1 flex-col" fill>
      <template #actions>
        <UiButton v-if="data?.can_create" data-testid="cuti-tambah" @click="ajukan"><Plus class="h-4 w-4" aria-hidden="true" /> Ajukan Cuti</UiButton>
      </template>
      <UiTabs v-model="tab" :items="tabs" aria-label="Daftar cuti" class="mb-4" />

      <div class="mb-4 grid gap-3 md:grid-cols-3" data-testid="cuti-filter">
        <UiSelect v-model="status" label="Status" placeholder="Semua status" :options="STATUS_OPSI" clearable />
        <UiSearchInput v-if="tampilkanPegawai" v-model="search" label="Cari pegawai" placeholder="Cari nama atau NIP" />
      </div>

      <UiNotice v-if="aksi.notice.value" :tone="aksi.notice.value.tone" class="mb-4" data-testid="cuti-notice">{{ aksi.notice.value.text }}</UiNotice>
      <UiNotice v-if="errorMuat" tone="danger" class="mb-4" data-testid="cuti-error">{{ errorMuat }}</UiNotice>

      <CutiTable
        :items="data?.items ?? []"
        :memuat="memuat"
        :tampilkan-pegawai="tampilkanPegawai"
        :busy-id="aksi.busyId.value"
        :kosong="tab === 'persetujuan' ? 'Tidak ada pengajuan yang menunggu persetujuan Anda.' : 'Belum ada pengajuan cuti.'"
        @action="onAction"
      />

      <UiPagination v-if="data && data.total > PER_PAGE" v-model:page="page" class="mt-4" :per-page="PER_PAGE" :total="data.total" />
    </UiCard>
    </div>

    <CutiFormDialog v-model:open="formOpen" :row="formRow" @saved="onSaved" />

    <CutiProsesDialog
      v-if="aksi.prosesRow.value"
      :open="aksi.prosesOpen.value"
      :aksi="aksi.prosesAksi.value"
      :atas-nama="aksi.prosesAtasNama.value"
      :subject="subjekProses"
      :loading="aksi.prosesBusy.value"
      :error="aksi.prosesError.value"
      @update:open="(v) => (aksi.prosesOpen.value = v)"
      @confirm="aksi.proses"
    />

    <EsignDialog
      :open="aksi.esignOpen.value"
      :subject="aksi.esignRow.value ? `surat cuti ${aksi.esignRow.value.nama}` : ''"
      :loading="aksi.esignBusy.value"
      :errors="aksi.esignErrors.value"
      :umum="aksi.esignUmum.value"
      @update:open="(v) => (aksi.esignOpen.value = v)"
      @confirm="aksi.esign"
    />

    <ConfirmDialog
      :open="aksi.hapusOpen.value"
      title="Hapus pengajuan cuti?"
      :description="aksi.hapusRow.value ? `Pengajuan ${aksi.hapusRow.value.nama_jenis_cuti} ${aksi.hapusRow.value.nama} akan dihapus; saldo yang terpotong dikembalikan.` : ''"
      confirm-label="Ya, hapus"
      danger
      @update:open="(v) => (aksi.hapusOpen.value = v)"
      @confirm="aksi.hapus"
    />

    <PengingatCutiDialog
      v-model:open="pengingatOpen"
      :items="pengingat"
      :busy-id="pengingatBusy"
      :error="pengingatError"
      @proses="lihatDetail"
      @tunda="tundaPengingat"
    />
  </RedesignShell>
</template>
