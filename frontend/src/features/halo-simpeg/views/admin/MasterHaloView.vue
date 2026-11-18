<script setup lang="ts">
/**
 * Admin master Halo Simpeg (B3, role 1): Kategori / Petugas / Kontak (E-02, E-07; pola Master Data / Chatbot Manager, tanpa
 * frame Figma H8-9). Tabel `page-fill` + `table-fill` + ⋮ (AGENTS.md §1): **Edit → Aktifkan/Nonaktifkan** (`PATCH …/status`,
 * 0/1; `hidden` sesuai status) **→ Hapus** (`danger`, `ConfirmDialog`; Kategori/Petugas `disabled` bila `!bisa_hapus`, alasannya
 * sebagai `keterangan` item ⋮ — `alasan_tidak_bisa_hapus` BE untuk petugas, teks FE [V2] untuk kategori; AC-E08.16, review B3
 * R2). Kolom Status hanya badge (AGENTS.md §1 butir 5).
 * `peringatan` PATCH/PUT petugas (S8) → `UiNotice` nada peringatan; 422 `kategori_dipakai` / `petugas_terakhir` → pesan BE.
 * Daftar berhalaman dan tersaring di server (`status` 0/1, `q`).
 */
import { Pencil, Plus, Power, PowerOff, Trash2 } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { UiBadge, UiButton, UiCard, UiNotice, UiPagination, UiSearchInput, UiSelect, type SelectOption } from '@/shared/ui'

import MasterHaloFormDialog from '../../components/admin/MasterHaloFormDialog.vue'
import PetugasFormDialog from '../../components/admin/PetugasFormDialog.vue'
import { PER_PAGE_MASTER } from '../../constants'
import type { BagianAdminHalo } from '../../routes.halo'
import { kategoriHaloService, kontakHaloService, petugasHaloService } from '../../services/master.service'
import { muatPetugasSaya } from '../../stores/haloSimpeg'
import type { Kategori, Kontak, Petugas, StatusMaster } from '../../types'

type Row = Kategori | Petugas | Kontak

const props = defineProps<{ bagian: BagianAdminHalo }>()

const JUDUL = { kategori: 'Kategori', petugas: 'Petugas', kontak: 'Kontak' } as const
const DESKRIPSI = {
  kategori: 'Kategori pertanyaan helpdesk yang dipilih pegawai.',
  petugas: 'Akun petugas penjawab per kategori.',
  kontak: 'Kontak helpdesk yang tampil di halaman Halo Simpeg pegawai.',
} as const
const FILTER_STATUS: SelectOption[] = [
  { value: '1', label: 'Aktif' },
  { value: '0', label: 'Tidak Aktif' },
]

const svc = computed(() => ({ kategori: kategoriHaloService, petugas: petugasHaloService, kontak: kontakHaloService })[props.bagian])
const items = ref<Row[]>([])
const total = ref(0)
const page = ref(1)
const statusFilter = ref('')
const search = ref('')
const q = ref('')
const memuat = ref(false)
const busy = ref(false)
const notice = ref('')
const peringatan = ref('')
const failure = ref('')
const formOpen = ref(false)
const editing = ref<Row | null>(null)
const hapus = ref<{ open: boolean; row: Row | null; loading: boolean }>({ open: false, row: null, loading: false })
let debounce: ReturnType<typeof setTimeout> | null = null
let urutanMuat = 0

const pesan = (e: unknown, baku: string): string => (isApiError(e) ? e.message : baku)
const nomor = (i: number): number => (page.value - 1) * PER_PAGE_MASTER + i + 1
const nama = (row: Row): string => ('label' in row ? row.label : row.nama)

async function muat(): Promise<void> {
  const n = ++urutanMuat
  memuat.value = true
  failure.value = ''
  try {
    const status = statusFilter.value === '' ? null : (Number(statusFilter.value) as StatusMaster)
    const r = await svc.value.list({ page: page.value, per_page: PER_PAGE_MASTER, status, q: q.value })
    if (n !== urutanMuat) return
    items.value = r.items
    total.value = r.total
  } catch (e) {
    if (n !== urutanMuat) return
    items.value = []
    total.value = 0
    failure.value = pesan(e, `Gagal memuat ${JUDUL[props.bagian].toLowerCase()}.`)
  } finally {
    if (n === urutanMuat) memuat.value = false
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

function bersihkanNotice(): void {
  notice.value = ''
  peringatan.value = ''
  failure.value = ''
}

function tambah(): void {
  bersihkanNotice()
  editing.value = null
  formOpen.value = true
}

/** Review B3 R4: perubahan petugas (bisa akun admin sendiri) → `petugas/saya` disegarkan agar tab Antrean akurat. */
function segarkanPetugasSaya(): void {
  if (props.bagian === 'petugas') void muatPetugasSaya(true)
}

function onSaved(hasilPeringatan: string | null = null): void {
  segarkanPetugasSaya()
  notice.value = editing.value === null ? `${JUDUL[props.bagian]} berhasil ditambahkan.` : `${JUDUL[props.bagian]} berhasil diperbarui.`
  peringatan.value = hasilPeringatan ?? ''
  void muat()
}

async function ubahStatus(row: Row, status: StatusMaster): Promise<void> {
  bersihkanNotice()
  busy.value = true
  try {
    const hasil = await svc.value.setStatus(row.id, status)
    notice.value = `${JUDUL[props.bagian]} ${status === 1 ? 'diaktifkan' : 'dinonaktifkan'}.`
    peringatan.value = hasil.peringatan ?? ''
    segarkanPetugasSaya()
    await muat()
  } catch (e) {
    failure.value = pesan(e, 'Gagal mengubah status.')
  } finally {
    busy.value = false
  }
}

/** `bisa_hapus` dari BE (Kategori, Petugas); Kontak selalu boleh dihapus. */
const bisaHapus = (row: Row): boolean => !('bisa_hapus' in row) || row.bisa_hapus
function keteranganHapus(row: Row): string {
  if (bisaHapus(row)) return ''
  if ('alasan_tidak_bisa_hapus' in row && row.alasan_tidak_bisa_hapus) return row.alasan_tidak_bisa_hapus
  return 'Masih dipakai tiket atau petugas. Nonaktifkan bila tidak dipakai lagi.'
}

function aksiBaris(row: Row): RowAction[] {
  return [
    { key: 'edit', label: 'Edit', icon: Pencil },
    { key: 'activate', label: 'Aktifkan', icon: Power, hidden: row.status !== 0, disabled: busy.value },
    { key: 'deactivate', label: 'Nonaktifkan', icon: PowerOff, hidden: row.status !== 1, disabled: busy.value },
    { key: 'delete', label: 'Hapus', icon: Trash2, danger: true, disabled: busy.value || !bisaHapus(row), keterangan: keteranganHapus(row) || undefined },
  ]
}

function onAksi(row: Row, key: string): void {
  if (key === 'edit') {
    bersihkanNotice()
    editing.value = row
    formOpen.value = true
  } else if (key === 'activate') void ubahStatus(row, 1)
  else if (key === 'deactivate') void ubahStatus(row, 0)
  else if (key === 'delete' && bisaHapus(row)) {
    bersihkanNotice()
    hapus.value = { open: true, row, loading: false }
  }
}

async function konfirmasiHapus(): Promise<void> {
  const row = hapus.value.row
  if (!row) return
  hapus.value.loading = true
  try {
    await svc.value.hapus(row.id)
    notice.value = `${JUDUL[props.bagian]} berhasil dihapus.`
    segarkanPetugasSaya()
    await muat()
  } catch (e) {
    // Daftar dimuat ulang (flag bisa_hapus terbaru) lalu pesan BE dipasang — muat() mengosongkan galat sebelumnya.
    const galat = pesan(e, 'Gagal menghapus.')
    await muat()
    failure.value = galat
  } finally {
    hapus.value.loading = false
    hapus.value.open = false
  }
}

onMounted(() => void muat())
onBeforeUnmount(() => {
  if (debounce) clearTimeout(debounce)
})

const kolom = computed(() => (props.bagian === 'kategori' ? 5 : props.bagian === 'petugas' ? 7 : 6))
</script>

<template>
  <section class="page-fill flex flex-col gap-4" :data-testid="`halo-${bagian}-page`">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-h2 font-semibold text-slate-900">{{ JUDUL[bagian] }} Halo Simpeg</h1>
        <p class="text-body2 text-slate-500">{{ DESKRIPSI[bagian] }}</p>
      </div>
      <UiButton :data-testid="`halo-${bagian}-tambah`" @click="tambah"><Plus class="h-4 w-4" aria-hidden="true" /> Tambah {{ JUDUL[bagian] }}</UiButton>
    </div>

    <UiNotice v-if="notice" tone="success">{{ notice }}</UiNotice>
    <UiNotice v-if="peringatan" tone="warning" :data-testid="`halo-${bagian}-peringatan`">{{ peringatan }}</UiNotice>
    <UiNotice v-if="failure" tone="danger">
      <span role="alert" :data-testid="`halo-${bagian}-galat`">{{ failure }}</span>
    </UiNotice>

    <UiCard :title="`Daftar ${JUDUL[bagian]}`" flush fill class="flex min-h-0 flex-1 flex-col">
      <div class="flex flex-wrap items-end gap-3 border-b border-slate-100 p-4">
        <UiSelect v-model="statusFilter" :options="FILTER_STATUS" clearable size="sm" placeholder="Semua status" aria-label="Filter status" class="w-44" />
        <UiSearchInput v-model="search" :placeholder="`Cari ${JUDUL[bagian].toLowerCase()}`" :label="`Cari ${JUDUL[bagian].toLowerCase()}`" class="min-w-[200px] flex-1" />
      </div>
      <div class="table-fill scrollbar-slim overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="w-16 px-4 py-3">No</th>
              <template v-if="bagian === 'kategori'">
                <th class="px-4 py-3">Nama</th>
                <th class="px-4 py-3">Jumlah Petugas</th>
              </template>
              <template v-else-if="bagian === 'petugas'">
                <th class="px-4 py-3">Akun</th>
                <th class="px-4 py-3">Email</th>
                <th class="px-4 py-3">No HP</th>
                <th class="px-4 py-3">Kategori</th>
              </template>
              <template v-else>
                <th class="px-4 py-3">Nama</th>
                <th class="px-4 py-3">Nomor</th>
                <th class="px-4 py-3">Email</th>
              </template>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="memuat && items.length === 0">
              <td :colspan="kolom" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
            </tr>
            <tr v-else-if="items.length === 0">
              <td :colspan="kolom" class="px-4 py-8 text-center text-slate-500" :data-testid="`halo-${bagian}-kosong`">
                Belum ada {{ JUDUL[bagian].toLowerCase() }} yang cocok.
              </td>
            </tr>
            <tr v-for="(row, i) in items" v-else :key="row.id" class="align-top hover:bg-slate-50" :data-testid="`halo-${bagian}-row-${row.id}`">
              <td class="px-4 py-3 text-slate-500">{{ nomor(i) }}</td>
              <template v-if="'jumlah_petugas' in row">
                <td class="px-4 py-3 text-slate-800">{{ row.nama }}</td>
                <td class="px-4 py-3 text-slate-600">{{ row.jumlah_petugas }}</td>
              </template>
              <template v-else-if="'label' in row">
                <td class="max-w-xs px-4 py-3 text-slate-800">{{ row.label }}</td>
                <td class="px-4 py-3 text-slate-600">{{ row.email }}</td>
                <td class="px-4 py-3 text-slate-600">{{ row.no_hp ?? '—' }}</td>
                <td class="px-4 py-3">
                  <div class="flex max-w-xs flex-wrap gap-1">
                    <UiBadge v-for="k in row.kategori" :key="k.id" :tone="k.status === 1 ? 'info' : 'neutral'" size="sm" :data-status="k.status">
                      {{ k.nama }}<template v-if="k.status !== 1"> (Tidak Aktif)</template>
                    </UiBadge>
                  </div>
                </td>
              </template>
              <template v-else>
                <td class="px-4 py-3 text-slate-800">{{ row.nama }}</td>
                <td class="px-4 py-3 text-slate-600">{{ row.kontak }}</td>
                <td class="px-4 py-3 text-slate-600">{{ row.email ?? '—' }}</td>
              </template>
              <td class="px-4 py-3">
                <UiBadge :tone="row.status === 1 ? 'success' : 'neutral'" size="sm" :data-status="row.status">
                  {{ row.status === 1 ? 'Aktif' : 'Tidak Aktif' }}
                </UiBadge>
              </td>
              <td class="px-4 py-3 text-right">
                <RowActionsMenu :actions="aksiBaris(row)" :label="`Aksi untuk ${nama(row)}`" :testid="`halo-${bagian}-actions-${row.id}`" @select="onAksi(row, $event)" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="total > PER_PAGE_MASTER" class="p-4"><UiPagination v-model:page="page" :per-page="PER_PAGE_MASTER" :total="total" /></div>
    </UiCard>

    <PetugasFormDialog v-if="bagian === 'petugas'" v-model:open="formOpen" :row="(editing as Petugas | null)" @saved="onSaved" />
    <MasterHaloFormDialog v-else v-model:open="formOpen" :jenis="bagian" :row="(editing as Kategori | Kontak | null)" @saved="onSaved()" />
    <ConfirmDialog
      :open="hapus.open"
      :title="`Hapus ${JUDUL[bagian].toLowerCase()} ini?`"
      :description="`${hapus.row ? nama(hapus.row) : ''} dihapus permanen.`"
      confirm-label="Hapus"
      danger
      :loading="hapus.loading"
      @update:open="(v) => !hapus.loading && (hapus.open = v)"
      @confirm="konfirmasiHapus"
    />
  </section>
</template>
