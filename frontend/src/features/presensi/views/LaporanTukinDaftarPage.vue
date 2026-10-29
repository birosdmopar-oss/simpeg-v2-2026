<script setup lang="ts">
/**
 * Laporan Tukin — daftar pegawai (PRD 5C §7.3; role 1, 3; varian Instansi Lain role 1). `GET presensi/tukin/pegawai[-il]`:
 * filter Unit/Satker (role 1), Jenis Pegawai, Cari; Bulan dipakai saat membuka/mengunduh laporan per pegawai (daftar
 * sendiri tidak bergantung bulan). Aksi ⋮: Lihat laporan, Unduh PDF. Tanpa frame Figma (QAUI-005) → pola Status Layanan.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role } from '@/features/auth/types'
import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiButton, UiCard, UiNotice, UiPagination, UiSelect } from '@/shared/ui'

import DaftarPegawaiTukinTable from '../components/DaftarPegawaiTukinTable.vue'
import FilterLaporanBar from '../components/FilterLaporanBar.vue'
import { useFilterLaporan } from '../composables/useFilterLaporan'
import { laporanTukinService, pesanGalatLaporan } from '../services/laporanTukin.service'
import { PER_PAGE_LAPORAN } from '../types.laporan'
import type { DaftarPegawaiTukin, PegawaiTukin } from '../types.laporan.tukin'

const props = withDefaults(defineProps<{ il?: boolean }>(), { il: false })

const router = useRouter()
const auth = useAuthStore()
const crumbs = computed(() => [{ label: 'Home', to: { name: 'home' } }, { label: 'Presensi' }, { label: props.il ? 'Laporan Tukin Instansi Lain' : 'Laporan Tukin' }])

const bisaPilihUnit = !props.il && auth.role === Role.SUPER_ADMIN
const { filter, param, unitOpsi, satkerOpsi, jenisPegawaiOpsi, muatOpsi } = useFilterLaporan({ bisaPilihUnit })
const perPageOpsi = PER_PAGE_LAPORAN.map((n) => ({ value: String(n), label: String(n) }))
const perPage = computed({ get: () => String(filter.perPage), set: (v: string) => (filter.perPage = Number(v)) })

const data = ref<DaftarPegawaiTukin | null>(null)
const memuat = ref(false)
const galat = ref('')
const noticeUnduh = ref('')
const mengunduh = ref<string | null>(null)
let token = 0
let timer: ReturnType<typeof setTimeout> | undefined

async function muat(): Promise<void> {
  const saya = ++token
  memuat.value = true
  galat.value = ''
  try {
    const hasil = await laporanTukinService.daftar(param.value, props.il)
    if (saya === token) data.value = hasil
  } catch (e) {
    if (saya !== token) return
    data.value = null
    galat.value = pesanGalatLaporan(e)
  } finally {
    if (saya === token) memuat.value = false
  }
}

onMounted(async () => {
  void muat()
  if (!props.il) await muatOpsi()
})
// Bulan tidak memuat ulang daftar; filter lain, halaman, dan jumlah baris ya (pencarian ditunda 300 ms).
watch(
  () => [filter.idUnit, filter.idSatker, filter.jenisPegawai.join(','), filter.perPage, filter.page],
  () => void muat(),
)
watch(
  () => filter.search,
  () => {
    clearTimeout(timer)
    timer = setTimeout(() => void muat(), 300)
  },
)
onBeforeUnmount(() => clearTimeout(timer))

async function onAction(key: 'lihat' | 'pdf', row: PegawaiTukin): Promise<void> {
  if (key === 'lihat') {
    void router.push({ name: 'presensi-tukin-pegawai', params: { nip: row.nip }, query: { bulan: filter.bulan } })
    return
  }
  mengunduh.value = row.nip
  noticeUnduh.value = ''
  try {
    simpanBerkas(await laporanTukinService.pdf(row.nip, filter.bulan))
  } catch (e) {
    noticeUnduh.value = `Gagal mengunduh PDF: ${pesanGalatLaporan(e)}`
  } finally {
    mengunduh.value = null
  }
}
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="page-fill flex flex-col gap-4" data-testid="tukin-daftar-page">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-h2 font-semibold text-slate-900">{{ il ? 'Laporan Tukin Instansi Lain' : 'Laporan Tukin' }}</h1>
          <p class="text-body2 text-slate-500">Pilih bulan, lalu buka laporan tunjangan kinerja per pegawai atau unduh PDF-nya.</p>
        </div>
        <div v-if="auth.role === Role.SUPER_ADMIN" class="flex gap-2">
          <UiButton :appearance="il ? 'outline' : 'solid'" data-testid="tukin-tab-pegawai" @click="router.push({ name: 'presensi-tukin' })">Pegawai</UiButton>
          <UiButton :appearance="il ? 'solid' : 'outline'" data-testid="tukin-tab-il" @click="router.push({ name: 'presensi-tukin-il' })">Instansi Lain</UiButton>
        </div>
      </div>

      <UiCard title="Daftar Pegawai" class="flex min-h-0 flex-1 flex-col" fill>
        <FilterLaporanBar
          v-model:bulan="filter.bulan"
          v-model:id-unit="filter.idUnit"
          v-model:id-satker="filter.idSatker"
          v-model:jenis-pegawai="filter.jenisPegawai"
          v-model:search="filter.search"
          class="mb-4"
          :bisa-pilih-unit="bisaPilihUnit"
          :unit-opsi="unitOpsi"
          :satker-opsi="satkerOpsi"
          :jenis-pegawai-opsi="il ? [] : jenisPegawaiOpsi"
        />
        <div class="mb-3 flex justify-end">
          <div class="w-24"><UiSelect v-model="perPage" :options="perPageOpsi" aria-label="Jumlah baris" /></div>
        </div>

        <UiNotice v-if="galat" tone="danger" class="mb-4" data-testid="tukin-daftar-galat">{{ galat }}</UiNotice>
        <UiNotice v-if="noticeUnduh" tone="danger" class="mb-4" data-testid="tukin-unduh-galat">{{ noticeUnduh }}</UiNotice>

        <DaftarPegawaiTukinTable :items="data?.items ?? []" :memuat="memuat" :mengunduh="mengunduh" @action="onAction" />

        <UiPagination v-if="data && data.total > filter.perPage" v-model:page="filter.page" class="mt-4" :per-page="filter.perPage" :total="data.total" />
      </UiCard>
    </div>
  </RedesignShell>
</template>
