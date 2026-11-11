<script setup lang="ts">
/**
 * Kelola form digital (F-06, MAKE-083, role 1; PRD Fase 7 v2.0 §5.7). Aksi baris lewat menu ⋮ (AGENTS.md bagian 1):
 * Edit → Aktifkan/Nonaktifkan → Lihat respons → Lihat target → Hapus (ConfirmDialog). Kolom Status hanya badge.
 * Hapus = lunak (status Dihapus, jawaban tetap — Q7-19); form yang sudah dijawab terkunci pertanyaannya (Q7-25).
 */
import { ClipboardList, Pencil, Plus, Power, PowerOff, Trash2, Users } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import { isApiError } from '@/lib/axios'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import type { SelectOption } from '@/shared/ui'
import { UiSearchInput, UiSelect } from '@/shared/ui'

import FormDigitalEditorDialog from '../components/FormDigitalEditorDialog.vue'
import FormDigitalTargetDialog from '../components/FormDigitalTargetDialog.vue'
import { FORM_DIGITAL_ROUTE } from '../routes'
import { formDigitalService } from '../services/formDigital.service'
import type { FormRingkas } from '../types'

const LABEL: Record<number, string> = { 1: 'Aktif', 2: 'Tidak Aktif', 10: 'Dihapus' }
const KELAS: Record<number, string> = { 1: 'bg-green-100 text-green-800', 2: 'bg-slate-200 text-slate-600', 10: 'bg-red-100 text-red-700' }
const FILTER: SelectOption[] = [
  { value: '1', label: 'Aktif' },
  { value: '2', label: 'Tidak Aktif' },
  { value: '10', label: 'Dihapus' },
]

const router = useRouter()
const items = ref<FormRingkas[]>([])
const total = ref(0)
const page = ref(1)
const perPage = 20
const cari = ref('')
const statusFilter = ref('')
const loading = ref(false)
const error = ref('')
const notice = ref('')
const busy = ref(false)

const editorOpen = ref(false)
const editingId = ref<number | null>(null)
const targetRow = ref<FormRingkas | null>(null)
const confirm = ref<{ open: boolean; row: FormRingkas | null; loading: boolean }>({ open: false, row: null, loading: false })

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage)))
const pesan = (e: unknown, c: string): string => (isApiError(e) ? e.message : c)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const r = await formDigitalService.daftar({
      page: page.value,
      per_page: perPage,
      q: cari.value.trim() || undefined,
      status: statusFilter.value ? Number(statusFilter.value) : undefined,
    })
    items.value = r.items
    total.value = r.total
  } catch (e) {
    error.value = pesan(e, 'Gagal memuat form digital.')
  } finally {
    loading.value = false
  }
}

watch([statusFilter, cari], () => {
  page.value = 1
  void load()
})

function goTo(n: number): void {
  page.value = Math.min(Math.max(1, n), totalPages.value)
  void load()
}

function tambah(): void {
  editingId.value = null
  editorOpen.value = true
}

function onSaved(): void {
  notice.value = editingId.value ? 'Form diperbarui.' : 'Form ditambahkan.'
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

function rowActions(row: FormRingkas): RowAction[] {
  const dihapus = row.status === 10
  return [
    { key: 'edit', label: 'Edit', icon: Pencil, hidden: dihapus },
    { key: 'deactivate', label: 'Nonaktifkan', icon: PowerOff, hidden: row.status !== 1, disabled: busy.value },
    { key: 'activate', label: 'Aktifkan', icon: Power, hidden: row.status !== 2, disabled: busy.value },
    { key: 'respons', label: 'Lihat respons', icon: ClipboardList },
    { key: 'target', label: 'Lihat target', icon: Users },
    { key: 'delete', label: 'Hapus', icon: Trash2, danger: true, hidden: dihapus },
  ]
}

function onRowAction(row: FormRingkas, key: string): void {
  if (key === 'edit') {
    editingId.value = row.id
    editorOpen.value = true
  } else if (key === 'activate' || key === 'deactivate') {
    const status = key === 'activate' ? 1 : 2
    void jalankan(() => formDigitalService.setStatus(row.id, status), status === 1 ? 'Form diaktifkan.' : 'Form dinonaktifkan.', 'Gagal mengubah status.')
  } else if (key === 'respons') {
    void router.push({ name: FORM_DIGITAL_ROUTE.RESPONS, params: { id: row.id } })
  } else if (key === 'target') {
    targetRow.value = row
  } else if (key === 'delete') {
    confirm.value = { open: true, row, loading: false }
  }
}

async function onConfirmDelete(): Promise<void> {
  const row = confirm.value.row
  if (!row) return
  confirm.value.loading = true
  await jalankan(() => formDigitalService.hapus(row.id), 'Form dihapus.', 'Gagal menghapus form.')
  confirm.value = { open: false, row: null, loading: false }
}

onMounted(() => void load())
</script>

<template>
  <section class="page-fill flex flex-col gap-4" data-testid="form-digital-kelola">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-slate-900">Form Digital</h1>
        <p class="text-sm text-slate-500">Kuesioner yang wajib diisi pegawai sasaran selama periode aktif.</p>
      </div>
      <button
        type="button"
        class="flex items-center gap-1.5 rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-white hover:bg-brand-primary/90"
        data-testid="form-digital-add"
        @click="tambah"
      >
        <Plus class="h-4 w-4" /> Tambah form
      </button>
    </div>

    <p v-if="notice" class="rounded-md border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-800" role="status">{{ notice }}</p>
    <p v-if="error" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <div class="flex flex-wrap gap-3 rounded-lg border border-slate-200 bg-white p-3">
      <UiSearchInput v-model="cari" placeholder="Cari judul form" aria-label="Cari judul form" class="w-64" />
      <UiSelect v-model="statusFilter" :options="FILTER" clearable size="sm" placeholder="Aktif & Tidak Aktif" aria-label="Filter status" class="w-52" />
    </div>

    <div class="table-fill overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3">Judul</th>
            <th class="px-4 py-3">Periode</th>
            <th class="px-4 py-3">Urutan</th>
            <th class="px-4 py-3">Target</th>
            <th class="px-4 py-3">Sudah</th>
            <th class="px-4 py-3">Belum</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading">
            <td colspan="8" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
          </tr>
          <tr v-else-if="items.length === 0">
            <td colspan="8" class="px-4 py-8 text-center text-slate-500">Belum ada form digital yang cocok.</td>
          </tr>
          <tr v-for="row in items" v-else :key="row.id" class="hover:bg-slate-50" :data-testid="`form-digital-row-${row.id}`">
            <td class="px-4 py-3 font-medium text-slate-800">
              {{ row.judul_form }}
              <span v-if="row.terkunci" class="ml-1 text-xs font-normal text-slate-500">(terkunci)</span>
            </td>
            <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row.tgl_mulai }} s.d. {{ row.tgl_akhir }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.order }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.jumlah_target }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.sudah_mengisi }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.belum_mengisi }}</td>
            <td class="px-4 py-3">
              <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="KELAS[row.status]" :data-status="row.status">
                {{ LABEL[row.status] }}
              </span>
            </td>
            <td class="px-4 py-3 text-right">
              <RowActionsMenu
                :actions="rowActions(row)"
                :label="`Aksi untuk ${row.judul_form}`"
                :testid="`form-digital-actions-${row.id}`"
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

    <FormDigitalEditorDialog v-model:open="editorOpen" :id-form="editingId" @saved="onSaved" />
    <FormDigitalTargetDialog :row="targetRow" @close="targetRow = null" />

    <ConfirmDialog
      v-model:open="confirm.open"
      title="Hapus form ini?"
      description="Form tidak lagi tampil ke pegawai. Jawaban yang sudah masuk tetap disimpan."
      danger
      confirm-label="Hapus"
      :loading="confirm.loading"
      @confirm="onConfirmDelete"
    />
  </section>
</template>
