<script setup lang="ts">
/**
 * Daftar tiket (B2 "Tiket saya" / B3 "Antrean", E-04; master kiri Gambar 33/34, tanpa frame Figma H8-9). Satu komponen untuk
 * kedua tab; state dari instance `daftar` (pabrik `buatDaftarTiket`, filter/halaman terpisah per tab). Antrean menambah kolom
 * Pemilik (`pemilik_nama` [K]) dan filter kategori hanya bila petugas memegang ≥ 2 kategori [K `VI:31-36`]. `<table>` biasa (bukan listbox); baris
 * tidak bisa diklik; aksi per baris lewat ⋮ (AGENTS.md §1): **Lihat detail** (→ rute `/halo-simpeg/tiket/:id`, panel kanan) →
 * **Tutup** (`hidden` bila Closed atau `!bisa_tutup`) → **Unduh lampiran** (lampiran pesan terakhir lewat E-04; `hidden` bila
 * tanpa lampiran, `disabled` saat memproses; review B2 P3) → **Hapus** (`danger`, `hidden` bila `!bisa_hapus`; T2 pemilik selalu
 * tidak bisa — flag dari BE, tidak dihitung ulang). Kolom "Lampiran Terakhir" hanya teks nama berkas. Konfirmasi lewat `ConfirmDialog` dengan teks legacy (`VI:136`, `VI:140`).
 * Filter: cari (subjek/isi), status, baca (bawaan per tab), kategori (tab saya [V2] PRD §7.4).
 */
import { Plus } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import { isApiError } from '@/lib/axios'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { formatApiDateTime } from '@/shared/utils/dateTime'
import { UiBadge, UiButton, UiNotice, UiPagination, UiSearchInput, UiSelect, type SelectOption } from '@/shared/ui'

import { KODE_GALAT_TIKET, PER_PAGE_MASTER, TAB_ANTREAN, TEKS_GALAT_TIKET } from '../constants'
import { kategoriHaloService, kodeGalat } from '../services/master.service'
import { tiketHaloService } from '../services/tiket.service'
import { bukaTiket, type DaftarTiket, hapusTiket, tutupTiket } from '../stores/tiket'
import type { FilterBaca, KategoriOpsi, StatusTiket, TiketBaris } from '../types'

const props = withDefaults(
  defineProps<{
    daftar: DaftarTiket
    bolehBuat?: boolean
    idAktif?: number | null
    /** Antrean: opsi filter kategori dari `petugas/saya` (filter tampil bila ≥ 2). Tanpa prop = `kategori/opsi` (tab saya). */
    kategoriPetugas?: readonly KategoriOpsi[] | null
  }>(),
  { bolehBuat: false, idAktif: null, kategoriPetugas: null },
)
const st = computed(() => props.daftar.state)
const antrean = computed(() => props.daftar.peran === 'petugas')
const bacaBawaan = computed<FilterBaca>(() => (antrean.value ? 'belum' : 'semua'))
/** Rute panel mempertahankan tab aktif (`?tab=antrean`). */
const queryTab = computed(() => (antrean.value ? { tab: TAB_ANTREAN } : {}))
const emit = defineEmits<{ 'tiket-baru': [] }>()

const router = useRouter()

const FILTER_STATUS: SelectOption[] = [
  { value: 'open', label: 'Open' },
  { value: 'closed', label: 'Closed' },
]
const FILTER_BACA: SelectOption[] = [
  { value: 'semua', label: 'Semua' },
  { value: 'belum', label: 'Belum dibaca' },
  { value: 'sudah', label: 'Sudah dibaca' },
]

const kategori = ref<KategoriOpsi[]>([])
const sumberKategori = computed<readonly KategoriOpsi[]>(() => props.kategoriPetugas ?? kategori.value)
const opsiKategori = computed<SelectOption[]>(() => sumberKategori.value.map((k) => ({ value: String(k.id), label: k.nama })))
const tampilFilterKategori = computed(() => (antrean.value ? sumberKategori.value.length >= 2 : true))

const cari = ref(props.daftar.state.filter.q ?? '')
let jedaCari: ReturnType<typeof setTimeout> | null = null
// Filter `q` diubah dari luar (mis. "Lihat semua" Perlu ditindak) → kotak cari ikut, tanpa memicu muat ulang ganda.
watch(
  () => st.value.filter.q ?? '',
  (q) => {
    if (q !== cari.value) cari.value = q
  },
)
watch(cari, (v) => {
  if (jedaCari) clearTimeout(jedaCari)
  if (v === (st.value.filter.q ?? '')) return
  jedaCari = setTimeout(() => void props.daftar.ubahFilter({ q: v }), 300)
})

const status = computed({
  get: () => st.value.filter.status ?? '',
  set: (v: string | number | null) => void props.daftar.ubahFilter({ status: v === '' || v === null ? null : (String(v) as StatusTiket) }),
})
const baca = computed({
  get: () => st.value.filter.baca ?? bacaBawaan.value,
  set: (v: string | number | null) => void props.daftar.ubahFilter({ baca: (v ? String(v) : bacaBawaan.value) as FilterBaca }),
})
const kategoriFilter = computed({
  get: () => (st.value.filter.kategori ? String(st.value.filter.kategori) : ''),
  set: (v: string | number | null) => void props.daftar.ubahFilter({ kategori: v === '' || v === null ? null : Number(v) }),
})
const page = computed({
  get: () => st.value.filter.page ?? 1,
  set: (v: number) => void props.daftar.ubahFilter({ page: v }),
})

onMounted(async () => {
  if (st.value.statusDaftar === 'awal') void props.daftar.muat()
  if (props.kategoriPetugas !== null) return
  try {
    kategori.value = await kategoriHaloService.opsi()
  } catch {
    // Filter kategori opsional; daftar tetap tampil.
  }
})

const items = computed(() => st.value.daftar?.items ?? [])
const total = computed(() => st.value.daftar?.total ?? 0)
const waktu = (iso: string | null | undefined): string => (iso ? (formatApiDateTime(iso) ?? iso) : '—')

function aksiBaris(t: TiketBaris): RowAction[] {
  return [
    { key: 'detail', label: 'Lihat detail' },
    { key: 'tutup', label: 'Tutup', hidden: t.status === 'closed' || !t.bisa_tutup },
    // Review B2 Q4: satu unduhan pada satu waktu → item di semua baris disabled selama unduhan berjalan (AGENTS.md §1 butir 4).
    { key: 'unduh', label: 'Unduh lampiran', hidden: !t.pesan_terakhir?.lampiran, disabled: mengunduh.value !== null },
    { key: 'hapus', label: 'Hapus', danger: true, hidden: !t.bisa_hapus },
  ]
}

const notice = ref('')
const galatAksi = ref('')
/** Target konfirmasi dipisah dari status buka dialog: event tutup dialog tidak mengosongkan target sebelum `confirm`. */
const konfirmasi = ref<{ jenis: 'tutup' | 'hapus'; tiket: TiketBaris } | null>(null)
const dialogTerbuka = ref(false)
const memproses = ref(false)

function onAksi(t: TiketBaris, key: string): void {
  notice.value = ''
  galatAksi.value = ''
  if (key === 'detail') void router.push({ name: 'halo-tiket', params: { id: t.id }, query: queryTab.value })
  else if (key === 'unduh') void unduhTerakhir(t)
  else if (key === 'tutup' || key === 'hapus') {
    konfirmasi.value = { jenis: key, tiket: t }
    dialogTerbuka.value = true
  }
}

async function jalankan(): Promise<void> {
  const k = konfirmasi.value
  if (!k || memproses.value) return
  memproses.value = true
  try {
    if (k.jenis === 'tutup') {
      await tutupTiket(k.tiket.id)
      notice.value = 'Percakapan berhasil ditutup.'
    } else {
      await hapusTiket(k.tiket.id)
      notice.value = 'Percakapan berhasil dihapus.'
      if (props.idAktif === k.tiket.id) void router.push({ name: 'halo-simpeg', query: queryTab.value })
    }
  } catch (e) {
    galatAksi.value = isApiError(e) ? e.message : 'Tindakan gagal. Silakan coba lagi.'
    // P1 (d): tiket sudah berubah di server (sudah ditutup / tidak ada) → daftar (dan panel bila terbuka) dimuat ulang.
    if (kodeGalat(e) === KODE_GALAT_TIKET.tiketSudahDitutup || (isApiError(e) && e.status === 404)) {
      void props.daftar.muat()
      if (props.idAktif === k.tiket.id) void bukaTiket(k.tiket.id)
    }
  } finally {
    dialogTerbuka.value = false
    memproses.value = false
  }
}

const galatUnduh = ref('')
const mengunduh = ref<number | null>(null)
async function unduhTerakhir(t: TiketBaris): Promise<void> {
  const pt = t.pesan_terakhir
  if (!pt?.lampiran || mengunduh.value !== null) return
  galatUnduh.value = ''
  mengunduh.value = t.id
  try {
    const berkas = await tiketHaloService.lampiran(t.id, pt.id_inbox_msg)
    simpanBerkas({ blob: berkas.blob, nama: berkas.nama || pt.lampiran.nama })
  } catch (e) {
    galatUnduh.value = isApiError(e) ? e.message : TEKS_GALAT_TIKET.lampiranTidakDitemukan
  } finally {
    mengunduh.value = null
  }
}

const teksKosong = computed(() =>
  st.value.filter.perlu_tindak
    ? 'Tidak ada tiket yang perlu ditindak.'
    : st.value.filter.q || st.value.filter.status || st.value.filter.kategori || st.value.filter.baca !== bacaBawaan.value
    ? 'Tidak ada percakapan yang cocok dengan filter.'
    : 'Belum ada percakapan.',
)
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col gap-3" :data-testid="antrean ? 'halo-daftar-antrean' : 'halo-daftar-saya'">
    <div class="flex flex-wrap items-end gap-3">
      <UiSearchInput v-model="cari" placeholder="Cari subjek atau pesan" label="Cari percakapan" class="min-w-[200px] flex-1" />
      <UiSelect v-model="status" :options="FILTER_STATUS" clearable size="sm" placeholder="Semua status" aria-label="Filter status" class="w-36" />
      <UiSelect v-model="baca" :options="FILTER_BACA" size="sm" aria-label="Filter baca" class="w-40" data-testid="halo-filter-baca" />
      <UiSelect
        v-if="tampilFilterKategori"
        v-model="kategoriFilter"
        :options="opsiKategori"
        clearable
        searchable
        size="sm"
        placeholder="Semua kategori"
        aria-label="Filter kategori"
        class="w-44"
      />
      <UiButton v-if="bolehBuat" data-testid="halo-tiket-baru" @click="emit('tiket-baru')">
        <template #icon-left><Plus class="h-4 w-4" aria-hidden="true" /></template>
        Tambah Pertanyaan
      </UiButton>
    </div>

    <UiNotice v-if="notice" tone="success">{{ notice }}</UiNotice>
    <UiNotice v-if="galatAksi" tone="danger"><span role="alert">{{ galatAksi }}</span></UiNotice>
    <p v-if="galatUnduh" class="text-sm text-danger" role="alert" data-testid="halo-daftar-galat-unduh">{{ galatUnduh }}</p>

    <div class="table-fill scrollbar-slim overflow-x-auto rounded-xl border border-slate-100">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3">Subjek</th>
            <th v-if="antrean" class="px-4 py-3">Pemilik</th>
            <th class="px-4 py-3">Kategori</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3">Pesan Terakhir</th>
            <th class="px-4 py-3">Lampiran Terakhir</th>
            <th class="px-4 py-3 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="st.statusDaftar === 'memuat' && items.length === 0">
            <td :colspan="antrean ? 7 : 6" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
          </tr>
          <tr v-else-if="st.statusDaftar === 'galat'">
            <td :colspan="antrean ? 7 : 6" class="px-4 py-8 text-center" data-testid="halo-daftar-galat">
              <p class="text-danger" role="alert">{{ st.galatDaftar?.pesan }}</p>
              <UiButton
                v-if="st.galatDaftar?.status !== 403"
                size="sm"
                variant="secondary"
                appearance="outline"
                class="mt-2"
                @click="daftar.muat()"
              >
                Muat ulang
              </UiButton>
            </td>
          </tr>
          <tr v-else-if="items.length === 0">
            <td :colspan="antrean ? 7 : 6" class="px-4 py-8 text-center text-slate-500" data-testid="halo-daftar-kosong">{{ teksKosong }}</td>
          </tr>
          <tr
            v-for="t in items"
            v-else
            :key="t.id"
            class="align-top"
            :class="idAktif === t.id ? 'bg-[#E8F1FF]' : ''"
            :aria-current="idAktif === t.id ? 'true' : undefined"
            :data-testid="`halo-tiket-row-${t.id}`"
          >
            <td class="px-4 py-3 text-slate-800">
              <span :class="t.belum_dibaca ? 'font-semibold' : ''">{{ t.subjek }}</span>
              <UiBadge v-if="t.belum_dibaca" tone="info" size="sm" class="ml-1" data-testid="halo-badge-belum-dibaca">Belum dibaca</UiBadge>
            </td>
            <td v-if="antrean" class="px-4 py-3 text-slate-700" data-kolom="pemilik">{{ t.pemilik_nama }}</td>
            <td class="px-4 py-3 text-slate-600" data-kolom="kategori">{{ t.kategori?.nama ?? '—' }}</td>
            <td class="px-4 py-3">
              <UiBadge :tone="t.status === 'open' ? 'success' : 'neutral'" size="sm" :data-status="t.status">
                {{ t.status === 'open' ? 'Open' : 'Closed' }}
              </UiBadge>
            </td>
            <td class="max-w-xs px-4 py-3 text-slate-600" data-kolom="pesan-terakhir">
              <template v-if="t.pesan_terakhir">
                <span class="block text-caption font-medium text-slate-700">{{ t.pesan_terakhir.pengirim_label }}</span>
                <span class="block truncate">{{ t.pesan_terakhir.potongan }}</span>
                <span class="block text-caption text-slate-400">{{ waktu(t.pesan_terakhir.waktu) }}</span>
              </template>
              <span v-else class="text-slate-400">—</span>
            </td>
            <td class="px-4 py-3" data-kolom="lampiran">
              <span v-if="t.pesan_terakhir?.lampiran" class="text-slate-700" :data-testid="`halo-tiket-lampiran-${t.id}`">
                {{ t.pesan_terakhir.lampiran.nama }}
              </span>
              <span v-else class="text-slate-400">—</span>
            </td>
            <td class="px-4 py-3 text-right">
              <RowActionsMenu :actions="aksiBaris(t)" :label="`Aksi untuk ${t.subjek}`" :testid="`halo-tiket-actions-${t.id}`" @select="onAksi(t, $event)" />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <UiPagination v-if="total > (st.filter.per_page ?? PER_PAGE_MASTER)" v-model:page="page" :per-page="st.filter.per_page ?? PER_PAGE_MASTER" :total="total" />

    <ConfirmDialog
      :open="dialogTerbuka"
      :title="konfirmasi?.jenis === 'hapus' ? 'Hapus percakapan?' : 'Tutup percakapan?'"
      :description="
        konfirmasi?.jenis === 'hapus' ? 'Apakah anda yakin akan menghapus percakapan ini?' : 'Apakah anda yakin akan menutup percakapan ini?'
      "
      :confirm-label="konfirmasi?.jenis === 'hapus' ? 'Hapus' : 'Tutup'"
      :danger="konfirmasi?.jenis === 'hapus'"
      :loading="memproses"
      @update:open="(v) => !memproses && (dialogTerbuka = v)"
      @confirm="jalankan"
    />
  </div>
</template>
