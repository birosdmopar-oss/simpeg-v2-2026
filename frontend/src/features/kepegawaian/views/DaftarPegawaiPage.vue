<script setup lang="ts">
/**
 * Halaman Daftar Pegawai (§4.1.5, Gambar 18–21) — Fase 3, B-20 (pemilik WS-2). Data dari `GET pegawai` (disaring
 * lingkup pemanggil di backend). Pencarian, panel filter (status, unit → satker, jenis pegawai, urutan; MAKE-014 —
 * disembunyikan untuk role 8 sesuai WS2 §3.4.2 #12), "Filter Kolom", tabel, paginasi. Pencarian lanjutan (role 1, 3, 4, 5;
 * legacy advsearch) mengganti sumber daftar dengan `POST pegawai/pencarian`; Export CSV/XLSX mengikuti sumber aktif
 * (filter atau kriteria) dan kolom yang sedang tampil (`GET pegawai/export` / `POST pegawai/pencarian/export`).
 * Akses: role 1, 3, 4, 5, 8; tombol "+ Data Pegawai" dan aksi "Hapus" hanya role 1.
 *
 * Tanpa data contoh: galat endpoint (mis. 404/501) tampil sebagai keadaan galat yang jelas.
 * Tambah (TambahPegawaiDialog) dan Hapus (⋮ → ConfirmDialog → `DELETE pegawai/{nip}`, hapus PERMANEN ikut legacy;
 * 422 bila pegawai masih punya data terkait) tersambung B-05 (MAKE-011).
 */
import { Plus, SlidersHorizontal } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { isApiError } from '@/lib/axios'
import { Role } from '@/features/auth/types'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiButton, UiCard, UiExportMenu, UiNotice, UiPagination, UiSearchInput, UiSelect } from '@/shared/ui'

import PegawaiColumnPicker from '../components/PegawaiColumnPicker.vue'
import PegawaiFilterPanel, { type PegawaiFilter } from '../components/PegawaiFilterPanel.vue'
import PencarianLanjutanPanel from '../components/PencarianLanjutanPanel.vue'
import PegawaiTable from '../components/PegawaiTable.vue'
import TambahPegawaiDialog from '../components/TambahPegawaiDialog.vue'
import { DEFAULT_COLUMN_KEYS, fullName } from '../columns'
import { describeApiError, type ApiFailure } from '../services/apiErrors'
import { simpanBerkas } from '../services/berkasUnduhan'
import { pegawaiService } from '../services/pegawai.service'
import type { KriteriaPencarian, PegawaiListItem } from '../types'

const auth = useAuthStore()
const router = useRouter()

const isSuperAdmin = computed(() => auth.role === Role.SUPER_ADMIN)
/** WS2 §3.4.2 #12: panel filter disembunyikan untuk role 8 (Pimpinan); pencarian tetap ada. */
const bolehFilter = computed(() => auth.role !== Role.PIMPINAN)
/** Status bawaan Aktif (legacy list.php initComplete; keputusan user 13-10-2026 Q-M6-3). */
/** Pencarian lanjutan: role 1, 3, 4, 5 (legacy advsearch). */
const bolehLanjutan = computed(() => ([Role.SUPER_ADMIN, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI] as number[]).includes(auth.role ?? 0))
const lanjutanOpen = ref(false)
const kriteria = ref<KriteriaPencarian[] | null>(null)
const galatKriteria = ref<Record<string, string>>({})
const mengekspor = ref(false)
const EXPORT_FORMATS = [
  { key: 'xlsx' as const, label: 'Export Excel (.xlsx)' },
  { key: 'csv' as const, label: 'Export CSV' },
]
const filter = ref<PegawaiFilter>({ status: '1', id_unit: '', id_satker: '', id_jenis_pegawai: '', sort: 'nama', order: 'asc' })

const search = ref('')
const columnKeys = ref<string[]>([...DEFAULT_COLUMN_KEYS])
const perPage = ref('10')
const page = ref(1)

const rows = ref<PegawaiListItem[]>([])
const total = ref(0)
const loading = ref(false)
const failure = ref<ApiFailure | null>(null)

const notice = ref<string | null>(null)
const deleting = ref<PegawaiListItem | null>(null)
/** Dipisah dari baris terpilih: klik konfirmasi menutup dialog SEBELUM handler confirm jalan. */
const deleteOpen = ref(false)
const tambahOpen = ref(false)
const busyNip = ref<string | null>(null)

const PER_PAGE_OPTIONS = ['10', '25', '50'].map((v) => ({ value: v, label: v }))

let requestId = 0
let timer: ReturnType<typeof setTimeout> | null = null

async function load(): Promise<void> {
  const current = ++requestId
  loading.value = true
  failure.value = null
  try {
    const result = kriteria.value
      ? await pegawaiService.pencarian(kriteria.value, { page: page.value, per_page: Number(perPage.value), sort: filter.value.sort, order: filter.value.order })
      : await pegawaiService.list({
          page: page.value,
          per_page: Number(perPage.value),
          search: search.value || undefined,
          ...(bolehFilter.value ? filter.value : {}),
        })
    // Abaikan respons yang sudah basi (pengguna keburu mengubah pencarian).
    if (current !== requestId) return
    rows.value = result.items
    total.value = result.total
    galatKriteria.value = {}
  } catch (error) {
    if (current !== requestId) return
    rows.value = []
    total.value = 0
    if (kriteria.value && isApiError(error) && error.status === 422 && error.errors) {
      galatKriteria.value = Object.fromEntries(Object.entries(error.errors).map(([k, v]) => [k, v?.[0] ?? '']))
      return
    }
    failure.value = describeApiError(error)
  } finally {
    if (current === requestId) loading.value = false
  }
}

function schedule(resetPage: boolean): void {
  if (resetPage) page.value = 1
  if (timer) clearTimeout(timer)
  timer = setTimeout(load, 250)
}

watch([search, perPage, filter], () => schedule(true))
watch(page, () => schedule(false))

onMounted(load)
onBeforeUnmount(() => {
  if (timer) clearTimeout(timer)
})

function cariLanjutan(isi: KriteriaPencarian[]): void {
  galatKriteria.value = {}
  kriteria.value = isi
  page.value = 1
  void load()
}

function tutupLanjutan(): void {
  galatKriteria.value = {}
  kriteria.value = null
  lanjutanOpen.value = false
  page.value = 1
  void load()
}

async function ekspor(format: 'pdf' | 'xlsx' | 'csv'): Promise<void> {
  if (format === 'pdf') return
  mengekspor.value = true
  // Kolom tampil tanpa Nama/NIP (server selalu menyertakannya paling kiri).
  const kolom = columnKeys.value.filter((k) => k !== 'nama_nip')
  try {
    const berkas = kriteria.value
      ? await pegawaiService.eksporPencarian(format, kolom, kriteria.value)
      : await pegawaiService.ekspor(format, kolom, { search: search.value || undefined, ...(bolehFilter.value ? filter.value : {}) })
    simpanBerkas(berkas)
  } catch (error) {
    notice.value = `Gagal mengekspor: ${pesanServer(error)}`
  } finally {
    mengekspor.value = false
  }
}

function onAction(key: 'detail' | 'hapus', row: PegawaiListItem): void {
  if (key === 'detail') void router.push({ name: 'pegawai-detail', params: { nip: row.nip } })
  else {
    deleting.value = row
    deleteOpen.value = true
  }
}

async function confirmDelete(): Promise<void> {
  deleteOpen.value = false
  const row = deleting.value
  if (!row) return
  busyNip.value = row.nip
  try {
    await pegawaiService.hapus(row.nip)
    notice.value = `Data pegawai ${fullName(row)} (${row.nip}) dihapus permanen.`
    void load()
  } catch (error) {
    notice.value = `Gagal menghapus pegawai: ${pesanServer(error)}`
  } finally {
    busyNip.value = null
    deleting.value = null
  }
}

/** Pesan 422 server (mis. "masih memiliki data terkait") bila ada; selain itu pesan baku per jenis galat. */
function pesanServer(error: unknown): string {
  return (isApiError(error) ? Object.values(error.errors ?? {})[0]?.[0] : undefined) ?? describeApiError(error).message
}

function onCreated(): void {
  notice.value = 'Pegawai baru ditambahkan. Lanjutkan dengan mengisi Riwayat Jabatan pegawai tersebut.'
  void load()
}

const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Daftar Pegawai' }]
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <UiNotice v-if="notice" tone="info" dismissible class="mb-4" @dismiss="notice = null">{{ notice }}</UiNotice>

    <UiCard title="Daftar Pegawai" flush>
      <template #actions>
        <UiButton v-if="isSuperAdmin" data-testid="add-pegawai" @click="tambahOpen = true">
          <template #icon-left><Plus class="h-4 w-4" aria-hidden="true" /></template>
          Data Pegawai
        </UiButton>
        <UiButton v-if="bolehLanjutan" variant="secondary" appearance="soft" data-testid="pencarian-toggle" @click="lanjutanOpen = !lanjutanOpen">
          <template #icon-left><SlidersHorizontal class="h-4 w-4" aria-hidden="true" /></template>
          Pencarian Lanjutan
        </UiButton>
        <UiExportMenu :formats="EXPORT_FORMATS" :disabled="mengekspor" @select="ekspor" />
      </template>

      <div class="mt-2 space-y-5 pb-5">
        <div class="flex flex-wrap items-center justify-between gap-3 px-5">
          <div class="w-24">
            <UiSelect v-model="perPage" :options="PER_PAGE_OPTIONS" placeholder="10" aria-label="Jumlah baris" />
          </div>
          <div class="flex flex-wrap items-center gap-3">
            <div class="w-64 max-w-full"><UiSearchInput v-model="search" placeholder="Search" label="Cari pegawai" /></div>
            <PegawaiColumnPicker v-model="columnKeys" />
          </div>
        </div>

        <div v-if="bolehLanjutan && lanjutanOpen" class="px-5">
          <PencarianLanjutanPanel :errors="galatKriteria" @cari="cariLanjutan" @reset="tutupLanjutan" />
        </div>
        <UiNotice v-if="kriteria" tone="info" class="mx-5" data-testid="pencarian-aktif">
          Menampilkan hasil pencarian lanjutan (pegawai aktif); filter dan pencarian biasa tidak dipakai.
          <button type="button" class="ml-2 font-medium underline" data-testid="pencarian-aktif-reset" @click="tutupLanjutan">Kembali ke daftar</button>
        </UiNotice>
        <div v-else-if="bolehFilter" class="px-5"><PegawaiFilterPanel v-model="filter" /></div>

        <UiNotice v-if="failure" :tone="failure.kind === 'unavailable' ? 'info' : 'danger'" class="mx-5" data-testid="pegawai-failure">{{ failure.message }}</UiNotice>

        <PegawaiTable
          v-if="!failure"
          :rows="rows"
          :column-keys="columnKeys"
          :loading="loading"
          :can-delete="isSuperAdmin"
          :busy-nip="busyNip"
          @action="onAction"
        />

        <div v-if="!failure" class="px-5">
          <UiPagination v-model:page="page" :per-page="Number(perPage)" :total="total" />
        </div>
      </div>
    </UiCard>

    <TambahPegawaiDialog v-if="isSuperAdmin" v-model:open="tambahOpen" @created="onCreated" />

    <ConfirmDialog
      :open="deleteOpen"
      title="Hapus pegawai?"
      :description="deleting ? `Data ${fullName(deleting)} (${deleting.nip}) akan dihapus PERMANEN. Pegawai yang masih punya riwayat, jabatan, atau akun login tidak dapat dihapus.` : ''"
      confirm-label="Ya, hapus"
      danger
      @update:open="(v) => (deleteOpen = v)"
      @confirm="confirmDelete"
    />
  </RedesignShell>
</template>
