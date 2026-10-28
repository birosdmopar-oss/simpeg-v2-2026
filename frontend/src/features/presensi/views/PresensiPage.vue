<script setup lang="ts">
/**
 * Presensi Saya (D-02, PRD Fase 5A §7.8.1; role 2, 6, 7) — mobile-first, dirancang dari 390 px [K `dashboard_pns.php`
 * panel presensi]. Tanggal & jam lokal, jendela, dan `boleh_masuk`/`boleh_pulang` dari server (zonasi satker); jam
 * berjalan di layar hanya tampilan. Panel status lokasi sebelum submit: izin lokasi, koordinat & akurasi, putusan
 * `POST presensi/cek-lokasi` (maks sekali per 10 detik) + indikator radius SVG. Bila `wajib_geofence` dan di luar
 * radius / lokasi belum terbaca → tombol dinonaktifkan dengan alasan (UX; server tetap penentu, ADR-025). Riwayat
 * pribadi per bulan + paginasi. Tanpa frame Figma → pola komponen Fase 3 (AGENTS §3.6).
 */
import { CheckCircle2, Clock, LogIn, LogOut, MapPin, XCircle } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiBadge, UiButton, UiMonthPicker, UiNotice, UiPagination } from '@/shared/ui'

import { describeApiError } from '@/features/kepegawaian/services/apiErrors'

import IndikatorRadius from '../components/IndikatorRadius.vue'
import { INTERVAL_GEOLOKASI_MS, useGeolokasi } from '../composables/useGeolokasi'
import { formatJam, formatTanggal, labelSumber, presensiService } from '../services/presensi.service'
import type { CekLokasi, PresensiHariIni, RiwayatPresensi } from '../types'

const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Presensi' }, { label: 'Presensi Saya' }]

const hariIni = ref<PresensiHariIni | null>(null)
const memuat = ref(true)
const failure = ref<string | null>(null)
const notice = ref('')
const galatRekam = ref('')
const merekam = ref<'masuk' | 'pulang' | null>(null)

// --- Jam tampilan (dari jam server + waktu berjalan; tidak dipakai menentukan apa pun) -----------------------------
const detikBerjalan = ref(0)
let dasarMs = 0
let tick: ReturnType<typeof setInterval> | undefined
const jamTampil = computed(() => {
  const j = hariIni.value?.jam_lokal
  const m = j ? /^(\d{2}):(\d{2}):(\d{2})$/.exec(j) : null
  if (!m) return j ?? '--:--:--'
  const total = (Number(m[1]) * 3600 + Number(m[2]) * 60 + Number(m[3]) + detikBerjalan.value) % 86400
  const p = (n: number) => String(n).padStart(2, '0')
  return `${p(Math.floor(total / 3600))}:${p(Math.floor((total % 3600) / 60))}:${p(total % 60)}`
})
const tanggalTampil = computed(() => (hariIni.value ? formatTanggal(hariIni.value.tanggal_lokal) : null))

// --- Geolokasi & putusan server ----------------------------------------------------------------------------------
const geo = useGeolokasi()
const cek = ref<CekLokasi | null>(null)
const mengecek = ref(false)
const galatCek = ref('')
let cekTerakhir = 0

async function cekLokasi(paksa = false): Promise<void> {
  const p = geo.posisi.value
  if (!p || mengecek.value) return
  // Maks sekali per pembacaan lokasi (tiap 10 detik). Toleransi 500 ms supaya pembacaan yang datang beberapa
  // milidetik lebih awal dari timer tidak terlewat (kalau terlewat, putusan baru tertunda 20 detik).
  if (!paksa && Date.now() - cekTerakhir < INTERVAL_GEOLOKASI_MS - 500) return
  cekTerakhir = Date.now()
  mengecek.value = true
  galatCek.value = ''
  try {
    cek.value = await presensiService.cekLokasi({ latitude: p.latitude, longitude: p.longitude, akurasi: p.akurasi })
  } catch (err) {
    galatCek.value = isApiError(err) && err.status === 422 && err.message ? err.message : describeApiError(err).message
  } finally {
    mengecek.value = false
  }
}
watch(() => geo.posisi.value?.waktu, () => void cekLokasi())

const lokasiTerdekat = computed(() => {
  const h = hariIni.value
  if (!h || h.lokasi.length === 0) return null
  const id = cek.value?.lokasi_terdekat?.id
  const l = h.lokasi.find((x) => x.id_lokasi_presensi === id) ?? h.lokasi[0]
  return l ? { latitude: l.latitude, longitude: l.longitude, radius: l.radius, nama: l.nama_lokasi } : null
})

/** Alasan tombol nonaktif (teks, bukan warna saja). `null` = boleh ditekan. */
function alasan(jenis: 'masuk' | 'pulang'): string | null {
  const h = hariIni.value
  if (!h) return 'Memuat status presensi…'
  if (jenis === 'masuk' && !h.boleh_masuk) {
    return h.presensi?.jam_masuk ? `Presensi masuk hari ini sudah terekam pukul ${formatJam(h.presensi.jam_masuk)}.` : `Presensi masuk hanya dapat direkam pukul ${formatJam(h.jendela.masuk[0])}–${formatJam(h.jendela.masuk[1])} waktu setempat.`
  }
  if (jenis === 'pulang' && !h.boleh_pulang) return `Presensi pulang hanya dapat direkam pukul ${formatJam(h.jendela.pulang[0])}–${formatJam(h.jendela.pulang[1])} waktu setempat.`
  if (!h.wajib_geofence) return null
  if (geo.status.value === 'ditolak' || geo.status.value === 'tidak-tersedia') return 'Lokasi perangkat wajib terbaca untuk presensi Anda.'
  if (!geo.posisi.value) return 'Menunggu lokasi perangkat…'
  if (!cek.value) return 'Memeriksa lokasi Anda…'
  if (!cek.value.dalam_radius) return 'Anda berada di luar radius lokasi presensi.'
  return null
}
const alasanMasuk = computed(() => alasan('masuk'))
const alasanPulang = computed(() => alasan('pulang'))

async function muatHariIni(): Promise<void> {
  try {
    hariIni.value = await presensiService.hariIni()
    dasarMs = Date.now()
    detikBerjalan.value = 0
    failure.value = null
  } catch (err) {
    failure.value = describeApiError(err).message
  } finally {
    memuat.value = false
  }
}

async function rekam(jenis: 'masuk' | 'pulang'): Promise<void> {
  if (merekam.value) return
  merekam.value = jenis
  galatRekam.value = ''
  notice.value = ''
  const p = geo.posisi.value
  try {
    const hasil = await presensiService.rekam(jenis, p ? { latitude: p.latitude, longitude: p.longitude, akurasi: p.akurasi } : null)
    const jam = jenis === 'masuk' ? hasil.jam_masuk : hasil.jam_pulang
    notice.value = `Presensi ${jenis} tanggal ${formatTanggal(hasil.tgl).tanggal} terekam pukul ${formatJam(jam)}.`
    await Promise.all([muatHariIni(), muatRiwayat()])
  } catch (err) {
    galatRekam.value = isApiError(err) && err.status === 422 && err.message ? err.message : describeApiError(err).message
    await muatHariIni()
    void cekLokasi(true)
  } finally {
    merekam.value = null
  }
}

// --- Riwayat ------------------------------------------------------------------------------------------------------
const bulan = ref('')
const page = ref(1)
const perPage = 20
const riwayat = ref<RiwayatPresensi | null>(null)
const memuatRiwayat = ref(false)
const galatRiwayat = ref<string | null>(null)

async function muatRiwayat(): Promise<void> {
  memuatRiwayat.value = true
  galatRiwayat.value = null
  try {
    riwayat.value = await presensiService.riwayatSaya(bulan.value, page.value, perPage)
    if (!bulan.value) bulan.value = riwayat.value.bulan
  } catch (err) {
    galatRiwayat.value = describeApiError(err).message
  } finally {
    memuatRiwayat.value = false
  }
}
watch(bulan, (_baru, lama) => {
  if (lama === '') return
  page.value = 1
  void muatRiwayat()
})
watch(page, () => void muatRiwayat())

let segar: ReturnType<typeof setInterval> | undefined
onMounted(async () => {
  await muatHariIni()
  void muatRiwayat()
  geo.mulai()
  tick = setInterval(() => {
    detikBerjalan.value = Math.floor((Date.now() - dasarMs) / 1000)
  }, 1000)
  // `boleh_*` bisa berubah saat jendela terlewati; disegarkan dari server tiap menit.
  segar = setInterval(() => void muatHariIni(), 60_000)
})
onBeforeUnmount(() => {
  clearInterval(tick)
  clearInterval(segar)
})

const statusIzin = computed(() => {
  switch (geo.status.value) {
    case 'diizinkan':
      return { label: 'Diizinkan', tone: 'success' as const }
    case 'ditolak':
      return { label: 'Ditolak', tone: 'danger' as const }
    case 'tidak-tersedia':
      return { label: 'Tidak tersedia', tone: 'danger' as const }
    case 'galat':
      return { label: 'Gagal membaca', tone: 'warning' as const }
    default:
      return { label: 'Meminta izin…', tone: 'neutral' as const }
  }
})
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="mx-auto max-w-5xl space-y-5" data-testid="presensi-page" :aria-busy="memuat">
      <div>
        <h1 class="text-h2 font-semibold text-slate-900">Presensi Saya</h1>
        <p class="text-body2 text-slate-500">Rekam presensi masuk dan pulang dari perangkat Anda.</p>
      </div>

      <UiNotice v-if="failure" tone="danger" data-testid="presensi-failure">{{ failure }}</UiNotice>
      <UiNotice v-if="notice" tone="success" data-testid="presensi-notice">{{ notice }}</UiNotice>
      <UiNotice v-if="galatRekam" tone="danger" data-testid="presensi-galat-rekam">{{ galatRekam }}</UiNotice>

      <div v-if="hariIni" class="grid gap-4 lg:grid-cols-2">
        <!-- Rekam -->
        <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5" aria-labelledby="presensi-hari-ini">
          <h2 id="presensi-hari-ini" class="sr-only">Presensi hari ini</h2>
          <div class="text-center" data-testid="presensi-waktu">
            <p class="text-body2 text-slate-500">{{ tanggalTampil?.hari }}, {{ tanggalTampil?.tanggal }}</p>
            <p class="text-h2 font-semibold tabular-nums text-slate-900" aria-live="off">{{ jamTampil }}</p>
            <p v-if="hariIni.zonasi_menit > 0" class="text-caption text-slate-500">Waktu setempat (WIB + {{ hariIni.zonasi_menit }} menit)</p>
          </div>

          <dl class="grid grid-cols-2 gap-3 text-center" data-testid="presensi-status-hari-ini">
            <div class="rounded-xl bg-slate-50 p-3">
              <dt class="text-caption text-slate-500">Jam Masuk</dt>
              <dd class="text-h4 font-semibold tabular-nums text-slate-900">{{ formatJam(hariIni.presensi?.jam_masuk ?? null) }}</dd>
            </div>
            <div class="rounded-xl bg-slate-50 p-3">
              <dt class="text-caption text-slate-500">Jam Pulang</dt>
              <dd class="text-h4 font-semibold tabular-nums text-slate-900">{{ formatJam(hariIni.presensi?.jam_pulang ?? null) }}</dd>
            </div>
          </dl>

          <div class="space-y-2">
            <UiButton
              variant="success"
              class="w-full justify-center"
              :disabled="alasanMasuk !== null || merekam !== null"
              :loading="merekam === 'masuk'"
              :aria-describedby="alasanMasuk ? 'alasan-masuk' : undefined"
              data-testid="presensi-masuk"
              @click="rekam('masuk')"
            >
              <LogIn class="h-4 w-4" aria-hidden="true" /> Rekam Masuk
            </UiButton>
            <p v-if="alasanMasuk" id="alasan-masuk" class="text-center text-caption text-slate-500" data-testid="presensi-alasan-masuk">{{ alasanMasuk }}</p>
            <UiButton
              variant="error"
              class="w-full justify-center"
              :disabled="alasanPulang !== null || merekam !== null"
              :loading="merekam === 'pulang'"
              :aria-describedby="alasanPulang ? 'alasan-pulang' : undefined"
              data-testid="presensi-pulang"
              @click="rekam('pulang')"
            >
              <LogOut class="h-4 w-4" aria-hidden="true" /> Rekam Pulang
            </UiButton>
            <p v-if="alasanPulang" id="alasan-pulang" class="text-center text-caption text-slate-500" data-testid="presensi-alasan-pulang">{{ alasanPulang }}</p>
          </div>
        </section>

        <!-- Status lokasi -->
        <section class="space-y-3 rounded-2xl border border-slate-200 bg-white p-5" aria-labelledby="presensi-lokasi" data-testid="presensi-panel-lokasi">
          <h2 id="presensi-lokasi" class="flex items-center gap-2 text-h5 text-slate-900"><MapPin class="h-5 w-5 text-brand-primary" aria-hidden="true" /> Status Lokasi</h2>
          <p class="text-body2 text-slate-600" data-testid="presensi-wajib-geofence">
            {{ hariIni.wajib_geofence ? 'Presensi Anda wajib dilakukan di dalam radius lokasi yang ditetapkan.' : 'Presensi Anda tidak terikat lokasi; koordinat tetap dikirim bila tersedia.' }}
          </p>
          <dl class="space-y-1 text-body2">
            <div class="flex justify-between gap-3">
              <dt class="text-slate-500">Izin lokasi</dt>
              <dd data-testid="presensi-izin"><UiBadge :tone="statusIzin.tone" size="sm">{{ statusIzin.label }}</UiBadge></dd>
            </div>
            <div v-if="geo.posisi.value" class="flex justify-between gap-3">
              <dt class="text-slate-500">Koordinat</dt>
              <dd class="tabular-nums text-slate-800" data-testid="presensi-koordinat">{{ geo.posisi.value.latitude.toFixed(6) }}, {{ geo.posisi.value.longitude.toFixed(6) }}</dd>
            </div>
            <div v-if="geo.posisi.value" class="flex justify-between gap-3">
              <dt class="text-slate-500">Akurasi</dt>
              <dd class="tabular-nums text-slate-800">{{ geo.posisi.value.akurasi === null ? '-' : `±${Math.round(geo.posisi.value.akurasi)} m` }}</dd>
            </div>
          </dl>
          <p v-if="geo.pesan.value" class="text-caption text-amber-800" role="status" data-testid="presensi-pesan-geo">{{ geo.pesan.value }}</p>

          <div
            v-if="cek && cek.lokasi_terdekat && hariIni.wajib_geofence"
            class="flex items-start gap-2 rounded-xl p-3 text-body2"
            :class="cek.dalam_radius ? 'bg-success-soft text-[#146c3a]' : 'bg-danger-soft text-[#9b2c2d]'"
            role="status"
            data-testid="presensi-hasil-cek"
          >
            <CheckCircle2 v-if="cek.dalam_radius" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <XCircle v-else class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <span>
              {{ cek.dalam_radius ? 'Di dalam radius' : 'Di luar radius' }} {{ cek.lokasi_terdekat.nama }} — jarak ±{{ cek.lokasi_terdekat.jarak_m }} m,
              radius {{ cek.lokasi_terdekat.radius }} m.
            </span>
          </div>
          <p v-else-if="mengecek" class="flex items-center gap-1 text-caption text-slate-500"><Clock class="h-3.5 w-3.5" aria-hidden="true" /> Memeriksa lokasi…</p>
          <p v-if="galatCek" class="text-caption text-red-700" role="alert" data-testid="presensi-galat-cek">{{ galatCek }}</p>

          <IndikatorRadius
            v-if="hariIni.wajib_geofence && lokasiTerdekat"
            :lokasi="lokasiTerdekat"
            :posisi="geo.posisi.value"
            :dalam-radius="cek ? cek.dalam_radius : null"
          />
        </section>
      </div>
      <p v-else-if="memuat" class="py-8 text-center text-body2 text-slate-500" role="status">Memuat status presensi…</p>

      <!-- Riwayat -->
      <section class="space-y-3" aria-labelledby="presensi-riwayat">
        <div class="flex flex-wrap items-end justify-between gap-3">
          <h2 id="presensi-riwayat" class="text-h4 font-semibold text-slate-900">Riwayat Presensi</h2>
          <div class="w-44">
            <UiMonthPicker v-model="bulan" aria-label="Bulan riwayat presensi" data-testid="presensi-bulan" />
          </div>
        </div>
        <UiNotice v-if="galatRiwayat" tone="danger">{{ galatRiwayat }}</UiNotice>
        <div class="scrollbar-slim overflow-x-auto rounded-2xl border border-slate-200 bg-white">
          <table class="min-w-full text-sm" data-testid="presensi-riwayat-tabel">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th class="px-4 py-3">Hari</th>
                <th class="px-4 py-3">Tanggal</th>
                <th class="whitespace-nowrap px-4 py-3">Jam Masuk</th>
                <th class="whitespace-nowrap px-4 py-3">Jam Pulang</th>
                <th class="px-4 py-3">Sumber</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-if="memuatRiwayat">
                <td colspan="5" class="px-4 py-6 text-center text-slate-500">Memuat...</td>
              </tr>
              <tr v-else-if="!riwayat || riwayat.items.length === 0">
                <td colspan="5" class="px-4 py-6 text-center text-slate-500" data-testid="presensi-riwayat-kosong">Belum ada data presensi pada bulan ini.</td>
              </tr>
              <tr v-for="r in riwayat?.items ?? []" v-else :key="r.tgl" :data-testid="`presensi-riwayat-${r.tgl}`">
                <td class="px-4 py-3 text-slate-600">{{ formatTanggal(r.tgl).hari }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-slate-800">{{ formatTanggal(r.tgl).tanggal }}</td>
                <td class="px-4 py-3 tabular-nums text-slate-800">{{ formatJam(r.jam_masuk) }}</td>
                <td class="px-4 py-3 tabular-nums text-slate-800">{{ formatJam(r.jam_pulang) }}</td>
                <td class="px-4 py-3">
                  <UiBadge :tone="r.sumber === 2 ? 'warning' : r.sumber === 1 ? 'info' : 'neutral'" size="sm">{{ labelSumber(r.sumber) }}</UiBadge>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <UiPagination v-if="riwayat && riwayat.total > perPage" v-model:page="page" :per-page="perPage" :total="riwayat.total" />
      </section>
    </div>
  </RedesignShell>
</template>
