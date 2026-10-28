<script setup lang="ts">
/**
 * Data Presensi (F5-15, D-03; PRD Fase 5A §7.6.4, §7.8.1; role 1, 3, 4, 5) [K `list_ad.php`, `list.php`]: daftar pegawai
 * dalam lingkup (server; role 3 satker sendiri) dengan pencarian + filter unit/satker → detail presensi per pegawai
 * (`?nip=`, rentang tanggal, bawaan 100 baris terbaru) dengan kolom Sumber (badge Online/Manual/Impor) dan lokasi.
 * Akun khusus (`can_kelola_manual`/`can_create`/`aksi` dari server) mendapat "Tambah Presensi" + ⋮ Edit → Hapus
 * (`danger`, `ConfirmDialog`). Tanpa frame Figma → pola komponen Fase 3 (AGENTS §3.6).
 */
import { ArrowLeft, CalendarSearch, Pencil, Plus, Trash2 } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { isApiError } from '@/lib/axios'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { useStickyActionsShadow } from '@/shared/composables/useStickyActionsShadow'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiBadge, UiButton, UiNotice, UiPagination, UiSearchInput, UiSelect, UiTextField } from '@/shared/ui'

import { describeApiError } from '@/features/kepegawaian/services/apiErrors'

import PresensiManualDialog from '../components/PresensiManualDialog.vue'
import { formatJam, formatTanggal, labelSumber, presensiService, type OpsiMaster } from '../services/presensi.service'
import type { BarisPresensiAdmin, DaftarPegawaiPresensi, DetailPresensiPegawai, PegawaiPresensi } from '../types'

const route = useRoute()
const router = useRouter()

const nipDipilih = computed(() => (typeof route.query.nip === 'string' && route.query.nip !== '' ? route.query.nip : null))
const crumbs = computed(() => [
  { label: 'Home', to: { name: 'home' } },
  { label: 'Presensi' },
  nipDipilih.value ? { label: 'Data Presensi', to: { name: 'presensi-data' } } : { label: 'Data Presensi' },
  ...(nipDipilih.value ? [{ label: detail.value?.pegawai.nama || nipDipilih.value }] : []),
])

const notice = ref('')
const failure = ref<string | null>(null)

// --- Daftar pegawai -----------------------------------------------------------------------------------------------
const search = ref('')
const unit = ref('')
const satker = ref('')
const page = ref(1)
const perPage = 20
const daftar = ref<DaftarPegawaiPresensi | null>(null)
const memuatDaftar = ref(false)
const opsiUnit = ref<OpsiMaster[]>([])
const opsiSatker = ref<OpsiMaster[]>([])

async function muatDaftar(): Promise<void> {
  memuatDaftar.value = true
  failure.value = null
  try {
    daftar.value = await presensiService.daftarPegawai({ search: search.value, id_unit: unit.value, id_satker: satker.value, page: page.value, per_page: perPage })
  } catch (err) {
    daftar.value = null
    failure.value = describeApiError(err).message
  } finally {
    memuatDaftar.value = false
  }
}

let timer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    page.value = 1
    void muatDaftar()
  }, 300)
})
watch(unit, async (u) => {
  satker.value = ''
  opsiSatker.value = []
  page.value = 1
  void muatDaftar()
  if (u) {
    try {
      opsiSatker.value = await presensiService.opsiSatker(u)
    } catch {
      opsiSatker.value = []
    }
  }
})
watch(satker, () => {
  page.value = 1
  void muatDaftar()
})
watch(page, () => void muatDaftar())

function aksiPegawai(): RowAction[] {
  return [{ key: 'lihat', label: 'Lihat presensi', icon: CalendarSearch }]
}

function bukaDetail(p: PegawaiPresensi): void {
  void router.push({ query: { ...route.query, nip: p.nip } })
}

// --- Detail presensi pegawai ----------------------------------------------------------------------------------------
const tglMulai = ref('')
const tglAkhir = ref('')
const pageDetail = ref(1)
const detail = ref<DetailPresensiPegawai | null>(null)
const memuatDetail = ref(false)
const galatDetail = ref<string | null>(null)

async function muatDetail(): Promise<void> {
  const nip = nipDipilih.value
  if (!nip) return
  memuatDetail.value = true
  galatDetail.value = null
  try {
    detail.value = await presensiService.detailPegawai(nip, { tgl_mulai: tglMulai.value, tgl_akhir: tglAkhir.value, page: pageDetail.value })
  } catch (err) {
    detail.value = null
    galatDetail.value = isApiError(err) && (err.status === 403 || err.status === 404 || err.status === 422) && err.message ? err.message : describeApiError(err).message
  } finally {
    memuatDetail.value = false
  }
}

watch(nipDipilih, (nip) => {
  notice.value = ''
  tglMulai.value = ''
  tglAkhir.value = ''
  pageDetail.value = 1
  detail.value = null
  if (nip) void muatDetail()
  else if (!daftar.value) void muatDaftar()
})
watch([tglMulai, tglAkhir], () => {
  pageDetail.value = 1
  void muatDetail()
})
watch(pageDetail, () => void muatDetail())

const tableScroller = ref<HTMLElement | null>(null)
const { actionsShadow, updateActionsShadow } = useStickyActionsShadow(tableScroller, [detail, daftar, memuatDetail, memuatDaftar])

// --- Presensi manual (akun khusus) ----------------------------------------------------------------------------------
const dialogOpen = ref(false)
const barisEdit = ref<BarisPresensiAdmin | null>(null)
const konfirmasi = ref<{ open: boolean; baris: BarisPresensiAdmin | null; loading: boolean }>({ open: false, baris: null, loading: false })
const bolehTambah = computed(() => (nipDipilih.value ? detail.value?.can_create === true : daftar.value?.can_kelola_manual === true))
const adaAksiBaris = computed(() => (detail.value?.items ?? []).some((b) => b.aksi.ubah || b.aksi.hapus))

function aksiBaris(b: BarisPresensiAdmin): RowAction[] {
  return [
    { key: 'edit', label: 'Edit', icon: Pencil, hidden: !b.aksi.ubah },
    { key: 'hapus', label: 'Hapus', icon: Trash2, danger: true, hidden: !b.aksi.hapus },
  ]
}

function onAksi(b: BarisPresensiAdmin, key: string): void {
  notice.value = ''
  if (key === 'edit') {
    barisEdit.value = b
    dialogOpen.value = true
  } else if (key === 'hapus') {
    konfirmasi.value = { open: true, baris: b, loading: false }
  }
}

function tambah(): void {
  notice.value = ''
  barisEdit.value = null
  dialogOpen.value = true
}

function onTersimpan(pesan: string): void {
  notice.value = pesan
  if (nipDipilih.value) void muatDetail()
}

async function hapus(): Promise<void> {
  const b = konfirmasi.value.baris
  if (!b) return
  konfirmasi.value.loading = true
  try {
    await presensiService.hapusManual(b.nip, b.tgl)
    notice.value = `Presensi NIP ${b.nip} tanggal ${formatTanggal(b.tgl).tanggal} dihapus.`
  } catch (err) {
    galatDetail.value = isApiError(err) && err.message && err.status !== null && err.status < 500 ? err.message : describeApiError(err).message
  } finally {
    konfirmasi.value = { open: false, baris: null, loading: false }
    void muatDetail()
  }
}

onMounted(async () => {
  if (nipDipilih.value) void muatDetail()
  else void muatDaftar()
  try {
    opsiUnit.value = await presensiService.opsiUnit()
  } catch {
    opsiUnit.value = []
  }
})
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="space-y-5" data-testid="data-presensi-page">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-h2 font-semibold text-slate-900">Data Presensi</h1>
          <p class="text-body2 text-slate-500">
            {{ nipDipilih ? `Presensi ${detail?.pegawai.nama ?? ''} (NIP ${nipDipilih})` : 'Lihat data presensi pegawai dalam lingkup Anda.' }}
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <UiButton v-if="nipDipilih" variant="secondary" appearance="outline" data-testid="data-presensi-kembali" @click="router.push({ name: 'presensi-data' })">
            <ArrowLeft class="h-4 w-4" aria-hidden="true" /> Daftar Pegawai
          </UiButton>
          <UiButton v-if="bolehTambah" data-testid="data-presensi-tambah" @click="tambah">
            <Plus class="h-4 w-4" aria-hidden="true" /> Tambah Presensi
          </UiButton>
        </div>
      </div>

      <UiNotice v-if="notice" tone="success" data-testid="data-presensi-notice">{{ notice }}</UiNotice>
      <UiNotice v-if="failure" tone="danger" data-testid="data-presensi-failure">{{ failure }}</UiNotice>

      <!-- Daftar pegawai -->
      <template v-if="!nipDipilih">
        <div class="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-3">
          <UiSelect v-model="unit" :options="opsiUnit" clearable searchable size="sm" placeholder="Semua Unit" aria-label="Filter unit" class="w-56" data-testid="data-presensi-unit" />
          <UiSelect
            v-model="satker"
            :options="opsiSatker"
            clearable
            searchable
            size="sm"
            :disabled="!unit"
            placeholder="Semua Satker"
            aria-label="Filter satker"
            class="w-56"
            data-testid="data-presensi-satker"
          />
          <UiSearchInput v-model="search" placeholder="Cari NIP / nama" label="Cari NIP atau nama pegawai" class="min-w-[200px] flex-1" data-testid="data-presensi-search" />
        </div>
        <div ref="tableScroller" class="scrollbar-slim overflow-x-auto rounded-2xl border border-slate-200 bg-white" @scroll.passive="updateActionsShadow">
          <table class="min-w-full text-sm" data-testid="data-presensi-pegawai">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th class="whitespace-nowrap px-4 py-3">NIP</th>
                <th class="min-w-[12rem] px-4 py-3">Nama Pegawai</th>
                <th class="min-w-[12rem] px-4 py-3">Unit</th>
                <th class="min-w-[12rem] px-4 py-3">Satker</th>
                <th class="sticky right-0 z-10 whitespace-nowrap bg-slate-50 px-4 py-3 text-right" :class="{ 'shadow-sticky-end': actionsShadow }">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-if="memuatDaftar">
                <td colspan="5" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
              </tr>
              <tr v-else-if="!daftar || daftar.items.length === 0">
                <td colspan="5" class="px-4 py-8 text-center text-slate-500" data-testid="data-presensi-kosong">Tidak ada pegawai yang cocok.</td>
              </tr>
              <tr v-for="p in daftar?.items ?? []" v-else :key="p.nip" class="group hover:bg-slate-50" :data-testid="`data-presensi-pegawai-${p.nip}`">
                <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-700">{{ p.nip }}</td>
                <td class="px-4 py-3 font-medium text-slate-800">{{ p.nama }}</td>
                <td class="px-4 py-3 text-slate-600">{{ p.unit ?? '-' }}</td>
                <td class="px-4 py-3 text-slate-600">{{ p.satker ?? '-' }}</td>
                <td class="sticky right-0 z-10 bg-white px-4 py-3 text-right group-hover:bg-slate-50" :class="{ 'shadow-sticky-end': actionsShadow }">
                  <RowActionsMenu :actions="aksiPegawai()" :label="`Aksi untuk ${p.nama}`" :testid="`presensi-pegawai-actions-${p.nip}`" @select="bukaDetail(p)" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <UiPagination v-if="daftar && daftar.total > perPage" v-model:page="page" :per-page="perPage" :total="daftar.total" />
      </template>

      <!-- Detail presensi pegawai -->
      <template v-else>
        <div class="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-3">
          <UiTextField v-model="tglMulai" type="date" label="Dari tanggal" class="w-44" data-testid="data-presensi-tgl-mulai" />
          <UiTextField v-model="tglAkhir" type="date" label="Sampai tanggal" class="w-44" data-testid="data-presensi-tgl-akhir" />
          <p class="pb-2 text-caption text-slate-500">Tanpa rentang: 100 data terbaru.</p>
        </div>
        <UiNotice v-if="galatDetail" tone="danger" data-testid="data-presensi-galat-detail">{{ galatDetail }}</UiNotice>
        <div ref="tableScroller" class="scrollbar-slim overflow-x-auto rounded-2xl border border-slate-200 bg-white" @scroll.passive="updateActionsShadow">
          <table class="min-w-full text-sm" data-testid="data-presensi-detail">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th class="px-4 py-3">Hari</th>
                <th class="px-4 py-3">Tanggal</th>
                <th class="whitespace-nowrap px-4 py-3">Jam Masuk</th>
                <th class="whitespace-nowrap px-4 py-3">Jam Pulang</th>
                <th class="px-4 py-3">Sumber</th>
                <th class="min-w-[10rem] px-4 py-3">Lokasi Masuk</th>
                <th class="min-w-[10rem] px-4 py-3">Lokasi Pulang</th>
                <th v-if="adaAksiBaris" class="sticky right-0 z-10 whitespace-nowrap bg-slate-50 px-4 py-3 text-right" :class="{ 'shadow-sticky-end': actionsShadow }">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-if="memuatDetail">
                <td :colspan="adaAksiBaris ? 8 : 7" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
              </tr>
              <tr v-else-if="!detail || detail.items.length === 0">
                <td :colspan="adaAksiBaris ? 8 : 7" class="px-4 py-8 text-center text-slate-500" data-testid="data-presensi-detail-kosong">Belum ada data presensi.</td>
              </tr>
              <tr v-for="b in detail?.items ?? []" v-else :key="b.tgl" class="group hover:bg-slate-50" :data-testid="`data-presensi-baris-${b.tgl}`">
                <td class="px-4 py-3 text-slate-600">{{ formatTanggal(b.tgl).hari }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-slate-800">{{ formatTanggal(b.tgl).tanggal }}</td>
                <td class="px-4 py-3 tabular-nums text-slate-800">{{ formatJam(b.jam_masuk) }}</td>
                <td class="px-4 py-3 tabular-nums text-slate-800">{{ formatJam(b.jam_pulang) }}</td>
                <td class="px-4 py-3" :data-testid="`data-presensi-sumber-${b.tgl}`">
                  <UiBadge :tone="b.sumber === 2 ? 'warning' : b.sumber === 1 ? 'info' : 'neutral'" size="sm">{{ labelSumber(b.sumber) }}</UiBadge>
                </td>
                <td class="px-4 py-3 text-slate-600">{{ b.lokasi_masuk ?? '-' }}</td>
                <td class="px-4 py-3 text-slate-600">{{ b.lokasi_pulang ?? '-' }}</td>
                <td v-if="adaAksiBaris" class="sticky right-0 z-10 bg-white px-4 py-3 text-right group-hover:bg-slate-50" :class="{ 'shadow-sticky-end': actionsShadow }">
                  <RowActionsMenu
                    :actions="aksiBaris(b)"
                    :label="`Aksi untuk presensi ${formatTanggal(b.tgl).tanggal}`"
                    :testid="`presensi-actions-${b.nip}-${b.tgl}`"
                    @select="onAksi(b, $event)"
                  />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <UiPagination v-if="detail && detail.total > detail.per_page" v-model:page="pageDetail" :per-page="detail.per_page" :total="detail.total" />
      </template>

      <PresensiManualDialog
        v-model:open="dialogOpen"
        :baris="barisEdit"
        :nip-awal="nipDipilih ?? ''"
        :nama-pegawai="nipDipilih && !barisEdit ? detail?.pegawai.nama : undefined"
        @saved="onTersimpan"
      />
      <ConfirmDialog
        :open="konfirmasi.open"
        title="Hapus Presensi"
        :description="`Hapus presensi NIP ${konfirmasi.baris?.nip ?? ''} tanggal ${konfirmasi.baris ? formatTanggal(konfirmasi.baris.tgl).tanggal : ''}? Data dihapus permanen dan tercatat di audit.`"
        confirm-label="Ya, hapus"
        danger
        :loading="konfirmasi.loading"
        @update:open="(v) => !konfirmasi.loading && (konfirmasi.open = v)"
        @confirm="hapus"
      />
    </div>
  </RedesignShell>
</template>
