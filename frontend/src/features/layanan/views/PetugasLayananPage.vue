<script setup lang="ts">
/**
 * Petugas Layanan (PIC), role 1 saja (PRD Fase 4 §6.10; brief §6). Daftar PIC per jenis layanan dengan filter jenis, status,
 * dan pencarian. Tambah (`can_create`); ⋮ Edit → Aktifkan/Nonaktifkan → Hapus, dengan item dari `aksi {}` server.
 * Hapus lewat ConfirmDialog (hapus fisik); aktifkan/nonaktifkan langsung lewat PUT kolom status.
 */
import { Plus } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'

import { describeApiError } from '@/features/kepegawaian/services/apiErrors'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import { useStickyActionsShadow } from '@/shared/composables/useStickyActionsShadow'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiBadge, UiButton, UiCard, UiNotice, UiPagination, UiSearchInput, UiSelect } from '@/shared/ui'
import type { SelectOption } from '@/shared/ui'

import PetugasLayananFormDialog from '../components/PetugasLayananFormDialog.vue'
import { aksiBarisPetugas, labelAksiPetugas } from '../rowActionsPetugas'
import {
  petugasLayananService,
  type JenisLayanan,
  type PetugasLayanan,
  type PetugasListResponse,
} from '../services/petugasLayanan.service'

const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Layanan' }, { label: 'Petugas Layanan (PIC)' }]
const PER_PAGE = 20

const jenis = ref<JenisLayanan[]>([])
const idJenis = ref('')
const status = ref('')
const search = ref('')
const page = ref(1)
const hasil = ref<PetugasListResponse | null>(null)
const memuat = ref(false)
const failure = ref<string | null>(null)
const notice = ref('')

const jenisOpsi = computed<SelectOption[]>(() => [{ value: '', label: 'Semua Jenis Layanan' }, ...jenis.value.map((j) => ({ value: String(j.id_jenis_layanan), label: j.jenis_layanan }))])
const statusOpsi: SelectOption[] = [
  { value: '', label: 'Semua Status' },
  { value: '1', label: 'Aktif' },
  { value: '0', label: 'Tidak Aktif' },
]

const tableScroller = ref<HTMLElement | null>(null)
const { actionsShadow, updateActionsShadow } = useStickyActionsShadow(tableScroller, [hasil, memuat])

async function muat(): Promise<void> {
  memuat.value = true
  failure.value = null
  try {
    hasil.value = await petugasLayananService.list({ id_jenis_layanan: idJenis.value, status: status.value, search: search.value, page: page.value, per_page: PER_PAGE })
  } catch (err) {
    hasil.value = null
    failure.value = describeApiError(err).message
  } finally {
    memuat.value = false
  }
}

let timer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    page.value = 1
    void muat()
  }, 300)
})
watch([idJenis, status], () => {
  page.value = 1
  void muat()
})
watch(page, () => void muat())

// --- Aksi baris (⋮) --------------------------------------------------------------------------------------------------
const formOpen = ref(false)
const formRow = ref<PetugasLayanan | null>(null)
const hapus = ref<{ open: boolean; row: PetugasLayanan | null; loading: boolean }>({ open: false, row: null, loading: false })
const sibuk = ref<number | null>(null)

function tambah(): void {
  notice.value = ''
  formRow.value = null
  formOpen.value = true
}

async function onAksi(row: PetugasLayanan, key: string): Promise<void> {
  notice.value = ''
  if (key === 'edit') {
    formRow.value = row
    formOpen.value = true
  } else if (key === 'aktifkan' || key === 'nonaktifkan') {
    sibuk.value = row.id
    try {
      await petugasLayananService.ubah(row.id, { status: key === 'aktifkan' ? 1 : 0 })
      notice.value = `PIC ${row.nama ?? row.nip} ${key === 'aktifkan' ? 'diaktifkan' : 'dinonaktifkan'}.`
    } catch (err) {
      failure.value = describeApiError(err).message
    } finally {
      sibuk.value = null
      void muat()
    }
  } else if (key === 'hapus') {
    hapus.value = { open: true, row, loading: false }
  }
}

function onSelesai(pesan: string): void {
  notice.value = pesan
  void muat()
}

async function konfirmasiHapus(): Promise<void> {
  const row = hapus.value.row
  if (!row) return
  hapus.value.loading = true
  try {
    await petugasLayananService.hapus(row.id)
    notice.value = `PIC ${row.nama ?? row.nip} dihapus.`
  } catch (err) {
    failure.value = describeApiError(err).message
  } finally {
    hapus.value = { open: false, row: null, loading: false }
    void muat()
  }
}

onMounted(async () => {
  try {
    jenis.value = await petugasLayananService.jenis()
  } catch {
    jenis.value = []
  }
  void muat()
})
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="page-fill flex flex-col gap-4" data-testid="layanan-petugas-page">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-h2 font-semibold text-slate-900">Petugas Layanan (PIC)</h1>
          <p class="text-body2 text-slate-500">Pegawai yang memproses pengajuan Tugas Belajar, Izin Belajar, dan Peninjauan Masa Kerja.</p>
        </div>
        <UiButton v-if="hasil?.can_create" data-testid="layanan-petugas-tambah" @click="tambah"><Plus class="h-4 w-4" aria-hidden="true" /> Tambah PIC</UiButton>
      </div>

      <UiNotice v-if="notice" tone="success" data-testid="layanan-petugas-notice">{{ notice }}</UiNotice>
      <UiNotice v-if="failure" tone="danger" data-testid="layanan-petugas-failure">{{ failure }}</UiNotice>

      <UiCard title="Daftar PIC" flush fill class="flex min-h-0 flex-1 flex-col">
        <div class="flex flex-wrap items-end gap-3 border-b border-slate-100 p-4">
          <UiSelect v-model="idJenis" :options="jenisOpsi" size="sm" aria-label="Filter jenis layanan" class="w-60" data-testid="layanan-petugas-filter-jenis" />
          <UiSelect v-model="status" :options="statusOpsi" size="sm" aria-label="Filter status" class="w-44" data-testid="layanan-petugas-filter-status" />
          <UiSearchInput v-model="search" placeholder="Cari NIP / nama" label="Cari NIP atau nama PIC" class="min-w-[200px] flex-1" data-testid="layanan-petugas-search" />
        </div>

        <div ref="tableScroller" class="table-fill scrollbar-slim overflow-x-auto" data-testid="layanan-petugas-table-scroll" @scroll.passive="updateActionsShadow">
          <table class="min-w-full text-sm" data-testid="layanan-petugas-tabel">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th class="whitespace-nowrap px-4 py-3">NIP</th>
                <th class="min-w-[12rem] px-4 py-3">Nama</th>
                <th class="min-w-[10rem] px-4 py-3">Jabatan</th>
                <th class="min-w-[10rem] px-4 py-3">Satker</th>
                <th class="min-w-[10rem] px-4 py-3">Unit</th>
                <th class="min-w-[10rem] px-4 py-3">Jenis Layanan</th>
                <th class="whitespace-nowrap px-4 py-3">Status</th>
                <th class="sticky right-0 z-10 whitespace-nowrap bg-slate-50 px-4 py-3 text-right" :class="{ 'shadow-sticky-end': actionsShadow }">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-if="memuat">
                <td colspan="8" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
              </tr>
              <tr v-else-if="!hasil || hasil.items.length === 0">
                <td colspan="8" class="px-4 py-8 text-center text-slate-500" data-testid="layanan-petugas-kosong">Belum ada PIC.</td>
              </tr>
              <tr v-for="row in hasil?.items ?? []" v-else :key="row.id" class="group hover:bg-slate-50" :data-testid="`layanan-petugas-baris-${row.id}`">
                <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-700">{{ row.nip }}</td>
                <td class="px-4 py-3 font-medium text-slate-800">{{ row.nama ?? '-' }}</td>
                <td class="px-4 py-3 text-slate-600">{{ row.jabatan ?? '-' }}</td>
                <td class="px-4 py-3 text-slate-600">{{ row.satker ?? '-' }}</td>
                <td class="px-4 py-3 text-slate-600">{{ row.unit ?? '-' }}</td>
                <td class="px-4 py-3 text-slate-600">{{ row.jenis_layanan ?? '-' }}</td>
                <td class="px-4 py-3" :data-testid="`layanan-petugas-status-${row.id}`">
                  <UiBadge :tone="row.status === 1 ? 'success' : 'neutral'" size="sm" class="whitespace-nowrap">{{ row.label_status }}</UiBadge>
                </td>
                <td class="sticky right-0 z-10 bg-white px-4 py-3 text-right group-hover:bg-slate-50" :class="{ 'shadow-sticky-end': actionsShadow }">
                  <RowActionsMenu
                    :actions="aksiBarisPetugas(row, { busy: sibuk === row.id })"
                    :label="labelAksiPetugas(row)"
                    :testid="`layanan-petugas-actions-${row.id}`"
                    @select="onAksi(row, $event)"
                  />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="hasil && hasil.total > PER_PAGE" class="p-4">
          <UiPagination v-model:page="page" :per-page="PER_PAGE" :total="hasil.total" />
        </div>
      </UiCard>

      <PetugasLayananFormDialog v-model:open="formOpen" :row="formRow" :jenis="jenis" @saved="onSelesai(formRow ? 'PIC diperbarui.' : 'PIC ditambahkan.')" />
      <ConfirmDialog
        :open="hapus.open"
        title="Hapus PIC Layanan"
        :description="`Hapus ${hapus.row?.nama ?? hapus.row?.nip ?? 'PIC'} sebagai PIC? Data dihapus permanen dan tercatat di audit.`"
        confirm-label="Ya, hapus"
        danger
        :loading="hapus.loading"
        @update:open="(v) => !hapus.loading && (hapus.open = v)"
        @confirm="konfirmasiHapus"
      />
    </div>
  </RedesignShell>
</template>
