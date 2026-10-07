<script setup lang="ts">
/**
 * Halaman Daftar Pegawai (§4.1.5, Gambar 18–21) — Fase 3, B-20 (pemilik WS-2). Data dari `GET pegawai` (disaring
 * lingkup pemanggil di backend). Pencarian, "Filter Kolom", tabel, paginasi.
 * Akses: role 1, 3, 4, 5, 8; tombol "+ Data Pegawai" dan aksi "Hapus" hanya role 1.
 *
 * Tanpa data contoh: selama endpoint masih stub (404/501) halaman menampilkan keadaan galat yang jelas.
 * TODO(B-20, WS-2): panel filter (unit, jenis/status pegawai, jabatan) & filter per kolom dikembalikan setelah
 * parameter `GET pegawai` ditetapkan. Tambah/Hapus/Export belum tersambung (B-05/B-20) — halaman memberi tahu.
 */
import { Plus } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role } from '@/features/auth/types'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiButton, UiCard, UiExportMenu, UiNotice, UiPagination, UiSearchInput, UiSelect } from '@/shared/ui'

import PegawaiColumnPicker from '../components/PegawaiColumnPicker.vue'
import PegawaiTable from '../components/PegawaiTable.vue'
import { DEFAULT_COLUMN_KEYS, fullName } from '../columns'
import { describeApiError, type ApiFailure } from '../services/apiErrors'
import { pegawaiService } from '../services/pegawai.service'
import type { PegawaiListItem } from '../types'

const auth = useAuthStore()
const router = useRouter()

const isSuperAdmin = computed(() => auth.role === Role.SUPER_ADMIN)

const search = ref('')
const columnKeys = ref<string[]>([...DEFAULT_COLUMN_KEYS])
const perPage = ref('10')
const page = ref(1)

const rows = ref<PegawaiListItem[]>([])
const total = ref(0)
const loading = ref(false)
const failure = ref<ApiFailure | null>(null)

const notice = ref<string | null>(null)
const deleting = ref<PegawaiListItem | null>(null)
/** Dipisah dari baris terpilih: klik konfirmasi menutup dialog SEBELUM handler confirm jalan. */
const deleteOpen = ref(false)

const PER_PAGE_OPTIONS = ['10', '25', '50'].map((v) => ({ value: v, label: v }))

let requestId = 0
let timer: ReturnType<typeof setTimeout> | null = null

async function load(): Promise<void> {
  const current = ++requestId
  loading.value = true
  failure.value = null
  try {
    const result = await pegawaiService.list({ page: page.value, per_page: Number(perPage.value), search: search.value || undefined })
    // Abaikan respons yang sudah basi (pengguna keburu mengubah pencarian).
    if (current !== requestId) return
    rows.value = result.items
    total.value = result.total
  } catch (error) {
    if (current !== requestId) return
    rows.value = []
    total.value = 0
    failure.value = describeApiError(error)
  } finally {
    if (current === requestId) loading.value = false
  }
}

function schedule(resetPage: boolean): void {
  if (resetPage) page.value = 1
  if (timer) clearTimeout(timer)
  timer = setTimeout(load, 250)
}

watch([search, perPage], () => schedule(true))
watch(page, () => schedule(false))

onMounted(load)
onBeforeUnmount(() => {
  if (timer) clearTimeout(timer)
})

function onAction(key: 'detail' | 'hapus', row: PegawaiListItem): void {
  if (key === 'detail') void router.push({ name: 'pegawai-detail', params: { nip: row.nip } })
  else {
    deleting.value = row
    deleteOpen.value = true
  }
}

function confirmDelete(): void {
  deleteOpen.value = false
  const name = deleting.value ? fullName(deleting.value) : ''
  notice.value = `Penghapusan pegawai (${name}) belum tersedia — akan aktif bersama task B-05.`
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
        <UiExportMenu @select="notice = 'Export belum tersedia — akan aktif bersama task B-20.'" />
      </template>

      <div class="mt-2 space-y-5 pb-5">
        <div class="flex flex-wrap items-center justify-between gap-3 px-5">
          <div class="w-24">
            <UiSelect v-model="perPage" :options="PER_PAGE_OPTIONS" placeholder="10" aria-label="Jumlah baris" />
          </div>
          <div class="flex flex-wrap items-center gap-3">
            <div class="w-64 max-w-full"><UiSearchInput v-model="search" placeholder="Search" label="Cari pegawai" /></div>
            <PegawaiColumnPicker v-model="columnKeys" />
          </div>
        </div>

        <UiNotice v-if="failure" :tone="failure.kind === 'unavailable' ? 'info' : 'danger'" class="mx-5" data-testid="pegawai-failure">{{ failure.message }}</UiNotice>

        <PegawaiTable
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
      :open="deleteOpen"
      title="Hapus pegawai?"
      :description="deleting ? `Data ${fullName(deleting)} (${deleting.nip}) akan dihapus.` : ''"
      confirm-label="Ya, hapus"
      danger
      @update:open="(v) => (deleteOpen = v)"
      @confirm="confirmDelete"
    />
  </RedesignShell>
</template>
