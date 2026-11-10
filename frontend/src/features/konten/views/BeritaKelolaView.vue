<script setup lang="ts">
/**
 * Kelola berita (role 1 dan 3; PRD Fase 7 v2.0 §5.5, Q7-07/08). Role 3 hanya membuat berita yang menunggu persetujuan;
 * persetujuan (Setujui/Tolak) khusus role 1. Aksi baris lewat menu ⋮ (AGENTS.md bagian 1): Edit → Setujui/Tolak →
 * Hapus (ConfirmDialog). Kolom Status hanya badge. Endpoint masih draf.
 */
import { Check, Pencil, Plus, Trash2, X } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { isApiError } from '@/lib/axios'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import type { SelectOption } from '@/shared/ui'
import { UiSelect } from '@/shared/ui'

import BeritaFormDialog from '../components/BeritaFormDialog.vue'
import { kelolaService } from '../services/kelola.service'
import { beritaKelolaService, masterKontenService } from '../services/kelolaTeks.service'
import type { BeritaKelolaRow, StatusBerita } from '../types'

const auth = useAuthStore()
const adalahAdmin = computed(() => auth.role === 1)

const LABEL: Record<StatusBerita, string> = { '0': 'Menunggu', '1': 'Disetujui', '2': 'Ditolak', '10': 'Dihapus' }
const KELAS: Record<StatusBerita, string> = {
  '0': 'bg-amber-100 text-amber-800',
  '1': 'bg-green-100 text-green-800',
  '2': 'bg-slate-200 text-slate-600',
  '10': 'bg-red-100 text-red-700',
}
const FILTER: SelectOption[] = [
  { value: '0', label: 'Menunggu' },
  { value: '1', label: 'Disetujui' },
  { value: '2', label: 'Ditolak' },
]

const items = ref<BeritaKelolaRow[]>([])
const total = ref(0)
const page = ref(1)
const perPage = 20
const statusFilter = ref('')
const loading = ref(false)
const error = ref('')
const notice = ref('')
const busy = ref(false)

const opsiKategori = ref<SelectOption[]>([])
const opsiUnitSatker = ref<SelectOption[]>([])
const opsiPegawai = ref<SelectOption[]>([])

const formOpen = ref(false)
const editing = ref<BeritaKelolaRow | null>(null)
const confirm = ref<{ open: boolean; row: BeritaKelolaRow | null; loading: boolean }>({ open: false, row: null, loading: false })

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage)))
const tanggal = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' })
function fmt(v: string): string {
  const t = Date.parse(`${v.slice(0, 10)}T00:00:00Z`)
  return Number.isNaN(t) ? v : tanggal.format(t)
}
const pesan = (e: unknown, c: string): string => (isApiError(e) ? e.message : c)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const r = await beritaKelolaService.list({ page: page.value, per_page: perPage, status: statusFilter.value || undefined })
    items.value = r.items
    total.value = r.total
  } catch (e) {
    error.value = pesan(e, 'Gagal memuat berita.')
  } finally {
    loading.value = false
  }
}

async function loadOpsi(): Promise<void> {
  const g = kelolaService('popup')
  try {
    const [kat, unit, peg] = await Promise.all([masterKontenService('berita-kategori').list(), g.opsiUnitSatker(), g.opsiPegawai()])
    opsiKategori.value = kat.filter((k) => k.status === '1').map((k) => ({ value: k.id, label: k.nama }))
    opsiUnitSatker.value = unit
    opsiPegawai.value = peg
  } catch (e) {
    error.value = `Gagal memuat pilihan form. ${pesan(e, '')}`.trim()
  }
}

watch(statusFilter, () => {
  page.value = 1
  void load()
})

function goTo(n: number): void {
  page.value = Math.min(Math.max(1, n), totalPages.value)
  void load()
}

function onSaved(): void {
  notice.value = editing.value ? 'Berita diperbarui.' : adalahAdmin.value ? 'Berita ditambahkan.' : 'Berita dikirim dan menunggu persetujuan.'
  void load()
}

async function jalankan(aksi: () => Promise<void>, sukses: string, gagal: string): Promise<void> {
  busy.value = true
  error.value = ''
  try {
    await aksi()
    notice.value = sukses
    await load()
  } catch (e) {
    error.value = pesan(e, gagal)
  } finally {
    busy.value = false
  }
}

function rowActions(row: BeritaKelolaRow): RowAction[] {
  const dihapus = row.status === '10'
  const menunggu = row.status === '0'
  return [
    { key: 'edit', label: 'Edit', icon: Pencil, hidden: dihapus },
    { key: 'approve', label: 'Setujui', icon: Check, hidden: !adalahAdmin.value || !menunggu, disabled: busy.value },
    { key: 'reject', label: 'Tolak', icon: X, hidden: !adalahAdmin.value || !menunggu, disabled: busy.value },
    { key: 'delete', label: 'Hapus', icon: Trash2, danger: true, hidden: dihapus },
  ]
}

function onRowAction(row: BeritaKelolaRow, key: string): void {
  if (key === 'edit') {
    editing.value = row
    formOpen.value = true
  } else if (key === 'approve') void jalankan(() => beritaKelolaService.proses(row.id, 'setuju'), 'Berita disetujui.', 'Gagal menyetujui.')
  else if (key === 'reject') void jalankan(() => beritaKelolaService.proses(row.id, 'tolak'), 'Berita ditolak.', 'Gagal menolak.')
  else if (key === 'delete') confirm.value = { open: true, row, loading: false }
}

async function onConfirmDelete(): Promise<void> {
  const row = confirm.value.row
  if (!row) return
  confirm.value.loading = true
  try {
    await beritaKelolaService.remove(row.id)
    notice.value = 'Berita dihapus.'
    confirm.value.open = false
    await load()
  } catch (e) {
    error.value = pesan(e, 'Gagal menghapus.')
    confirm.value.open = false
  } finally {
    confirm.value.loading = false
  }
}

onMounted(() => {
  void load()
  void loadOpsi()
})
</script>

<template>
  <section class="page-fill flex flex-col gap-4" data-testid="konten-berita-kelola">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-slate-900">Kelola Berita</h1>
        <p class="text-sm text-slate-500">
          {{ adalahAdmin ? 'Tulis, setujui, dan hapus berita.' : 'Berita yang Anda tulis menunggu persetujuan admin sebelum tampil.' }}
        </p>
      </div>
      <button
        type="button"
        class="flex items-center gap-1.5 rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-white hover:bg-brand-primary/90"
        data-testid="konten-berita-add"
        @click="(editing = null), (formOpen = true)"
      >
        <Plus class="h-4 w-4" /> Tambah Berita
      </button>
    </div>

    <p v-if="notice" class="rounded-md border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-800" role="status">{{ notice }}</p>
    <p v-if="error" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <div class="flex flex-wrap gap-3 rounded-lg border border-slate-200 bg-white p-3">
      <UiSelect v-model="statusFilter" :options="FILTER" clearable size="sm" placeholder="Semua status" aria-label="Filter status" class="w-52" />
    </div>

    <div class="table-fill overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3">Judul</th>
            <th class="px-4 py-3">Kategori</th>
            <th class="px-4 py-3">Penulis</th>
            <th class="px-4 py-3">Terbit</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading">
            <td colspan="6" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
          </tr>
          <tr v-else-if="items.length === 0">
            <td colspan="6" class="px-4 py-8 text-center text-slate-500">Belum ada berita yang cocok.</td>
          </tr>
          <tr v-for="row in items" v-else :key="row.id" class="hover:bg-slate-50" :data-testid="`konten-berita-row-${row.id}`">
            <td class="px-4 py-3 font-medium text-slate-800">{{ row.judul }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.kategori || '—' }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.penulis || '—' }}</td>
            <td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ row.publish_at ? fmt(row.publish_at) : '—' }}</td>
            <td class="px-4 py-3">
              <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="KELAS[row.status]" :data-status="row.status">
                {{ LABEL[row.status] }}
              </span>
            </td>
            <td class="px-4 py-3 text-right">
              <RowActionsMenu
                :actions="rowActions(row)"
                :label="`Aksi untuk ${row.judul}`"
                :testid="`konten-berita-actions-${row.id}`"
                @select="onRowAction(row, $event)"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-slate-600">
      <span>{{ total }} data · halaman {{ page }} dari {{ totalPages }}</span>
      <div class="flex gap-1">
        <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 disabled:opacity-40" :disabled="page <= 1" @click="goTo(page - 1)">Sebelumnya</button>
        <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 disabled:opacity-40" :disabled="page >= totalPages" @click="goTo(page + 1)">Berikutnya</button>
      </div>
    </div>

    <BeritaFormDialog
      v-model:open="formOpen"
      :row="editing"
      :opsi-kategori="opsiKategori"
      :opsi-unit-satker="opsiUnitSatker"
      :opsi-pegawai="opsiPegawai"
      :perlu-persetujuan="!adalahAdmin"
      @saved="onSaved"
    />

    <ConfirmDialog
      v-model:open="confirm.open"
      title="Hapus berita ini?"
      description="Berita tidak akan tampil lagi ke pegawai."
      danger
      confirm-label="Hapus"
      :loading="confirm.loading"
      @confirm="onConfirmDelete"
    />
  </section>
</template>
