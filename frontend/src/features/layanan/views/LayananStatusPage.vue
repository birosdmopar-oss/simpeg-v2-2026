<script setup lang="ts">
/**
 * Status Layanan (PRD §6.11.1, Gambar 17): monitoring semua pengajuan layanan (cuti, TB, IB, PMK) dengan tahap dan PIC.
 * Kartu "Status Layanan": filter Unit/Satker (role 1, 3, 4, 5), Jenis, Status (berjalan/selesai), pencarian, jumlah baris
 * 10/25/50, tabel + ⋮ "Lihat detail" (hanya bila `aksi.lihat_detail` server), paginasi. Lingkup data dan hak lihat
 * ditegakkan server; halaman read-only (tanpa aksi tulis). Role 8 → 403.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { describeApiError } from '@/features/kepegawaian/services/apiErrors'
import { masterService } from '@/features/master-data/services/master.service'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiCard, UiNotice, UiPagination, UiSearchInput, UiSelect, type SelectOption } from '@/shared/ui'

import LayananStatusTable from '../components/LayananStatusTable.vue'
import { STATUS_LAYANAN_FILTER_UNIT_ROLES } from '../roles'
import { layananStatusService } from '../services/layananStatus.service'
import type { LayananStatusItem, LayananStatusResponse } from '../types'

const router = useRouter()
const auth = useAuthStore()
const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Layanan' }, { label: 'Status Layanan' }]

const PER_PAGE_OPTIONS = ['10', '25', '50'].map((v) => ({ value: v, label: v }))
const STATUS_OPSI = [
  { value: 'berjalan', label: 'Sedang Berjalan' },
  { value: 'selesai', label: 'Selesai' },
]
/** Nilai `jenis` yang diterima `GET layanan/status` (bukan master `jenis_layanan`, yang memuat KARPEG/KARISKARSU dan tanpa cuti). */
const JENIS_OPSI: SelectOption[] = [
  { value: 'cuti', label: 'Cuti' },
  { value: 'tb', label: 'Tugas Belajar' },
  { value: 'ib', label: 'Izin Belajar' },
  { value: 'pmk', label: 'PMK' },
]

const filterUnit = computed(() => auth.role !== null && STATUS_LAYANAN_FILTER_UNIT_ROLES.includes(auth.role))

const data = ref<LayananStatusResponse | null>(null)
const memuat = ref(false)
const errorMuat = ref('')
const page = ref(1)
const perPage = ref('10')
const search = ref('')
const jenis = ref('')
const status = ref('')
const idUnit = ref('')
const idSatker = ref('')
const unitOpsi = ref<SelectOption[]>([])
const satkerOpsi = ref<SelectOption[]>([])
let token = 0
let timer: ReturnType<typeof setTimeout> | undefined

async function muat(): Promise<void> {
  const saya = ++token
  memuat.value = true
  errorMuat.value = ''
  try {
    const hasil = await layananStatusService.list({
      page: page.value,
      per_page: Number(perPage.value),
      jenis: jenis.value,
      status: status.value,
      id_unit: idUnit.value,
      id_satker: idSatker.value,
      search: search.value.trim(),
    })
    if (saya !== token) return
    data.value = hasil
  } catch (e) {
    if (saya !== token) return
    data.value = null
    errorMuat.value = describeApiError(e).message
  } finally {
    if (saya === token) memuat.value = false
  }
}

const keOpsi = (list: { id: string; nama: string }[]): SelectOption[] => list.map((o) => ({ value: o.id, label: o.nama }))

async function muatOpsiMaster(entity: string, parent?: string): Promise<SelectOption[]> {
  try {
    return keOpsi(await masterService.options(entity, parent))
  } catch {
    // Opsi filter gagal dimuat: filter itu kosong; daftar tetap bisa dipakai.
    return []
  }
}

onMounted(async () => {
  void muat()
  if (filterUnit.value) unitOpsi.value = await muatOpsiMaster('unit')
})

watch(idUnit, async (unit) => {
  satkerOpsi.value = unit ? await muatOpsiMaster('satker', unit) : []
  if (idSatker.value && !satkerOpsi.value.some((o) => String(o.value) === idSatker.value)) idSatker.value = ''
})
watch(page, () => void muat())
watch([jenis, status, idUnit, idSatker, perPage], () => {
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

function onAction(key: string, row: LayananStatusItem): void {
  if (key === 'detail' && row.url_detail) void router.push(row.url_detail)
}
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="page-fill flex flex-col gap-4" data-testid="layanan-status-page">
      <div>
        <h1 class="text-h2 font-semibold text-slate-900">Status Layanan</h1>
        <p class="text-body2 text-slate-500">Pantau tahap dan PIC seluruh pengajuan layanan.</p>
      </div>
      <UiCard title="Daftar Pengajuan" class="flex min-h-0 flex-1 flex-col" fill>
        <div class="mb-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4" data-testid="layanan-status-filter">
          <UiSelect v-if="filterUnit" v-model="idUnit" label="Unit" placeholder="Semua Unit" :options="unitOpsi" clearable />
          <UiSelect v-if="filterUnit" v-model="idSatker" label="Satker" :placeholder="idUnit ? 'Semua Satker' : 'Pilih Unit Dulu'" :options="satkerOpsi" :disabled="!idUnit" clearable />
          <UiSelect v-model="jenis" label="Jenis Layanan" placeholder="Semua Jenis Layanan" :options="JENIS_OPSI" clearable />
          <UiSelect v-model="status" label="Status" placeholder="Semua Status" :options="STATUS_OPSI" clearable />
        </div>
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
          <div class="w-64 max-w-full"><UiSearchInput v-model="search" label="Cari pegawai" placeholder="Cari nama atau NIP" /></div>
          <div class="w-24"><UiSelect v-model="perPage" :options="PER_PAGE_OPTIONS" placeholder="10" aria-label="Jumlah baris" /></div>
        </div>

        <UiNotice v-if="errorMuat" tone="danger" class="mb-4" data-testid="layanan-status-error">{{ errorMuat }}</UiNotice>

        <LayananStatusTable :items="data?.items ?? []" :memuat="memuat" @action="onAction" />

        <UiPagination v-if="data && data.total > Number(perPage)" v-model:page="page" class="mt-4" :per-page="Number(perPage)" :total="data.total" />
      </UiCard>
    </div>
  </RedesignShell>
</template>
