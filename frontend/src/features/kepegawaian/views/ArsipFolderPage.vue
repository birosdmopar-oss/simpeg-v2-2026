<script setup lang="ts">
/**
 * Tabel berkas satu folder (F-10 Tahap 1, MAKE-091; PRD §5, §9): `/arsip/:nip/:folder` dengan `:folder` = `semua`,
 * `id_jenis_rwy`, atau `tanpa_jenis`. Data dari `GET pegawai/{nip}/arsip/berkas` (berhalaman server, pencarian `q`) dan
 * `GET pegawai/{nip}/arsip` (identitas + label folder untuk breadcrumb). Tanpa data contoh. Aksi baris di
 * `ArsipBerkasTable` (menu ⋮ → Buka). Semua teks diinterpolasi (tanpa `v-html`).
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'

import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiButton, UiCard, UiNotice, UiPagination, UiSearchInput } from '@/shared/ui'
import type { Crumb } from '@/shared/ui'

import { FOLDER_SEMUA, type ArsipBerkasResponse, type ArsipFolderResponse } from '../arsip.types'
import { filterDariFolder, labelFolder, namaPegawaiArsip } from '../arsip.utils'
import ArsipBerkasTable from '../components/arsip/ArsipBerkasTable.vue'
import { describeApiError } from '../services/apiErrors'
import { arsipService } from '../services/arsip.service'

const PER_PAGE = 25
const JEDA_CARI_MS = 300

const route = useRoute()

const nip = computed(() => String(route.params.nip ?? ''))
const folderParam = computed(() => String(route.params.folder ?? FOLDER_SEMUA))
const filter = computed(() => filterDariFolder(folderParam.value))
const tampilJenis = computed(() => folderParam.value === FOLDER_SEMUA)

const ringkas = ref<ArsipFolderResponse | null>(null)
const hasil = ref<ArsipBerkasResponse | null>(null)
const loading = ref(false)
const failure = ref<string | null>(null)
const cari = ref('')
const kataCari = ref('')
const page = ref(1)

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

let permintaan = 0
async function muatBerkas(): Promise<void> {
  const ini = ++permintaan
  failure.value = null
  const saring = filter.value
  if (saring === null) {
    hasil.value = null
    loading.value = false
    failure.value = 'Folder tidak ditemukan.'
    return
  }
  loading.value = true
  try {
    const data = await arsipService.berkas(nip.value, { ...saring, q: kataCari.value, page: page.value, per_page: PER_PAGE })
    if (ini !== permintaan) return
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
async function muatRingkas(): Promise<void> {
  const ini = ++permintaanRingkas
  ringkas.value = null
  try {
    const data = await arsipService.folder(nip.value)
    if (ini === permintaanRingkas) ringkas.value = data
  } catch {
    // Breadcrumb memakai cadangan teks; galat nyata sudah tampil lewat daftar berkas.
  }
}

watch(
  [nip, folderParam],
  () => {
    cari.value = ''
    kataCari.value = ''
    page.value = 1
    hasil.value = null
    void muatRingkas()
    void muatBerkas()
  },
  { immediate: true },
)
// Pencarian / halaman berganti pada folder yang sama → muat ulang daftar saja.
watch([kataCari, page], () => void muatBerkas())

const namaPegawai = computed(() => (ringkas.value ? namaPegawaiArsip(ringkas.value.pegawai) : 'Pegawai'))
const namaFolder = computed(() => labelFolder(folderParam.value, ringkas.value?.folder ?? []))

const crumbs = computed<Crumb[]>(() => [
  { label: 'Arsip', to: { name: 'arsip' } },
  { label: namaPegawai.value, to: { name: 'arsip-pegawai', params: { nip: nip.value } } },
  { label: namaFolder.value },
])

const nf = new Intl.NumberFormat('id-ID')
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
        <UiButton appearance="outline" :to="{ name: 'arsip-pegawai', params: { nip } }" data-testid="arsip-kembali">Semua folder</UiButton>
      </div>

      <UiNotice v-if="failure" tone="danger" data-testid="arsip-failure">{{ failure }}</UiNotice>

      <UiCard v-else title="Berkas">
        <div class="mb-4 max-w-md">
          <UiSearchInput v-model="cari" placeholder="Cari nama arsip" label="Cari arsip" data-testid="arsip-cari" @submit="cariSekarang" />
        </div>
        <ArsipBerkasTable :items="hasil?.items ?? []" :loading="loading" :tampil-jenis="tampilJenis" />
        <div v-if="hasil && hasil.total > hasil.per_page" class="mt-4 flex justify-end">
          <UiPagination v-model:page="page" :per-page="hasil.per_page" :total="hasil.total" />
        </div>
      </UiCard>
    </div>
  </RedesignShell>
</template>
