<script setup lang="ts">
/**
 * Tabel berkas satu folder (F-10 Tahap 1 MAKE-091 + Tahap 2 MAKE-096; PRD §5, §6, §9): `/arsip/:nip/:folder` dengan
 * `:folder` = `semua`, `id_jenis_rwy`, atau `tanpa_jenis`. Data dari `GET pegawai/{nip}/arsip/berkas` (berhalaman server,
 * pencarian `q`) dan `GET pegawai/{nip}/arsip` (identitas, label folder, `aksi.jenis_hubungkan`). Tahap 2:
 *  - filter "Semua berkas": `UiSelect` Jenis Riwayat + `UiCheckbox` "Belum terhubung" (mencentang me-reset select ke
 *    "Semua"; bila sesudahnya jenis dipilih, keduanya berlaku bersama dan hasil bisa kosong [K], AC filter §6.1);
 *  - ekspor Excel / CSV mengikuti filter yang aktif (folder + jenis + belum terhubung + pencarian), semua role 1–7;
 *  - aksi baris (menu ⋮): Edit (dialog Ubah Nama), Hubungkan (dialog dua langkah), Hapus (`ConfirmDialog`); role 4/5 hanya
 *    lihat. Sesudah mutasi daftar dimuat ulang, cache folder dikosongkan dan folder dimuat ulang (AC2-12).
 * Tanpa data contoh. Semua teks diinterpolasi (tanpa `v-html`).
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role } from '@/features/auth/types'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiButton, UiCard, UiCheckbox, UiExportMenu, UiNotice, UiPagination, UiSearchInput, UiSelect } from '@/shared/ui'
import type { Crumb, ExportFormat, SelectOption } from '@/shared/ui'

import { ambilFolderArsip, kosongkanFolderArsip, simpanFolderArsip } from '../arsip.cache'
import { urainGalatAksi } from '../arsip.galat'
import {
  FOLDER_SEMUA,
  FOLDER_TANPA_JENIS,
  type ArsipBerkas,
  type ArsipBerkasQuery,
  type ArsipBerkasResponse,
  type ArsipFolderResponse,
  type FormatEksporArsip,
} from '../arsip.types'
import { filterDariFolder, labelFolder, namaPegawaiArsip, paramFolder } from '../arsip.utils'
import ArsipBerkasTable from '../components/arsip/ArsipBerkasTable.vue'
import ArsipEditDialog from '../components/arsip/ArsipEditDialog.vue'
import ArsipHubungkanDialog from '../components/arsip/ArsipHubungkanDialog.vue'
import { describeApiError } from '../services/apiErrors'
import { arsipService } from '../services/arsip.service'
import { simpanBerkas } from '../services/berkasUnduhan'

const PER_PAGE = 25
const JEDA_CARI_MS = 300
const FORMAT_EKSPOR: ExportFormat[] = [
  { key: 'xlsx', label: 'Ekspor Excel' },
  { key: 'csv', label: 'Ekspor CSV' },
]

const route = useRoute()
const auth = useAuthStore()

const nip = computed(() => String(route.params.nip ?? ''))
const folderParam = computed(() => String(route.params.folder ?? FOLDER_SEMUA))
const filter = computed(() => filterDariFolder(folderParam.value))
const folderTidakSah = computed(() => filter.value === null)
const tampilJenis = computed(() => folderParam.value === FOLDER_SEMUA)
/** Role 4 (Admin View) dan 5 (Menteri) hanya melihat: aksi tulis disembunyikan (AC2-02). */
const bacaSaja = computed(() => auth.role === Role.ADMIN_VIEW_ESELON1 || auth.role === Role.MENTERI)

const ringkas = ref<ArsipFolderResponse | null>(null)
const hasil = ref<ArsipBerkasResponse | null>(null)
const loading = ref(false)
const failure = ref<string | null>(null)
const cari = ref('')
const kataCari = ref('')
const page = ref(1)
const jenisDipilih = ref('')
const belumTerhubung = ref(false)
const pesan = ref<{ tone: 'success' | 'danger'; text: string } | null>(null)
/** Galat memuat folder (identitas / `aksi.jenis_hubungkan`); tampil dengan "Coba lagi". */
const galatRingkas = ref<string | null>(null)

let timer: ReturnType<typeof setTimeout> | undefined
onBeforeUnmount(() => clearTimeout(timer))
watch(cari, (nilai) => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    kataCari.value = nilai.trim()
    page.value = 1
  }, JEDA_CARI_MS)
})
function cariSekarang(): void {
  clearTimeout(timer)
  kataCari.value = cari.value.trim()
  page.value = 1
}

/** Mencentang "Belum terhubung" me-reset select jenis ke "Semua" [K]; jenis yang dipilih sesudahnya berlaku bersama. */
watch(belumTerhubung, (nilai) => {
  if (nilai) jenisDipilih.value = ''
  page.value = 1
})
watch(jenisDipilih, () => {
  page.value = 1
})

const opsiJenis = computed<SelectOption[]>(() =>
  (ringkas.value?.folder ?? []).map((f) => ({ value: paramFolder(f), label: f.label })),
)

/** Folder terpilih bisa lenyap sesudah mutasi (mis. kode 0 habis): reset filter agar select dan kueri tidak berselisih. */
watch(ringkas, (baru) => {
  if (baru !== null && jenisDipilih.value !== '' && !opsiJenis.value.some((o) => String(o.value) === jenisDipilih.value)) jenisDipilih.value = ''
})
/** Filter aktif: folder dari URL, lalu (hanya di "Semua berkas") jenis + belum terhubung dari panel filter. */
function kueriFilter(): ArsipBerkasQuery | null {
  const dasar = filter.value
  if (dasar === null) return null
  const kueri: ArsipBerkasQuery = { ...dasar }
  if (tampilJenis.value) {
    if (jenisDipilih.value === FOLDER_TANPA_JENIS) kueri.id_jenis_rwy = FOLDER_TANPA_JENIS
    else if (/^\d+$/.test(jenisDipilih.value)) kueri.id_jenis_rwy = Number(jenisDipilih.value)
    if (belumTerhubung.value) kueri.belum_terhubung = true
  }
  if (kataCari.value !== '') kueri.q = kataCari.value
  return kueri
}

let permintaan = 0
async function muatBerkas(): Promise<void> {
  const ini = ++permintaan
  failure.value = null
  const kueri = kueriFilter()
  if (kueri === null) {
    hasil.value = null
    loading.value = false
    failure.value = 'Folder tidak ditemukan.'
    return
  }
  loading.value = true
  try {
    const data = await arsipService.berkas(nip.value, { ...kueri, q: kueri.q ?? '', page: page.value, per_page: PER_PAGE })
    if (ini !== permintaan) return
    // Halaman terakhir kosong sesudah Hapus / Hubungkan: mundur satu halaman (memicu muat ulang).
    if (data.items.length === 0 && page.value > 1) {
      page.value -= 1
      return
    }
    hasil.value = data
  } catch (error) {
    if (ini !== permintaan) return
    hasil.value = null
    failure.value = describeApiError(error).message
  } finally {
    if (ini === permintaan) loading.value = false
  }
}

let permintaanRingkas = 0
/**
 * Folder pegawai (identitas, label, `aksi.jenis_hubungkan`). `paksa` = lewati cache (sesudah mutasi) TANPA mengosongkan
 * `ringkas` lama sampai data baru tiba; galat dipertahankan sebagai pesan dengan "Coba lagi", bukan ditelan.
 */
async function muatRingkas(paksa = false): Promise<void> {
  const ini = ++permintaanRingkas
  galatRingkas.value = null
  if (!paksa) {
    const cache = ambilFolderArsip(nip.value)
    ringkas.value = cache
    if (cache !== null) return // dipakai ulang dari ArsipPage; API hanya dipanggil bila URL folder dibuka langsung
  }
  try {
    const data = await arsipService.folder(nip.value)
    if (ini !== permintaanRingkas) return
    simpanFolderArsip(nip.value, data)
    ringkas.value = data
  } catch (error) {
    if (ini !== permintaanRingkas) return
    galatRingkas.value = describeApiError(error).message
  }
}

watch(
  [nip, folderParam],
  () => {
    cari.value = ''
    kataCari.value = ''
    page.value = 1
    jenisDipilih.value = ''
    belumTerhubung.value = false
    pesan.value = null
    hasil.value = null
    void muatRingkas()
    void muatBerkas()
  },
  { immediate: true },
)
// Pencarian / halaman / filter berganti pada folder yang sama → muat ulang daftar saja.
watch([kataCari, page, jenisDipilih, belumTerhubung], () => void muatBerkas())

const namaPegawai = computed(() => (ringkas.value ? namaPegawaiArsip(ringkas.value.pegawai) : 'Pegawai'))
const namaFolder = computed(() => labelFolder(folderParam.value, ringkas.value?.folder ?? []))

const crumbs = computed<Crumb[]>(() => [
  { label: 'Arsip', to: { name: 'arsip' } },
  { label: namaPegawai.value, to: { name: 'arsip-pegawai', params: { nip: nip.value } } },
  { label: namaFolder.value },
])

const nf = new Intl.NumberFormat('id-ID')

// ---- Tahap 2: aksi tulis ----------------------------------------------------------------------------------------

const editItem = ref<ArsipBerkas | null>(null)
const editOpen = ref(false)
const hubungkanItem = ref<ArsipBerkas | null>(null)
const hubungkanOpen = ref(false)
/** Dipisah dari baris terpilih: klik konfirmasi menutup dialog SEBELUM handler confirm jalan. */
const hapusItem = ref<ArsipBerkas | null>(null)
const hapusOpen = ref(false)
const menghapus = ref(false)
const mengekspor = ref(false)

function bukaEdit(item: ArsipBerkas): void {
  editItem.value = item
  editOpen.value = true
}
function bukaHubungkan(item: ArsipBerkas): void {
  hubungkanItem.value = item
  hubungkanOpen.value = true
}
function bukaHapus(item: ArsipBerkas): void {
  hapusItem.value = item
  hapusOpen.value = true
}

/** Sesudah mutasi: daftar dimuat ulang, cache folder dikosongkan dan folder dimuat ulang (jumlah per folder, AC2-12). */
async function sesudahMutasi(teks: string): Promise<void> {
  pesan.value = { tone: 'success', text: teks }
  kosongkanFolderArsip()
  await Promise.all([muatRingkas(true), muatBerkas()])
}

/** Mutasi gagal karena baris sudah berubah di server (404 / 422 `errors.id_attachment`): muat ulang tanpa pesan sukses. */
async function muatUlangBasi(): Promise<void> {
  kosongkanFolderArsip()
  await Promise.all([muatRingkas(true), muatBerkas()])
}

async function konfirmasiHapus(): Promise<void> {
  hapusOpen.value = false
  const item = hapusItem.value
  if (!item) return
  menghapus.value = true
  pesan.value = null
  try {
    await arsipService.hapus(nip.value, item.id_attachment)
    await sesudahMutasi('Arsip dihapus.')
  } catch (error) {
    const galat = urainGalatAksi(error, ['id_attachment'])
    pesan.value = { tone: 'danger', text: galat.kolom.id_attachment ?? galat.umum }
    if (galat.basi) await muatUlangBasi()
  } finally {
    menghapus.value = false
  }
}

async function ekspor(format: ExportFormat['key']): Promise<void> {
  const kueri = kueriFilter()
  if (kueri === null || mengekspor.value || (format !== 'xlsx' && format !== 'csv')) return
  mengekspor.value = true
  pesan.value = null
  try {
    simpanBerkas(await arsipService.ekspor(nip.value, format as FormatEksporArsip, kueri))
  } catch (error) {
    pesan.value = { tone: 'danger', text: urainGalatAksi(error).umum }
  } finally {
    mengekspor.value = false
  }
}
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="flex flex-col gap-5" data-testid="arsip-folder-page" :aria-busy="loading">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-h2 font-semibold text-slate-900" data-testid="arsip-folder-judul">{{ namaFolder }}</h1>
          <p class="text-body2 text-slate-500">
            {{ namaPegawai }}<template v-if="hasil"> · {{ nf.format(hasil.total) }} berkas</template>
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <div v-if="!folderTidakSah" data-testid="arsip-ekspor">
            <UiExportMenu :formats="FORMAT_EKSPOR" label="Ekspor" :disabled="mengekspor || loading" @select="ekspor" />
          </div>
          <UiButton appearance="outline" :to="{ name: 'arsip-pegawai', params: { nip } }" data-testid="arsip-kembali">Semua folder</UiButton>
        </div>
      </div>

      <UiNotice v-if="pesan" :tone="pesan.tone" dismissible data-testid="arsip-pesan" @dismiss="pesan = null">{{ pesan.text }}</UiNotice>

      <UiNotice v-if="galatRingkas" tone="danger" data-testid="arsip-galat-folder">
        <span>{{ galatRingkas }}</span>
        <UiButton size="sm" appearance="outline" class="ml-3" data-testid="arsip-folder-coba-lagi" @click="muatRingkas(true)">Coba lagi</UiButton>
      </UiNotice>

      <UiNotice v-if="folderTidakSah" tone="danger" data-testid="arsip-failure">{{ failure }}</UiNotice>

      <UiCard v-else title="Berkas">
        <div class="mb-4 flex flex-wrap items-end gap-4">
          <div class="w-full max-w-md">
            <UiSearchInput v-model="cari" placeholder="Cari nama arsip" label="Cari arsip" data-testid="arsip-cari" @submit="cariSekarang" />
          </div>
          <template v-if="tampilJenis">
            <div class="w-full sm:w-64">
              <UiSelect
                v-model="jenisDipilih"
                label="Jenis Riwayat"
                placeholder="Semua jenis"
                clearable
                :options="opsiJenis"
                data-testid="arsip-filter-jenis"
              />
            </div>
            <div class="pb-2">
              <UiCheckbox v-model="belumTerhubung" label="Belum terhubung" data-testid="arsip-filter-belum-terhubung" />
            </div>
          </template>
        </div>
        <UiNotice v-if="failure" tone="danger" class="mb-3" data-testid="arsip-failure">
          <span>{{ failure }}</span>
          <UiButton size="sm" appearance="outline" class="ml-3" data-testid="arsip-coba-lagi" @click="muatBerkas">Coba lagi</UiButton>
        </UiNotice>
        <ArsipBerkasTable
          v-else
          :items="hasil?.items ?? []"
          :loading="loading"
          :tampil-jenis="tampilJenis"
          :mencari="kataCari !== '' || jenisDipilih !== '' || belumTerhubung"
          :baca-saja="bacaSaja"
          :memproses="menghapus"
          @edit="bukaEdit"
          @hubungkan="bukaHubungkan"
          @hapus="bukaHapus"
        />
        <div v-if="hasil && hasil.total > hasil.per_page" class="mt-4 flex justify-end">
          <UiPagination v-model:page="page" :per-page="hasil.per_page" :total="hasil.total" />
        </div>
      </UiCard>
    </div>

    <ArsipEditDialog v-model:open="editOpen" :nip="nip" :item="editItem" @done="sesudahMutasi" @basi="muatUlangBasi" />
    <ArsipHubungkanDialog
      v-model:open="hubungkanOpen"
      :nip="nip"
      :item="hubungkanItem"
      :jenis-hubungkan="ringkas?.aksi.jenis_hubungkan ?? null"
      @done="sesudahMutasi"
      @basi="muatUlangBasi"
      @muat-ulang="muatRingkas(true)"
    />
    <ConfirmDialog
      v-model:open="hapusOpen"
      title="Hapus arsip?"
      :description="hapusItem ? `${hapusItem.nama_arsip} akan dihapus dari daftar arsip. Berkas fisik tidak ikut dihapus.` : ''"
      confirm-label="Ya, hapus"
      danger
      @confirm="konfirmasiHapus"
    />
  </RedesignShell>
</template>
