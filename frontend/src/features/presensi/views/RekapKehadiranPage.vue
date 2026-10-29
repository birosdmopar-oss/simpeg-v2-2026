<script setup lang="ts">
/**
 * Rekap Kehadiran (5B MAKE-042, PRD 5B §7.4.1; PRD 5C §7.5; role 1, 3, 4). Tabel ringkas densitas tinggi per pegawai
 * (kolom sesuai `V/rekap.php:114-133` + TB [V2]) dengan filter Bulan, Unit/Satker (role 1), dan Jenis Pegawai. Ekspor
 * "Excel ringkas" dan "Excel harian" (seluruh lingkup). Rekap tanpa warna baris; kode ditampilkan lewat legenda (AC-FE5C.3).
 * Tanpa frame Figma → pola halaman 5A/Fase 4 (AGENTS §3.6).
 */
import { computed, onMounted, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import { useAuthStore } from '@/features/auth/stores/auth.store'
import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import { describeApiError } from '@/features/kepegawaian/services/apiErrors'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiCard, UiExportMenu, UiNotice, UiPagination, UiSelect } from '@/shared/ui'

import FilterLaporanBar from '../components/FilterLaporanBar.vue'
import LegendaStatusPresensi from '../components/LegendaStatusPresensi.vue'
import { useFilterLaporan } from '../composables/useFilterLaporan'
import { rekapKehadiranService, type BarisRekapKehadiran, type DaftarRekap } from '../services/rekapKehadiran.service'
import { labelBulan } from '../formatLaporan'
import { PER_PAGE_LAPORAN } from '../types.laporan'
import { REKAP_KEHADIRAN_PILIH_UNIT_ROLES } from '../roles'

const auth = useAuthStore()
const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Presensi' }, { label: 'Rekap Kehadiran' }]

// Unit/Satker: role 1 dan 4 boleh memilih (lingkup penuh); role 3 dipaksa server (`CakupanLaporan`).
const bisaPilihUnit = auth.role !== null && REKAP_KEHADIRAN_PILIH_UNIT_ROLES.includes(auth.role)
const { filter, param, unitOpsi, satkerOpsi, jenisPegawaiOpsi, muatOpsi } = useFilterLaporan({ bisaPilihUnit })

const daftar = ref<DaftarRekap<BarisRekapKehadiran> | null>(null)
const memuat = ref(false)
const failure = ref<string | null>(null)
const notice = ref('')
const sedangUnduh = ref<'' | 'ringkas' | 'harian'>('')

const perPageOpsi = PER_PAGE_LAPORAN.map((n) => ({ value: String(n), label: String(n) }))
const judulBulan = computed(() => labelBulan(filter.bulan))

async function muat(): Promise<void> {
  memuat.value = true
  failure.value = null
  try {
    daftar.value = await rekapKehadiranService.daftar({
      bulan: param.value.bulan,
      unit_satker: param.value.unit_satker,
      jenis_pegawai: param.value.jenis_pegawai,
      page: filter.page,
      per_page: filter.perPage,
    })
  } catch (err) {
    daftar.value = null
    failure.value = describeApiError(err).message
  } finally {
    memuat.value = false
  }
}

watch(
  () => [param.value, filter.page],
  () => void muat(),
  { deep: true },
)

async function unduh(bentuk: 'ringkas' | 'harian'): Promise<void> {
  if (sedangUnduh.value) return
  sedangUnduh.value = bentuk
  notice.value = ''
  failure.value = null
  try {
    const berkas = await rekapKehadiranService.unduh(bentuk === 'ringkas' ? 'xlsx' : 'xlsx-harian', {
      bulan: param.value.bulan,
      unit_satker: param.value.unit_satker,
      jenis_pegawai: param.value.jenis_pegawai,
    })
    simpanBerkas(berkas)
    notice.value = `Berkas ${berkas.nama} diunduh.`
  } catch (err) {
    failure.value = isApiError(err) && err.message && err.status !== null && err.status < 500 ? err.message : describeApiError(err).message
  } finally {
    sedangUnduh.value = ''
  }
}

function ubahPerPage(nilai: string | number | null): void {
  const n = Number(nilai)
  if ((PER_PAGE_LAPORAN as readonly number[]).includes(n)) filter.perPage = n
}

onMounted(() => {
  void muatOpsi()
  void muat()
})
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="page-fill flex flex-col gap-5" data-testid="rekap-kehadiran-page">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-h2 font-semibold text-slate-900">Rekap Kehadiran</h1>
          <p class="text-body2 text-slate-500">Rekap kehadiran per pegawai untuk {{ judulBulan }}. Angka dihitung sampai hari ini untuk bulan berjalan.</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <UiExportMenu
            label="Excel Ringkas"
            :formats="[{ key: 'xlsx', label: 'Excel ringkas (seluruh lingkup)' }]"
            :disabled="sedangUnduh !== ''"
            data-testid="rekap-kehadiran-ekspor-ringkas"
            @select="unduh('ringkas')"
          />
          <UiExportMenu
            label="Excel Harian"
            :formats="[{ key: 'xlsx', label: 'Excel harian (seluruh lingkup)' }]"
            :disabled="sedangUnduh !== ''"
            data-testid="rekap-kehadiran-ekspor-harian"
            @select="unduh('harian')"
          />
        </div>
      </div>

      <UiNotice v-if="notice" tone="success" data-testid="rekap-kehadiran-notice">{{ notice }}</UiNotice>
      <UiNotice v-if="failure" tone="danger" data-testid="rekap-kehadiran-failure">{{ failure }}</UiNotice>

      <UiCard>
        <FilterLaporanBar
          v-model:bulan="filter.bulan"
          v-model:id-unit="filter.idUnit"
          v-model:id-satker="filter.idSatker"
          v-model:jenis-pegawai="filter.jenisPegawai"
          :bisa-pilih-unit="bisaPilihUnit"
          :unit-opsi="unitOpsi"
          :satker-opsi="satkerOpsi"
          :jenis-pegawai-opsi="jenisPegawaiOpsi"
          :tampil-cari="false"
        />
      </UiCard>

      <UiCard fill>
        <div class="table-fill scrollbar-slim overflow-auto rounded-xl border border-slate-200" data-testid="rekap-kehadiran-wadah">
          <table class="min-w-max text-sm" data-testid="rekap-kehadiran-tabel">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th class="col-sticky-kiri w-56 min-w-[14rem] px-3 py-3">Nama</th>
                <th class="col-sticky-kiri whitespace-nowrap px-3 py-3" style="--sticky-kiri: 14rem">NIP</th>
                <th class="min-w-[12rem] px-3 py-3">Jabatan</th>
                <th class="whitespace-nowrap px-3 py-3 text-right">Hari Kerja</th>
                <th class="whitespace-nowrap px-3 py-3 text-right">Hadir</th>
                <th class="whitespace-nowrap px-3 py-3 text-right">TL</th>
                <th class="whitespace-nowrap px-3 py-3 text-right">PSW</th>
                <th class="whitespace-nowrap px-3 py-3 text-right">TK</th>
                <th class="whitespace-nowrap px-3 py-3 text-right">Sakit</th>
                <th class="whitespace-nowrap px-3 py-3 text-right">Dinas Luar</th>
                <th class="whitespace-nowrap px-3 py-3 text-right">Lain-lain</th>
                <th class="whitespace-nowrap px-3 py-3 text-right">Cuti</th>
                <th class="whitespace-nowrap px-3 py-3 text-right">Tidak Hadir</th>
                <th class="whitespace-nowrap px-3 py-3 text-right" data-testid="rekap-kehadiran-kolom-tb">TB <span class="normal-case text-slate-400">(v2)</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-if="memuat">
                <td colspan="14" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
              </tr>
              <tr v-else-if="!daftar || daftar.items.length === 0">
                <td colspan="14" class="px-4 py-8 text-center text-slate-500" data-testid="rekap-kehadiran-kosong">Tidak ada data untuk filter ini.</td>
              </tr>
              <tr v-for="b in daftar?.items ?? []" v-else :key="b.nip" class="group hover:bg-slate-50" :data-testid="`rekap-kehadiran-baris-${b.nip}`">
                <td class="col-sticky-kiri w-56 min-w-[14rem] px-3 py-2.5 font-medium text-slate-800">{{ b.nama }}</td>
                <td class="col-sticky-kiri whitespace-nowrap px-3 py-2.5 tabular-nums text-slate-700" style="--sticky-kiri: 14rem">{{ b.nip }}</td>
                <td class="px-3 py-2.5 text-slate-600">{{ b.jabatan ?? '-' }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ b.hari_kerja }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ b.hadir }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ b.tl }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ b.psw }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ b.tk }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ b.sakit }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ b.dinas_luar }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ b.lain_lain }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ b.cuti }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ b.tidak_hadir }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ b.tb }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
          <p class="text-caption text-slate-500" data-testid="rekap-kehadiran-catatan-tb">
            Kolom TB adalah tambahan v2: jumlah hari tugas belajar, informasi saja, dan tidak mengurangi kolom lain.
          </p>
          <div class="flex items-center gap-2">
            <span class="text-caption text-slate-500">Baris per halaman</span>
            <div class="w-24">
              <UiSelect :model-value="String(filter.perPage)" :options="perPageOpsi" size="sm" aria-label="Jumlah baris" data-testid="rekap-kehadiran-per-page" @update:model-value="ubahPerPage" />
            </div>
          </div>
        </div>
        <UiPagination v-if="daftar && daftar.total > filter.perPage" v-model:page="filter.page" :per-page="filter.perPage" :total="daftar.total" />
      </UiCard>

      <UiCard>
        <LegendaStatusPresensi ringkas />
      </UiCard>
    </div>
  </RedesignShell>
</template>
