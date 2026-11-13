<script setup lang="ts">
/**
 * Chatbot Manager → Kategori / Tag (role 1; PRD Fase 8 v1.1 §6.3, §7.10.4; `dev:V/list_kategori.php:39-46`). Tabel `page-fill`
 * + ⋮ (AGENTS.md §1): Edit → Aktifkan/Nonaktifkan (`PATCH …/status`) → Hapus (status 10, `ConfirmDialog`, tanpa Pulihkan,
 * F8-38). Status hanya badge. Daftar berhalaman dan tersaring di server (status 1/2 bawaan Aktif, `q`); tag punya ekspor xlsx
 * (F8-47). Tanpa frame Figma: ikut pola tabel v2 (K-11 / AGENTS.md §3.6).
 */
import { Pencil, Plus, Power, PowerOff, Trash2 } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import { isApiError } from '@/lib/axios'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { UiButton, UiCard, UiExportMenu, UiNotice, UiPagination, UiSearchInput, UiSelect, type SelectOption } from '@/shared/ui'

import MasterChatbotFormDialog, { type JenisMasterChatbot } from '../../components/admin/MasterChatbotFormDialog.vue'
import StatusChatbotBadge from '../../components/admin/StatusChatbotBadge.vue'
import { kategoriChatbotService, tagChatbotService } from '../../services/chatbot.service'
import type { KategoriRow, StatusUbah, TagRow } from '../../types'

type Row = KategoriRow | TagRow

const props = defineProps<{ jenis: JenisMasterChatbot }>()

const PER_PAGE = 20
const FILTER_STATUS: SelectOption[] = [
  { value: '1', label: 'Aktif' },
  { value: '2', label: 'Tidak Aktif' },
]
const EKSPOR = [{ key: 'xlsx' as const, label: 'Ekspor Excel (.xlsx)' }]
const JUDUL = { kategori: 'Kategori', tag: 'Tag' } as const

const svc = computed(() => (props.jenis === 'kategori' ? kategoriChatbotService : tagChatbotService))
const items = ref<Row[]>([])
const total = ref(0)
const page = ref(1)
const statusFilter = ref('1')
const search = ref('')
const q = ref('')
const memuat = ref(false)
const busy = ref(false)
const mengekspor = ref(false)
const notice = ref('')
const failure = ref('')
const formOpen = ref(false)
const editing = ref<Row | null>(null)
const hapus = ref<{ open: boolean; row: Row | null; loading: boolean }>({ open: false, row: null, loading: false })
let debounce: ReturnType<typeof setTimeout> | null = null

const pesan = (e: unknown, baku: string): string => (isApiError(e) ? e.message : baku)
const nomor = (i: number): number => (page.value - 1) * PER_PAGE + i + 1
const bubble = (row: Row): boolean | null => ('tampil_bubble' in row ? row.tampil_bubble : null)

async function muat(): Promise<void> {
  memuat.value = true
  failure.value = ''
  try {
    const r = await svc.value.list({ page: page.value, per_page: PER_PAGE, status: statusFilter.value === '' ? undefined : (Number(statusFilter.value) as StatusUbah), q: q.value })
    items.value = r.items
    total.value = r.total
  } catch (e) {
    items.value = []
    total.value = 0
    failure.value = pesan(e, `Gagal memuat ${props.jenis}.`)
  } finally {
    memuat.value = false
  }
}

watch(statusFilter, () => {
  page.value = 1
  void muat()
})
watch(search, (v) => {
  if (debounce) clearTimeout(debounce)
  debounce = setTimeout(() => {
    q.value = v.trim()
    page.value = 1
    void muat()
  }, 300)
})
watch(page, () => void muat())

function tambah(): void {
  editing.value = null
  formOpen.value = true
}

function onSaved(): void {
  notice.value =
    editing.value === null ? (props.jenis === 'kategori' ? 'Kategori baru berhasil ditambahkan.' : 'Tag baru berhasil ditambahkan.') : `${JUDUL[props.jenis]} berhasil diperbarui.`
  void muat()
}

async function ubahStatus(row: Row, status: StatusUbah): Promise<void> {
  busy.value = true
  failure.value = ''
  try {
    await svc.value.setStatus(row.id, status)
    notice.value = `${JUDUL[props.jenis]} ${status === 1 ? 'diaktifkan' : 'dinonaktifkan'}.`
    await muat()
  } catch (e) {
    failure.value = pesan(e, 'Gagal mengubah status.')
  } finally {
    busy.value = false
  }
}

function aksiBaris(row: Row): RowAction[] {
  return [
    { key: 'edit', label: 'Edit', icon: Pencil },
    { key: 'activate', label: 'Aktifkan', icon: Power, hidden: row.status !== 2, disabled: busy.value },
    { key: 'deactivate', label: 'Nonaktifkan', icon: PowerOff, hidden: row.status !== 1, disabled: busy.value },
    { key: 'delete', label: 'Hapus', icon: Trash2, danger: true, disabled: busy.value },
  ]
}

function onAksi(row: Row, key: string): void {
  if (key === 'edit') {
    editing.value = row
    formOpen.value = true
  } else if (key === 'activate') void ubahStatus(row, 1)
  else if (key === 'deactivate') void ubahStatus(row, 2)
  else if (key === 'delete') hapus.value = { open: true, row, loading: false }
}

async function konfirmasiHapus(): Promise<void> {
  const row = hapus.value.row
  if (!row) return
  hapus.value.loading = true
  failure.value = ''
  try {
    await svc.value.hapus(row.id)
    notice.value = `${JUDUL[props.jenis]} berhasil dihapus.`
    hapus.value.open = false
    await muat()
  } catch (e) {
    failure.value = pesan(e, 'Gagal menghapus.')
    hapus.value.open = false
  } finally {
    hapus.value.loading = false
  }
}

async function ekspor(): Promise<void> {
  mengekspor.value = true
  failure.value = ''
  try {
    simpanBerkas(await tagChatbotService.ekspor())
  } catch (e) {
    failure.value = `Gagal mengekspor: ${pesan(e, 'coba lagi.')}`
  } finally {
    mengekspor.value = false
  }
}

onMounted(() => void muat())
onBeforeUnmount(() => {
  if (debounce) clearTimeout(debounce)
})
</script>

<template>
  <section class="page-fill flex flex-col gap-4" :data-testid="`chatbot-${jenis}-page`">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-h2 font-semibold text-slate-900">{{ jenis === 'kategori' ? 'Kategori QnA' : 'Tag QnA' }}</h1>
        <p class="text-body2 text-slate-500">Chatbot Manager · {{ jenis === 'kategori' ? 'kelompok QnA dan bubble topik Vanya' : 'kata kunci tambahan pencocokan Vanya' }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <UiExportMenu v-if="jenis === 'tag'" :formats="EKSPOR" :disabled="mengekspor" data-testid="chatbot-tag-ekspor" @select="ekspor" />
        <UiButton :data-testid="`chatbot-${jenis}-tambah`" @click="tambah"><Plus class="h-4 w-4" aria-hidden="true" /> Tambah {{ JUDUL[jenis] }}</UiButton>
      </div>
    </div>

    <UiNotice v-if="notice" tone="success">{{ notice }}</UiNotice>
    <UiNotice v-if="failure" tone="danger">
      <span role="alert">{{ failure }}</span>
    </UiNotice>

    <UiCard :title="`Daftar ${JUDUL[jenis]}`" flush fill class="flex min-h-0 flex-1 flex-col">
      <div class="flex flex-wrap items-end gap-3 border-b border-slate-100 p-4">
        <UiSelect v-model="statusFilter" :options="FILTER_STATUS" clearable size="sm" placeholder="Semua status" aria-label="Filter status" class="w-44" />
        <UiSearchInput v-model="search" :placeholder="`Cari ${jenis}`" :label="`Cari judul ${jenis}`" class="min-w-[200px] flex-1" />
      </div>
      <div class="table-fill scrollbar-slim overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="w-16 px-4 py-3">No</th>
              <th class="px-4 py-3">{{ JUDUL[jenis] }}</th>
              <th v-if="jenis === 'kategori'" class="px-4 py-3">Bubble</th>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="memuat">
              <td :colspan="jenis === 'kategori' ? 5 : 4" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
            </tr>
            <tr v-else-if="items.length === 0">
              <td :colspan="jenis === 'kategori' ? 5 : 4" class="px-4 py-8 text-center text-slate-500">Belum ada {{ jenis }} yang cocok.</td>
            </tr>
            <tr v-for="(row, i) in items" v-else :key="row.id" class="hover:bg-slate-50" :data-testid="`chatbot-${jenis}-row-${row.id}`">
              <td class="px-4 py-3 text-slate-500">{{ nomor(i) }}</td>
              <td class="px-4 py-3 font-medium text-slate-800">{{ row.judul }}</td>
              <td v-if="jenis === 'kategori'" class="px-4 py-3 text-slate-600">{{ bubble(row) ? 'Ya' : 'Tidak' }}</td>
              <td class="px-4 py-3"><StatusChatbotBadge :status="row.status" /></td>
              <td class="px-4 py-3 text-right">
                <RowActionsMenu :actions="aksiBaris(row)" :label="`Aksi untuk ${row.judul}`" :testid="`chatbot-${jenis}-actions-${row.id}`" @select="onAksi(row, $event)" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="total > PER_PAGE" class="p-4"><UiPagination v-model:page="page" :per-page="PER_PAGE" :total="total" /></div>
    </UiCard>

    <MasterChatbotFormDialog v-model:open="formOpen" :jenis="jenis" :row="editing" @saved="onSaved" />
    <ConfirmDialog
      :open="hapus.open"
      :title="`Hapus ${jenis} ini?`"
      :description="`${JUDUL[jenis]} “${hapus.row?.judul ?? ''}” dihapus dan tidak tampil lagi di daftar maupun widget Vanya.`"
      confirm-label="Hapus"
      danger
      :loading="hapus.loading"
      @update:open="(v) => !hapus.loading && (hapus.open = v)"
      @confirm="konfirmasiHapus"
    />
  </section>
</template>
