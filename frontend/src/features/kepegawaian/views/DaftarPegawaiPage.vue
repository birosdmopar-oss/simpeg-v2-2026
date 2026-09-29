<script setup lang="ts">
/**
 * Halaman Daftar Pegawai (§4.1.5, Gambar 18–21) — Fase 3, B-20.
 * Panel filter (bisa disembunyikan), pencarian, "Filter Kolom", tabel dengan filter per kolom, paginasi.
 * Akses: role 1, 3, 4, 5, 8 (hr/employee/index); tombol "+ Data Pegawai" dan aksi "Hapus" hanya role 1.
 *
 * DATA CONTOH: layanan memakai mock sampai backend B-20 ada. Tambah/Hapus/Export belum tersambung —
 * halaman menampilkan pesan yang jelas, bukan berpura-pura berhasil.
 */
import { Filter, Plus } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role } from '@/features/auth/types'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiButton, UiCard, UiExportMenu, UiNotice, UiPagination, UiSearchInput, UiSelect } from '@/shared/ui'

import PegawaiColumnPicker from '../components/PegawaiColumnPicker.vue'
import PegawaiFilterPanel, { type PegawaiFilters } from '../components/PegawaiFilterPanel.vue'
import PegawaiTable from '../components/PegawaiTable.vue'
import { DEFAULT_COLUMN_KEYS, fullName } from '../columns'
import { pegawaiService } from '../services/pegawai.service'
import type { PegawaiFacets, PegawaiRow } from '../types'

const auth = useAuthStore()
const router = useRouter()

const isSuperAdmin = computed(() => auth.role === Role.SUPER_ADMIN)

const facets = ref<PegawaiFacets | null>(null)
const filters = ref<PegawaiFilters>({
  periode_skp: '2025-09',
  unit: '',
  status_pegawai: '',
  jenis_pegawai: '',
  group_jabatan: '',
  sub_group_jabatan: '',
})
const showFilters = ref(true)
const search = ref('')
const columnFilters = ref<Record<string, string>>({})
const columnKeys = ref<string[]>([...DEFAULT_COLUMN_KEYS])
const perPage = ref('10')
const page = ref(1)

const rows = ref<PegawaiRow[]>([])
const total = ref(0)
const loading = ref(false)
const loadError = ref(false)

const notice = ref<string | null>(null)
const deleting = ref<PegawaiRow | null>(null)

const PER_PAGE_OPTIONS = ['10', '25', '50'].map((v) => ({ value: v, label: v }))

let requestId = 0
let timer: ReturnType<typeof setTimeout> | null = null

async function load(): Promise<void> {
  const current = ++requestId
  loading.value = true
  loadError.value = false
  try {
    const result = await pegawaiService.list({
      page: page.value,
      per_page: Number(perPage.value),
      search: search.value || undefined,
      unit: filters.value.unit || undefined,
      status_pegawai: filters.value.status_pegawai || undefined,
      jenis_pegawai: filters.value.jenis_pegawai || undefined,
      group_jabatan: filters.value.group_jabatan || undefined,
      sub_group_jabatan: filters.value.sub_group_jabatan || undefined,
      columns: columnFilters.value,
    })
    // Abaikan respons yang sudah basi (pengguna keburu mengubah filter).
    if (current !== requestId) return
    rows.value = result.items
    total.value = result.total
  } catch {
    if (current === requestId) loadError.value = true
  } finally {
    if (current === requestId) loading.value = false
  }
}

function schedule(resetPage: boolean): void {
  if (resetPage) page.value = 1
  if (timer) clearTimeout(timer)
  timer = setTimeout(load, 250)
}

watch([filters, search, columnFilters, perPage], () => schedule(true), { deep: true })
watch(page, () => schedule(false))

onMounted(async () => {
  facets.value = await pegawaiService.facets()
  await load()
})
onBeforeUnmount(() => {
  if (timer) clearTimeout(timer)
})

function onAction(key: 'detail' | 'hapus', row: PegawaiRow): void {
  if (key === 'detail') void router.push({ name: 'pegawai-detail', params: { nip: row.nip } })
  else deleting.value = row
}

function confirmDelete(): void {
  const name = deleting.value ? fullName(deleting.value) : ''
  deleting.value = null
  notice.value = `Penghapusan pegawai (${name}) belum tersambung ke backend — akan aktif bersama task B-05.`
}

const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Daftar Pegawai' }]
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <UiNotice v-if="notice" tone="info" dismissible class="mb-4" @dismiss="notice = null">{{ notice }}</UiNotice>

    <UiCard title="Daftar Pegawai" flush>
      <template #actions>
        <UiButton v-if="isSuperAdmin" data-testid="add-pegawai" @click="notice = 'Form tambah pegawai belum tersedia — akan aktif bersama task B-05.'">
          <template #icon-left><Plus class="h-4 w-4" aria-hidden="true" /></template>
          Data Pegawai
        </UiButton>
        <UiExportMenu @select="notice = 'Export belum tersambung ke backend — akan aktif bersama task B-20.'" />
        <UiButton
          appearance="outline"
          class="!w-10 !px-0"
          :aria-label="showFilters ? 'Sembunyikan filter' : 'Tampilkan filter'"
          :aria-pressed="showFilters"
          data-testid="toggle-filter"
          @click="showFilters = !showFilters"
        >
          <Filter class="h-4 w-4" aria-hidden="true" />
        </UiButton>
      </template>

      <div class="mt-2 space-y-5 pb-5">
        <PegawaiFilterPanel v-if="showFilters && facets" v-model="filters" :facets="facets" />

        <div class="flex flex-wrap items-center justify-between gap-3 px-5">
          <div class="w-24">
            <UiSelect v-model="perPage" :options="PER_PAGE_OPTIONS" placeholder="10" aria-label="Jumlah baris" />
          </div>
          <div class="flex flex-wrap items-center gap-3">
            <div class="w-64 max-w-full"><UiSearchInput v-model="search" placeholder="Search" label="Cari pegawai" /></div>
            <PegawaiColumnPicker v-model="columnKeys" />
          </div>
        </div>

        <UiNotice v-if="loadError" tone="danger" class="mx-5">Data pegawai gagal dimuat. Coba lagi.</UiNotice>

        <PegawaiTable
          v-model:filters="columnFilters"
          :rows="rows"
          :column-keys="columnKeys"
          :loading="loading"
          :can-delete="isSuperAdmin"
          @action="onAction"
        />

        <div class="px-5">
          <UiPagination v-model:page="page" :per-page="Number(perPage)" :total="total" />
        </div>
      </div>
    </UiCard>

    <ConfirmDialog
      :open="deleting !== null"
      title="Hapus pegawai?"
      :description="deleting ? `Data ${fullName(deleting)} (${deleting.nip}) akan dihapus. Tindakan ini tidak dapat dibatalkan.` : ''"
      confirm-label="Ya, hapus"
      danger
      @update:open="(v) => { if (!v) deleting = null }"
      @confirm="confirmDelete"
    />
  </RedesignShell>
</template>
