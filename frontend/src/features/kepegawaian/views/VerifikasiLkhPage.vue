<script setup lang="ts">
/**
 * Laporan Kerja Harian (B-12b, pemilik WS-2, MAKE-013) — role 1, 2, 3, 6, 7. Dua tab (`?tab=`):
 *  - "LKH Saya": daftar LKH sendiri (legacy `hr/rwy/lkh`, urut Revisi → Menunggu → Disetujui), Tambah, ⋮ Edit → Unduh
 *    PDF → Hapus (ConfirmDialog);
 *  - "Verifikasi Bawahan": antrean atasan langsung (`nip_atasan` = saya; legacy notifikasi need_approval), ⋮ Verifikasi
 *    → Unduh PDF.
 * PDF dibangkitkan server (TCPDF). Tab LKH di Detail Pegawai = `components/LkhTab.vue` (MAKE-014).
 */
import { Plus } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiBadge, UiButton, UiCard, UiNotice, UiPagination, UiSelect, UiTabs } from '@/shared/ui'

import LkhFormDialog from '../components/LkhFormDialog.vue'
import LkhProsesDialog from '../components/LkhProsesDialog.vue'
import { describeApiError } from '../services/apiErrors'
import { simpanBerkas } from '../services/berkasUnduhan'
import { lkhService, type AksiLkh } from '../services/lkh.service'
import type { LkhItem, LkhListResponse, LkhOpsi, StatusLkh } from '../types'

const route = useRoute()
const router = useRouter()
const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Laporan Kerja Harian' }]
const PER_PAGE = 20

const TABS = [
  { value: 'saya', label: 'LKH Saya' },
  { value: 'verifikasi', label: 'Verifikasi Bawahan' },
]
const tab = computed<string>({
  get: () => (route.query.tab === 'verifikasi' ? 'verifikasi' : 'saya'),
  set: (v) => void router.replace({ query: { ...route.query, tab: v } }),
})

const STATUS: Record<StatusLkh, { label: string; tone: 'warning' | 'success' | 'danger' | 'info' }> = {
  0: { label: 'Menunggu', tone: 'warning' },
  1: { label: 'Disetujui', tone: 'success' },
  2: { label: 'Ditolak', tone: 'danger' },
  3: { label: 'Revisi', tone: 'info' },
}

const opsi = ref<LkhOpsi | null>(null)
const data = ref<LkhListResponse | null>(null)
const page = ref(1)
const statusVerif = ref('0')
const memuat = ref(false)
const notice = ref<{ tone: 'danger' | 'success'; text: string } | null>(null)
const formOpen = ref(false)
const editing = ref<LkhItem | null>(null)
const prosesOpen = ref(false)
const prosesRow = ref<LkhItem | null>(null)
const prosesAksi = ref<AksiLkh>('setujui')
const hapusOpen = ref(false)
const hapusRow = ref<LkhItem | null>(null)
const busyId = ref<number | null>(null)
let token = 0

async function muat(): Promise<void> {
  const saya = ++token
  memuat.value = true
  try {
    const hasil = tab.value === 'verifikasi'
      ? await lkhService.verifikasi({ page: page.value, per_page: PER_PAGE, status: statusVerif.value })
      : await lkhService.list({ page: page.value, per_page: PER_PAGE })
    if (saya === token) data.value = hasil
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
    opsi.value = await lkhService.opsi()
  } catch {
    // Opsi gagal (mis. akun tanpa NIP) → tombol Tambah tidak tampil.
  }
})

watch(tab, () => {
  page.value = 1
  notice.value = null
  void muat()
}, { immediate: true })
watch([page, statusVerif], () => void muat())

function actionsFor(row: LkhItem): RowAction[] {
  const busy = busyId.value === row.id
  if (tab.value === 'verifikasi') {
    return [
      { key: 'verifikasi', label: 'Verifikasi', hidden: !row.aksi.proses, disabled: busy },
      // PDF hanya untuk LKH Disetujui (server 422 selain itu; keputusan user 13-10-2026).
      { key: 'pdf', label: 'Unduh PDF', hidden: row.status !== 1, disabled: busy },
    ]
  }
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
  } else if (key === 'verifikasi') {
    prosesRow.value = row
    prosesAksi.value = 'setujui'
    prosesOpen.value = true
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
    const berkas = await lkhService.pdf(row.id)
    simpanBerkas(berkas)
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

function selesai(teks: string): void {
  notice.value = { tone: 'success', text: teks }
  void muat()
}

function tambah(): void {
  editing.value = null
  formOpen.value = true
}

const STATUS_VERIF = [
  { value: '0', label: 'Menunggu' },
  { value: '3', label: 'Revisi' },
  { value: '1', label: 'Disetujui' },
  { value: '2', label: 'Ditolak' },
]
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="space-y-4" data-testid="lkh-page">
      <UiTabs v-model="tab" :items="TABS" aria-label="Laporan kerja harian" />

      <UiCard :title="tab === 'verifikasi' ? 'Verifikasi LKH Bawahan' : 'Laporan Kerja Harian Saya'">
        <template #actions>
          <UiButton v-if="tab === 'saya' && opsi" data-testid="lkh-tambah" @click="tambah"><Plus class="h-4 w-4" aria-hidden="true" /> Tambah LKH</UiButton>
        </template>

        <div v-if="tab === 'verifikasi'" class="mb-4 max-w-xs">
          <UiSelect v-model="statusVerif" label="Status" :options="STATUS_VERIF" />
        </div>

        <UiNotice v-if="notice" :tone="notice.tone" class="mb-4" data-testid="lkh-notice">{{ notice.text }}</UiNotice>

        <div class="scrollbar-slim overflow-x-auto">
          <table class="w-full min-w-[44rem] border-collapse text-left text-body1" data-testid="lkh-table">
            <thead>
              <tr class="border-b border-slate-200 text-body2 font-semibold text-slate-900">
                <th v-if="tab === 'verifikasi'" scope="col" class="px-4 py-3">Pegawai</th>
                <th scope="col" class="px-4 py-3">Tanggal</th>
                <th scope="col" class="px-4 py-3">Kegiatan</th>
                <th v-if="tab === 'saya'" scope="col" class="px-4 py-3">Atasan</th>
                <th scope="col" class="px-4 py-3">Status</th>
                <th scope="col" class="sticky right-0 bg-white px-4 py-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
              <tr v-if="memuat && !data">
                <td colspan="5" class="px-4 py-8 text-center text-body2 text-slate-500">Memuat…</td>
              </tr>
              <tr v-else-if="!data || data.items.length === 0">
                <td colspan="5" class="px-4 py-10 text-center text-body2 text-slate-500" data-testid="lkh-empty">Belum ada laporan kerja harian.</td>
              </tr>
              <tr v-for="row in data?.items ?? []" v-else :key="row.id" :data-testid="`lkh-row-${row.id}`">
                <td v-if="tab === 'verifikasi'" class="px-4 py-3">
                  <span class="block font-medium text-slate-900">{{ row.nama }}</span>
                  <span class="block text-body2 text-slate-500">{{ row.nip }}</span>
                </td>
                <td class="px-4 py-3 text-slate-700">{{ row.tgl_laporan }}</td>
                <td class="px-4 py-3 text-slate-700">
                  {{ row.jumlah }} kegiatan
                  <span v-if="row.catatan" class="block text-caption text-slate-500">Catatan: {{ row.catatan }}</span>
                </td>
                <td v-if="tab === 'saya'" class="px-4 py-3 text-slate-700">{{ row.nama_atasan }}</td>
                <td class="px-4 py-3"><UiBadge :tone="STATUS[row.status].tone">{{ STATUS[row.status].label }}</UiBadge></td>
                <td class="sticky right-0 bg-white px-4 py-3 text-right">
                  <RowActionsMenu
                    :actions="actionsFor(row)"
                    :label="`Aksi untuk LKH ${row.nama} ${row.tgl_laporan}`"
                    :testid="`lkh-actions-${row.id}`"
                    @select="(key) => onAction(key, row)"
                  />
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <UiPagination v-if="data && data.meta.total > PER_PAGE" v-model:page="page" class="mt-4" :per-page="PER_PAGE" :total="data.meta.total" />
      </UiCard>
    </div>

    <LkhFormDialog v-model:open="formOpen" :opsi="opsi" :row="editing" @saved="selesai('Laporan kerja harian disimpan.')" />
    <LkhProsesDialog v-model:open="prosesOpen" :row="prosesRow" :aksi-awal="prosesAksi" @processed="selesai('Laporan kerja harian diproses.')" />
    <ConfirmDialog
      :open="hapusOpen"
      title="Hapus laporan kerja harian?"
      :description="hapusRow ? `LKH tanggal ${hapusRow.tgl_laporan} akan dihapus.` : ''"
      confirm-label="Ya, hapus"
      danger
      @update:open="(v) => (hapusOpen = v)"
      @confirm="hapus"
    />
  </RedesignShell>
</template>
