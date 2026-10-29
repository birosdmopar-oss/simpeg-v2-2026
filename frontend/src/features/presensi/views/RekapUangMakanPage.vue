<script setup lang="ts">
/**
 * Rekap Uang Makan (5B MAKE-042, F5B-2, F5B-20; PRD 5C §7.5; role 1, 3). Daftar baris `NIP | Tanggal Absen` (satu per
 * hari dibayar, aturan slip) seperti `V/rekap_uma.php` [K], tanpa nominal. Tab Instansi Lain hanya role 1 (F5B-6) dan
 * hanya memakai Bulan. Ekspor XLSX dua kolom. Bantuan teks: perbedaan pegawai baru dibanding legacy (Q5B-26 vs F5B-2).
 */
import { computed, onMounted, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import { Role } from '@/features/auth/types'
import { useAuthStore } from '@/features/auth/stores/auth.store'
import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import { describeApiError } from '@/features/kepegawaian/services/apiErrors'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiCard, UiExportMenu, UiNotice, UiPagination, UiSelect, UiTabs } from '@/shared/ui'

import FilterLaporanBar from '../components/FilterLaporanBar.vue'
import { useFilterLaporan } from '../composables/useFilterLaporan'
import { rekapUangMakanService, type BarisUangMakan, type DaftarRekap } from '../services/rekapKehadiran.service'
import { formatTanggal } from '../services/presensi.service'
import { labelBulan } from '../formatLaporan'
import { PER_PAGE_LAPORAN } from '../types.laporan'

const auth = useAuthStore()
const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Presensi' }, { label: 'Rekap Uang Makan' }]
const bisaIl = computed(() => auth.role === Role.SUPER_ADMIN)

const { filter, param, unitOpsi, satkerOpsi, jenisPegawaiOpsi, muatOpsi } = useFilterLaporan({ bisaPilihUnit: bisaIl.value })

const tab = ref<'pegawai' | 'il'>('pegawai')
const tabItems = computed(() => (bisaIl.value ? [{ value: 'pegawai', label: 'Pegawai' }, { value: 'il', label: 'Instansi Lain' }] : [{ value: 'pegawai', label: 'Pegawai' }]))
const il = computed(() => tab.value === 'il' && bisaIl.value)

const daftar = ref<DaftarRekap<BarisUangMakan> | null>(null)
const memuat = ref(false)
const failure = ref<string | null>(null)
const notice = ref('')
const sedangUnduh = ref(false)

const perPageOpsi = PER_PAGE_LAPORAN.map((n) => ({ value: String(n), label: String(n) }))
const judulBulan = computed(() => labelBulan(filter.bulan))

async function muat(): Promise<void> {
  memuat.value = true
  failure.value = null
  try {
    daftar.value = await rekapUangMakanService.daftar(
      { bulan: param.value.bulan, unit_satker: param.value.unit_satker, jenis_pegawai: param.value.jenis_pegawai, page: filter.page, per_page: filter.perPage },
      il.value,
    )
  } catch (err) {
    daftar.value = null
    failure.value = describeApiError(err).message
  } finally {
    memuat.value = false
  }
}

watch(() => [param.value, filter.page, il.value], () => void muat(), { deep: true })

async function unduh(): Promise<void> {
  if (sedangUnduh.value) return
  sedangUnduh.value = true
  notice.value = ''
  failure.value = null
  try {
    const berkas = await rekapUangMakanService.unduh({ bulan: param.value.bulan, unit_satker: param.value.unit_satker, jenis_pegawai: param.value.jenis_pegawai }, il.value)
    simpanBerkas(berkas)
    notice.value = `Berkas ${berkas.nama} diunduh.`
  } catch (err) {
    failure.value = isApiError(err) && err.message && err.status !== null && err.status < 500 ? err.message : describeApiError(err).message
  } finally {
    sedangUnduh.value = false
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
    <div class="page-fill flex flex-col gap-5" data-testid="rekap-uang-makan-page">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-h2 font-semibold text-slate-900">Rekap Uang Makan</h1>
          <p class="text-body2 text-slate-500">Hari yang dibayar uang makan untuk {{ judulBulan }}, satu baris per pegawai dan hari.</p>
        </div>
        <UiExportMenu
          label="Excel"
          :formats="[{ key: 'xlsx', label: 'Excel (NIP | Tanggal Absen)' }]"
          :disabled="sedangUnduh"
          data-testid="rekap-uang-makan-ekspor"
          @select="unduh"
        />
      </div>

      <UiNotice v-if="notice" tone="success" data-testid="rekap-uang-makan-notice">{{ notice }}</UiNotice>
      <UiNotice v-if="failure" tone="danger" data-testid="rekap-uang-makan-failure">{{ failure }}</UiNotice>

      <UiTabs v-if="bisaIl" v-model="tab" :items="tabItems" aria-label="Jenis rekap uang makan" data-testid="rekap-uang-makan-tab" />

      <UiCard>
        <FilterLaporanBar
          v-model:bulan="filter.bulan"
          v-model:id-unit="filter.idUnit"
          v-model:id-satker="filter.idSatker"
          v-model:jenis-pegawai="filter.jenisPegawai"
          :bisa-pilih-unit="!il && bisaIl"
          :unit-opsi="unitOpsi"
          :satker-opsi="satkerOpsi"
          :jenis-pegawai-opsi="il ? [] : jenisPegawaiOpsi"
          :tampil-cari="false"
        />
        <p v-if="il" class="mt-2 text-caption text-slate-500" data-testid="rekap-uang-makan-il-catatan">Instansi Lain: lingkup seluruh instansi, tanpa filter unit dan jenis pegawai.</p>
      </UiCard>

      <UiCard fill>
        <div class="table-fill scrollbar-slim overflow-auto rounded-xl border border-slate-200" data-testid="rekap-uang-makan-wadah">
          <table class="min-w-max text-sm" data-testid="rekap-uang-makan-tabel">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th class="col-sticky-kiri whitespace-nowrap px-4 py-3">NIP</th>
                <th class="whitespace-nowrap px-4 py-3">Tanggal Absen</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-if="memuat">
                <td colspan="2" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
              </tr>
              <tr v-else-if="!daftar || daftar.items.length === 0">
                <td colspan="2" class="px-4 py-8 text-center text-slate-500" data-testid="rekap-uang-makan-kosong">Tidak ada data untuk filter ini.</td>
              </tr>
              <tr v-for="(b, i) in daftar?.items ?? []" v-else :key="`${b.nip}-${b.tgl_absen}-${i}`" class="group hover:bg-slate-50" :data-testid="`rekap-uang-makan-baris-${b.nip}`">
                <td class="col-sticky-kiri whitespace-nowrap px-4 py-2.5 tabular-nums text-slate-700">{{ b.nip }}</td>
                <td class="whitespace-nowrap px-4 py-2.5 text-slate-800">{{ formatTanggal(b.tgl_absen).hari }}, {{ formatTanggal(b.tgl_absen).tanggal }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
          <p class="text-caption text-slate-500">
            Pegawai yang dibuat setelah awal bulan dihitung dari hari presensinya (aturan slip). Rekap kehadiran mengikuti aturan lama, sehingga angka keduanya dapat berbeda.
          </p>
          <div class="flex items-center gap-2">
            <span class="text-caption text-slate-500">Baris per halaman</span>
            <div class="w-24">
              <UiSelect :model-value="String(filter.perPage)" :options="perPageOpsi" size="sm" aria-label="Jumlah baris" data-testid="rekap-uang-makan-per-page" @update:model-value="ubahPerPage" />
            </div>
          </div>
        </div>
        <UiPagination v-if="daftar && daftar.total > filter.perPage" v-model:page="filter.page" :per-page="filter.perPage" :total="daftar.total" />
      </UiCard>
    </div>
  </RedesignShell>
</template>
