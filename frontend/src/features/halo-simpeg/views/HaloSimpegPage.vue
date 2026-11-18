<script setup lang="ts">
/**
 * Halaman Halo Simpeg (role 1–7) — B2 pemilik + B3 petugas (MAKE-073). Master-detail ala Gambar 33/34 (tanpa frame Figma, H8-9):
 * - Tab "Tiket saya" (semua) dan "Antrean" (hanya petugas aktif menurut `GET petugas/saya`, bukan role; `?tab=antrean`).
 *   Filter/halaman tiap tab terpisah (pabrik store). Tab antrean: panel "Perlu ditindak" + tabel antrean.
 * - ≥ 1024 px: daftar di kiri, thread di kanan; `idTiket` (rute `halo-tiket`) = keadaan panel kanan (deep-link lonceng/push
 *   `/halo-simpeg/tiket/{id}`, tombol kembali browser berfungsi; fokus ke judul thread).
 * - < 1024 px: satu panel — daftar, atau thread + "Kembali ke daftar" (perilaku responsif, bukan deviasi).
 * - "Tambah Pertanyaan" hanya role 2/6/7 (`POST tiket`, F8-04).
 * - `idTidakSah` (id 0 / di luar batas) → "Data percakapan tidak ditemukan." (review B1 V3).
 */
import { ArrowLeft } from 'lucide-vue-next'
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { useIsDesktop } from '@/shared/composables/useIsDesktop'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { type Crumb, type TabItem, UiButton, UiCard, UiNotice, UiTabs } from '@/shared/ui'

import DaftarTiket from '../components/DaftarTiket.vue'
import KontakHelpdeskCard from '../components/KontakHelpdeskCard.vue'
import PanelPerluTindak from '../components/PanelPerluTindak.vue'
import ThreadTiket from '../components/ThreadTiket.vue'
import TiketBaruDialog from '../components/TiketBaruDialog.vue'
import { BACA_BAWAAN_ANTREAN, HALO_PEMBUAT_TIKET_ROLES, TAB_ANTREAN, TEKS_GALAT_TIKET } from '../constants'
import { haloState, muatPetugasSaya, petugasAktif } from '../stores/haloSimpeg'
import { bukaTiket, daftarAntrean, daftarSaya, tutupPanel, type DaftarTiket as Daftar } from '../stores/tiket'
import type { TiketDetail } from '../types'

const props = defineProps<{ idTiket: number | null; idTidakSah: boolean }>()

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const isDesktop = useIsDesktop()

const bolehBuat = computed(() => auth.role !== null && HALO_PEMBUAT_TIKET_ROLES.includes(auth.role))
const dialogBaru = ref(false)

// Review B3 R4: dimuat paksa sekali per kunjungan (admin bisa baru menambah/menonaktifkan dirinya sebagai petugas).
onMounted(() => {
  void muatPetugasSaya(true)
})

const TAB_SAYA = 'saya'
const tabs = computed<TabItem[]>(() => [
  { value: TAB_SAYA, label: 'Tiket Saya' },
  ...(petugasAktif.value ? [{ value: TAB_ANTREAN, label: 'Antrean' }] : []),
])
const TAB_GALAT: TabItem[] = [
  { value: TAB_SAYA, label: 'Tiket Saya' },
  { value: TAB_ANTREAN, label: 'Antrean' },
]
/** `?tab=antrean` hanya berlaku untuk petugas aktif; selain itu tab "Tiket saya". */
const tab = computed<string>({
  get: () => (route.query.tab === TAB_ANTREAN && petugasAktif.value ? TAB_ANTREAN : TAB_SAYA),
  set: (v) => void router.replace({ name: 'halo-simpeg', query: v === TAB_ANTREAN ? { tab: TAB_ANTREAN } : {} }),
})
const diAntrean = computed(() => tab.value === TAB_ANTREAN)
/** Review B3 R5: deep-link `?tab=antrean` sebelum `petugas/saya` selesai → "Memuat..." (jangan memasang tab Saya dulu). */
const mintaAntrean = computed(() => route.query.tab === TAB_ANTREAN)
const memuatAntrean = computed(() => mintaAntrean.value && ['awal', 'memuat'].includes(haloState.statusPetugasSaya) && !petugasAktif.value)
/** Review S2 (b): galat muat paksa dengan cache petugas aktif → antrean dari cache tetap tampil; galat hanya tanpa data petugas. */
const galatAntrean = computed(() => mintaAntrean.value && haloState.statusPetugasSaya === 'galat' && !petugasAktif.value)
const daftarAktif = computed<Daftar>(() => (diAntrean.value ? daftarAntrean : daftarSaya))
const queryTab = computed(() => (diAntrean.value ? { tab: TAB_ANTREAN } : {}))
const kategoriPetugas = computed(() => (haloState.petugasSaya?.kategori ?? []).map((k) => ({ id: k.id, nama: k.nama })))

const crumbs = computed<Crumb[]>(() => {
  const dasar: Crumb[] = [{ label: 'Home', to: { name: 'home' } }, { label: 'Halo Simpeg', to: { name: 'halo-simpeg' } }]
  if (props.idTidakSah) return [...dasar, { label: 'Tiket' }]
  return props.idTiket === null ? dasar : [...dasar, { label: `Tiket #${props.idTiket}` }]
})

const panelDaftarEl = ref<HTMLElement | null>(null)

/**
 * < 1024 px: sesudah kembali dari thread ke daftar, fokus ke tombol ⋮ baris tiket tadi (atau judul kartu bila baris tidak
 * ada), bukan jatuh ke `<body>` (review B2 P4 (b)).
 */
/** Tunggu daftar aktif selesai dimuat (`siap`/`galat`), paling lama `batasMs` (review B2 Q2: GET daftar nyata bisa lambat). */
function tungguDaftarSelesai(batasMs = 10_000): Promise<void> {
  const selesai = () => ['siap', 'galat'].includes(daftarAktif.value.state.statusDaftar)
  if (selesai()) return Promise.resolve()
  return new Promise<void>((lepas) => {
    const batas = setTimeout(() => {
      henti()
      lepas()
    }, batasMs)
    const henti = watch(
      () => daftarAktif.value.state.statusDaftar,
      () => {
        if (!selesai()) return
        clearTimeout(batas)
        henti()
        lepas()
      },
    )
  })
}

async function fokusSesudahKembali(idSebelum: number | null): Promise<void> {
  // Di layar sempit daftar baru dipasang (dan dimuat) sesudah kembali: daftar dipasang dulu, lalu tunggu muatnya selesai.
  await nextTick()
  await tungguDaftarSelesai()
  await nextTick()
  const akar = panelDaftarEl.value
  if (!akar) return
  const tombol = idSebelum === null ? null : akar.querySelector<HTMLElement>(`[data-testid="halo-tiket-actions-${idSebelum}"]`)
  if (tombol) {
    tombol.focus()
    return
  }
  const judul = akar.querySelector<HTMLElement>('h2, h3')
  if (judul) {
    judul.setAttribute('tabindex', '-1')
    judul.focus()
  }
}

watch(
  () => [props.idTiket, props.idTidakSah] as const,
  ([id], sebelum) => {
    if (id === null) tutupPanel()
    else void bukaTiket(id)
    const [idSebelum, tidakSahSebelum] = sebelum ?? [null, false]
    if (id === null && !isDesktop.value && (idSebelum !== null || tidakSahSebelum)) void fokusSesudahKembali(idSebelum)
  },
  { immediate: true },
)

const panelThread = computed(() => props.idTiket !== null || props.idTidakSah)
const tampilDaftar = computed(() => isDesktop.value || !panelThread.value)
const tampilThread = computed(() => isDesktop.value || panelThread.value)

function kembali(): void {
  void router.push({ name: 'halo-simpeg', query: queryTab.value })
}

function onDibuat(t: TiketDetail): void {
  void router.push({ name: 'halo-tiket', params: { id: t.id } })
}

/**
 * Review B3 R1: "Lihat semua" menampilkan SEMUA tiket perlu ditindak — `baca` = semua dan filter lain dikosongkan, agar tiket
 * yang sudah dibaca tetapi pengingatnya jatuh tempo tetap tampil (total tabel = panel = `Ringkasan.perlu_tindak`, AC-E04.13).
 */
function lihatSemuaPerluTindak(): void {
  void daftarAntrean.ubahFilter({ perlu_tindak: true, baca: 'semua', status: null, q: '', kategori: null })
}

/** "Hapus filter" chip perlu ditindak → kembali ke bawaan antrean (`baca` = belum [K]). */
function hapusFilterPerluTindak(): void {
  void daftarAntrean.ubahFilter({ perlu_tindak: false, baca: BACA_BAWAAN_ANTREAN })
}
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="grid gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]" data-testid="halo-halaman">
      <div v-if="tampilDaftar" ref="panelDaftarEl" class="flex min-w-0 flex-col gap-4" data-testid="halo-panel-daftar">
        <UiCard v-if="memuatAntrean" title="Antrean">
          <p class="py-6 text-center text-sm text-slate-500" data-testid="halo-antrean-memuat">Memuat...</p>
        </UiCard>
        <UiCard v-else-if="galatAntrean" title="Antrean">
          <!-- S2 (b): tab tetap ada agar "Tiket Saya" selalu bisa dipilih; S2 (c): UiNotice danger sudah role="alert". -->
          <UiTabs :model-value="TAB_ANTREAN" :items="TAB_GALAT" aria-label="Halo Simpeg" class="mb-4" data-testid="halo-tabs" @update:model-value="tab = $event" />
          <UiNotice tone="danger" data-testid="halo-antrean-galat">{{ haloState.galatPetugasSaya }}</UiNotice>
          <UiButton size="sm" variant="secondary" appearance="outline" class="mt-3" data-testid="halo-antrean-coba-lagi" @click="muatPetugasSaya(true)">
            Coba lagi
          </UiButton>
        </UiCard>
        <UiCard v-else :title="diAntrean ? 'Antrean' : 'Tiket Saya'">
          <UiTabs v-if="tabs.length > 1" v-model="tab" :items="tabs" aria-label="Halo Simpeg" class="mb-4" data-testid="halo-tabs" />
          <template v-if="diAntrean">
            <PanelPerluTindak class="mb-3" @lihat-semua="lihatSemuaPerluTindak" />
            <div v-if="daftarAntrean.state.filter.perlu_tindak" class="mb-2 flex items-center gap-2 text-sm" data-testid="halo-filter-perlu-tindak">
              <span class="rounded-full bg-warning-soft px-2 py-0.5 text-[#8a4f0f]">Hanya perlu ditindak</span>
              <UiButton size="sm" variant="secondary" appearance="ghost" data-testid="halo-hapus-filter-perlu-tindak" @click="hapusFilterPerluTindak">Hapus filter</UiButton>
            </div>
            <DaftarTiket :key="TAB_ANTREAN" :daftar="daftarAntrean" :id-aktif="idTiket" :kategori-petugas="kategoriPetugas" />
          </template>
          <DaftarTiket v-else :key="TAB_SAYA" :daftar="daftarSaya" :boleh-buat="bolehBuat" :id-aktif="idTiket" @tiket-baru="dialogBaru = true" />
        </UiCard>
        <!-- S2 (a): non-petugas dengan ?tab=antrean melihat tab Saya → kartu Kontak tetap tampil (kecuali saat memuat/galat). -->
        <KontakHelpdeskCard v-if="!diAntrean && !memuatAntrean && !galatAntrean" />
      </div>

      <UiCard v-if="tampilThread" class="min-w-0" data-testid="halo-panel-thread">
        <p v-if="idTidakSah" class="py-6 text-sm text-danger" role="alert" data-testid="halo-tiket-tidak-ditemukan">
          {{ TEKS_GALAT_TIKET.tidakDitemukan }}
        </p>
        <!-- :key per tiket (review B2 P2): respons balas/unduh tiket A yang terlambat tidak menyentuh form/galat tiket B. -->
        <ThreadTiket v-else-if="idTiket !== null" :key="idTiket" :id-tiket="idTiket" :tampil-kembali="!isDesktop" @kembali="kembali" />
        <p v-else class="py-10 text-center text-sm text-slate-500" data-testid="halo-thread-kosong">
          Pilih "Lihat detail" pada daftar untuk membuka percakapan.
        </p>
        <UiButton
          v-if="idTidakSah && !isDesktop"
          variant="secondary"
          appearance="soft"
          size="sm"
          class="mt-2"
          data-testid="halo-tidak-sah-kembali"
          @click="kembali"
        >
          <template #icon-left><ArrowLeft class="h-4 w-4" aria-hidden="true" /></template>
          Kembali ke daftar
        </UiButton>
      </UiCard>
    </div>

    <TiketBaruDialog v-if="bolehBuat" v-model:open="dialogBaru" @dibuat="onDibuat" />
  </RedesignShell>
</template>
