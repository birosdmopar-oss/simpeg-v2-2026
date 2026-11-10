<script setup lang="ts">
/**
 * Halaman kelola popup / banner (role 1; PRD Fase 7 v2.0 §5.3-5.4). Satu komponen untuk dua jenis (`jenis`), karena bentuk
 * data dan penargetannya sama. Aksi baris lewat menu ⋮ (AGENTS.md bagian 1): Edit → Aktifkan/Nonaktifkan →
 * Naikkan/Turunkan urutan → Pulihkan → Hapus (ConfirmDialog). Kolom Status hanya badge. Endpoint masih draf.
 */
import { ArrowDown, ArrowUp, Pencil, Plus, Power, PowerOff, RotateCcw, Trash2 } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import type { SelectOption } from '@/shared/ui'
import { UiSelect } from '@/shared/ui'

import KontenGambarFormDialog from '../components/KontenGambarFormDialog.vue'
import { kelolaService } from '../services/kelola.service'
import type { JenisKontenGambar, KontenGambarRow } from '../types'

const props = defineProps<{ jenis: JenisKontenGambar }>()

const nama = computed(() => (props.jenis === 'popup' ? 'Popup' : 'Banner'))
const svc = computed(() => kelolaService(props.jenis))
const tid = (s: string) => `konten-${props.jenis}-${s}`

const STATUS_LABEL = { '1': 'Aktif', '2': 'Tidak Aktif', '10': 'Dihapus' } as const
const STATUS_CLASS = { '1': 'bg-green-100 text-green-800', '2': 'bg-slate-200 text-slate-600', '10': 'bg-red-100 text-red-700' } as const
const STATUS_FILTER: SelectOption[] = [
  { value: '1', label: 'Aktif' },
  { value: '2', label: 'Tidak Aktif' },
  { value: '10', label: 'Dihapus' },
]

const items = ref<KontenGambarRow[]>([])
const total = ref(0)
const page = ref(1)
const perPage = 20
const statusFilter = ref('')
const loading = ref(false)
const error = ref('')
const notice = ref('')
const busy = ref(false)

const opsiUnitSatker = ref<SelectOption[]>([])
const opsiPegawai = ref<SelectOption[]>([])

const formOpen = ref(false)
const editing = ref<KontenGambarRow | null>(null)
const confirm = ref<{ open: boolean; row: KontenGambarRow | null; loading: boolean }>({ open: false, row: null, loading: false })

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage)))

const tanggal = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' })
function fmt(v: string): string {
  const t = Date.parse(`${v}T00:00:00Z`)
  return Number.isNaN(t) ? v : tanggal.format(t)
}

function pesan(e: unknown, cadangan: string): string {
  return isApiError(e) ? e.message : cadangan
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const r = await svc.value.list({ page: page.value, per_page: perPage, status: statusFilter.value || undefined })
    items.value = r.items
    total.value = r.total
  } catch (e) {
    error.value = pesan(e, `Gagal memuat ${nama.value.toLowerCase()}.`)
  } finally {
    loading.value = false
  }
}

async function loadOpsi(): Promise<void> {
  try {
    ;[opsiUnitSatker.value, opsiPegawai.value] = await Promise.all([svc.value.opsiUnitSatker(), svc.value.opsiPegawai()])
  } catch (e) {
    error.value = `Gagal memuat pilihan target. ${pesan(e, '')}`.trim()
  }
}

watch(statusFilter, () => {
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

function onSaved(): void {
  notice.value = editing.value ? `${nama.value} diperbarui.` : `${nama.value} ditambahkan.`
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

function rowActions(row: KontenGambarRow, index: number): RowAction[] {
  const dihapus = row.status === '10'
  return [
    { key: 'edit', label: 'Edit', icon: Pencil, hidden: dihapus },
    { key: 'deactivate', label: 'Nonaktifkan', icon: PowerOff, hidden: row.status !== '1', disabled: busy.value },
    { key: 'activate', label: 'Aktifkan', icon: Power, hidden: row.status !== '2', disabled: busy.value },
    { key: 'up', label: 'Naikkan urutan', icon: ArrowUp, hidden: dihapus, disabled: busy.value || (page.value === 1 && index === 0) },
    { key: 'down', label: 'Turunkan urutan', icon: ArrowDown, hidden: dihapus, disabled: busy.value || (page.value === totalPages.value && index === items.value.length - 1) },
    { key: 'restore', label: 'Pulihkan', icon: RotateCcw, hidden: !dihapus, disabled: busy.value },
    { key: 'delete', label: 'Hapus', icon: Trash2, danger: true, hidden: dihapus },
  ]
}

function onRowAction(row: KontenGambarRow, key: string): void {
  const n = nama.value
  if (key === 'edit') {
    editing.value = row
    formOpen.value = true
  } else if (key === 'deactivate') void jalankan(() => svc.value.setStatus(row.id, '2'), `${n} dinonaktifkan.`, 'Gagal mengubah status.')
  else if (key === 'activate') void jalankan(() => svc.value.setStatus(row.id, '1'), `${n} diaktifkan.`, 'Gagal mengubah status.')
  else if (key === 'restore') void jalankan(() => svc.value.setStatus(row.id, '1'), `${n} dipulihkan.`, 'Gagal memulihkan.')
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
    notice.value = `${nama.value} dihapus; bisa dipulihkan lewat filter status Dihapus.`
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
  <section class="page-fill flex flex-col gap-4" :data-testid="tid('page')">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-slate-900">{{ nama }}</h1>
        <p class="text-sm text-slate-500">
          {{ jenis === 'popup' ? 'Pengumuman bergambar yang tampil sekali per sesi login selama periode berlaku.' : 'Gambar slider di beranda selama periode berlaku, diurut menurut urutan.' }}
        </p>
      </div>
      <button
        type="button"
        class="flex items-center gap-1.5 rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-white hover:bg-brand-primary/90"
        :data-testid="tid('add')"
        @click="openCreate"
      >
        <Plus class="h-4 w-4" /> Tambah {{ nama }}
      </button>
    </div>

    <p v-if="notice" class="rounded-md border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-800" role="status">{{ notice }}</p>
    <p v-if="error" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <div class="flex flex-wrap gap-3 rounded-lg border border-slate-200 bg-white p-3">
      <UiSelect
        v-model="statusFilter"
        :options="STATUS_FILTER"
        clearable
        size="sm"
        placeholder="Aktif & Tidak Aktif"
        aria-label="Filter status"
        class="w-52"
        :data-testid="tid('status')"
      />
    </div>

    <div class="table-fill overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3">Gambar</th>
            <th class="px-4 py-3">Keterangan</th>
            <th class="px-4 py-3">Periode</th>
            <th class="px-4 py-3">Urutan</th>
            <th class="px-4 py-3">Penerima</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading">
            <td colspan="7" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
          </tr>
          <tr v-else-if="items.length === 0">
            <td colspan="7" class="px-4 py-8 text-center text-slate-500">Belum ada {{ nama.toLowerCase() }} yang cocok.</td>
          </tr>
          <tr v-for="(row, i) in items" v-else :key="row.id" class="hover:bg-slate-50" :data-testid="tid(`row-${row.id}`)">
            <td class="px-4 py-3">
              <img v-if="row.gambar_url" :src="row.gambar_url" :alt="row.keterangan || nama" class="h-12 w-24 rounded object-cover" />
              <span v-else class="text-slate-400">—</span>
            </td>
            <td class="px-4 py-3 text-slate-800">{{ row.keterangan || '—' }}</td>
            <td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ fmt(row.tgl_mulai) }} – {{ fmt(row.tgl_akhir) }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.urutan }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.jumlah_penerima }}</td>
            <td class="px-4 py-3">
              <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="STATUS_CLASS[row.status]" :data-status="row.status">
                {{ STATUS_LABEL[row.status] }}
              </span>
            </td>
            <td class="px-4 py-3 text-right">
              <RowActionsMenu
                :actions="rowActions(row, i)"
                :label="`Aksi untuk ${row.keterangan || `${nama} ${row.id}`}`"
                :testid="`konten-${jenis}-actions-${row.id}`"
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
        <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 disabled:opacity-40" :disabled="page <= 1" @click="goTo(page - 1)">
          Sebelumnya
        </button>
        <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 disabled:opacity-40" :disabled="page >= totalPages" @click="goTo(page + 1)">
          Berikutnya
        </button>
      </div>
    </div>

    <KontenGambarFormDialog
      v-model:open="formOpen"
      :jenis="jenis"
      :row="editing"
      :opsi-unit-satker="opsiUnitSatker"
      :opsi-pegawai="opsiPegawai"
      @saved="onSaved"
    />

    <ConfirmDialog
      v-model:open="confirm.open"
      :title="`Hapus ${nama.toLowerCase()} ini?`"
      description="Data tidak dihapus permanen: statusnya menjadi Dihapus sehingga tidak lagi tampil, dan bisa dipulihkan."
      danger
      confirm-label="Hapus"
      :loading="confirm.loading"
      @confirm="onConfirmDelete"
    />
  </section>
</template>
