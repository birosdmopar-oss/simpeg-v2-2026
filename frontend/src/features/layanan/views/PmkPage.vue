<script setup lang="ts">
/**
 * PMK (Modul C, PRD §6.9/§6.11.1): tab Riwayat PMK (final, impor/SIASN; role 1 kelola arsip/hapus), Pengajuan Saya
 * (role 2), Perlu Persetujuan (PIC; server → 403 bila bukan PIC aktif), Monitoring (role 1, 3). Pengajuan: tabel + ⋮
 * (`pmk-actions-<id>`) dengan hak dari `aksi {}`/`aksi_tersedia[]` server; "Ajukan PMK" hanya bila `can_create`.
 * Role 3 hanya melihat (server menolak tulis).
 */
import { Plus } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role } from '@/features/auth/types'
import { describeApiError } from '@/features/kepegawaian/services/apiErrors'
import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiButton, UiCard, UiNotice, UiPagination, UiSearchInput, UiSelect, UiTabs } from '@/shared/ui'

import ArsipPmkDialog from '../components/ArsipPmkDialog.vue'
import PmkFormDialog from '../components/PmkFormDialog.vue'
import PmkProsesDialog from '../components/PmkProsesDialog.vue'
import PmkTable from '../components/PmkTable.vue'
import RiwayatPmkTable from '../components/RiwayatPmkTable.vue'
import { useFokusPemicu } from '../composables/useFokusPemicu'
import { usePmkAksi } from '../composables/usePmkAksi'
import { LAYANAN_ROUTE } from '../routes.layanan'
import { pmkService } from '../services/pmk.service'
import type { ModePmk, PmkItem, PmkListResponse, RiwayatPmkItem, RiwayatPmkResponse } from '../types'

const PER_PAGE = 20

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Layanan' }, { label: 'PMK' }]

const role = computed(() => auth.role)
const tabs = computed(() => [
  { value: 'riwayat', label: 'Riwayat PMK' },
  ...(role.value === Role.PEGAWAI ? [{ value: 'saya', label: 'Pengajuan Saya' }] : []),
  ...(adalahPic.value === false ? [] : [{ value: 'pic', label: 'Perlu Persetujuan' }]),
  ...(role.value === Role.SUPER_ADMIN || role.value === Role.ADMIN_SATKER ? [{ value: 'admin', label: 'Monitoring' }] : []),
])

const tab = computed<string>({
  get: () => {
    const q = String(route.query.tab ?? '')
    return tabs.value.some((t) => t.value === q) ? q : (tabs.value[0]?.value ?? 'riwayat')
  },
  set: (v) => void router.replace({ query: { ...route.query, tab: v } }),
})

const dataPengajuan = ref<PmkListResponse | null>(null)
const dataRiwayat = ref<RiwayatPmkResponse | null>(null)
const labelBerkas = ref<Record<string, string>>({})
const bisaMengajukan = ref(false)
/** `null` = belum diketahui (opsi belum dimuat): tab PIC tetap tampil; `false` dari server menyembunyikannya. */
const adalahPic = ref<boolean | null>(null)
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
    if (tab.value === 'riwayat') {
      const hasil = await pmkService.riwayat({ page: page.value, per_page: PER_PAGE, nip: search.value.trim() })
      if (saya !== token) return
      dataRiwayat.value = hasil
    } else {
      const hasil = await pmkService.list({ mode: tab.value as ModePmk, page: page.value, per_page: PER_PAGE, status: status.value, search: search.value.trim() })
      if (saya !== token) return
      dataPengajuan.value = hasil
    }
  } catch (e) {
    if (saya !== token) return
    dataPengajuan.value = null
    dataRiwayat.value = null
    const gagal = describeApiError(e)
    errorMuat.value = tab.value === 'pic' && gagal.kind === 'forbidden' ? 'Anda bukan PIC peninjauan masa kerja yang aktif.' : gagal.message
  } finally {
    if (saya === token) memuat.value = false
  }
}

async function muatOpsi(): Promise<void> {
  try {
    const o = await pmkService.opsi()
    labelBerkas.value = o.berkas
    bisaMengajukan.value = o.can_create
    adalahPic.value = o.adalah_pic
  } catch {
    bisaMengajukan.value = false
  }
}

const aksi = usePmkAksi(async () => {
  await muat()
})

const formOpen = ref(false)
const formRow = ref<PmkItem | null>(null)
const arsipOpen = ref(false)
const arsipRow = ref<RiwayatPmkItem | null>(null)
const hapusRiwayatRow = ref<RiwayatPmkItem | null>(null)
const hapusRiwayatOpen = ref(false)

function ajukan(): void {
  formRow.value = null
  formOpen.value = true
}

function onAction(key: string, row: PmkItem): void {
  if (key === 'detail') void router.push({ name: LAYANAN_ROUTE.PMK_DETAIL, params: { id: row.id } })
  else if (key === 'edit') {
    formRow.value = row
    formOpen.value = true
  } else aksi.pilih(key, row)
}

async function unduhArsip(row: RiwayatPmkItem, idAttachment: number): Promise<void> {
  try {
    simpanBerkas(await pmkService.arsip(row.id, idAttachment))
  } catch (e) {
    aksi.notice.value = { tone: 'danger', text: describeApiError(e).message }
  }
}

function onActionRiwayat(key: string, row: RiwayatPmkItem): void {
  if (key.startsWith('unduh:')) {
    void unduhArsip(row, Number(key.slice('unduh:'.length)))
  } else if (key === 'arsip') {
    arsipRow.value = row
    arsipOpen.value = true
  } else if (key === 'hapus') {
    hapusRiwayatRow.value = row
    hapusRiwayatOpen.value = true
  }
}

async function hapusRiwayat(): Promise<void> {
  const row = hapusRiwayatRow.value
  hapusRiwayatOpen.value = false
  if (!row) return
  try {
    await pmkService.hapusRiwayat(row.id)
    aksi.notice.value = { tone: 'success', text: 'Riwayat PMK dihapus.' }
    await muat()
  } catch (e) {
    aksi.notice.value = { tone: 'danger', text: describeApiError(e).message }
  }
}

function onArsipBerubah(id: number, arsip: { id_attachment: number; display_name: string | null }[]): void {
  const row = dataRiwayat.value?.items.find((r) => r.id === id)
  if (row) row.arsip = arsip
}

function onSaved(): void {
  aksi.notice.value = { tone: 'success', text: 'Pengajuan PMK disimpan.' }
  void muat()
}

onMounted(() => {
  void muat()
  void muatOpsi()
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
  { value: '0', label: 'Menunggu PIC' },
  { value: '3', label: 'Diproses' },
  { value: '1', label: 'Selesai' },
  { value: '2', label: 'Ditolak' },
]
const adaCari = computed(() => tab.value === 'admin' || tab.value === 'pic' || (tab.value === 'riwayat' && role.value !== Role.PEGAWAI))
const subjekProses = computed(() => (aksi.prosesRow.value ? `PMK ${aksi.prosesRow.value.nama || aksi.prosesRow.value.nip}` : ''))

// CR-072 butir 8: fokus kembali ke ⋮ baris setelah dialog proses/hapus ditutup (Esc, Batal, atau selesai).
function selectorMenuPmk(): string | null {
  const row = aksi.prosesRow.value ?? aksi.hapusRow.value
  return row ? `[data-testid="pmk-actions-${row.id}"]` : null
}
useFokusPemicu(aksi.prosesOpen, selectorMenuPmk)
useFokusPemicu(aksi.hapusOpen, selectorMenuPmk)
const total = computed(() => (tab.value === 'riwayat' ? (dataRiwayat.value?.total ?? 0) : (dataPengajuan.value?.total ?? 0)))
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="page-fill flex flex-col gap-4" data-testid="pmk-page">
    <div>
      <h1 class="text-h2 font-semibold text-slate-900">Peninjauan Masa Kerja (PMK)</h1>
      <p class="text-body2 text-slate-500">Riwayat PMK final, pengajuan, dan proses persetujuan PIC.</p>
    </div>
    <UiCard title="Daftar PMK" class="flex min-h-0 flex-1 flex-col" fill>
      <template #actions>
        <UiButton v-if="bisaMengajukan && tab !== 'riwayat'" data-testid="pmk-tambah" @click="ajukan"><Plus class="h-4 w-4" aria-hidden="true" /> Ajukan PMK</UiButton>
      </template>

      <UiTabs v-model="tab" :items="tabs" aria-label="Daftar PMK" class="mb-4" />

      <div class="mb-4 grid gap-3 md:grid-cols-3" data-testid="pmk-filter">
        <UiSelect v-if="tab !== 'riwayat'" v-model="status" label="Status" placeholder="Semua Status" :options="STATUS_OPSI" clearable />
        <UiSearchInput v-if="adaCari" v-model="search" :label="tab === 'riwayat' ? 'Cari NIP' : 'Cari pegawai'" :placeholder="tab === 'riwayat' ? 'NIP pegawai' : 'Cari nama atau NIP'" />
      </div>

      <UiNotice v-if="aksi.notice.value" :tone="aksi.notice.value.tone" class="mb-4" data-testid="pmk-notice">{{ aksi.notice.value.text }}</UiNotice>
      <UiNotice v-if="errorMuat" tone="danger" class="mb-4" data-testid="pmk-error">{{ errorMuat }}</UiNotice>

      <RiwayatPmkTable
        v-if="tab === 'riwayat'"
        :items="dataRiwayat?.items ?? []"
        :memuat="memuat"
        :tampilkan-nip="role !== Role.PEGAWAI"
        :bisa-ubah="dataRiwayat?.can_edit ?? false"
        @action="onActionRiwayat"
      />
      <PmkTable
        v-else
        :items="dataPengajuan?.items ?? []"
        :memuat="memuat"
        :tampilkan-pegawai="tab !== 'saya'"
        :busy-id="aksi.busyId.value"
        :label-berkas="labelBerkas"
        :kosong="tab === 'pic' ? 'Tidak ada pengajuan yang menunggu proses PIC.' : 'Belum ada pengajuan PMK.'"
        @action="onAction"
      />

      <UiPagination v-if="total > PER_PAGE" v-model:page="page" class="mt-4" :per-page="PER_PAGE" :total="total" />
    </UiCard>
    </div>

    <PmkFormDialog v-model:open="formOpen" :row="formRow" @saved="onSaved" />
    <ArsipPmkDialog v-model:open="arsipOpen" :row="arsipRow" @berubah="onArsipBerubah" />

    <PmkProsesDialog
      v-if="aksi.prosesRow.value"
      :open="aksi.prosesOpen.value"
      :aksi="aksi.prosesAksi.value"
      :subject="subjekProses"
      :loading="aksi.prosesBusy.value"
      :error="aksi.prosesError.value"
      @update:open="(v) => (aksi.prosesOpen.value = v)"
      @confirm="aksi.proses"
    />

    <ConfirmDialog
      :open="aksi.hapusOpen.value"
      title="Hapus pengajuan PMK?"
      description="Pengajuan beserta berkas yang diunggah akan dihapus."
      confirm-label="Ya, hapus"
      danger
      @update:open="(v) => (aksi.hapusOpen.value = v)"
      @confirm="aksi.hapus"
    />
    <ConfirmDialog
      :open="hapusRiwayatOpen"
      title="Hapus riwayat PMK?"
      :description="hapusRiwayatRow ? `Riwayat PMK SK ${hapusRiwayatRow.no_sk} beserta arsipnya akan dihapus.` : ''"
      confirm-label="Ya, hapus"
      danger
      @update:open="(v) => (hapusRiwayatOpen = v)"
      @confirm="hapusRiwayat"
    />
  </RedesignShell>
</template>
