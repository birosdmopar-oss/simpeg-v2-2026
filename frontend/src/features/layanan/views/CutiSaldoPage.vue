<script setup lang="ts">
/**
 * Saldo Cuti (Modul C, role 1, 3; PRD Fase 4 §6.2): daftar saldo per pegawai per tahun dengan filter tahun, unit/satker,
 * dan pencarian; tambah (target semua pegawai atau satu pegawai) dan ubah/hapus per baris lewat ⋮. Hasil tambah
 * (`ringkasan`, `dilewati`) ditampilkan utuh. Lingkup role 3 ditegakkan server (baris di luar lingkup → 404).
 */
import { Pencil, Plus, Trash2 } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'

import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { useStickyActionsShadow } from '@/shared/composables/useStickyActionsShadow'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiButton, UiCard, UiNotice, UiPagination, UiSearchInput, UiTextField } from '@/shared/ui'

import { describeApiError } from '@/features/kepegawaian/services/apiErrors'

import SaldoCutiFormDialog from '../components/SaldoCutiFormDialog.vue'
import { cutiSaldoService, type HasilTambahSaldo, type SaldoCuti } from '../services/cutiSaldo.service'

const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Layanan' }, { label: 'Cuti', to: { name: 'layanan-cuti' } }, { label: 'Saldo Cuti' }]
const PER_PAGE = 20
const tahunIni = new Date().getFullYear()

const tahun = ref(String(tahunIni))
const search = ref('')
const page = ref(1)
const daftar = ref<{ items: SaldoCuti[]; total: number } | null>(null)
const memuat = ref(false)
const failure = ref<string | null>(null)
const notice = ref('')
const hasilTambah = ref<HasilTambahSaldo | null>(null)

const tableScroller = ref<HTMLElement | null>(null)
const { actionsShadow, updateActionsShadow } = useStickyActionsShadow(tableScroller, [daftar, memuat])

async function muat(): Promise<void> {
  memuat.value = true
  failure.value = null
  try {
    const hasil = await cutiSaldoService.list({ tahun: tahun.value, search: search.value, page: page.value, per_page: PER_PAGE })
    daftar.value = hasil
  } catch (err) {
    daftar.value = null
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
watch(tahun, () => {
  page.value = 1
  void muat()
})
watch(page, () => void muat())

// --- Aksi baris (⋮) ---------------------------------------------------------------------------------------------------
const formOpen = ref(false)
const formRow = ref<SaldoCuti | null>(null)
const hapus = ref<{ open: boolean; row: SaldoCuti | null; loading: boolean }>({ open: false, row: null, loading: false })

function aksiBaris(): RowAction[] {
  return [
    { key: 'edit', label: 'Edit', icon: Pencil },
    { key: 'hapus', label: 'Hapus', icon: Trash2, danger: true },
  ]
}

function onAksi(row: SaldoCuti, key: string): void {
  notice.value = ''
  hasilTambah.value = null
  if (key === 'edit') {
    formRow.value = row
    formOpen.value = true
  } else if (key === 'hapus') {
    hapus.value = { open: true, row, loading: false }
  }
}

function tambah(): void {
  notice.value = ''
  hasilTambah.value = null
  formRow.value = null
  formOpen.value = true
}

function onTersimpan(hasil: HasilTambahSaldo | null): void {
  if (hasil) {
    hasilTambah.value = hasil
    notice.value = hasil.message
  } else {
    notice.value = 'Saldo cuti diperbarui.'
  }
  void muat()
}

async function konfirmasiHapus(): Promise<void> {
  const row = hapus.value.row
  if (!row) return
  hapus.value.loading = true
  try {
    await cutiSaldoService.hapus(row.id)
    notice.value = `Saldo cuti ${row.nama} tahun ${row.tahun} dihapus.`
  } catch (err) {
    failure.value = describeApiError(err).message
  } finally {
    hapus.value = { open: false, row: null, loading: false }
    void muat()
  }
}

onMounted(() => void muat())
const tanpaData = computed(() => daftar.value !== null && daftar.value.items.length === 0)
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="page-fill flex flex-col gap-4" data-testid="saldo-page">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-h2 font-semibold text-slate-900">Saldo Cuti</h1>
          <p class="text-body2 text-slate-500">Saldo cuti pegawai per tahun dalam lingkup Anda.</p>
        </div>
        <UiButton data-testid="saldo-tambah" @click="tambah"><Plus class="h-4 w-4" aria-hidden="true" /> Tambah Saldo</UiButton>
      </div>

      <UiNotice v-if="notice" tone="success" data-testid="saldo-notice">{{ notice }}</UiNotice>
      <UiNotice v-if="failure" tone="danger" data-testid="saldo-failure">{{ failure }}</UiNotice>

      <UiCard v-if="hasilTambah" title="Hasil Tambah Saldo" class="shrink-0" data-testid="saldo-hasil">
        <p class="text-body2 text-slate-700" data-testid="saldo-hasil-ringkasan">
          Populasi {{ hasilTambah.ringkasan.jumlah_populasi }} pegawai · dibuat {{ hasilTambah.ringkasan.jumlah_dibuat }} · dilewati
          {{ hasilTambah.ringkasan.jumlah_dilewati }}
        </p>
        <div v-if="hasilTambah.dilewati.length" class="mt-3">
          <p class="text-body2 font-medium text-slate-800">Pegawai yang dilewati:</p>
          <ul class="mt-1 max-h-40 list-disc space-y-1 overflow-y-auto pl-5 text-body2 text-slate-600" data-testid="saldo-hasil-dilewati">
            <li v-for="d in hasilTambah.dilewati" :key="d.nip">{{ d.nama }} ({{ d.nip }}): {{ d.alasan }}</li>
          </ul>
        </div>
      </UiCard>

      <UiCard title="Daftar Saldo" flush fill class="flex min-h-0 flex-1 flex-col">
        <div class="flex flex-wrap items-end gap-3 border-b border-slate-100 p-4">
          <UiTextField v-model="tahun" type="number" label="Tahun" class="w-36" data-testid="saldo-filter-tahun" />
          <UiSearchInput v-model="search" placeholder="Cari NIP / nama" label="Cari NIP atau nama pegawai" class="min-w-[200px] flex-1" data-testid="saldo-search" />
        </div>

        <div ref="tableScroller" class="table-fill scrollbar-slim overflow-x-auto" data-testid="saldo-table-scroll" @scroll.passive="updateActionsShadow">
          <table class="min-w-full text-sm" data-testid="saldo-tabel">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th class="whitespace-nowrap px-4 py-3">NIP</th>
                <th class="min-w-[12rem] px-4 py-3">Nama</th>
                <th class="whitespace-nowrap px-4 py-3 text-right">N</th>
                <th class="whitespace-nowrap px-4 py-3 text-right">N-1</th>
                <th class="whitespace-nowrap px-4 py-3 text-right">N-2</th>
                <th class="whitespace-nowrap px-4 py-3 text-right">Utang</th>
                <th class="whitespace-nowrap px-4 py-3 text-right">Ditangguhkan</th>
                <th class="sticky right-0 z-10 whitespace-nowrap bg-slate-50 px-4 py-3 text-right" :class="{ 'shadow-sticky-end': actionsShadow }">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-if="memuat">
                <td colspan="8" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
              </tr>
              <tr v-else-if="tanpaData">
                <td colspan="8" class="px-4 py-8 text-center text-slate-500" data-testid="saldo-kosong">Belum ada saldo cuti untuk tahun ini.</td>
              </tr>
              <tr v-for="row in daftar?.items ?? []" v-else :key="row.id" class="group hover:bg-slate-50" :data-testid="`saldo-baris-${row.id}`">
                <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-700">{{ row.nip }}</td>
                <td class="px-4 py-3 font-medium text-slate-800">{{ row.nama }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ row.sisa_cuti }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ row.sisa_cuti_n1 }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ row.sisa_cuti_n2 }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ row.utang_cuti }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ row.sisa_cuti_ditangguhkan }}</td>
                <td class="sticky right-0 z-10 bg-white px-4 py-3 text-right group-hover:bg-slate-50" :class="{ 'shadow-sticky-end': actionsShadow }">
                  <RowActionsMenu :actions="aksiBaris()" :label="`Aksi untuk saldo ${row.nama} ${row.tahun}`" :testid="`saldo-actions-${row.id}`" @select="onAksi(row, $event)" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="daftar && daftar.total > PER_PAGE" class="p-4">
          <UiPagination v-model:page="page" :per-page="PER_PAGE" :total="daftar.total" />
        </div>
      </UiCard>

      <SaldoCutiFormDialog v-model:open="formOpen" :row="formRow" :tahun-awal="Number(tahun) || tahunIni" @saved="onTersimpan" />
      <ConfirmDialog
        :open="hapus.open"
        title="Hapus Saldo Cuti"
        :description="`Hapus saldo cuti ${hapus.row?.nama ?? ''} tahun ${hapus.row?.tahun ?? ''}? Data dihapus permanen dan tercatat di audit.`"
        confirm-label="Ya, hapus"
        danger
        :loading="hapus.loading"
        @update:open="(v) => !hapus.loading && (hapus.open = v)"
        @confirm="konfirmasiHapus"
      />
    </div>
  </RedesignShell>
</template>
