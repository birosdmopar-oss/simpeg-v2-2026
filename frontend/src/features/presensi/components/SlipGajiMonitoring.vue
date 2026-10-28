<script setup lang="ts">
/**
 * Tab Monitoring Kelola Slip Gaji (`GET slip-gaji`, PRD 5A §6.2, §7.3.4) [K `admin_list.php`]: filter periode (wajib;
 * bawaan = periode terbaru yang tersedia, seperti legacy `admin`), satker (role 1 saja — role 3 dipaksa satker akun oleh
 * server), status pembukaan, pencarian NIP/nama; ringkasan jumlah/dibuka/belum; tabel NIP · Nama · Unit/Satker · Periode
 * · Status · Waktu dibuka · IP · Aksi ⋮ (AGENTS §1: Edit → Lihat detail → Cetak PDF → Buka ulang). Aksi yang tampil
 * mengikuti `aksi {}` dari server; "Buka ulang" lewat `ConfirmDialog`.
 */
import { Eye, Pencil, Printer, RotateCcw } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { useStickyActionsShadow } from '@/shared/composables/useStickyActionsShadow'
import { UiBadge, UiNotice, UiPagination, UiSearchInput, UiSelect, UiStatTile } from '@/shared/ui'
import type { SelectOption } from '@/shared/ui'

import { describeApiError } from '@/features/kepegawaian/services/apiErrors'
import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'

import { labelPeriode, slipGajiAdminService, type OpsiSatker } from '../services/slipGajiAdmin.service'
import type { DaftarMonitoring, SlipAdmin, SlipMonitoring } from '../types'
import SlipGajiDetailDialog from './SlipGajiDetailDialog.vue'
import SlipGajiKoreksiDialog from './SlipGajiKoreksiDialog.vue'

const props = defineProps<{ bolehFilterSatker: boolean; awal?: string }>()

/** Bulan berjalan menurut kalender lokal (`YYYY-MM`), hanya sebagai periode pertama sebelum daftar periode diketahui. */
function bulanIni(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

const periode = ref(props.awal ?? bulanIni())
const satker = ref('')
const status = ref<'semua' | 'dibuka' | 'belum'>('semua')
const search = ref('')
const page = ref(1)
const perPage = 20

const hasil = ref<DaftarMonitoring | null>(null)
const memuat = ref(false)
const failure = ref<string | null>(null)
const notice = ref('')
const opsiSatker = ref<OpsiSatker[]>([])
let pertama = props.awal === undefined

const opsiPeriode = computed<SelectOption[]>(() => {
  const ada = hasil.value?.periode_tersedia ?? []
  const semua = ada.includes(periode.value) ? ada : [periode.value, ...ada]
  return semua.map((p) => ({ value: p, label: labelPeriode(p) }))
})
const opsiStatus: SelectOption[] = [
  { value: 'semua', label: 'Semua Status' },
  { value: 'dibuka', label: 'Sudah Dibuka' },
  { value: 'belum', label: 'Belum Dibuka' },
]

const tableScroller = ref<HTMLElement | null>(null)
const { actionsShadow, updateActionsShadow } = useStickyActionsShadow(tableScroller, [hasil, memuat])

async function muat(): Promise<void> {
  memuat.value = true
  failure.value = null
  try {
    const data = await slipGajiAdminService.daftar({ periode: periode.value, unit_satker: satker.value, status_view: status.value, search: search.value, page: page.value, per_page: perPage })
    // Periode bawaan = periode terbaru yang tersedia [K `Sl_gaji.php:219-221`]; hanya sekali saat halaman dibuka.
    const terbaru = data.periode_tersedia[0]
    if (pertama && terbaru && !data.periode_tersedia.includes(periode.value)) {
      pertama = false
      periode.value = terbaru
      return
    }
    pertama = false
    hasil.value = data
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
watch([periode, satker, status], () => {
  page.value = 1
  void muat()
})
watch(page, () => void muat())

// --- Aksi baris -----------------------------------------------------------------------------------------------------
const detailOpen = ref(false)
const koreksiOpen = ref(false)
const slip = ref<SlipAdmin | null>(null)
const memuatDetail = ref(false)
const galatDetail = ref('')
const mencetak = ref(false)
const konfirmasi = ref<{ open: boolean; row: SlipMonitoring | null; loading: boolean }>({ open: false, row: null, loading: false })

function aksiBaris(row: SlipMonitoring): RowAction[] {
  return [
    { key: 'edit', label: 'Edit', icon: Pencil, hidden: !row.aksi.koreksi },
    { key: 'lihat', label: 'Lihat detail', icon: Eye, hidden: !row.aksi.lihat },
    { key: 'cetak', label: 'Cetak PDF', icon: Printer, hidden: !row.aksi.cetak, disabled: mencetak.value },
    { key: 'buka-ulang', label: 'Buka ulang', icon: RotateCcw, hidden: !row.aksi.buka_ulang },
  ]
}

async function ambilDetail(row: SlipMonitoring): Promise<SlipAdmin | null> {
  memuatDetail.value = true
  galatDetail.value = ''
  slip.value = null
  try {
    slip.value = await slipGajiAdminService.detail(row.id)
    return slip.value
  } catch (err) {
    galatDetail.value = describeApiError(err).message
    return null
  } finally {
    memuatDetail.value = false
  }
}

async function cetak(id: number, nip: string, kunci: string): Promise<void> {
  mencetak.value = true
  failure.value = null
  try {
    simpanBerkas(await slipGajiAdminService.pdf(id, nip, kunci))
  } catch (err) {
    failure.value = describeApiError(err).message
  } finally {
    mencetak.value = false
  }
}

async function onAksi(row: SlipMonitoring, key: string): Promise<void> {
  notice.value = ''
  if (key === 'lihat') {
    detailOpen.value = true
    await ambilDetail(row)
  } else if (key === 'edit') {
    const d = await ambilDetail(row)
    if (d) koreksiOpen.value = true
    else failure.value = galatDetail.value
  } else if (key === 'cetak') {
    await cetak(row.id, row.nip, row.periode)
  } else if (key === 'buka-ulang') {
    konfirmasi.value = { open: true, row, loading: false }
  }
}

function onKoreksiTersimpan(baru: SlipAdmin): void {
  notice.value = `Data slip gaji ${baru.pegawai.nama ?? baru.nip} periode ${baru.label} diperbarui.`
  void muat()
}

async function bukaUlang(): Promise<void> {
  const row = konfirmasi.value.row
  if (!row) return
  konfirmasi.value.loading = true
  try {
    await slipGajiAdminService.bukaUlang(row.id)
    notice.value = `Akses slip gaji ${row.nama ?? row.nip} dibuka kembali. Pegawai dapat membuka slipnya 1x lagi.`
  } catch (err) {
    failure.value = isApiError(err) && err.status === 422 && err.message ? err.message : describeApiError(err).message
  } finally {
    konfirmasi.value = { open: false, row: null, loading: false }
    void muat()
  }
}

onMounted(async () => {
  void muat()
  if (props.bolehFilterSatker) {
    try {
      opsiSatker.value = await slipGajiAdminService.opsiSatker()
    } catch {
      opsiSatker.value = []
    }
  }
})

defineExpose({ muat, periode })
</script>

<template>
  <div class="space-y-4">
    <UiNotice v-if="notice" tone="success" data-testid="slipgaji-notice">{{ notice }}</UiNotice>
    <UiNotice v-if="failure" tone="danger" data-testid="slipgaji-failure">{{ failure }}</UiNotice>

    <div class="grid gap-3 sm:grid-cols-3" data-testid="slipgaji-ringkasan">
      <UiStatTile label="Total Data" :value="hasil?.ringkasan.jumlah ?? 0" />
      <UiStatTile label="Sudah Dibuka" :value="hasil?.ringkasan.dibuka ?? 0" />
      <UiStatTile label="Belum Dibuka" :value="hasil?.ringkasan.belum_dibuka ?? 0" />
    </div>

    <div class="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-3">
      <UiSelect v-model="periode" :options="opsiPeriode" size="sm" aria-label="Periode slip gaji" class="w-48" data-testid="slipgaji-periode" />
      <UiSelect
        v-if="bolehFilterSatker"
        v-model="satker"
        :options="opsiSatker"
        clearable
        searchable
        size="sm"
        placeholder="Semua Unit / Satker"
        aria-label="Filter satker"
        class="w-64"
        data-testid="slipgaji-satker"
      />
      <UiSelect v-model="status" :options="opsiStatus" size="sm" aria-label="Status pembukaan" class="w-44" data-testid="slipgaji-status" />
      <UiSearchInput v-model="search" placeholder="Cari NIP / nama" label="Cari NIP atau nama pegawai" class="min-w-[200px] flex-1" data-testid="slipgaji-search" />
    </div>

    <div
      ref="tableScroller"
      class="scrollbar-slim overflow-x-auto rounded-2xl border border-slate-200 bg-white"
      data-testid="slipgaji-table-scroll"
      @scroll.passive="updateActionsShadow"
    >
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="whitespace-nowrap px-4 py-3">NIP</th>
            <th class="min-w-[12rem] px-4 py-3">Nama Pegawai</th>
            <th class="min-w-[16rem] px-4 py-3">Unit / Satker</th>
            <th class="whitespace-nowrap px-4 py-3">Periode</th>
            <th class="whitespace-nowrap px-4 py-3">Status</th>
            <th class="whitespace-nowrap px-4 py-3">Waktu Dibuka</th>
            <th class="whitespace-nowrap px-4 py-3">IP Address</th>
            <th class="sticky right-0 z-10 whitespace-nowrap bg-slate-50 px-4 py-3 text-right" :class="{ 'shadow-sticky-end': actionsShadow }">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="memuat">
            <td colspan="8" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
          </tr>
          <tr v-else-if="!hasil || hasil.items.length === 0">
            <td colspan="8" class="px-4 py-8 text-center text-slate-500" data-testid="slipgaji-kosong">
              <template v-if="hasil && hasil.ringkasan.jumlah === 0 && !search && status === 'semua'">
                Data slip gaji periode {{ labelPeriode(periode) }} belum tersedia. Impor data dari Biro Perencanaan Keuangan lewat tab Impor, atau pilih periode lain.
              </template>
              <template v-else>Tidak ada data slip gaji yang cocok.</template>
            </td>
          </tr>
          <tr v-for="row in hasil?.items ?? []" v-else :key="row.id" class="group hover:bg-slate-50" :data-testid="`slipgaji-row-${row.id}`">
            <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-700">{{ row.nip }}</td>
            <td class="px-4 py-3 font-medium text-slate-800">{{ row.nama ?? '-' }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.unit_satker || '-' }}</td>
            <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ labelPeriode(row.periode) }}</td>
            <td class="px-4 py-3" :data-testid="`slipgaji-status-${row.id}`">
              <UiBadge :tone="row.dibuka ? 'success' : 'warning'" size="sm" class="whitespace-nowrap">{{ row.dibuka ? 'Sudah Dibuka' : 'Belum Dibuka' }}</UiBadge>
            </td>
            <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row.viewed_at_wib ?? '-' }}</td>
            <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-600">{{ row.viewed_ip ?? '-' }}</td>
            <td class="sticky right-0 z-10 bg-white px-4 py-3 text-right group-hover:bg-slate-50" :class="{ 'shadow-sticky-end': actionsShadow }">
              <RowActionsMenu
                :actions="aksiBaris(row)"
                :label="`Aksi untuk ${row.nama ?? row.nip}`"
                :testid="`slipgaji-actions-${row.id}`"
                @select="onAksi(row, $event)"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <UiPagination v-if="hasil && hasil.total > perPage" v-model:page="page" :per-page="perPage" :total="hasil.total" />

    <SlipGajiDetailDialog
      v-model:open="detailOpen"
      :slip="slip"
      :memuat="memuatDetail"
      :error="galatDetail"
      :mencetak="mencetak"
      @cetak="slip && cetak(slip.id, slip.nip, slip.periode)"
    />
    <SlipGajiKoreksiDialog v-model:open="koreksiOpen" :slip="slip" @saved="onKoreksiTersimpan" />
    <ConfirmDialog
      :open="konfirmasi.open"
      title="Buka Kembali Akses Lihat"
      :description="`Buka kembali akses lihat slip gaji ${konfirmasi.row?.nama ?? konfirmasi.row?.nip ?? ''} periode ${labelPeriode(konfirmasi.row?.periode ?? null)}? Pegawai akan bisa mengakses slip gajinya 1x lagi lewat aplikasi.`"
      confirm-label="Ya, Buka Akses"
      :loading="konfirmasi.loading"
      @update:open="(v) => !konfirmasi.loading && (konfirmasi.open = v)"
      @confirm="bukaUlang"
    />
  </div>
</template>
