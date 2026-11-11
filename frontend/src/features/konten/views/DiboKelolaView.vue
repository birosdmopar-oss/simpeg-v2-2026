<script setup lang="ts">
/**
 * Kelola DIBO (role 1; PRD Fase 7 v2.0 §5.6, Q7-12/13). Aksi baris lewat menu ⋮ (AGENTS.md bagian 1): Edit →
 * Aktifkan/Nonaktifkan → Pulihkan → Hapus (ConfirmDialog). Kolom Status hanya badge. Endpoint masih draf.
 */
import { Pencil, Plus, Power, PowerOff, RotateCcw, Trash2 } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import type { SelectOption } from '@/shared/ui'
import { UiSelect } from '@/shared/ui'

import DiboFormDialog from '../components/DiboFormDialog.vue'
import { diboKelolaService, masterKontenService } from '../services/kelolaTeks.service'
import type { DiboRow } from '../types'

const LABEL = { '1': 'Aktif', '2': 'Dicabut', '10': 'Dihapus' } as const
const KELAS = { '1': 'bg-green-100 text-green-800', '2': 'bg-slate-200 text-slate-600', '10': 'bg-red-100 text-red-700' } as const
const FILTER: SelectOption[] = [
  { value: '1', label: 'Aktif' },
  { value: '2', label: 'Dicabut' },
  { value: '10', label: 'Dihapus' },
]

const items = ref<DiboRow[]>([])
const total = ref(0)
const page = ref(1)
const perPage = 20
const statusFilter = ref('')
const loading = ref(false)
const error = ref('')
const notice = ref('')
const busy = ref(false)
const opsiJenis = ref<SelectOption[]>([])

const formOpen = ref(false)
const editing = ref<DiboRow | null>(null)
const confirm = ref<{ open: boolean; row: DiboRow | null; loading: boolean }>({ open: false, row: null, loading: false })

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage)))
const pesan = (e: unknown, c: string): string => (isApiError(e) ? e.message : c)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const r = await diboKelolaService.list({ page: page.value, per_page: perPage, status: statusFilter.value || undefined })
    items.value = r.items
    total.value = r.total
  } catch (e) {
    error.value = pesan(e, 'Gagal memuat DIBO.')
  } finally {
    loading.value = false
  }
}

async function loadJenis(): Promise<void> {
  try {
    const jenis = await masterKontenService('dibo-jenis').list()
    opsiJenis.value = jenis.filter((j) => j.status === '1').map((j) => ({ value: j.id, label: j.nama }))
  } catch (e) {
    error.value = `Gagal memuat jenis DIBO. ${pesan(e, '')}`.trim()
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
  notice.value = editing.value ? 'DIBO diperbarui.' : 'DIBO ditambahkan.'
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

function rowActions(row: DiboRow): RowAction[] {
  const dihapus = row.status === '10'
  return [
    { key: 'edit', label: 'Edit', icon: Pencil, hidden: dihapus },
    { key: 'deactivate', label: 'Nonaktifkan', icon: PowerOff, hidden: row.status !== '1', disabled: busy.value },
    { key: 'activate', label: 'Aktifkan', icon: Power, hidden: row.status !== '2', disabled: busy.value },
    { key: 'restore', label: 'Pulihkan', icon: RotateCcw, hidden: !dihapus, disabled: busy.value },
    { key: 'delete', label: 'Hapus', icon: Trash2, danger: true, hidden: dihapus },
  ]
}

function onRowAction(row: DiboRow, key: string): void {
  if (key === 'edit') {
    editing.value = row
    formOpen.value = true
  } else if (key === 'deactivate') void jalankan(() => diboKelolaService.setStatus(row.id, '2'), 'DIBO dinonaktifkan.', 'Gagal mengubah status.')
  else if (key === 'activate') void jalankan(() => diboKelolaService.setStatus(row.id, '1'), 'DIBO diaktifkan.', 'Gagal mengubah status.')
  else if (key === 'restore') void jalankan(() => diboKelolaService.setStatus(row.id, '1'), 'DIBO dipulihkan.', 'Gagal memulihkan.')
  else if (key === 'delete') confirm.value = { open: true, row, loading: false }
}

async function onConfirmDelete(): Promise<void> {
  const row = confirm.value.row
  if (!row) return
  confirm.value.loading = true
  try {
    await diboKelolaService.remove(row.id)
    notice.value = 'DIBO dihapus; bisa dipulihkan lewat filter status Dihapus.'
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
  void loadJenis()
})
</script>

<template>
  <section class="page-fill flex flex-col gap-4" data-testid="konten-dibo-kelola">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-slate-900">Kelola DIBO</h1>
        <p class="text-sm text-slate-500">Dokumen yang dibagikan ke pegawai. Pegawai hanya melihat DIBO berstatus Aktif.</p>
      </div>
      <button
        type="button"
        class="flex items-center gap-1.5 rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-white hover:bg-brand-primary/90"
        data-testid="konten-dibo-add"
        @click="(editing = null), (formOpen = true)"
      >
        <Plus class="h-4 w-4" /> Tambah DIBO
      </button>
    </div>

    <p v-if="notice" class="rounded-md border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-800" role="status">{{ notice }}</p>
    <p v-if="error" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <div class="flex flex-wrap gap-3 rounded-lg border border-slate-200 bg-white p-3">
      <UiSelect v-model="statusFilter" :options="FILTER" clearable size="sm" placeholder="Aktif & Dicabut" aria-label="Filter status" class="w-52" />
    </div>

    <div class="table-fill overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3">Nomor</th>
            <th class="px-4 py-3">Tahun</th>
            <th class="px-4 py-3">Tentang</th>
            <th class="px-4 py-3">Jenis</th>
            <th class="px-4 py-3">Berkas</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading">
            <td colspan="7" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
          </tr>
          <tr v-else-if="items.length === 0">
            <td colspan="7" class="px-4 py-8 text-center text-slate-500">Belum ada DIBO yang cocok.</td>
          </tr>
          <tr v-for="row in items" v-else :key="row.id" class="hover:bg-slate-50" :data-testid="`konten-dibo-row-${row.id}`">
            <td class="whitespace-nowrap px-4 py-3 font-medium text-slate-800">{{ row.nomor || '—' }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.tahun || '—' }}</td>
            <td class="px-4 py-3 text-slate-700">{{ row.tentang }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.jenis || '—' }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.berkas.length }}</td>
            <td class="px-4 py-3">
              <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="KELAS[row.status]" :data-status="row.status">
                {{ LABEL[row.status] }}
              </span>
            </td>
            <td class="px-4 py-3 text-right">
              <RowActionsMenu
                :actions="rowActions(row)"
                :label="`Aksi untuk DIBO ${row.nomor}`"
                :testid="`konten-dibo-actions-${row.id}`"
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

    <DiboFormDialog v-model:open="formOpen" :row="editing" :opsi-jenis="opsiJenis" @saved="onSaved" />

    <ConfirmDialog
      v-model:open="confirm.open"
      title="Hapus DIBO ini?"
      description="Statusnya menjadi Dihapus sehingga tidak tampil ke pegawai, dan bisa dipulihkan."
      danger
      confirm-label="Hapus"
      :loading="confirm.loading"
      @confirm="onConfirmDelete"
    />
  </section>
</template>
