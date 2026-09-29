<script setup lang="ts">
/**
 * Tab riwayat berbentuk daftar (Pendidikan, Pelatihan, Jabatan, Pangkat, KGB, SKP, dst.): kartu dengan judul, pencarian,
 * tombol "+ Tambah …", tabel (kolom dari konfigurasi + badge status verifikasi B-04 + kolom Aksi), paginasi, dialog
 * tambah/ubah, dan konfirmasi hapus. Aksi baris hanya lewat menu ⋮ (AGENTS.md §1).
 *
 * DATA CONTOH: perubahan hanya tersimpan sementara (lihat riwayat.service.ts) dan halaman mengatakannya dengan jelas.
 */
import { FileSearch, Plus } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'

import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { UiBadge, UiButton, UiCard, UiNotice, UiPagination, UiSearchInput } from '@/shared/ui'

import RiwayatFormDialog from './RiwayatFormDialog.vue'
import type { ColumnDef, RiwayatConfig } from './riwayat.config'
import type { RiwayatRow } from './riwayat.mock'
import { riwayatService } from './riwayat.service'

const props = defineProps<{ nip: string; config: RiwayatConfig; readonly: boolean }>()

const PER_PAGE = 5

const rows = ref<RiwayatRow[]>([])
const loading = ref(true)
const search = ref('')
const page = ref(1)
const notice = ref<string | null>(null)
const formOpen = ref(false)
const editing = ref<RiwayatRow | null>(null)
const deleting = ref<RiwayatRow | null>(null)
/** Status buka-tutup dialog dipisah dari baris terpilih: klik konfirmasi menutup dialog SEBELUM handler confirm jalan. */
const deleteOpen = ref(false)

const TONE = { Disetujui: 'success', 'Menunggu Verifikasi': 'warning', Ditolak: 'danger' } as const

let requestId = 0
async function load(): Promise<void> {
  const current = ++requestId
  loading.value = true
  const result = await riwayatService.list(props.nip, props.config.key)
  if (current !== requestId) return
  rows.value = result
  loading.value = false
}
onMounted(load)
watch(() => [props.nip, props.config.key], () => {
  page.value = 1
  search.value = ''
  notice.value = null
  void load()
})
watch(search, () => (page.value = 1))

const filtered = computed(() => {
  const needle = search.value.trim().toLowerCase()
  if (!needle) return rows.value
  return rows.value.filter((r) => props.config.columns.some((c) => String(r[c.key] ?? '').toLowerCase().includes(needle)))
})
const paged = computed(() => filtered.value.slice((page.value - 1) * PER_PAGE, page.value * PER_PAGE))

function cell(row: RiwayatRow, col: ColumnDef): string {
  const value = String(row[col.key] ?? '')
  if (!value) return '—'
  if (col.format === 'date') {
    const d = new Date(`${value}T00:00:00`)
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })
  }
  return value
}

const rowActions = (): RowAction[] => [
  { key: 'edit', label: 'Edit' },
  { key: 'hapus', label: 'Hapus', danger: true },
]

const backendNote = computed(() => `Perubahan hanya tersimpan sementara di halaman ini — belum tersambung ke backend (task ${props.config.task}).`)

function openCreate(): void {
  editing.value = null
  formOpen.value = true
}

function onAction(key: string, row: RiwayatRow): void {
  if (key === 'edit') {
    editing.value = row
    formOpen.value = true
  } else if (key === 'hapus') {
    deleting.value = row
    deleteOpen.value = true
  }
}

const initialValues = computed<Record<string, string> | null>(() => {
  if (!editing.value) return null
  return Object.fromEntries(props.config.fields.map((f) => [f.name, String(editing.value?.[f.name] ?? '')]))
})

async function onSubmit(values: Record<string, string>): Promise<void> {
  if (editing.value) await riwayatService.update(props.nip, props.config.key, editing.value.id, values)
  else await riwayatService.create(props.nip, props.config.key, values)
  formOpen.value = false
  page.value = 1
  await load()
  notice.value = `${backendNote.value} Status verifikasi menjadi "Menunggu Verifikasi".`
}

async function confirmDelete(): Promise<void> {
  deleteOpen.value = false
  const row = deleting.value
  if (!row) return
  await riwayatService.remove(props.nip, props.config.key, row.id)
  await load()
  if (page.value > 1 && paged.value.length === 0) page.value -= 1
  notice.value = backendNote.value
}

function rowLabel(row: RiwayatRow): string {
  const first = props.config.columns[0]
  return first ? String(row[first.key] ?? props.config.title) : props.config.title
}
</script>

<template>
  <div class="space-y-4" :data-testid="`riwayat-section-${config.key}`">
    <UiNotice v-if="notice" tone="info" dismissible @dismiss="notice = null">{{ notice }}</UiNotice>

    <UiCard :title="config.title" :subtitle="config.subtitle" flush>
      <template #actions>
        <UiButton v-if="!readonly" data-testid="riwayat-add" @click="openCreate">
          <template #icon-left><Plus class="h-4 w-4" aria-hidden="true" /></template>
          Tambah {{ config.singular }}
        </UiButton>
      </template>

      <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-4">
        <div class="w-64 max-w-full"><UiSearchInput v-model="search" placeholder="Search" :label="`Cari ${config.title.toLowerCase()}`" /></div>
        <p class="text-body2 text-slate-500" aria-live="polite">{{ filtered.length }} data</p>
      </div>

      <div class="scrollbar-slim overflow-x-auto">
        <table class="w-full min-w-[40rem] border-collapse text-left text-body1" data-testid="riwayat-table">
          <thead>
            <tr class="border-y border-slate-200 text-body2 font-semibold text-slate-900">
              <th v-for="col in config.columns" :key="col.key" scope="col" class="px-5 py-3">{{ col.label }}</th>
              <th scope="col" class="px-5 py-3">Verifikasi</th>
              <th v-if="!readonly" scope="col" class="px-5 py-3 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200">
            <template v-if="loading">
              <tr v-for="n in 3" :key="`s-${n}`" aria-hidden="true">
                <td :colspan="config.columns.length + 2" class="px-5 py-4"><div class="h-4 w-2/3 animate-pulse rounded bg-slate-200" /></td>
              </tr>
            </template>
            <tr v-else-if="filtered.length === 0">
              <td :colspan="config.columns.length + (readonly ? 1 : 2)" class="px-5 py-12 text-center" data-testid="riwayat-empty">
                <FileSearch class="mx-auto mb-3 h-8 w-8 text-slate-300" aria-hidden="true" />
                <p class="text-body1 font-medium text-slate-700">{{ search ? 'Tidak ada data yang cocok' : `Belum ada ${config.title.toLowerCase()}` }}</p>
                <p v-if="!readonly && !search" class="text-body2 text-slate-500">Gunakan "Tambah {{ config.singular }}" untuk menambahkan.</p>
              </td>
            </tr>
            <template v-else>
              <tr v-for="row in paged" :key="row.id" class="transition-colors hover:bg-slate-50/70" :data-testid="`riwayat-row-${row.id}`">
                <td v-for="col in config.columns" :key="col.key" class="px-5 py-3 text-slate-800">{{ cell(row, col) }}</td>
                <td class="px-5 py-3"><UiBadge :tone="TONE[row.status_verifikasi]" dot>{{ row.status_verifikasi }}</UiBadge></td>
                <td v-if="!readonly" class="px-5 py-3 text-right">
                  <RowActionsMenu
                    :actions="rowActions()"
                    :label="`Aksi untuk ${rowLabel(row)}`"
                    :testid="`riwayat-actions-${row.id}`"
                    @select="(key) => onAction(key, row)"
                  />
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <div class="border-t border-slate-200 px-5 py-4"><UiPagination v-model:page="page" :per-page="PER_PAGE" :total="filtered.length" /></div>
    </UiCard>

    <RiwayatFormDialog v-model:open="formOpen" :config="config" :initial="initialValues" @submit="onSubmit" />

    <ConfirmDialog
      :open="deleteOpen"
      :title="`Hapus ${config.singular}?`"
      :description="deleting ? `Data ${rowLabel(deleting)} akan dihapus. Tindakan ini tidak dapat dibatalkan.` : ''"
      confirm-label="Ya, hapus"
      danger
      @update:open="(v) => (deleteOpen = v)"
      @confirm="confirmDelete"
    />
  </div>
</template>
