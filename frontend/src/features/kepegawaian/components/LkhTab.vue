<script setup lang="ts">
/**
 * Tab LKH di Detail Pegawai (B-12b × B-20 penutup, pemilik WS-2, MAKE-014) — tab non-engine `lkh` dari descriptor
 * server (`PegawaiService::detail` → `LkhService::descriptorTab`: pemilik, role 1, role 3 dalam lingkup).
 * Data `GET lkh?nip=` (urut legacy Revisi → Menunggu → Disetujui), Tambah untuk pegawai itu (`can_create`), menu ⋮ per
 * baris dari `aksi` server: Edit → Unduh PDF (hanya Disetujui) → Hapus (ConfirmDialog). Verifikasi tetap di halaman
 * Verifikasi LKH (atasan langsung).
 */
import { formatTanggalIndonesia as tgl } from '@/shared/utils/dateTime'
import { Plus } from 'lucide-vue-next'
import { onMounted, ref, watch } from 'vue'

import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { UiBadge, UiButton, UiCard, UiNotice, UiPagination } from '@/shared/ui'

import { describeApiError } from '../services/apiErrors'
import { simpanBerkas } from '../services/berkasUnduhan'
import { lkhService } from '../services/lkh.service'
import type { LkhItem, LkhListResponse, LkhOpsi, RiwayatTabDescriptor, StatusLkh } from '../types'

import LkhFormDialog from './LkhFormDialog.vue'

const props = defineProps<{ nip: string; descriptor: RiwayatTabDescriptor }>()

const PER_PAGE = 20
const STATUS: Record<StatusLkh, { label: string; tone: 'warning' | 'success' | 'danger' | 'info' }> = {
  0: { label: 'Menunggu', tone: 'warning' },
  1: { label: 'Disetujui', tone: 'success' },
  2: { label: 'Ditolak', tone: 'danger' },
  3: { label: 'Revisi', tone: 'info' },
}

const data = ref<LkhListResponse | null>(null)
const opsi = ref<LkhOpsi | null>(null)
const page = ref(1)
const memuat = ref(false)
const notice = ref<{ tone: 'danger' | 'success'; text: string } | null>(null)
const formOpen = ref(false)
const editing = ref<LkhItem | null>(null)
const hapusOpen = ref(false)
const hapusRow = ref<LkhItem | null>(null)
const busyId = ref<number | null>(null)
let token = 0

async function muat(): Promise<void> {
  const saya = ++token
  memuat.value = true
  try {
    const hasil = await lkhService.list({ page: page.value, per_page: PER_PAGE, nip: props.nip })
    if (saya !== token) return
    data.value = hasil
    if (notice.value?.tone === 'danger') notice.value = null
  } catch (e) {
    if (saya !== token) return
    data.value = null
    notice.value = { tone: 'danger', text: describeApiError(e).message }
  } finally {
    if (saya === token) memuat.value = false
  }
}

onMounted(async () => {
  void muat()
  if (!props.descriptor.can_create) return
  try {
    opsi.value = await lkhService.opsi(props.nip)
  } catch {
    // Opsi gagal (mis. di luar lingkup tulis) → tombol Tambah tidak tampil.
  }
})
watch(page, () => void muat())
watch(
  () => props.nip,
  () => {
    page.value = 1
    void muat()
  },
)

function actionsFor(row: LkhItem): RowAction[] {
  const busy = busyId.value === row.id
  return [
    { key: 'edit', label: 'Edit', hidden: !row.aksi.ubah, disabled: busy },
    { key: 'pdf', label: 'Unduh PDF', hidden: row.status !== 1, disabled: busy },
    { key: 'hapus', label: 'Hapus', danger: true, hidden: !row.aksi.hapus, disabled: busy },
  ]
}

function onAction(key: string, row: LkhItem): void {
  if (key === 'edit') {
    editing.value = row
    formOpen.value = true
  } else if (key === 'pdf') {
    void unduh(row)
  } else if (key === 'hapus') {
    hapusRow.value = row
    hapusOpen.value = true
  }
}

async function unduh(row: LkhItem): Promise<void> {
  busyId.value = row.id
  try {
    simpanBerkas(await lkhService.pdf(row.id))
  } catch (e) {
    notice.value = { tone: 'danger', text: describeApiError(e).message }
  } finally {
    busyId.value = null
  }
}

async function hapus(): Promise<void> {
  const row = hapusRow.value
  hapusOpen.value = false
  if (!row) return
  busyId.value = row.id
  try {
    await lkhService.remove(row.id)
    notice.value = { tone: 'success', text: 'Laporan kerja harian dihapus.' }
    await muat()
  } catch (e) {
    notice.value = { tone: 'danger', text: describeApiError(e).message }
  } finally {
    busyId.value = null
    hapusRow.value = null
  }
}

function tersimpan(): void {
  notice.value = { tone: 'success', text: 'Laporan kerja harian disimpan.' }
  void muat()
}

function tambah(): void {
  editing.value = null
  formOpen.value = true
}
</script>

<template>
  <UiCard :title="descriptor.label" data-testid="riwayat-section-lkh">
    <template #actions>
      <UiButton v-if="descriptor.can_create && opsi" data-testid="lkh-tab-tambah" @click="tambah">
        <Plus class="h-4 w-4" aria-hidden="true" /> Tambah LKH
      </UiButton>
    </template>

    <UiNotice v-if="notice" :tone="notice.tone" class="mb-4" data-testid="lkh-tab-notice">{{ notice.text }}</UiNotice>

    <div class="scrollbar-slim overflow-x-auto">
      <table class="w-full min-w-[40rem] border-collapse text-left text-body1" data-testid="lkh-tab-table">
        <thead>
          <tr class="border-b border-slate-200 text-body2 font-semibold text-slate-900">
            <th scope="col" class="px-4 py-3">Tanggal</th>
            <th scope="col" class="px-4 py-3">Kegiatan</th>
            <th scope="col" class="px-4 py-3">Atasan</th>
            <th scope="col" class="px-4 py-3">Status</th>
            <th scope="col" class="sticky right-0 bg-white px-4 py-3 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          <tr v-if="memuat && !data">
            <td colspan="5" class="px-4 py-8 text-center text-body2 text-slate-500">Memuat…</td>
          </tr>
          <tr v-else-if="!data || data.items.length === 0">
            <td colspan="5" class="px-4 py-10 text-center text-body2 text-slate-500" data-testid="lkh-tab-empty">Belum ada laporan kerja harian.</td>
          </tr>
          <tr v-for="row in data?.items ?? []" v-else :key="row.id" :data-testid="`lkh-tab-row-${row.id}`">
            <td class="px-4 py-3 text-slate-700">{{ tgl(row.tgl_laporan) }}</td>
            <td class="px-4 py-3 text-slate-700">
              {{ row.jumlah }} kegiatan
              <span v-if="row.catatan" class="block text-caption text-slate-500">Catatan: {{ row.catatan }}</span>
            </td>
            <td class="px-4 py-3 text-slate-700">{{ row.nama_atasan }}</td>
            <td class="px-4 py-3"><UiBadge :tone="STATUS[row.status].tone">{{ STATUS[row.status].label }}</UiBadge></td>
            <td class="sticky right-0 bg-white px-4 py-3 text-right">
              <RowActionsMenu
                :actions="actionsFor(row)"
                :label="`Aksi untuk LKH ${row.nama} ${tgl(row.tgl_laporan)}`"
                :testid="`lkh-actions-${row.id}`"
                @select="(key) => onAction(key, row)"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <UiPagination v-if="data && data.meta.total > PER_PAGE" v-model:page="page" class="mt-4" :per-page="PER_PAGE" :total="data.meta.total" />

    <LkhFormDialog v-model:open="formOpen" :opsi="opsi" :row="editing" @saved="tersimpan" />
    <ConfirmDialog
      :open="hapusOpen"
      title="Hapus laporan kerja harian?"
      :description="hapusRow ? `LKH tanggal ${tgl(hapusRow.tgl_laporan)} akan dihapus.` : ''"
      confirm-label="Ya, hapus"
      danger
      @update:open="(v) => (hapusOpen = v)"
      @confirm="hapus"
    />
  </UiCard>
</template>
