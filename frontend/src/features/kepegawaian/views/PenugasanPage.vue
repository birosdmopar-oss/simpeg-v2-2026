<script setup lang="ts">
/**
 * Penugasan Plt/Plh (B-07, pemilik WS-2, MAKE-012) — legacy `hr/rwy/jabatan/list_off_plt|plh`. Dua tab (Plt, Plh;
 * `?jenis=`), daftar dari `GET penugasan/{jenis}` (role 1 semua; role 3 lingkup satker/unit; 4/5 lihat), pencarian
 * nama/NIP, paginasi. Aksi baris lewat ⋮ (AGENTS.md §1): Edit → Unduh lampiran → Hapus (ConfirmDialog; hapus PERMANEN
 * ikut legacy, keputusan user 13-10-2026).
 * Tambah/Edit/Hapus hanya bila `can_create` (role 1).
 */
import { formatTanggalIndonesia as tgl } from '@/shared/utils/dateTime'
import { Plus } from 'lucide-vue-next'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiBadge, UiButton, UiCard, UiNotice, UiPagination, UiSearchInput, UiTabs } from '@/shared/ui'

import PenugasanFormDialog from '../components/PenugasanFormDialog.vue'
import { LABEL_PENUGASAN } from '../penugasan.config'
import { describeApiError } from '../services/apiErrors'
import { penugasanService } from '../services/penugasan.service'
import type { JenisPenugasan, PenugasanItem, PenugasanListResponse } from '../types'

const route = useRoute()
const router = useRouter()

const TABS = [
  { value: 'plt', label: 'Pelaksana Tugas (Plt)' },
  { value: 'plh', label: 'Pelaksana Harian (Plh)' },
]

const jenis = computed<JenisPenugasan>({
  get: () => (route.query.jenis === 'plh' ? 'plh' : 'plt'),
  set: (value) => void router.replace({ query: { ...route.query, jenis: value } }),
})

const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Plt/Plh' }]
const PER_PAGE = 20

const page = ref(1)
const search = ref('')
const data = ref<PenugasanListResponse | null>(null)
const memuat = ref(false)
const notice = ref<{ tone: 'danger' | 'success'; text: string } | null>(null)
const dialogOpen = ref(false)
const editing = ref<PenugasanItem | null>(null)
const deleting = ref<PenugasanItem | null>(null)
const deleteOpen = ref(false)
const busyId = ref<number | null>(null)
let token = 0
let searchTimer: ReturnType<typeof setTimeout> | undefined

async function muat(): Promise<void> {
  const saya = ++token
  memuat.value = true
  try {
    const hasil = await penugasanService.list(jenis.value, { page: page.value, per_page: PER_PAGE, search: search.value.trim() })
    if (saya === token) data.value = hasil
  } catch (e) {
    if (saya !== token) return
    data.value = null
    notice.value = { tone: 'danger', text: describeApiError(e).message }
  } finally {
    if (saya === token) memuat.value = false
  }
}

watch(
  jenis,
  () => {
    page.value = 1
    notice.value = null
    void muat()
  },
  { immediate: true },
)
watch(page, () => void muat())
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    void muat()
  }, 300)
})
onBeforeUnmount(() => clearTimeout(searchTimer))

const canWrite = computed(() => data.value?.can_create ?? false)
const label = computed(() => LABEL_PENUGASAN[jenis.value])

function actionsFor(row: PenugasanItem): RowAction[] {
  return [
    { key: 'edit', label: 'Edit', hidden: !canWrite.value, disabled: busyId.value === row.id },
    { key: 'lampiran', label: 'Unduh lampiran', hidden: !row.ada_lampiran, disabled: busyId.value === row.id },
    { key: 'hapus', label: 'Hapus', danger: true, hidden: !canWrite.value, disabled: busyId.value === row.id },
  ]
}

function onAction(key: string, row: PenugasanItem): void {
  if (key === 'edit') {
    editing.value = row
    dialogOpen.value = true
  } else if (key === 'lampiran') {
    void unduh(row)
  } else if (key === 'hapus') {
    deleting.value = row
    deleteOpen.value = true
  }
}

function tambah(): void {
  editing.value = null
  dialogOpen.value = true
}

async function unduh(row: PenugasanItem): Promise<void> {
  busyId.value = row.id
  try {
    const { blob, nama } = await penugasanService.lampiran(jenis.value, row.id)
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = nama
    document.body.appendChild(link)
    link.click()
    link.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (e) {
    notice.value = { tone: 'danger', text: describeApiError(e).message }
  } finally {
    busyId.value = null
  }
}

async function confirmDelete(): Promise<void> {
  const row = deleting.value
  deleteOpen.value = false
  if (!row) return
  busyId.value = row.id
  try {
    await penugasanService.remove(jenis.value, row.id)
    notice.value = { tone: 'success', text: `Penugasan ${label.value} ${row.nama_lengkap ?? row.nip} dihapus.` }
    await muat()
  } catch (e) {
    notice.value = { tone: 'danger', text: describeApiError(e).message }
  } finally {
    busyId.value = null
    deleting.value = null
  }
}

function onSaved(): void {
  notice.value = { tone: 'success', text: `Penugasan ${label.value} disimpan.` }
  void muat()
}

const jabatanRow = (row: PenugasanItem): string => row.jabatan ?? row.jabatan_koord ?? '—'
const periode = (row: PenugasanItem): string => [tgl(row.tgl_mulai), tgl(row.tgl_akhir)].filter(Boolean).join(' s.d. ') || '—'
const aktif = (row: PenugasanItem): boolean => String(row.status) === '1'
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="space-y-4" data-testid="penugasan-page">
      <UiTabs v-model="jenis" :items="TABS" aria-label="Jenis penugasan" />

      <UiCard :title="`Daftar ${label}`">
        <template #actions>
          <UiButton v-if="canWrite" data-testid="penugasan-tambah" @click="tambah">
            <Plus class="h-4 w-4" aria-hidden="true" /> Tambah {{ label }}
          </UiButton>
        </template>

        <div class="mb-4 max-w-sm">
          <UiSearchInput v-model="search" placeholder="Cari nama atau NIP" label="Cari penugasan" />
        </div>

        <UiNotice v-if="notice" :tone="notice.tone" class="mb-4" data-testid="penugasan-notice">{{ notice.text }}</UiNotice>

        <div class="scrollbar-slim overflow-x-auto">
          <table class="w-full min-w-[48rem] border-collapse text-left text-body1" data-testid="penugasan-table">
            <thead>
              <tr class="border-b border-slate-200 text-body2 font-semibold text-slate-900">
                <th scope="col" class="px-4 py-3">Pegawai</th>
                <th scope="col" class="px-4 py-3">Jabatan</th>
                <th scope="col" class="px-4 py-3">Unit / Satker</th>
                <th scope="col" class="px-4 py-3">Periode</th>
                <th scope="col" class="px-4 py-3">Status</th>
                <th scope="col" class="sticky right-0 bg-white px-4 py-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
              <tr v-if="memuat && !data">
                <td colspan="6" class="px-4 py-8 text-center text-body2 text-slate-500">Memuat…</td>
              </tr>
              <tr v-else-if="!data || data.items.length === 0">
                <td colspan="6" class="px-4 py-10 text-center text-body2 text-slate-500" data-testid="penugasan-empty">
                  Belum ada penugasan {{ label }}.
                </td>
              </tr>
              <tr v-for="row in data?.items ?? []" v-else :key="row.id" :data-testid="`penugasan-row-${row.id}`">
                <td class="px-4 py-3">
                  <span class="block font-medium text-slate-900">{{ row.nama_lengkap ?? '—' }}</span>
                  <span class="block text-body2 text-slate-500">{{ row.nip }}</span>
                </td>
                <td class="px-4 py-3 text-slate-700">{{ jabatanRow(row) }}</td>
                <td class="px-4 py-3 text-slate-700">{{ [row.unit, row.satker].filter(Boolean).join(' / ') || '—' }}</td>
                <td class="px-4 py-3 text-slate-700">{{ periode(row) }}</td>
                <td class="px-4 py-3">
                  <UiBadge :tone="aktif(row) ? 'success' : 'neutral'">{{ aktif(row) ? 'Aktif' : 'Tidak Aktif' }}</UiBadge>
                </td>
                <td class="sticky right-0 bg-white px-4 py-3 text-right">
                  <RowActionsMenu
                    :actions="actionsFor(row)"
                    :label="`Aksi untuk ${row.nama_lengkap ?? row.nip}`"
                    :testid="`penugasan-actions-${row.id}`"
                    @select="(key) => onAction(key, row)"
                  />
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <UiPagination
          v-if="data && data.meta.total > PER_PAGE"
          v-model:page="page"
          class="mt-4"
          :per-page="PER_PAGE"
          :total="data.meta.total"
        />
      </UiCard>
    </div>

    <PenugasanFormDialog v-model:open="dialogOpen" :jenis="jenis" :row="editing" @saved="onSaved" />

    <ConfirmDialog
      :open="deleteOpen"
      :title="`Hapus penugasan ${label}?`"
      :description="deleting ? `Penugasan ${label} ${deleting.nama_lengkap ?? deleting.nip} pada ${jabatanRow(deleting)} akan dihapus PERMANEN beserta lampirannya dan tidak dapat dipulihkan.` : ''"
      confirm-label="Ya, hapus"
      danger
      @update:open="(v) => (deleteOpen = v)"
      @confirm="confirmDelete"
    />
  </RedesignShell>
</template>
