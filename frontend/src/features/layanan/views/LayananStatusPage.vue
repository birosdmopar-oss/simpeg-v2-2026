<script setup lang="ts">
/**
 * Status Layanan (§4.1.4, Gambar 17) — Fase 4, C-10. Filter Unit/Satker + Status Layanan, pemilih jumlah baris, pencarian,
 * tabel dengan stepper status per layanan (Izin Belajar, Tugas Belajar, Cuti), paginasi, dan Export (popup).
 * Akses: role 1–7 (hr/rwy/layanan/index). ASUMSI: pegawai biasa melihat daftar yang sama pada data contoh; pembatasan
 * "hanya milik sendiri" ditegakkan backend.
 *
 * DATA CONTOH: layanan dari mock; Export & detail belum tersambung dan memberi pesan jelas.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { FACETS } from '@/features/kepegawaian/services/pegawai.mock'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiAvatar, UiCard, UiExportMenu, UiNotice, UiPagination, UiSearchInput, UiSelect, UiStepper } from '@/shared/ui'
import { placeholderAvatar } from '@/shared/ui/placeholderAssets'

import { layananService } from '../services/layanan.service'
import type { JenisLayanan, LayananRow } from '../types'

const unit = ref('')
const status = ref('')
const search = ref('')
const perPage = ref('10')
const page = ref(1)
const rows = ref<LayananRow[]>([])
const total = ref(0)
const loading = ref(true)
const error = ref(false)
const notice = ref<string | null>(null)

const TONE: Record<JenisLayanan, 'info' | 'primary' | 'warning'> = { 'Izin Belajar': 'info', 'Tugas Belajar': 'primary', Cuti: 'warning' }
const STATUS_OPTIONS = [
  { value: 'Berjalan', label: 'Berjalan' },
  { value: 'Selesai', label: 'Selesai' },
]
const PER_PAGE_OPTIONS = ['10', '25', '50'].map((v) => ({ value: v, label: v }))

let requestId = 0
let timer: ReturnType<typeof setTimeout> | null = null

async function load(): Promise<void> {
  const current = ++requestId
  loading.value = true
  error.value = false
  try {
    const result = await layananService.list({
      page: page.value,
      per_page: Number(perPage.value),
      search: search.value || undefined,
      unit: unit.value || undefined,
      status: status.value || undefined,
    })
    if (current !== requestId) return
    rows.value = result.items
    total.value = result.total
  } catch {
    if (current === requestId) error.value = true
  } finally {
    if (current === requestId) loading.value = false
  }
}

function schedule(reset: boolean): void {
  if (reset) page.value = 1
  if (timer) clearTimeout(timer)
  timer = setTimeout(load, 250)
}

watch([unit, status, search, perPage], () => schedule(true))
watch(page, () => schedule(false))
onMounted(load)
onBeforeUnmount(() => {
  if (timer) clearTimeout(timer)
})

const actions = (): RowAction[] => [{ key: 'detail', label: 'Lihat detail' }]
const avatar = (r: LayananRow): string => placeholderAvatar(r.foto_seed)
const summary = (r: LayananRow): string =>
  r.langkah.map((l) => `${l.label}: ${l.state === 'done' ? 'selesai' : l.state === 'current' ? 'berjalan' : 'belum'}`).join(', ')
const crumbs = computed(() => [{ label: 'Home', to: { name: 'home' } }, { label: 'Layanan' }, { label: 'Status Layanan' }])

function onAction(r: LayananRow): void {
  notice.value = `Detail layanan ${r.jenis} (${r.nama}) belum tersambung ke backend — akan aktif bersama task C-10.`
}
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <UiNotice v-if="notice" tone="info" dismissible class="mb-4" @dismiss="notice = null">{{ notice }}</UiNotice>

    <UiCard title="Status Layanan" flush>
      <template #actions>
        <UiExportMenu @select="notice = 'Export belum tersambung ke backend — akan aktif bersama task C-10.'" />
      </template>

      <div class="mt-2 space-y-5 pb-5">
        <div class="grid gap-4 border-b border-slate-200 px-5 pb-5 md:grid-cols-2">
          <UiSelect v-model="unit" label="Unit/Satker" :options="FACETS.unit" placeholder="Kementerian Pariwisata..." clearable />
          <UiSelect v-model="status" label="Status Layanan" :options="STATUS_OPTIONS" placeholder="Semua Status Layanan" clearable />
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 px-5">
          <div class="w-24"><UiSelect v-model="perPage" :options="PER_PAGE_OPTIONS" placeholder="10" aria-label="Jumlah baris" /></div>
          <div class="w-64 max-w-full"><UiSearchInput v-model="search" placeholder="Search" label="Cari layanan" /></div>
        </div>

        <UiNotice v-if="error" tone="danger" class="mx-5">Data layanan gagal dimuat. Coba lagi.</UiNotice>

        <div class="scrollbar-slim overflow-x-auto">
          <table class="w-full min-w-[54rem] border-collapse text-left text-body1" data-testid="layanan-table">
            <thead>
              <tr class="border-y border-slate-200 text-body2 font-semibold text-slate-900">
                <th scope="col" class="px-5 py-3">Nama Pegawai</th>
                <th scope="col" class="px-5 py-3">Jenis Layanan</th>
                <th scope="col" class="px-5 py-3">Status Layanan</th>
                <th scope="col" class="px-5 py-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
              <template v-if="loading && rows.length === 0">
                <tr v-for="n in 4" :key="n" aria-hidden="true">
                  <td colspan="4" class="px-5 py-8"><div class="h-4 w-1/2 animate-pulse rounded bg-slate-200" /></td>
                </tr>
              </template>
              <tr v-else-if="rows.length === 0">
                <td colspan="4" class="px-5 py-14 text-center" data-testid="layanan-empty">
                  <p class="text-body1 font-medium text-slate-700">Tidak ada layanan yang cocok</p>
                  <p class="text-body2 text-slate-500">Ubah kata kunci atau filter.</p>
                </td>
              </tr>
              <template v-else>
                <tr v-for="r in rows" :key="r.id" class="align-middle transition-colors hover:bg-slate-50/70" :data-testid="`layanan-row-${r.id}`">
                  <td class="px-5 py-4">
                    <div class="flex items-center gap-3">
                      <UiAvatar :name="r.nama" :src="avatar(r)" size="sm" alt="" />
                      <div class="min-w-0">
                        <p class="font-medium text-slate-900">{{ r.nama }}</p>
                        <p class="text-body2 text-slate-500">{{ r.nip }}</p>
                      </div>
                    </div>
                  </td>
                  <td class="px-5 py-4 text-slate-800">{{ r.jenis }}</td>
                  <td class="px-5 py-4">
                    <UiStepper :steps="r.langkah" :tone="TONE[r.jenis]" />
                    <p class="sr-only">{{ summary(r) }}</p>
                  </td>
                  <td class="px-5 py-4 text-right">
                    <RowActionsMenu
                      :actions="actions()"
                      :label="`Aksi untuk layanan ${r.jenis} ${r.nama}`"
                      :testid="`layanan-actions-${r.id}`"
                      @select="onAction(r)"
                    />
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>

        <div class="px-5"><UiPagination v-model:page="page" :per-page="Number(perPage)" :total="total" /></div>
      </div>
    </UiCard>
  </RedesignShell>
</template>
