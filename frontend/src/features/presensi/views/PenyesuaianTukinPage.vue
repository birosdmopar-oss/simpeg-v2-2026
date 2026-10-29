<script setup lang="ts">
/**
 * Penyesuaian Tukin per NIP (5B MAKE-045, PRD 5B §7.3.10; PRD 5C §7.4; role 1 saja). Daftar paginasi server dengan
 * filter NIP, Bulan, dan Status; tambah lewat tombol halaman, ubah & hapus lewat ⋮ (Edit → Hapus `danger` + ConfirmDialog,
 * AGENTS §1). Aktifkan/Nonaktifkan lewat ⋮ tanpa konfirmasi (pola Master Data: tidak destruktif). Kolom Status hanya
 * badge. Tanpa frame Figma → pola halaman 5A (AGENTS §3.6).
 */
import { Pencil, Plus, Power, PowerOff, Trash2 } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { useStickyActionsShadow } from '@/shared/composables/useStickyActionsShadow'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiBadge, UiButton, UiNotice, UiPagination, UiSearchInput, UiSelect, UiTextField } from '@/shared/ui'

import { describeApiError } from '@/features/kepegawaian/services/apiErrors'

import PenyesuaianTukinFormDialog from '../components/PenyesuaianTukinFormDialog.vue'
import {
  formatPersen,
  labelBulan,
  penyesuaianTukinService,
  type DaftarPenyesuaian,
  type PenyesuaianTukin,
  type StatusPenyesuaian,
} from '../services/penyesuaianTukin.service'

const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Presensi' }, { label: 'Penyesuaian Tukin' }]

const PER_PAGE = 25
const nip = ref('')
const bulan = ref('')
const status = ref('')
const page = ref(1)
const daftar = ref<DaftarPenyesuaian | null>(null)
const memuat = ref(false)
const failure = ref<string | null>(null)
const notice = ref('')

const statusFilter = computed<StatusPenyesuaian | undefined>(() => (status.value === '1' ? 1 : status.value === '2' ? 2 : undefined))
const OPSI_STATUS_FILTER = [
  { value: '1', label: 'Aktif' },
  { value: '2', label: 'Nonaktif' },
]

async function muat(): Promise<void> {
  memuat.value = true
  failure.value = null
  try {
    daftar.value = await penyesuaianTukinService.daftar({
      nip: nip.value.trim() || undefined,
      bulan: bulan.value || undefined,
      status: statusFilter.value,
      page: page.value,
      per_page: PER_PAGE,
    })
  } catch (err) {
    daftar.value = null
    failure.value = describeApiError(err).message
  } finally {
    memuat.value = false
  }
}

let timer: ReturnType<typeof setTimeout> | undefined
watch(nip, () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    page.value = 1
    void muat()
  }, 300)
})
watch([bulan, status], () => {
  page.value = 1
  void muat()
})
watch(page, () => void muat())

const tableScroller = ref<HTMLElement | null>(null)
const { actionsShadow, updateActionsShadow } = useStickyActionsShadow(tableScroller, [daftar, memuat])

const periode = (b: PenyesuaianTukin): string => `${labelBulan(b.bulan_mulai)} s.d. ${labelBulan(b.bulan_akhir)}`
const labelAksi = (b: PenyesuaianTukin): string => `Aksi untuk penyesuaian ${b.nama || b.nip} ${periode(b)}`

function aksiBaris(b: PenyesuaianTukin): RowAction[] {
  const busy = busyId.value !== null
  return [
    { key: 'edit', label: 'Edit', icon: Pencil, disabled: busy },
    { key: 'aktifkan', label: 'Aktifkan', icon: Power, hidden: b.status !== 2, disabled: busy },
    { key: 'nonaktifkan', label: 'Nonaktifkan', icon: PowerOff, hidden: b.status !== 1, disabled: busy },
    { key: 'hapus', label: 'Hapus', icon: Trash2, danger: true, disabled: busy },
  ]
}

/** Id baris yang sedang diubah statusnya (⋮ dinonaktifkan selama memproses). */
const busyId = ref<number | null>(null)

async function ubahStatus(b: PenyesuaianTukin, status: 1 | 2): Promise<void> {
  notice.value = ''
  busyId.value = b.id
  try {
    await penyesuaianTukinService.ubahStatus(b, status)
    notice.value = `Penyesuaian Tukin ${b.nama || b.nip} (${periode(b)}) ${status === 1 ? 'diaktifkan' : 'dinonaktifkan'}.`
    await muat()
  } catch (err) {
    failure.value = isApiError(err) && err.message && err.status !== null && err.status < 500 ? err.message : describeApiError(err).message
  } finally {
    busyId.value = null
  }
}

// --- Tambah / ubah / hapus -----------------------------------------------------------------------------------------
const dialogOpen = ref(false)
const barisEdit = ref<PenyesuaianTukin | null>(null)
const konfirmasi = ref<{ open: boolean; baris: PenyesuaianTukin | null; loading: boolean }>({ open: false, baris: null, loading: false })

function tambah(): void {
  notice.value = ''
  barisEdit.value = null
  dialogOpen.value = true
}

function onAksi(b: PenyesuaianTukin, key: string): void {
  notice.value = ''
  if (key === 'edit') {
    barisEdit.value = b
    dialogOpen.value = true
  } else if (key === 'aktifkan') {
    void ubahStatus(b, 1)
  } else if (key === 'nonaktifkan') {
    void ubahStatus(b, 2)
  } else if (key === 'hapus') {
    konfirmasi.value = { open: true, baris: b, loading: false }
  }
}

function onTersimpan(pesan: string): void {
  notice.value = pesan
  void muat()
}

async function hapus(): Promise<void> {
  const b = konfirmasi.value.baris
  if (!b) return
  konfirmasi.value.loading = true
  try {
    await penyesuaianTukinService.hapus(b.id)
    notice.value = `Penyesuaian Tukin ${b.nama || b.nip} (${periode(b)}) dihapus.`
  } catch (err) {
    failure.value = isApiError(err) && err.message && err.status !== null && err.status < 500 ? err.message : describeApiError(err).message
  } finally {
    konfirmasi.value = { open: false, baris: null, loading: false }
    void muat()
  }
}

onMounted(() => void muat())
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="page-fill flex flex-col gap-5" data-testid="penyesuaian-tukin-page">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-h2 font-semibold text-slate-900">Penyesuaian Tukin</h1>
          <p class="text-body2 text-slate-500">Penyesuaian persen Tukin per NIP dan rentang bulan. Hanya Super Admin.</p>
        </div>
        <UiButton data-testid="penyesuaian-tukin-tambah" @click="tambah">
          <Plus class="h-4 w-4" aria-hidden="true" /> Tambah Penyesuaian
        </UiButton>
      </div>

      <UiNotice v-if="notice" tone="success" data-testid="penyesuaian-tukin-notice">{{ notice }}</UiNotice>
      <UiNotice v-if="failure" tone="danger" data-testid="penyesuaian-tukin-failure">{{ failure }}</UiNotice>

      <div class="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-3">
        <UiSearchInput v-model="nip" placeholder="Cari NIP" label="Cari NIP" class="min-w-[200px] flex-1" data-testid="penyesuaian-tukin-cari" />
        <UiTextField v-model="bulan" type="month" label="Bulan berlaku" class="w-52" data-testid="penyesuaian-tukin-bulan" />
        <UiSelect
          v-model="status"
          :options="OPSI_STATUS_FILTER"
          clearable
          size="sm"
          placeholder="Semua Status"
          aria-label="Filter status"
          class="w-44"
          data-testid="penyesuaian-tukin-status-filter"
        />
      </div>

      <div ref="tableScroller" class="table-fill scrollbar-slim overflow-x-auto rounded-2xl border border-slate-200 bg-white" @scroll.passive="updateActionsShadow">
        <table class="min-w-full text-sm" data-testid="penyesuaian-tukin-tabel">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="whitespace-nowrap px-4 py-3">NIP</th>
              <th class="min-w-[12rem] px-4 py-3">Nama Pegawai</th>
              <th class="min-w-[14rem] px-4 py-3">Periode</th>
              <th class="whitespace-nowrap px-4 py-3 text-right">Persen</th>
              <th class="min-w-[14rem] px-4 py-3">Alasan</th>
              <th class="whitespace-nowrap px-4 py-3">Status</th>
              <th class="sticky right-0 z-10 whitespace-nowrap bg-slate-50 px-4 py-3 text-right" :class="{ 'shadow-sticky-end': actionsShadow }">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="memuat">
              <td colspan="7" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
            </tr>
            <tr v-else-if="!daftar || daftar.items.length === 0">
              <td colspan="7" class="px-4 py-8 text-center text-slate-500" data-testid="penyesuaian-tukin-kosong">Tidak ada penyesuaian untuk filter ini.</td>
            </tr>
            <tr v-for="b in daftar?.items ?? []" v-else :key="b.id" class="group hover:bg-slate-50" :data-testid="`penyesuaian-tukin-baris-${b.id}`">
              <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-700">{{ b.nip }}</td>
              <td class="px-4 py-3 font-medium text-slate-800">{{ b.nama || '-' }}</td>
              <td class="px-4 py-3 text-slate-700">{{ periode(b) }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-slate-800">{{ formatPersen(b.persen) }}</td>
              <td class="px-4 py-3 text-slate-600">{{ b.alasan }}</td>
              <td class="px-4 py-3">
                <UiBadge :tone="b.status === 1 ? 'success' : 'neutral'" size="sm" :data-testid="`penyesuaian-tukin-status-${b.id}`">
                  {{ b.status === 1 ? 'Aktif' : 'Nonaktif' }}
                </UiBadge>
              </td>
              <td class="sticky right-0 z-10 bg-white px-4 py-3 text-right group-hover:bg-slate-50" :class="{ 'shadow-sticky-end': actionsShadow }">
                <RowActionsMenu :actions="aksiBaris(b)" :label="labelAksi(b)" :testid="`penyesuaian-actions-${b.id}`" @select="onAksi(b, $event)" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <UiPagination v-if="daftar && daftar.total > PER_PAGE" v-model:page="page" :per-page="PER_PAGE" :total="daftar.total" />

      <PenyesuaianTukinFormDialog v-model:open="dialogOpen" :baris="barisEdit" @saved="onTersimpan" />
      <ConfirmDialog
        :open="konfirmasi.open"
        title="Hapus Penyesuaian Tukin"
        :description="`Hapus penyesuaian ${konfirmasi.baris?.nama || konfirmasi.baris?.nip || ''} (${konfirmasi.baris ? periode(konfirmasi.baris) : ''})? Data dinonaktifkan dan tercatat di audit.`"
        confirm-label="Ya, hapus"
        danger
        :loading="konfirmasi.loading"
        @update:open="(v) => !konfirmasi.loading && (konfirmasi.open = v)"
        @confirm="hapus"
      />
    </div>
  </RedesignShell>
</template>
