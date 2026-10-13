<script setup lang="ts">
/**
 * Usulan Konket — Konfirmasi Ketidakhadiran (B-13, pemilik WS-2, MAKE-013; WS2 §3.4.3): halaman sendiri, layout ikut
 * legacy `hr/rwy/konket` (pegawai: daftar pengajuan sendiri + Ajukan) dan `list_ad` (role 1/3: antrean rentang tanggal,
 * pencarian, Tambah untuk pegawai lain), style redesign. Aksi baris lewat ⋮ (AGENTS.md §1): Edit → Setujui/Tolak →
 * Unduh bukti → Hapus (ConfirmDialog). Hak per baris dari `aksi` server.
 */
import { Plus } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import ApprovalDialog from '@/shared/components/ApprovalDialog.vue'
import { approvalRowActions, type ApprovalAksi, type ApprovalPayload } from '@/shared/components/approvalActions'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import StatusBadge from '@/shared/components/StatusBadge.vue'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiButton, UiCard, UiNotice, UiPagination, UiSearchInput, UiSelect, UiTextField } from '@/shared/ui'

import KonketFormDialog from '../components/KonketFormDialog.vue'
import { describeApiError } from '../services/apiErrors'
import { simpanBerkas } from '../services/berkasUnduhan'
import { konketService } from '../services/konket.service'
import type { KonketItem, KonketListResponse, KonketOpsi, StatusKonket } from '../types'

const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Konfirmasi Ketidakhadiran' }]
const PER_PAGE = 20

/** Status konket → status riwayat StatusBadge (W 0 Menunggu, V 1 Disetujui, X 2 Ditolak). */
const STATUS_BADGE: Record<StatusKonket, number> = { W: 0, V: 1, X: 2 }

const opsi = ref<KonketOpsi | null>(null)
const data = ref<KonketListResponse | null>(null)
const page = ref(1)
const search = ref('')
const status = ref('')
const ds = ref('')
const de = ref('')
const memuat = ref(false)
const notice = ref<{ tone: 'danger' | 'success'; text: string } | null>(null)
const formOpen = ref(false)
const editing = ref<KonketItem | null>(null)
const prosesOpen = ref(false)
const prosesMode = ref<ApprovalAksi>('setujui')
const prosesRow = ref<KonketItem | null>(null)
const prosesBusy = ref(false)
const prosesError = ref('')
const hapusOpen = ref(false)
const hapusRow = ref<KonketItem | null>(null)
const busyId = ref<number | null>(null)
let token = 0
let timer: ReturnType<typeof setTimeout> | undefined
/** Rentang bawaan diisi dari respons pertama: watch ds/de dilewati sekali supaya daftar tidak dimuat dua kali. */
let rentangDariServer = false

const admin = computed(() => opsi.value?.mode === 'admin')

async function muat(): Promise<void> {
  const saya = ++token
  memuat.value = true
  try {
    const hasil = await konketService.list({ page: page.value, per_page: PER_PAGE, search: search.value.trim(), status: status.value, ds: ds.value, de: de.value })
    if (saya !== token) return
    data.value = hasil
    if (hasil.rentang && !ds.value) {
      rentangDariServer = true
      ds.value = hasil.rentang.ds
      de.value = hasil.rentang.de
    }
  } catch (e) {
    if (saya !== token) return
    data.value = null
    notice.value = { tone: 'danger', text: describeApiError(e).message }
  } finally {
    if (saya === token) memuat.value = false
  }
}

onMounted(async () => {
  try {
    opsi.value = await konketService.opsi()
  } catch (e) {
    notice.value = { tone: 'danger', text: describeApiError(e).message }
  }
  await muat()
})

watch(page, () => void muat())
watch([status, ds, de], () => {
  if (rentangDariServer) {
    rentangDariServer = false
    return
  }
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

function actionsFor(row: KonketItem): RowAction[] {
  const busy = busyId.value === row.id
  return [
    { key: 'edit', label: 'Edit', hidden: !row.aksi.ubah, disabled: busy },
    ...approvalRowActions({ canProcess: row.aksi.proses, status: STATUS_BADGE[row.status], busy }),
    { key: 'berkas', label: 'Unduh bukti', hidden: !row.ada_berkas, disabled: busy },
    { key: 'hapus', label: 'Hapus', danger: true, hidden: !row.aksi.hapus, disabled: busy },
  ]
}

function onAction(key: string, row: KonketItem): void {
  if (key === 'edit') {
    editing.value = row
    formOpen.value = true
  } else if (key === 'setujui' || key === 'tolak') {
    prosesRow.value = row
    prosesMode.value = key
    prosesError.value = ''
    prosesOpen.value = true
  } else if (key === 'berkas') {
    void unduh(row)
  } else if (key === 'hapus') {
    hapusRow.value = row
    hapusOpen.value = true
  }
}

function tambah(): void {
  editing.value = null
  formOpen.value = true
}

async function unduh(row: KonketItem): Promise<void> {
  busyId.value = row.id
  try {
    const berkas = await konketService.berkas(row.id)
    simpanBerkas(berkas)
  } catch (e) {
    notice.value = { tone: 'danger', text: describeApiError(e).message }
  } finally {
    busyId.value = null
  }
}

async function proses(payload: ApprovalPayload): Promise<void> {
  const row = prosesRow.value
  if (!row) return
  prosesBusy.value = true
  prosesError.value = ''
  try {
    await konketService.process(row.id, payload)
    prosesOpen.value = false
    notice.value = { tone: 'success', text: `Konfirmasi ketidakhadiran ${payload.aksi === 'setujui' ? 'disetujui' : 'ditolak'}.` }
    await muat()
  } catch (e) {
    prosesError.value = describeApiError(e).message
  } finally {
    prosesBusy.value = false
  }
}

async function hapus(): Promise<void> {
  const row = hapusRow.value
  hapusOpen.value = false
  if (!row) return
  busyId.value = row.id
  try {
    await konketService.remove(row.id)
    notice.value = { tone: 'success', text: 'Konfirmasi ketidakhadiran dihapus.' }
    await muat()
  } catch (e) {
    notice.value = { tone: 'danger', text: describeApiError(e).message }
  } finally {
    busyId.value = null
    hapusRow.value = null
  }
}

function onSaved(): void {
  notice.value = { tone: 'success', text: 'Konfirmasi ketidakhadiran disimpan.' }
  void muat()
}

const periode = (r: KonketItem): string => (r.date_start === r.date_end ? r.date_start : `${r.date_start} s.d. ${r.date_end}`)
const STATUS_OPSI = [
  { value: 'W', label: 'Menunggu' },
  { value: 'V', label: 'Disetujui' },
  { value: 'X', label: 'Ditolak' },
]
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <UiCard title="Konfirmasi Ketidakhadiran">
      <template #actions>
        <UiButton v-if="opsi && data?.can_create" data-testid="konket-tambah" @click="tambah">
          <Plus class="h-4 w-4" aria-hidden="true" /> {{ admin ? 'Tambah' : 'Ajukan' }}
        </UiButton>
      </template>

      <div v-if="admin" class="mb-4 grid gap-3 md:grid-cols-4" data-testid="konket-filter">
        <UiTextField v-model="ds" type="date" label="Dari tanggal" />
        <UiTextField v-model="de" type="date" label="Sampai tanggal" />
        <UiSelect v-model="status" label="Status" placeholder="Semua status" :options="STATUS_OPSI" clearable />
        <UiSearchInput v-model="search" label="Cari pegawai" placeholder="Cari nama atau NIP" />
      </div>
      <div v-else class="mb-4 max-w-xs">
        <UiSelect v-model="status" label="Status" placeholder="Semua status" :options="STATUS_OPSI" clearable />
      </div>

      <UiNotice v-if="notice" :tone="notice.tone" class="mb-4" data-testid="konket-notice">{{ notice.text }}</UiNotice>

      <div class="scrollbar-slim overflow-x-auto">
        <table class="w-full min-w-[48rem] border-collapse text-left text-body1" data-testid="konket-table">
          <thead>
            <tr class="border-b border-slate-200 text-body2 font-semibold text-slate-900">
              <th v-if="admin" scope="col" class="px-4 py-3">Pegawai</th>
              <th scope="col" class="px-4 py-3">Tanggal</th>
              <th scope="col" class="px-4 py-3">Jenis</th>
              <th scope="col" class="px-4 py-3">Alasan</th>
              <th v-if="admin" scope="col" class="px-4 py-3">Pengaruh Tukin</th>
              <th scope="col" class="px-4 py-3">Status</th>
              <th scope="col" class="sticky right-0 bg-white px-4 py-3 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200">
            <tr v-if="memuat && !data">
              <td :colspan="admin ? 7 : 5" class="px-4 py-8 text-center text-body2 text-slate-500">Memuat…</td>
            </tr>
            <tr v-else-if="!data || data.items.length === 0">
              <td :colspan="admin ? 7 : 5" class="px-4 py-10 text-center text-body2 text-slate-500" data-testid="konket-empty">
                Belum ada konfirmasi ketidakhadiran.
              </td>
            </tr>
            <tr v-for="row in data?.items ?? []" v-else :key="row.id" :data-testid="`konket-row-${row.id}`">
              <td v-if="admin" class="px-4 py-3">
                <span class="block font-medium text-slate-900">{{ row.nama_lengkap }}</span>
                <span class="block text-body2 text-slate-500">{{ row.nip }}</span>
              </td>
              <td class="px-4 py-3 text-slate-700">{{ periode(row) }}</td>
              <td class="px-4 py-3 text-slate-700">
                {{ row.jenis_konket ?? '—' }}
                <span v-if="row.jenis_dinas === 1" class="block text-caption text-slate-500">Dinas gabungan</span>
              </td>
              <td class="max-w-xs px-4 py-3 text-slate-700">
                <span class="line-clamp-2">{{ row.alasan }}</span>
                <span v-if="row.status === 'X' && row.reason_note" class="block text-caption text-danger">Ditolak: {{ row.reason_note }}</span>
              </td>
              <td v-if="admin" class="px-4 py-3 text-slate-700">{{ row.affect_tukin === 1 ? 'Ya' : 'Tidak' }}</td>
              <td class="px-4 py-3"><StatusBadge :status="STATUS_BADGE[row.status]" /></td>
              <td class="sticky right-0 bg-white px-4 py-3 text-right">
                <RowActionsMenu
                  :actions="actionsFor(row)"
                  :label="`Aksi untuk konket ${admin ? row.nama_lengkap + ' ' : ''}${periode(row)}`"
                  :testid="`konket-actions-${row.id}`"
                  @select="(key) => onAction(key, row)"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <UiPagination v-if="data && data.meta.total > PER_PAGE" v-model:page="page" class="mt-4" :per-page="PER_PAGE" :total="data.meta.total" />
    </UiCard>

    <KonketFormDialog v-if="opsi" v-model:open="formOpen" :opsi="opsi" :row="editing" @saved="onSaved" />

    <ApprovalDialog
      :open="prosesOpen"
      :mode="prosesMode"
      :subject="prosesRow ? `konfirmasi ketidakhadiran ${prosesRow.nama_lengkap} (${periode(prosesRow)})` : ''"
      :loading="prosesBusy"
      :error="prosesError"
      @update:open="(v) => (prosesOpen = v)"
      @confirm="proses"
    />

    <ConfirmDialog
      :open="hapusOpen"
      title="Hapus konfirmasi ketidakhadiran?"
      :description="hapusRow ? `Data ${periode(hapusRow)}${hapusRow.jenis_dinas === 1 ? ' beserta seluruh peserta dinas gabungan' : ''} akan dihapus.` : ''"
      confirm-label="Ya, hapus"
      danger
      @update:open="(v) => (hapusOpen = v)"
      @confirm="hapus"
    />
  </RedesignShell>
</template>
