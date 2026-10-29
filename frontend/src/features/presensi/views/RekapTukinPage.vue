<script setup lang="ts">
/**
 * Rekap Tukin (PRD 5C §7.4; role 1, 3; varian Instansi Lain role 1): `GET presensi/tukin/rekap[-il]` dengan filter Bulan,
 * Unit/Satker (role 1), Jenis Pegawai; tabel densitas tinggi, ekspor Excel (seluruh lingkup) dan PDF (hanya unit/satker ≤
 * batas baris server; selain itu 422 `unit_satker` tampil sebagai pesan). Blok penanda tangan tidak tampil di layar
 * (Q5C-08, hanya di berkas). Tanpa frame Figma (QAUI-005) → pola Status Layanan.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role } from '@/features/auth/types'
import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { formatTanggalIndonesia as tgl } from '@/shared/utils/dateTime'
import { UiButton, UiCard, UiExportMenu, UiNotice, UiPagination, UiSelect } from '@/shared/ui'

import FilterLaporanBar from '../components/FilterLaporanBar.vue'
import TabelRekapTukin from '../components/TabelRekapTukin.vue'
import { useFilterLaporan } from '../composables/useFilterLaporan'
import { pesanGalatRekap, rekapTukinService } from '../services/rekapTukin.service'
import { PER_PAGE_LAPORAN } from '../types.laporan'
import type { FormatEksporRekap, RekapTukin } from '../types.laporan.rekap'

const props = withDefaults(defineProps<{ il?: boolean }>(), { il: false })

const router = useRouter()
const auth = useAuthStore()
const crumbs = computed(() => [{ label: 'Home', to: { name: 'home' } }, { label: 'Presensi' }, { label: props.il ? 'Rekap Tukin Instansi Lain' : 'Rekap Tukin' }])

const bisaPilihUnit = !props.il && auth.role === Role.SUPER_ADMIN
const { filter, param, unitOpsi, satkerOpsi, jenisPegawaiOpsi, muatOpsi } = useFilterLaporan({ bisaPilihUnit, bulanTukin: true })
const perPageOpsi = PER_PAGE_LAPORAN.map((n) => ({ value: String(n), label: String(n) }))
const perPage = computed({ get: () => String(filter.perPage), set: (v: string) => (filter.perPage = Number(v)) })

const FORMAT_EKSPOR = computed(() => [
  { key: 'xlsx' as const, label: 'Ekspor Excel (.xlsx)' },
  ...(props.il ? [] : [{ key: 'pdf' as const, label: 'Ekspor PDF' }]),
])

const data = ref<RekapTukin | null>(null)
const memuat = ref(false)
const galat = ref('')
const noticeEkspor = ref('')
const mengekspor = ref(false)
let token = 0

async function muat(): Promise<void> {
  const saya = ++token
  memuat.value = true
  galat.value = ''
  try {
    const hasil = await rekapTukinService.daftar(param.value, props.il)
    if (saya === token) data.value = hasil
  } catch (e) {
    if (saya !== token) return
    data.value = null
    galat.value = pesanGalatRekap(e)
  } finally {
    if (saya === token) memuat.value = false
  }
}

onMounted(async () => {
  void muat()
  if (!props.il) await muatOpsi()
})
watch(() => [filter.bulan, filter.idUnit, filter.idSatker, filter.jenisPegawai.join(','), filter.perPage, filter.page], () => void muat())

/** Ekspor sesuai filter aktif (bukan hanya halaman ini); lingkup dan batas baris PDF ditegakkan server. */
async function ekspor(format: string): Promise<void> {
  if (format !== 'xlsx' && format !== 'pdf') return
  mengekspor.value = true
  noticeEkspor.value = ''
  try {
    simpanBerkas(await rekapTukinService.ekspor(format as FormatEksporRekap, param.value, props.il))
  } catch (e) {
    noticeEkspor.value = `Gagal mengekspor: ${pesanGalatRekap(e)}`
  } finally {
    mengekspor.value = false
  }
}

const mulaiNomor = computed(() => (filter.page - 1) * filter.perPage + 1)
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="page-fill flex flex-col gap-4" data-testid="rekap-tukin-page">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-h2 font-semibold text-slate-900">{{ il ? 'Rekap Tukin Instansi Lain' : 'Rekap Tukin' }}</h1>
          <p class="text-body2 text-slate-500">Rekap potongan tunjangan kinerja seluruh pegawai pada lingkup dan bulan yang dipilih.</p>
        </div>
        <div v-if="auth.role === Role.SUPER_ADMIN" class="flex gap-2">
          <UiButton :appearance="il ? 'outline' : 'solid'" data-testid="rekap-tab-pegawai" @click="router.push({ name: 'presensi-rekap-tukin' })">Pegawai</UiButton>
          <UiButton :appearance="il ? 'solid' : 'outline'" data-testid="rekap-tab-il" @click="router.push({ name: 'presensi-rekap-tukin-il' })">Instansi Lain</UiButton>
        </div>
      </div>

      <UiCard title="Rekap Potongan Tukin" class="flex min-h-0 flex-1 flex-col" fill>
        <template #actions>
          <UiExportMenu :formats="FORMAT_EKSPOR" :disabled="mengekspor || data === null" @select="ekspor" />
        </template>

        <FilterLaporanBar
          v-model:bulan="filter.bulan"
          v-model:id-unit="filter.idUnit"
          v-model:id-satker="filter.idSatker"
          v-model:jenis-pegawai="filter.jenisPegawai"
          class="mb-4"
          :bisa-pilih-unit="bisaPilihUnit"
          :unit-opsi="unitOpsi"
          :satker-opsi="satkerOpsi"
          :jenis-pegawai-opsi="il ? [] : jenisPegawaiOpsi"
          :tampil-cari="false"
        />

        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
          <p v-if="data" class="text-body2 text-slate-600" data-testid="rekap-ringkas">
            <span v-if="data.lingkup">Lingkup: <strong class="font-semibold text-slate-900">{{ data.lingkup.nama }}</strong> · </span>
            Periode {{ tgl(data.periode.awal) }} s.d. {{ tgl(data.periode.akhir) }} · {{ data.total }} pegawai
          </p>
          <span v-else />
          <div class="w-24"><UiSelect v-model="perPage" :options="perPageOpsi" aria-label="Jumlah baris" /></div>
        </div>

        <UiNotice v-if="galat" tone="danger" class="mb-4" data-testid="rekap-tukin-galat">{{ galat }}</UiNotice>
        <UiNotice v-if="noticeEkspor" tone="danger" class="mb-4" data-testid="rekap-ekspor-galat">{{ noticeEkspor }}</UiNotice>
        <UiNotice v-if="data && data.jumlah_galat > 0" tone="warning" class="mb-4" data-testid="rekap-jumlah-galat">
          {{ data.jumlah_galat }} pegawai pada halaman ini tidak dapat dihitung; barisnya ditandai Galat.
        </UiNotice>

        <TabelRekapTukin :items="data?.items ?? []" :memuat="memuat" :mulai-nomor="mulaiNomor" />

        <dl class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-caption text-slate-500" data-testid="rekap-legenda">
          <div><dt class="inline font-semibold text-slate-700">TL</dt> <dd class="inline">Datang terlambat</dd></div>
          <div><dt class="inline font-semibold text-slate-700">PSW</dt> <dd class="inline">Pulang sebelum waktunya</dd></div>
          <div><dt class="inline font-semibold text-slate-700">TK</dt> <dd class="inline">Tanpa keterangan</dd></div>
          <div><dt class="inline font-semibold text-slate-700">TA</dt> <dd class="inline">Tanpa absen</dd></div>
          <div><dt class="inline font-semibold text-slate-700">TB</dt> <dd class="inline">Tugas belajar</dd></div>
        </dl>

        <UiPagination v-if="data && data.total > filter.perPage" v-model:page="filter.page" class="mt-4" :per-page="filter.perPage" :total="data.total" />
      </UiCard>
    </div>
  </RedesignShell>
</template>
