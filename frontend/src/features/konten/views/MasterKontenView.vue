<script setup lang="ts">
/**
 * Master sederhana konten (role 1; PRD Fase 7 v2.0 Q7-07/11): kategori berita dan jenis DIBO — nama + urutan + status.
 * Aksi baris lewat menu ⋮ (AGENTS.md bagian 1): Edit → Aktifkan/Nonaktifkan → Naikkan/Turunkan urutan → Pulihkan →
 * Hapus (ConfirmDialog). Kolom Status hanya badge. Endpoint masih draf.
 */
import { ArrowDown, ArrowUp, Pencil, Plus, Power, PowerOff, RotateCcw, Trash2, X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, onMounted, ref } from 'vue'

import { isApiError } from '@/lib/axios'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import FormField from '@/shared/components/FormField.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { FORM_ACTIONS_CLASS, FORM_ALERT_CLASS } from '@/shared/components/formLayout'
import UiButton from '@/shared/ui/UiButton.vue'

import { masterKontenService } from '../services/kelolaTeks.service'
import type { JenisMasterKonten, MasterKontenRow } from '../types'

const props = defineProps<{ jenis: JenisMasterKonten }>()

const judul = computed(() => (props.jenis === 'dibo-jenis' ? 'Jenis DIBO' : 'Kategori Berita'))
const svc = computed(() => masterKontenService(props.jenis))
const LABEL = { '1': 'Aktif', '2': 'Tidak Aktif', '10': 'Dihapus' } as const
const KELAS = { '1': 'bg-green-100 text-green-800', '2': 'bg-slate-200 text-slate-600', '10': 'bg-red-100 text-red-700' } as const

const items = ref<MasterKontenRow[]>([])
const loading = ref(false)
const error = ref('')
const notice = ref('')
const busy = ref(false)

const formOpen = ref(false)
const editing = ref<MasterKontenRow | null>(null)
const nama = ref('')
const submitting = ref(false)
const submitted = ref(false)
const formError = ref('')
const confirm = ref<{ open: boolean; row: MasterKontenRow | null; loading: boolean }>({ open: false, row: null, loading: false })

const pesan = (e: unknown, c: string): string => (isApiError(e) ? e.message : c)
const namaError = computed(() => (submitted.value && nama.value.trim() === '' ? 'Nama wajib diisi.' : undefined))

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    items.value = await svc.value.list()
  } catch (e) {
    error.value = pesan(e, `Gagal memuat ${judul.value.toLowerCase()}.`)
  } finally {
    loading.value = false
  }
}

function bukaForm(row: MasterKontenRow | null): void {
  editing.value = row
  nama.value = row?.nama ?? ''
  submitted.value = false
  formError.value = ''
  formOpen.value = true
}

async function onSubmit(): Promise<void> {
  submitted.value = true
  if (nama.value.trim() === '' || submitting.value) return
  submitting.value = true
  formError.value = ''
  try {
    await svc.value.simpan(editing.value?.id ?? null, nama.value.trim())
    notice.value = editing.value ? `${judul.value} diperbarui.` : `${judul.value} ditambahkan.`
    formOpen.value = false
    await load()
  } catch (e) {
    formError.value = pesan(e, 'Terjadi kesalahan. Silakan coba lagi.')
  } finally {
    submitting.value = false
  }
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

function rowActions(row: MasterKontenRow, i: number): RowAction[] {
  const dihapus = row.status === '10'
  return [
    { key: 'edit', label: 'Edit', icon: Pencil, hidden: dihapus },
    { key: 'deactivate', label: 'Nonaktifkan', icon: PowerOff, hidden: row.status !== '1', disabled: busy.value },
    { key: 'activate', label: 'Aktifkan', icon: Power, hidden: row.status !== '2', disabled: busy.value },
    { key: 'up', label: 'Naikkan urutan', icon: ArrowUp, hidden: dihapus, disabled: busy.value || i === 0 },
    { key: 'down', label: 'Turunkan urutan', icon: ArrowDown, hidden: dihapus, disabled: busy.value || i === items.value.length - 1 },
    { key: 'restore', label: 'Pulihkan', icon: RotateCcw, hidden: !dihapus, disabled: busy.value },
    { key: 'delete', label: 'Hapus', icon: Trash2, danger: true, hidden: dihapus },
  ]
}

function onRowAction(row: MasterKontenRow, key: string): void {
  const j = judul.value
  if (key === 'edit') bukaForm(row)
  else if (key === 'deactivate') void jalankan(() => svc.value.setStatus(row.id, '2'), `${j} dinonaktifkan.`, 'Gagal mengubah status.')
  else if (key === 'activate') void jalankan(() => svc.value.setStatus(row.id, '1'), `${j} diaktifkan.`, 'Gagal mengubah status.')
  else if (key === 'restore') void jalankan(() => svc.value.setStatus(row.id, '1'), `${j} dipulihkan.`, 'Gagal memulihkan.')
  else if (key === 'up') void jalankan(() => svc.value.geser(row.id, 'naik'), 'Urutan dinaikkan.', 'Gagal mengubah urutan.')
  else if (key === 'down') void jalankan(() => svc.value.geser(row.id, 'turun'), 'Urutan diturunkan.', 'Gagal mengubah urutan.')
  else if (key === 'delete') confirm.value = { open: true, row, loading: false }
}

async function onConfirmDelete(): Promise<void> {
  const row = confirm.value.row
  if (!row) return
  confirm.value.loading = true
  try {
    await svc.value.remove(row.id)
    notice.value = `${judul.value} dihapus; bisa dipulihkan.`
    confirm.value.open = false
    await load()
  } catch (e) {
    error.value = pesan(e, 'Gagal menghapus.')
    confirm.value.open = false
  } finally {
    confirm.value.loading = false
  }
}

onMounted(() => void load())
</script>

<template>
  <section class="page-fill flex flex-col gap-4" :data-testid="`konten-${jenis}-kelola`">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-xl font-semibold text-slate-900">{{ judul }}</h1>
      <button
        type="button"
        class="flex items-center gap-1.5 rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-white hover:bg-brand-primary/90"
        :data-testid="`konten-${jenis}-add`"
        @click="bukaForm(null)"
      >
        <Plus class="h-4 w-4" /> Tambah
      </button>
    </div>

    <p v-if="notice" class="rounded-md border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-800" role="status">{{ notice }}</p>
    <p v-if="error" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <div class="table-fill overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3">Nama</th>
            <th class="px-4 py-3">Urutan</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading">
            <td colspan="4" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
          </tr>
          <tr v-else-if="items.length === 0">
            <td colspan="4" class="px-4 py-8 text-center text-slate-500">Belum ada data.</td>
          </tr>
          <tr v-for="(row, i) in items" v-else :key="row.id" class="hover:bg-slate-50" :data-testid="`konten-${jenis}-row-${row.id}`">
            <td class="px-4 py-3 font-medium text-slate-800">{{ row.nama }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.urutan }}</td>
            <td class="px-4 py-3">
              <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="KELAS[row.status]" :data-status="row.status">
                {{ LABEL[row.status] }}
              </span>
            </td>
            <td class="px-4 py-3 text-right">
              <RowActionsMenu
                :actions="rowActions(row, i)"
                :label="`Aksi untuk ${row.nama}`"
                :testid="`konten-${jenis}-actions-${row.id}`"
                @select="onRowAction(row, $event)"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <DialogRoot v-model:open="formOpen">
      <DialogPortal>
        <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
        <DialogContent
          class="fixed left-1/2 top-1/2 z-50 w-[calc(100%-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        >
          <div class="mb-4 flex items-start justify-between">
            <div>
              <DialogTitle class="text-h5 font-semibold text-slate-900">{{ editing ? 'Ubah' : 'Tambah' }} {{ judul }}</DialogTitle>
              <DialogDescription class="text-sm text-slate-500">Nama harus unik.</DialogDescription>
            </div>
            <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
              <X class="h-5 w-5" />
            </DialogClose>
          </div>
          <form class="flex flex-col gap-4" novalidate :data-testid="`konten-${jenis}-form`" @submit.prevent="onSubmit">
            <p v-if="formError" :class="FORM_ALERT_CLASS" role="alert">{{ formError }}</p>
            <FormField v-model="nama" name="nama" label="Nama" required :error="namaError" />
            <div :class="FORM_ACTIONS_CLASS">
              <DialogClose as-child>
                <UiButton variant="secondary" appearance="soft">Batal</UiButton>
              </DialogClose>
              <UiButton type="submit" :disabled="submitting">{{ submitting ? 'Menyimpan...' : 'Simpan' }}</UiButton>
            </div>
          </form>
        </DialogContent>
      </DialogPortal>
    </DialogRoot>

    <ConfirmDialog
      v-model:open="confirm.open"
      :title="`Hapus ${judul.toLowerCase()} ini?`"
      description="Statusnya menjadi Dihapus dan tidak bisa dipilih lagi, dan bisa dipulihkan."
      danger
      confirm-label="Hapus"
      :loading="confirm.loading"
      @confirm="onConfirmDelete"
    />
  </section>
</template>
