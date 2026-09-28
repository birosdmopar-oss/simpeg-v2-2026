<script setup lang="ts">
/**
 * Halaman Hari Libur (G-08, DBV-003/CR-010) — daftar dibaca role 1/4/5/8 (legacy Presensi.php:1015), tambah/ubah/
 * aktif-nonaktif/hapus/pulihkan hanya role 1. Role 4/5/8 hanya melihat hari libur Aktif (yang dihitung sebagai libur);
 * role 1 bisa menyaring status (default tanpa Dihapus). Filter tahun (bawaan tahun berjalan) menampilkan rentang yang
 * beririsan dengan tahun itu; urut tanggal mulai terbaru. Error 409 (penulisan lain sedang berjalan) tampil sebagai
 * pesan coba lagi. Aksi baris (Edit, Aktifkan/Nonaktifkan, Pulihkan, Hapus) lewat menu titik tiga (AGENTS.md bagian 1);
 * kolom Status hanya badge.
 */
import { Pencil, Plus, Power, PowerOff, RotateCcw, Search, Trash2 } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { isApiError } from '@/lib/axios'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'

import HariLiburFormDialog from '../components/HariLiburFormDialog.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { HARI_LIBUR_WRITE_ROLES, type HariLiburRow } from '../hariLibur.types'
import { jumlahHari } from '../schemas/hariLibur.schema'
import { hariLiburService } from '../services/hariLibur.service'
import type { MasterStatus } from '../types'

const auth = useAuthStore()
const canWrite = computed(() => auth.role !== null && (HARI_LIBUR_WRITE_ROLES as readonly number[]).includes(auth.role))

const currentYear = new Date().getFullYear()
/** Pilihan tahun: tahun depan s.d. 5 tahun lalu, plus "Semua tahun". */
const yearOptions = Array.from({ length: 7 }, (_, i) => String(currentYear + 1 - i))

const items = ref<HariLiburRow[]>([])
const total = ref(0)
const page = ref(1)
const perPage = 20
const tahun = ref(String(currentYear))
const search = ref('')
const statusFilter = ref<MasterStatus | ''>('')
const loading = ref(false)
const error = ref('')
const notice = ref('')
const busyId = ref('')

const formOpen = ref(false)
const editing = ref<HariLiburRow | null>(null)
const confirm = ref<{ open: boolean; row: HariLiburRow | null; loading: boolean }>({ open: false, row: null, loading: false })

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage)))

const dateFormat = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' })

function formatTanggal(value: string): string {
  const time = Date.parse(`${value}T00:00:00Z`)
  return Number.isNaN(time) ? value : dateFormat.format(time)
}

function rentang(row: HariLiburRow): string {
  return row.tgl_mulai === row.tgl_akhir ? formatTanggal(row.tgl_mulai) : `${formatTanggal(row.tgl_mulai)} – ${formatTanggal(row.tgl_akhir)}`
}

function idOf(row: HariLiburRow): string {
  return String(row.id_libur)
}

function statusOf(row: HariLiburRow): string {
  return String(row.status ?? '1')
}

function messageOf(err: unknown, fallback: string): string {
  return isApiError(err) ? err.message : fallback
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const result = await hariLiburService.list({
      tahun: tahun.value,
      search: search.value.trim(),
      status: canWrite.value ? statusFilter.value : '',
      page: page.value,
      per_page: perPage,
    })
    const lastPage = Math.max(1, Math.ceil(result.total / perPage))
    if (result.items.length === 0 && result.total > 0 && page.value > lastPage) {
      page.value = lastPage
      loading.value = false
      await load()
      return
    }
    items.value = result.items
    total.value = result.total
  } catch (err) {
    error.value = messageOf(err, 'Gagal memuat hari libur.')
  } finally {
    loading.value = false
  }
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    void load()
  }, 300)
})
watch([tahun, statusFilter], () => {
  page.value = 1
  void load()
})

function goTo(next: number): void {
  page.value = Math.min(Math.max(1, next), totalPages.value)
  void load()
}

function openCreate(): void {
  editing.value = null
  formOpen.value = true
}

function openEdit(row: HariLiburRow): void {
  editing.value = row
  formOpen.value = true
}

function onSaved(row: HariLiburRow): void {
  notice.value = editing.value ? `Hari libur "${row.nama_libur}" diperbarui.` : `Hari libur "${row.nama_libur}" ditambahkan.`
  void load()
}

async function setStatus(row: HariLiburRow, status: '1' | '2', message: string): Promise<void> {
  busyId.value = idOf(row)
  error.value = ''
  try {
    await hariLiburService.setStatus(idOf(row), status)
    notice.value = message
    await load()
  } catch (err) {
    error.value = messageOf(err, 'Gagal mengubah status.')
  } finally {
    busyId.value = ''
  }
}

function toggleStatus(row: HariLiburRow, active: boolean): Promise<void> {
  return setStatus(
    row,
    active ? '1' : '2',
    active ? `"${row.nama_libur}" diaktifkan dan dihitung sebagai hari libur.` : `"${row.nama_libur}" dinonaktifkan dan tidak lagi dihitung sebagai hari libur.`,
  )
}

function restore(row: HariLiburRow): Promise<void> {
  return setStatus(row, '1', `"${row.nama_libur}" dipulihkan dan kembali aktif.`)
}

function askDelete(row: HariLiburRow): void {
  confirm.value = { open: true, row, loading: false }
}

/** Aksi baris untuk menu titik tiga (urutan & label sesuai AGENTS.md bagian 1). */
function rowActions(row: HariLiburRow): RowAction[] {
  const status = statusOf(row)
  const busy = busyId.value !== ''
  return [
    { key: 'edit', label: 'Edit', icon: Pencil },
    { key: 'deactivate', label: 'Nonaktifkan', icon: PowerOff, hidden: status !== '1', disabled: busy },
    { key: 'activate', label: 'Aktifkan', icon: Power, hidden: status !== '2', disabled: busy },
    { key: 'restore', label: 'Pulihkan', icon: RotateCcw, hidden: status !== '10', disabled: busy },
    { key: 'delete', label: 'Hapus', icon: Trash2, danger: true, hidden: status === '10' },
  ]
}

function onRowAction(row: HariLiburRow, key: string): void {
  if (key === 'edit') openEdit(row)
  else if (key === 'deactivate') void toggleStatus(row, false)
  else if (key === 'activate') void toggleStatus(row, true)
  else if (key === 'restore') void restore(row)
  else if (key === 'delete') askDelete(row)
}

async function onConfirmDelete(): Promise<void> {
  const row = confirm.value.row
  if (!row) return
  confirm.value.loading = true
  try {
    await hariLiburService.remove(idOf(row))
    notice.value = `"${row.nama_libur}" dihapus dan tidak lagi dihitung sebagai hari libur; bisa dipulihkan lewat filter status Dihapus.`
    confirm.value.open = false
    await load()
  } catch (err) {
    error.value = messageOf(err, 'Gagal menghapus.')
    confirm.value.open = false
  } finally {
    confirm.value.loading = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-slate-900">Hari Libur</h1>
        <p class="text-sm text-slate-500">
          Daftar libur nasional dan cuti bersama. Hanya hari libur berstatus Aktif yang dihitung sebagai hari libur.
        </p>
      </div>
      <button
        v-if="canWrite"
        type="button"
        class="flex items-center gap-1.5 rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-white hover:bg-brand-primary/90"
        data-testid="hari-libur-add"
        @click="openCreate"
      >
        <Plus class="h-4 w-4" /> Tambah Hari Libur
      </button>
    </div>

    <p v-if="notice" class="rounded-md border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-800" role="status">{{ notice }}</p>
    <p v-if="error" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <div class="flex flex-wrap gap-3 rounded-lg border border-slate-200 bg-white p-3">
      <label class="relative min-w-[200px] flex-1">
        <Search class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
        <input
          v-model="search"
          type="search"
          placeholder="Cari nama libur"
          class="w-full rounded-md border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-brand-tertiary focus:outline-none focus:ring-2 focus:ring-brand-tertiary/40"
          data-testid="hari-libur-search"
        />
      </label>
      <select v-model="tahun" class="rounded-md border border-slate-300 px-3 py-2 text-sm" aria-label="Filter tahun" data-testid="hari-libur-tahun">
        <option value="">Semua tahun</option>
        <option v-for="year in yearOptions" :key="year" :value="year">{{ year }}</option>
      </select>
      <select
        v-if="canWrite"
        v-model="statusFilter"
        class="rounded-md border border-slate-300 px-3 py-2 text-sm"
        aria-label="Filter status"
        data-testid="hari-libur-status"
      >
        <option value="">Aktif &amp; Tidak Aktif</option>
        <option value="1">Aktif</option>
        <option value="2">Tidak Aktif</option>
        <option value="10">Dihapus</option>
      </select>
    </div>

    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3">Tanggal</th>
            <th class="px-4 py-3">Hari</th>
            <th class="px-4 py-3">Nama Libur</th>
            <th class="px-4 py-3">Jenis</th>
            <th class="px-4 py-3">Keterangan</th>
            <th class="px-4 py-3">Status</th>
            <th v-if="canWrite" class="px-4 py-3 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading">
            <td :colspan="canWrite ? 7 : 6" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
          </tr>
          <tr v-else-if="items.length === 0">
            <td :colspan="canWrite ? 7 : 6" class="px-4 py-8 text-center text-slate-500">Belum ada hari libur yang cocok.</td>
          </tr>
          <tr v-for="row in items" v-else :key="idOf(row)" class="hover:bg-slate-50" :data-testid="`hari-libur-row-${idOf(row)}`">
            <td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ rentang(row) }}</td>
            <td class="px-4 py-3 text-slate-600">{{ jumlahHari(row.tgl_mulai, row.tgl_akhir) }}</td>
            <td class="px-4 py-3 font-medium text-slate-800">{{ row.nama_libur }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.jenis_libur ?? '—' }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.keterangan ?? '' }}</td>
            <td class="px-4 py-3">
              <StatusBadge :status="row.status ?? '1'" />
            </td>
            <td v-if="canWrite" class="px-4 py-3 text-right">
              <RowActionsMenu
                :actions="rowActions(row)"
                :label="`Aksi untuk ${row.nama_libur}`"
                :testid="`hari-libur-actions-${idOf(row)}`"
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
        <button
          type="button"
          class="rounded-md border border-slate-300 px-3 py-1.5 disabled:opacity-40"
          data-testid="hari-libur-prev"
          :disabled="page <= 1"
          @click="goTo(page - 1)"
        >
          Sebelumnya
        </button>
        <button
          type="button"
          class="rounded-md border border-slate-300 px-3 py-1.5 disabled:opacity-40"
          data-testid="hari-libur-next"
          :disabled="page >= totalPages"
          @click="goTo(page + 1)"
        >
          Berikutnya
        </button>
      </div>
    </div>

    <HariLiburFormDialog v-if="canWrite" v-model:open="formOpen" :row="editing" @saved="onSaved" />

    <ConfirmDialog
      v-model:open="confirm.open"
      :title="`Hapus hari libur &quot;${confirm.row?.nama_libur ?? ''}&quot;?`"
      description="Data tidak dihapus permanen: statusnya menjadi Dihapus sehingga tidak lagi dihitung sebagai hari libur. Tanggalnya tetap terpakai (tidak bisa dibuat ganda) sampai dipulihkan atau diubah."
      danger
      confirm-label="Hapus"
      :loading="confirm.loading"
      @confirm="onConfirmDelete"
    />
  </section>
</template>
