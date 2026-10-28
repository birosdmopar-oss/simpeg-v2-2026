<script setup lang="ts">
/**
 * Petugas Approval Cuti (Modul C, role 1, 3; PRD Fase 4 §6.2): daftar aturan `pa_layanan` jenis cuti dengan petugas
 * pemegang saat ini, serta tambah dan ubah/hapus per baris lewat ⋮. Role 3 hanya melihat unit pemohon sendiri; ubah/hapus
 * unit lain ditolak server (403). Ubah hanya untuk baris status 1 (ditegakkan server).
 */
import { Pencil, Plus, Trash2 } from 'lucide-vue-next'
import { onMounted, ref, watch } from 'vue'

import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { useStickyActionsShadow } from '@/shared/composables/useStickyActionsShadow'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiButton, UiCard, UiNotice, UiPagination } from '@/shared/ui'

import { describeApiError } from '@/features/kepegawaian/services/apiErrors'

import PetugasApprovalFormDialog from '../components/PetugasApprovalFormDialog.vue'
import { cutiPetugasApprovalService, type PetugasApprovalCuti } from '../services/cutiPetugasApproval.service'

const crumbs = [
  { label: 'Home', to: { name: 'home' } },
  { label: 'Layanan' },
  { label: 'Cuti', to: { name: 'layanan-cuti' } },
  { label: 'Petugas Approval' },
]
const PER_PAGE = 20

const page = ref(1)
const daftar = ref<{ items: PetugasApprovalCuti[]; total: number } | null>(null)
const memuat = ref(false)
const failure = ref<string | null>(null)
const notice = ref('')

const tableScroller = ref<HTMLElement | null>(null)
const { actionsShadow, updateActionsShadow } = useStickyActionsShadow(tableScroller, [daftar, memuat])

async function muat(): Promise<void> {
  memuat.value = true
  failure.value = null
  try {
    daftar.value = await cutiPetugasApprovalService.list({ page: page.value, per_page: PER_PAGE })
  } catch (err) {
    daftar.value = null
    failure.value = describeApiError(err).message
  } finally {
    memuat.value = false
  }
}

watch(page, () => void muat())

// --- Aksi baris (⋮) ---------------------------------------------------------------------------------------------------
const formOpen = ref(false)
const formRow = ref<PetugasApprovalCuti | null>(null)
const hapus = ref<{ open: boolean; row: PetugasApprovalCuti | null; loading: boolean }>({ open: false, row: null, loading: false })

function aksiBaris(): RowAction[] {
  return [
    { key: 'edit', label: 'Edit', icon: Pencil },
    { key: 'hapus', label: 'Hapus', icon: Trash2, danger: true },
  ]
}

function onAksi(row: PetugasApprovalCuti, key: string): void {
  notice.value = ''
  if (key === 'edit') {
    formRow.value = row
    formOpen.value = true
  } else if (key === 'hapus') {
    hapus.value = { open: true, row, loading: false }
  }
}

function tambah(): void {
  notice.value = ''
  formRow.value = null
  formOpen.value = true
}

function onTersimpan(): void {
  notice.value = formRow.value ? 'Petugas approval cuti diperbarui.' : 'Petugas approval cuti ditambahkan.'
  void muat()
}

async function konfirmasiHapus(): Promise<void> {
  const row = hapus.value.row
  if (!row) return
  hapus.value.loading = true
  try {
    await cutiPetugasApprovalService.hapus(row.id)
    notice.value = 'Petugas approval cuti dihapus.'
  } catch (err) {
    failure.value = describeApiError(err).message
  } finally {
    hapus.value = { open: false, row: null, loading: false }
    void muat()
  }
}

/** Nama pemohon ringkas: unit pemohon, lalu group jabatan atau golongan/ruang. */
function pemohon(r: PetugasApprovalCuti): string {
  const pangkat = r.gol_ruang_pemohon ? `gol/ruang ${r.gol_ruang_pemohon}` : (r.sub_group_jabatan_pemohon ?? '')
  return [r.unit_pemohon, r.group_jabatan_pemohon, pangkat].filter(Boolean).join(' · ') || '-'
}

/** Label ⋮ yang bermakna: nama (atau jabatan) petugas + unit pemohon; id hanya bila keduanya kosong. */
function labelBaris(r: PetugasApprovalCuti): string {
  return [r.petugas.nama ?? r.petugas.jabatan, r.unit_pemohon].filter(Boolean).join(' · ') || String(r.id)
}

onMounted(() => void muat())
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="page-fill flex flex-col gap-4" data-testid="pa-page">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-h2 font-semibold text-slate-900">Petugas Approval Cuti</h1>
          <p class="text-body2 text-slate-500">Petugas yang memproses cuti per unit dan golongan pemohon.</p>
        </div>
        <UiButton data-testid="pa-tambah" @click="tambah"><Plus class="h-4 w-4" aria-hidden="true" /> Tambah Petugas</UiButton>
      </div>

      <UiNotice v-if="notice" tone="success" data-testid="pa-notice">{{ notice }}</UiNotice>
      <UiNotice v-if="failure" tone="danger" data-testid="pa-failure">{{ failure }}</UiNotice>

      <UiCard title="Daftar Petugas" flush fill class="flex min-h-0 flex-1 flex-col">
        <div ref="tableScroller" class="table-fill scrollbar-slim overflow-x-auto" data-testid="pa-table-scroll" @scroll.passive="updateActionsShadow">
          <table class="min-w-full text-sm" data-testid="pa-tabel">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th class="min-w-[16rem] px-4 py-3">Pemohon</th>
                <th class="whitespace-nowrap px-4 py-3">Jenis Pegawai</th>
                <th class="min-w-[14rem] px-4 py-3">Petugas</th>
                <th class="min-w-[14rem] px-4 py-3">Unit / Satker Petugas</th>
                <th class="sticky right-0 z-10 whitespace-nowrap bg-slate-50 px-4 py-3 text-right" :class="{ 'shadow-sticky-end': actionsShadow }">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-if="memuat">
                <td colspan="5" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
              </tr>
              <tr v-else-if="!daftar || daftar.items.length === 0">
                <td colspan="5" class="px-4 py-8 text-center text-slate-500" data-testid="pa-kosong">Belum ada petugas approval cuti.</td>
              </tr>
              <tr v-for="row in daftar?.items ?? []" v-else :key="row.id" class="group hover:bg-slate-50" :data-testid="`pa-baris-${row.id}`">
                <td class="px-4 py-3 text-slate-700">{{ pemohon(row) }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row.jenis_pegawai ?? '-' }}</td>
                <td class="px-4 py-3">
                  <p class="font-medium text-slate-800">{{ row.petugas.nama ?? row.petugas.jabatan ?? '-' }}</p>
                  <p v-if="row.petugas.nama" class="text-caption text-slate-500">{{ row.petugas.jabatan ?? '' }}</p>
                </td>
                <td class="px-4 py-3 text-slate-600">{{ [row.unit_petugas, row.satker_petugas].filter(Boolean).join(' · ') || '-' }}</td>
                <td class="sticky right-0 z-10 bg-white px-4 py-3 text-right group-hover:bg-slate-50" :class="{ 'shadow-sticky-end': actionsShadow }">
                  <RowActionsMenu :actions="aksiBaris()" :label="`Aksi untuk petugas approval ${labelBaris(row)}`" :testid="`pa-actions-${row.id}`" @select="onAksi(row, $event)" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="daftar && daftar.total > PER_PAGE" class="p-4">
          <UiPagination v-model:page="page" :per-page="PER_PAGE" :total="daftar.total" />
        </div>
      </UiCard>

      <PetugasApprovalFormDialog v-model:open="formOpen" :row="formRow" @saved="onTersimpan" />
      <ConfirmDialog
        :open="hapus.open"
        title="Hapus Petugas Approval"
        description="Hapus aturan petugas approval cuti ini? Data dihapus permanen dan tercatat di audit."
        confirm-label="Ya, hapus"
        danger
        :loading="hapus.loading"
        @update:open="(v) => !hapus.loading && (hapus.open = v)"
        @confirm="konfirmasiHapus"
      />
    </div>
  </RedesignShell>
</template>
