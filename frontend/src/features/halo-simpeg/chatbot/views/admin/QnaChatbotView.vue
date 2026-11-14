<script setup lang="ts">
/**
 * Chatbot Manager → QnA (role 1; PRD Fase 8 v1.1 §6.3, §7.10.4; `dev:V/list_qna.php:36-46`). Kolom No, Kategori, Tag,
 * Pertanyaan, Opsi Alternatif, Status; filter server status (bawaan Aktif), kategori, tag, `q`. Tombol level halaman
 * "Unduh template" dan "Impor Excel". ⋮: Edit → Aktifkan/Nonaktifkan (`PATCH …/status`) → Hapus (status 10). Status induk
 * F8-49: kategori/tag Tidak Aktif berbadge, kategori Dihapus berpenanda; tag Dihapus tidak tampil.
 */
import { Download, Pencil, Plus, Power, PowerOff, Trash2, Upload } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import { isApiError } from '@/lib/axios'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { UiBadge, UiButton, UiCard, UiNotice, UiPagination, UiSearchInput, UiSelect, type SelectOption } from '@/shared/ui'

import ImporQnaDialog from '../../components/admin/ImporQnaDialog.vue'
import QnaFormDialog from '../../components/admin/QnaFormDialog.vue'
import StatusChatbotBadge from '../../components/admin/StatusChatbotBadge.vue'
import { qnaChatbotService } from '../../services/chatbot.service'
import type { OpsiQna, QnaRow, StatusUbah } from '../../types'

const PER_PAGE = 20
const FILTER_STATUS: SelectOption[] = [
  { value: '1', label: 'Aktif' },
  { value: '2', label: 'Tidak Aktif' },
]

const items = ref<QnaRow[]>([])
const total = ref(0)
const page = ref(1)
const statusFilter = ref('1')
const kategoriFilter = ref('')
const tagFilter = ref('')
const search = ref('')
const q = ref('')
const opsi = ref<OpsiQna>({ kategori: [], tag: [] })
const memuat = ref(false)
const busy = ref(false)
const mengunduh = ref(false)
const notice = ref('')
const failure = ref('')
const formOpen = ref(false)
const imporOpen = ref(false)
const editing = ref<QnaRow | null>(null)
const hapus = ref<{ open: boolean; row: QnaRow | null; loading: boolean }>({ open: false, row: null, loading: false })
let debounce: ReturnType<typeof setTimeout> | null = null

const opsiKategori = computed<SelectOption[]>(() => opsi.value.kategori.map((k) => ({ value: String(k.id), label: k.judul })))
const opsiTag = computed<SelectOption[]>(() => opsi.value.tag.map((t) => ({ value: String(t.id), label: t.judul })))
const pesan = (e: unknown, baku: string): string => (isApiError(e) ? e.message : baku)
const nomor = (i: number): number => (page.value - 1) * PER_PAGE + i + 1
const tagTampil = (row: QnaRow) => row.tag.filter((t) => t.status !== 10)

/** Penjaga urutan (pola FaqView `searchSeq`, M-3): hanya respons permintaan terakhir yang ditulis. */
let urutanMuat = 0

async function muat(): Promise<void> {
  const n = ++urutanMuat
  memuat.value = true
  failure.value = ''
  try {
    const r = await qnaChatbotService.list({
      page: page.value,
      per_page: PER_PAGE,
      status: statusFilter.value === '' ? undefined : (Number(statusFilter.value) as StatusUbah),
      kategori: kategoriFilter.value === '' ? undefined : Number(kategoriFilter.value),
      tag: tagFilter.value === '' ? undefined : Number(tagFilter.value),
      q: q.value,
    })
    if (n !== urutanMuat) return
    items.value = r.items
    total.value = r.total
  } catch (e) {
    if (n !== urutanMuat) return
    items.value = []
    total.value = 0
    failure.value = pesan(e, 'Gagal memuat QnA.')
  } finally {
    if (n === urutanMuat) memuat.value = false
  }
}

/** `opsiSiap` menahan form QnA sampai opsi kategori/tag termuat (M-2); galatnya ditampilkan di form + tombol muat ulang. */
const opsiSiap = ref(false)
const opsiGalat = ref('')

async function muatOpsi(): Promise<void> {
  opsiGalat.value = ''
  try {
    opsi.value = await qnaChatbotService.opsi()
    opsiSiap.value = true
  } catch (e) {
    opsiGalat.value = pesan(e, 'Gagal memuat pilihan kategori dan tag.')
    failure.value = opsiGalat.value
  }
}

watch([statusFilter, kategoriFilter, tagFilter], () => {
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
  notice.value = editing.value === null ? 'QnA baru berhasil ditambahkan.' : 'QnA berhasil diperbarui.'
  void muat()
}

function onImported(): void {
  notice.value = 'Data QnA beserta Kategori dan Tag berhasil diimpor!'
  void muat()
  void muatOpsi()
}

async function ubahStatus(row: QnaRow, status: StatusUbah): Promise<void> {
  busy.value = true
  failure.value = ''
  try {
    await qnaChatbotService.setStatus(row.id, status)
    notice.value = `QnA ${status === 1 ? 'diaktifkan' : 'dinonaktifkan'}.`
    await muat()
  } catch (e) {
    failure.value = pesan(e, 'Gagal mengubah status.')
  } finally {
    busy.value = false
  }
}

function aksiBaris(row: QnaRow): RowAction[] {
  return [
    { key: 'edit', label: 'Edit', icon: Pencil },
    { key: 'activate', label: 'Aktifkan', icon: Power, hidden: row.status !== 2, disabled: busy.value },
    { key: 'deactivate', label: 'Nonaktifkan', icon: PowerOff, hidden: row.status !== 1, disabled: busy.value },
    { key: 'delete', label: 'Hapus', icon: Trash2, danger: true, disabled: busy.value },
  ]
}

function onAksi(row: QnaRow, key: string): void {
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
    await qnaChatbotService.hapus(row.id)
    notice.value = 'QnA berhasil dihapus.'
    hapus.value.open = false
    await muat()
  } catch (e) {
    failure.value = pesan(e, 'Gagal menghapus.')
    hapus.value.open = false
  } finally {
    hapus.value.loading = false
  }
}

async function unduhTemplate(): Promise<void> {
  mengunduh.value = true
  failure.value = ''
  try {
    simpanBerkas(await qnaChatbotService.template())
  } catch (e) {
    failure.value = `Gagal mengunduh template: ${pesan(e, 'coba lagi.')}`
  } finally {
    mengunduh.value = false
  }
}

onMounted(() => {
  void muat()
  void muatOpsi()
})
onBeforeUnmount(() => {
  if (debounce) clearTimeout(debounce)
})
</script>

<template>
  <section class="page-fill flex flex-col gap-4" data-testid="chatbot-qna-page">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-h2 font-semibold text-slate-900">QnA</h1>
        <p class="text-body2 text-slate-500">Chatbot Manager · pertanyaan dan jawaban sumber pengetahuan Vanya</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <UiButton variant="secondary" appearance="outline" :disabled="mengunduh" data-testid="chatbot-qna-template" @click="unduhTemplate">
          <Download class="h-4 w-4" aria-hidden="true" /> Unduh template
        </UiButton>
        <UiButton variant="secondary" appearance="outline" data-testid="chatbot-qna-impor" @click="imporOpen = true">
          <Upload class="h-4 w-4" aria-hidden="true" /> Impor Excel
        </UiButton>
        <UiButton data-testid="chatbot-qna-tambah" @click="tambah"><Plus class="h-4 w-4" aria-hidden="true" /> Tambah QnA</UiButton>
      </div>
    </div>

    <UiNotice v-if="notice" tone="success">{{ notice }}</UiNotice>
    <UiNotice v-if="failure" tone="danger">
      <span role="alert">{{ failure }}</span>
    </UiNotice>

    <UiCard title="Daftar QnA" flush fill class="flex min-h-0 flex-1 flex-col">
      <div class="flex flex-wrap items-end gap-3 border-b border-slate-100 p-4">
        <UiSelect v-model="statusFilter" :options="FILTER_STATUS" clearable size="sm" placeholder="Semua status" aria-label="Filter status" class="w-40" />
        <UiSelect v-model="kategoriFilter" :options="opsiKategori" clearable searchable size="sm" placeholder="Semua kategori" aria-label="Filter kategori" class="w-52" />
        <UiSelect v-model="tagFilter" :options="opsiTag" clearable searchable size="sm" placeholder="Semua tag" aria-label="Filter tag" class="w-44" />
        <UiSearchInput v-model="search" placeholder="Cari pertanyaan" label="Cari pertanyaan" class="min-w-[200px] flex-1" />
      </div>
      <div class="table-fill scrollbar-slim overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="w-16 px-4 py-3">No</th>
              <th class="px-4 py-3">Kategori</th>
              <th class="px-4 py-3">Tag</th>
              <th class="px-4 py-3">Pertanyaan</th>
              <th class="px-4 py-3">Opsi Alternatif</th>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="memuat">
              <td colspan="7" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
            </tr>
            <tr v-else-if="items.length === 0">
              <td colspan="7" class="px-4 py-8 text-center text-slate-500">Belum ada QnA yang cocok.</td>
            </tr>
            <tr v-for="(row, i) in items" v-else :key="row.id" class="align-top hover:bg-slate-50" :data-testid="`chatbot-qna-row-${row.id}`">
              <td class="px-4 py-3 text-slate-500">{{ nomor(i) }}</td>
              <td class="px-4 py-3 text-slate-700" data-kolom="kategori">
                <template v-if="row.kategori">
                  {{ row.kategori.judul }}
                  <UiBadge v-if="row.kategori.status === 2" tone="neutral" size="sm" data-status="2">Tidak Aktif</UiBadge>
                  <UiBadge v-else-if="row.kategori.status === 10" tone="danger" size="sm" data-status="10">Dihapus</UiBadge>
                </template>
                <span v-else class="text-slate-400">—</span>
              </td>
              <td class="px-4 py-3" data-kolom="tag">
                <div class="flex max-w-xs flex-wrap gap-1">
                  <UiBadge v-for="t in tagTampil(row)" :key="t.id" :tone="t.status === 2 ? 'neutral' : 'info'" size="sm" :data-status="t.status">
                    {{ t.judul }}<template v-if="t.status === 2"> (Tidak Aktif)</template>
                  </UiBadge>
                  <span v-if="tagTampil(row).length === 0" class="text-slate-400">—</span>
                </div>
              </td>
              <td class="max-w-md px-4 py-3 text-slate-800">{{ row.pertanyaan }}</td>
              <td class="px-4 py-3 text-slate-600">{{ row.is_fallback_option ? 'Ya' : 'Tidak' }}</td>
              <td class="px-4 py-3"><StatusChatbotBadge :status="row.status" /></td>
              <td class="px-4 py-3 text-right">
                <RowActionsMenu :actions="aksiBaris(row)" :label="`Aksi untuk ${row.pertanyaan}`" :testid="`chatbot-qna-actions-${row.id}`" @select="onAksi(row, $event)" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="total > PER_PAGE" class="p-4"><UiPagination v-model:page="page" :per-page="PER_PAGE" :total="total" /></div>
    </UiCard>

    <QnaFormDialog v-model:open="formOpen" :row="editing" :opsi="opsi" :opsi-siap="opsiSiap" :opsi-galat="opsiGalat" @saved="onSaved" @muat-ulang-opsi="muatOpsi" />
    <ImporQnaDialog v-model:open="imporOpen" @imported="onImported" />
    <ConfirmDialog
      :open="hapus.open"
      title="Hapus QnA ini?"
      description="QnA dihapus dan tidak lagi dipakai Vanya maupun tampil di daftar."
      confirm-label="Hapus"
      danger
      :loading="hapus.loading"
      @update:open="(v) => !hapus.loading && (hapus.open = v)"
      @confirm="konfirmasiHapus"
    />
  </section>
</template>
