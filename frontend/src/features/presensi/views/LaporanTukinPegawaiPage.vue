<script setup lang="ts">
/**
 * Laporan Tukin per pegawai (PRD 5C §7.3; `GET presensi/tukin/laporan/{nip}?bulan=`): kartu identitas, periode (+ catatan
 * "dihitung sampai" untuk periode berjalan), ringkasan kode, tabel harian ber-color-coding, rincian & total, penyesuaian,
 * keterangan, dan Unduh PDF. Varian "Saya" (role 2/6/7) memakai NIP akun tanpa input NIP; membuka NIP lain → 403 dari
 * server ditampilkan sebagai pesan. Halaman menggulir normal (bukan daftar ber-tabel, AGENTS.md §3.4).
 */
import { ArrowLeft, Download } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { formatTanggalIndonesia as tgl } from '@/shared/utils/dateTime'
import { UiButton, UiCard, UiNotice, UiTextField } from '@/shared/ui'

import LegendaStatusPresensi from '../components/LegendaStatusPresensi.vue'
import TabelHarianTukin from '../components/TabelHarianTukin.vue'
import { bulanBerjalanWib } from '../composables/useFilterLaporan'
import { persenId } from '../format'
import { laporanTukinService, pesanGalatLaporan } from '../services/laporanTukin.service'
import type { LaporanTukinPegawai } from '../types.laporan.tukin'

const props = withDefaults(defineProps<{ saya?: boolean }>(), { saya: false })

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const nip = computed(() => (props.saya ? (auth.user?.nip ?? '') : String(route.params.nip ?? '')))
const POLA_BULAN = /^\d{4}-(0[1-9]|1[0-2])$/
const bulanAwal = typeof route.query.bulan === 'string' && POLA_BULAN.test(route.query.bulan) ? route.query.bulan : bulanBerjalanWib()
const bulan = ref(bulanAwal)

const crumbs = computed(() => [{ label: 'Home', to: { name: 'home' } }, { label: 'Presensi' }, { label: props.saya ? 'Laporan Tukin Saya' : 'Laporan Tukin' }])

const data = ref<LaporanTukinPegawai | null>(null)
const memuat = ref(false)
const galat = ref('')
const noticeUnduh = ref('')
const mengunduh = ref(false)
let token = 0

const KODE_JUMLAH = ['TL1', 'TL2', 'TL3', 'PSW1', 'PSW2', 'PSW3', 'TPM', 'TPP', 'TK', 'CS']
const KOLOM_RINCIAN: [string, string][] = [
  ['TL', 'Terlambat (TL)'],
  ['PSW', 'Pulang sebelum waktunya (PSW)'],
  ['TA', 'Tanpa absen (TA)'],
  ['TK', 'Tanpa keterangan (TK)'],
  ['CUTI', 'Cuti'],
]
const berjalan = computed(() => data.value !== null && data.value.periode.dihitung_sampai !== '' && data.value.periode.dihitung_sampai < data.value.periode.akhir)

async function muat(): Promise<void> {
  if (!POLA_BULAN.test(bulan.value) || nip.value === '') return
  const saya = ++token
  memuat.value = true
  galat.value = ''
  try {
    const hasil = await laporanTukinService.laporan(nip.value, bulan.value)
    if (saya === token) data.value = hasil
  } catch (e) {
    if (saya !== token) return
    data.value = null
    galat.value = pesanGalatLaporan(e)
  } finally {
    if (saya === token) memuat.value = false
  }
}

onMounted(() => void muat())
watch(bulan, () => {
  void router.replace({ query: bulan.value === bulanAwal ? {} : { bulan: bulan.value } })
  void muat()
})

async function unduhPdf(): Promise<void> {
  mengunduh.value = true
  noticeUnduh.value = ''
  try {
    simpanBerkas(await laporanTukinService.pdf(nip.value, bulan.value))
  } catch (e) {
    noticeUnduh.value = `Gagal mengunduh PDF: ${pesanGalatLaporan(e)}`
  } finally {
    mengunduh.value = false
  }
}
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="flex flex-col gap-5" data-testid="tukin-laporan-page">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-h2 font-semibold text-slate-900">{{ saya ? 'Laporan Tukin Saya' : 'Laporan Tukin' }}</h1>
          <p class="text-body2 text-slate-500">Rincian kehadiran dan potongan tunjangan kinerja per bulan.</p>
        </div>
        <div class="flex flex-wrap items-end gap-2">
          <UiButton v-if="!saya" variant="secondary" appearance="outline" data-testid="tukin-kembali" @click="router.back()">
            <ArrowLeft class="h-4 w-4" aria-hidden="true" /> Kembali
          </UiButton>
          <UiButton :disabled="mengunduh || data === null" data-testid="tukin-unduh-pdf" @click="unduhPdf">
            <Download class="h-4 w-4" aria-hidden="true" /> Unduh PDF
          </UiButton>
        </div>
      </div>

      <div class="max-w-xs"><UiTextField v-model="bulan" type="month" label="Bulan" placeholder="bb/tttt" required /></div>

      <UiNotice v-if="galat" tone="danger" data-testid="tukin-laporan-galat">{{ galat }}</UiNotice>
      <UiNotice v-if="noticeUnduh" tone="danger" data-testid="tukin-unduh-galat">{{ noticeUnduh }}</UiNotice>
      <p v-if="memuat && data === null" class="text-body2 text-slate-500" data-testid="tukin-memuat">Memuat…</p>

      <template v-if="data">
        <UiCard title="Identitas">
          <dl class="grid gap-x-8 gap-y-2 text-body2 sm:grid-cols-2 xl:grid-cols-3" data-testid="tukin-identitas">
            <div><dt class="text-slate-500">Nama</dt><dd class="font-medium text-slate-900">{{ data.identitas.nama }}</dd></div>
            <div><dt class="text-slate-500">NIP</dt><dd class="font-medium text-slate-900">{{ data.identitas.nip }}</dd></div>
            <div v-if="data.identitas.gol_ruang"><dt class="text-slate-500">Gol/Ruang</dt><dd class="font-medium text-slate-900">{{ data.identitas.gol_ruang }}</dd></div>
            <div><dt class="text-slate-500">Jabatan</dt><dd class="font-medium text-slate-900">{{ data.identitas.jabatan || '-' }}</dd></div>
            <div><dt class="text-slate-500">Satker</dt><dd class="font-medium text-slate-900">{{ data.identitas.satker || '-' }}</dd></div>
            <div v-if="data.identitas.kelas_jabatan !== null"><dt class="text-slate-500">Kelas Jabatan</dt><dd class="font-medium text-slate-900">{{ data.identitas.kelas_jabatan }}</dd></div>
            <div v-if="data.identitas.predikat_skp"><dt class="text-slate-500">Predikat SKP</dt><dd class="font-medium text-slate-900">{{ data.identitas.predikat_skp }}</dd></div>
            <div>
              <dt class="text-slate-500">Periode</dt>
              <dd class="font-medium text-slate-900" data-testid="tukin-periode">
                {{ tgl(data.periode.awal) }} s.d. {{ tgl(data.periode.akhir) }}
                <span class="font-normal text-slate-500">({{ data.periode.sumber === 'bkn' ? 'ketetapan BKN' : 'periode bawaan' }})</span>
              </dd>
            </div>
          </dl>
          <p v-if="berjalan" class="mt-3 text-body2 text-slate-600" data-testid="tukin-berjalan">Periode berjalan: dihitung sampai {{ tgl(data.periode.dihitung_sampai) }}.</p>
        </UiCard>

        <UiCard title="Rekap Harian" subtitle="Warna baris mengikuti jenis hari; kode kejadian ada di kolom Status.">
          <TabelHarianTukin :harian="data.harian" />
          <div class="mt-4"><LegendaStatusPresensi /></div>
        </UiCard>

        <UiCard title="Ringkasan">
          <ul class="mb-4 flex flex-wrap gap-2" data-testid="tukin-jumlah">
            <li v-for="k in KODE_JUMLAH" :key="k" class="rounded-lg border border-slate-200 px-3 py-1.5 text-body2" :data-kode="k">
              <span class="font-semibold text-slate-900">{{ k }}</span> <span class="text-slate-600">{{ data.jumlah[k] ?? 0 }}</span>
            </li>
          </ul>
          <dl class="grid gap-x-8 gap-y-2 text-body2 sm:grid-cols-2 xl:grid-cols-3" data-testid="tukin-rincian">
            <div v-for="[kunci, label] in KOLOM_RINCIAN" :key="kunci">
              <dt class="text-slate-500">Potongan {{ label }}</dt>
              <dd class="font-medium text-slate-900" :data-kolom="kunci">{{ persenId(data.rincian[kunci] ?? '0') }}</dd>
            </div>
            <div><dt class="text-slate-500">Total presensi</dt><dd class="font-medium text-slate-900" data-testid="tukin-total-presensi">{{ persenId(data.total_presensi) }}</dd></div>
            <div><dt class="text-slate-500">Total SKP</dt><dd class="font-medium text-slate-900" data-testid="tukin-total-skp">{{ persenId(data.total_skp) }}</dd></div>
            <div><dt class="text-slate-500">Total potongan</dt><dd class="text-h6 font-semibold text-slate-900" data-testid="tukin-total-potongan">{{ persenId(data.total_potongan) }}</dd></div>
          </dl>
          <UiNotice v-if="data.penyesuaian" tone="info" class="mt-4" data-testid="tukin-penyesuaian">
            Penyesuaian Tukin {{ persenId(data.penyesuaian.persen) }}: {{ data.penyesuaian.alasan }}
          </UiNotice>
          <p v-if="data.keterangan" class="mt-4 whitespace-pre-line text-body2 text-slate-700" data-testid="tukin-keterangan">{{ data.keterangan }}</p>
        </UiCard>
      </template>
    </div>
  </RedesignShell>
</template>
