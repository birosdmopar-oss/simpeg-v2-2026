<script setup lang="ts">
/**
 * Halaman Admin → Integrasi (MAKE-078, ISSUE-058 / deviasi K1 Fase 6; PRD Fase 6 §6.2, F6-24) — Super Admin saja.
 *
 * - Tabel log integrasi `GET api/v1/integrasi/log`: filter layanan, status, rentang tanggal (WIB), paginasi, terbaru
 *   dulu; kolom Status hanya badge. Aksi baris lewat menu ⋮ (AGENTS.md §1): "Lihat detail" (log baca-saja, tidak ada
 *   Edit/Hapus).
 * - Tombol level halaman "Tarik Ulang e-Kinerja" → `POST api/v1/integrasi/bkn/tarik` (202 = job diantre). Bukan aksi
 *   baris karena tarik ulang berlaku per periode, bukan per baris log.
 *
 * Figma halaman ini belum ada: tata letak mengikuti halaman daftar lain (Hari Libur: page-fill + table-fill).
 */
import { Eye, RefreshCw } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { UiBadge, UiButton, UiPagination, UiSelect, UiTextField } from '@/shared/ui'
import { formatApiDateTime } from '@/shared/utils/dateTime'

import LogIntegrasiDetailDialog from '../components/LogIntegrasiDetailDialog.vue'
import TarikEkinerjaDialog from '../components/TarikEkinerjaDialog.vue'
import { LABEL_ARAH, LABEL_STATUS, labelLayanan, type LogIntegrasiFilter, type LogIntegrasiRow, type TarikEkinerjaHasil } from '../integrasi.types'
import { logIntegrasiService } from '../services/logIntegrasi.service'

const PER_PAGE = 25

const STATUS_OPTIONS = [
  { value: '1', label: 'Sukses' },
  { value: '2', label: 'Gagal' },
]

const items = ref<LogIntegrasiRow[]>([])
const total = ref(0)
const page = ref(1)
const layananTersedia = ref<string[]>([])
const layanan = ref('')
const status = ref<'' | '1' | '2'>('')
const tanggalMulai = ref('')
const tanggalSelesai = ref('')
const loading = ref(false)
const error = ref('')
const fieldErrors = ref<Record<string, string>>({})
const notice = ref('')

const detail = ref<{ open: boolean; row: LogIntegrasiRow | null }>({ open: false, row: null })
const tarikOpen = ref(false)

const layananOptions = computed(() => layananTersedia.value.map((kode) => ({ value: kode, label: labelLayanan(kode) })))

function filter(): LogIntegrasiFilter {
  return {
    layanan: layanan.value,
    status: status.value,
    tanggal_mulai: tanggalMulai.value,
    tanggal_selesai: tanggalSelesai.value,
    page: page.value,
    per_page: PER_PAGE,
  }
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  fieldErrors.value = {}
  try {
    const hasil = await logIntegrasiService.list(filter())
    items.value = hasil.items
    total.value = hasil.total
    layananTersedia.value = hasil.layanan_tersedia
  } catch (err) {
    items.value = []
    total.value = 0
    if (isApiError(err) && err.status === 422 && err.errors) {
      fieldErrors.value = Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0] ?? '']))
      error.value = 'Filter tidak valid. Periksa isian yang ditandai.'
    } else {
      error.value = isApiError(err) ? err.message : 'Gagal memuat log integrasi.'
    }
  } finally {
    loading.value = false
  }
}

watch([layanan, status, tanggalMulai, tanggalSelesai], () => {
  page.value = 1
  void load()
})

function goTo(next: number): void {
  page.value = next
  void load()
}

function resetFilter(): void {
  layanan.value = ''
  status.value = ''
  tanggalMulai.value = ''
  tanggalSelesai.value = ''
}

const adaFilter = computed(() => Boolean(layanan.value || status.value || tanggalMulai.value || tanggalSelesai.value))

function waktu(row: LogIntegrasiRow): string {
  return formatApiDateTime(row.created_at) ?? row.created_at
}

function http(row: LogIntegrasiRow): string {
  return row.http_status === null ? '—' : String(row.http_status)
}

/** Aksi baris (AGENTS.md §1): log baca-saja → hanya "Lihat detail". */
function rowActions(): RowAction[] {
  return [{ key: 'detail', label: 'Lihat detail', icon: Eye }]
}

function onRowAction(row: LogIntegrasiRow, key: string): void {
  if (key === 'detail') detail.value = { open: true, row }
}

function onQueued(hasil: TarikEkinerjaHasil): void {
  const sasaran =
    hasil.jenis === 'periode'
      ? 'daftar periode'
      : hasil.tahun !== null && hasil.bulan !== null
        ? `SKP periodik ${String(hasil.bulan).padStart(2, '0')}/${hasil.tahun}`
        : 'SKP periodik (periode terpilih otomatis)'
  notice.value = `Tarik ulang e-Kinerja ${sasaran} masuk antrean. Hasilnya akan tampil di log setelah diproses.`
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="page-fill flex flex-col gap-4" data-testid="integrasi-admin-page">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-slate-900">Integrasi</h1>
        <p class="text-sm text-slate-500">Log panggilan ke dan dari layanan luar (BKN, BSrE, SIASN, FCM, SIMAPI). Disimpan 180 hari.</p>
      </div>
      <UiButton data-testid="integrasi-tarik-buka" @click="tarikOpen = true">
        <RefreshCw class="h-4 w-4" /> Tarik Ulang e-Kinerja
      </UiButton>
    </div>

    <p v-if="notice" class="rounded-md border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-800" role="status">{{ notice }}</p>
    <p v-if="error" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <div class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-3">
      <UiSelect
        v-model="layanan"
        :options="layananOptions"
        clearable
        size="sm"
        placeholder="Semua layanan"
        aria-label="Filter layanan"
        class="w-52"
        :state="fieldErrors.layanan ? 'error' : 'default'"
        :help-text="fieldErrors.layanan ?? ''"
        data-testid="integrasi-filter-layanan"
      />
      <UiSelect
        :model-value="status"
        :options="STATUS_OPTIONS"
        clearable
        size="sm"
        placeholder="Semua status"
        aria-label="Filter status"
        class="w-44"
        :state="fieldErrors.status ? 'error' : 'default'"
        :help-text="fieldErrors.status ?? ''"
        data-testid="integrasi-filter-status"
        @update:model-value="status = ($event ?? '') as '' | '1' | '2'"
      />
      <UiTextField
        v-model="tanggalMulai"
        type="date"
        label="Dari tanggal"
        :max="tanggalSelesai || undefined"
        :state="fieldErrors.tanggal_mulai ? 'error' : 'default'"
        :help-text="fieldErrors.tanggal_mulai ?? ''"
        class="w-44"
        data-testid="integrasi-filter-mulai"
      />
      <UiTextField
        v-model="tanggalSelesai"
        type="date"
        label="Sampai tanggal"
        :min="tanggalMulai || undefined"
        :state="fieldErrors.tanggal_selesai ? 'error' : 'default'"
        :help-text="fieldErrors.tanggal_selesai ?? ''"
        class="w-44"
        data-testid="integrasi-filter-selesai"
      />
      <UiButton v-if="adaFilter" variant="secondary" appearance="ghost" size="sm" data-testid="integrasi-filter-reset" @click="resetFilter">
        Hapus filter
      </UiButton>
    </div>

    <!-- Kartu tabel: satu-satunya area gulir data (table-fill); header tabel sticky di atasnya (CR-069). -->
    <div class="table-fill overflow-x-auto rounded-lg border border-slate-200 bg-white" data-testid="integrasi-log-table-scroll">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3">Waktu</th>
            <th class="px-4 py-3">Layanan</th>
            <th class="px-4 py-3">Arah</th>
            <th class="px-4 py-3">Operasi</th>
            <th class="px-4 py-3">HTTP</th>
            <th class="px-4 py-3">Kode</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading">
            <td colspan="8" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
          </tr>
          <tr v-else-if="items.length === 0">
            <td colspan="8" class="px-4 py-8 text-center text-slate-500">Belum ada log integrasi yang cocok.</td>
          </tr>
          <tr
            v-for="row in items"
            v-else
            :key="row.id_integrasi_log"
            class="hover:bg-slate-50"
            :data-testid="`integrasi-log-row-${row.id_integrasi_log}`"
          >
            <td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ waktu(row) }}</td>
            <td class="px-4 py-3 font-medium text-slate-800">{{ labelLayanan(row.layanan) }}</td>
            <td class="px-4 py-3 text-slate-600">{{ LABEL_ARAH[row.arah] ?? row.arah }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.operasi }}</td>
            <td class="px-4 py-3 text-slate-600">{{ http(row) }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.kode ?? '—' }}</td>
            <td class="px-4 py-3">
              <UiBadge :tone="row.status === 1 ? 'success' : 'danger'" dot>{{ LABEL_STATUS[row.status] ?? row.status }}</UiBadge>
            </td>
            <td class="px-4 py-3 text-right">
              <RowActionsMenu
                :actions="rowActions()"
                :label="`Aksi untuk log ${labelLayanan(row.layanan)} ${row.operasi} #${row.id_integrasi_log}`"
                :testid="`integrasi-actions-${row.id_integrasi_log}`"
                @select="onRowAction(row, $event)"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-slate-600">
      <span data-testid="integrasi-log-total">{{ total }} log</span>
      <UiPagination :page="page" :per-page="PER_PAGE" :total="total" data-testid="integrasi-log-pagination" @update:page="goTo" />
    </div>

    <LogIntegrasiDetailDialog v-model:open="detail.open" :row="detail.row" />
    <TarikEkinerjaDialog v-model:open="tarikOpen" @queued="onQueued" />
  </section>
</template>
