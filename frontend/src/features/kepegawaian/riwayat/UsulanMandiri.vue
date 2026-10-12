<script setup lang="ts">
/**
 * Mode halaman usulan mandiri mesin riwayat FE (WS1.md §3.4.5/§3.4.7, M4 MAKE-007) — dipakai halaman Usulan Karpeg/Karis.
 * Layout ikut legacy (`karpeg/list.php`, `list_ad.php`), style redesign:
 *
 * - Role pengusul (izin `tambah`, 2/7) → "usulan saya" (`GET usulan/{jenis}/saya`): tombol Ajukan (Karis/Karsu hanya bila
 *   punya riwayat keluarga aktif), tabel usulan sendiri, ⋮ Edit → Berkas → Hapus; Edit/Hapus nonaktif untuk baris
 *   Disetujui (kunci baris legacy).
 * - Role lain (1/4/5) → antrean (`GET usulan/{jenis}`): filter status, tabel semua usulan, ⋮ Berkas → Setujui/Tolak
 *   (`can_process`, baris Menunggu; ApprovalDialog, alasan wajib saat tolak).
 *
 * Berkas kolom diunduh lewat `…/{id}/berkas/{kolom}`. Hapus = status 10 (ConfirmDialog). Pesan galat baku (apiErrors).
 */
import { FileSearch, Plus, X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, onMounted, ref, watch } from 'vue'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import ApprovalDialog from '@/shared/components/ApprovalDialog.vue'
import { approvalRowActions, type ApprovalAksi, type ApprovalPayload } from '@/shared/components/approvalActions'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import StatusBadge from '@/shared/components/StatusBadge.vue'
import { UiButton, UiCard, UiNotice, UiSelect } from '@/shared/ui'
import { formatApiDateTime } from '@/shared/utils/dateTime'

import { describeApiError, splitValidationErrors, type ApiFailure } from '../services/apiErrors'
import { STATUS_RIWAYAT, STATUS_RIWAYAT_LABELS, type RiwayatRow } from '../types'

import { riwayatService, type BerkasMap } from './riwayat.service'
import type { UsulanConfig } from './usulan.config'
import UsulanFormDialog from './UsulanFormDialog.vue'
import { usulanService, type UsulanAntrean, type UsulanSaya } from './usulan.service'

const props = defineProps<{ config: UsulanConfig }>()

const auth = useAuthStore()

const PENGUSUL = [2, 7]
const modeSaya = computed(() => auth.role !== null && PENGUSUL.includes(auth.role))

const saya = ref<UsulanSaya | null>(null)
const antrean = ref<UsulanAntrean | null>(null)
const loading = ref(true)
const failure = ref<ApiFailure | null>(null)
const notice = ref<{ tone: 'success' | 'danger'; text: string } | null>(null)
const statusFilter = ref('')

const formOpen = ref(false)
const editing = ref<RiwayatRow | null>(null)
const saving = ref(false)
const formErrors = ref<{ fields: Record<string, string>; umum: string[] } | null>(null)

const berkasRow = ref<RiwayatRow | null>(null)
const berkasOpen = ref(false)

const deleting = ref<RiwayatRow | null>(null)
const deleteOpen = ref(false)

const processing = ref<RiwayatRow | null>(null)
const processOpen = ref(false)
const processMode = ref<ApprovalAksi>('setujui')
const processBusy = ref(false)
const processError = ref('')

const pk = computed(() => `id_riwayat_${props.config.jenis}`)
const rows = computed<RiwayatRow[]>(() => (modeSaya.value ? saya.value?.items : antrean.value?.items) ?? [])
const rowId = (row: RiwayatRow): string => String(row[pk.value] ?? '')
const rowStatus = (row: RiwayatRow): number => Number(row.status ?? STATUS_RIWAYAT.MENUNGGU)
const rowNip = (row: RiwayatRow): string => String(row.nip ?? '')
const rowLabel = (row: RiwayatRow): string =>
  modeSaya.value ? `${props.config.title} ${formatApiDateTime(String(row.created_at ?? '')) || rowId(row)}` : `${props.config.title} ${String(row.nama ?? rowNip(row))}`

const judul = computed(() => {
  const jk = Number(saya.value?.pegawai.jenis_kelamin ?? 0) as 1 | 2
  return props.config.titleMenurutJenisKelamin && (jk === 1 || jk === 2) ? props.config.titleMenurutJenisKelamin[jk] : props.config.title
})

const STATUS_OPTIONS = [STATUS_RIWAYAT.MENUNGGU, STATUS_RIWAYAT.DISETUJUI, STATUS_RIWAYAT.DITOLAK].map((v) => ({ value: String(v), label: STATUS_RIWAYAT_LABELS[v] }))

let requestId = 0
async function load(): Promise<void> {
  const current = ++requestId
  loading.value = true
  failure.value = null
  try {
    if (modeSaya.value) {
      const hasil = await usulanService.saya(props.config.jenis)
      if (current === requestId) saya.value = hasil
    } else {
      const hasil = await usulanService.antrean(props.config.jenis, statusFilter.value)
      if (current === requestId) antrean.value = hasil
    }
  } catch (error) {
    if (current === requestId) failure.value = describeApiError(error)
  } finally {
    if (current === requestId) loading.value = false
  }
}
onMounted(load)
watch(statusFilter, () => void load())
watch(() => props.config.jenis, () => void load())

function rowActions(row: RiwayatRow): RowAction[] {
  const disetujui = rowStatus(row) === STATUS_RIWAYAT.DISETUJUI
  if (modeSaya.value) {
    return [
      { key: 'edit', label: 'Edit', hidden: !saya.value?.can_edit, disabled: disetujui },
      { key: 'berkas', label: 'Berkas' },
      { key: 'hapus', label: 'Hapus', danger: true, hidden: !saya.value?.can_delete, disabled: disetujui },
    ]
  }
  return [
    { key: 'berkas', label: 'Berkas' },
    ...approvalRowActions({ canProcess: Boolean(antrean.value?.can_process), status: rowStatus(row), busy: processBusy.value && processing.value === row }),
  ]
}

function onAction(key: string, row: RiwayatRow): void {
  if (key === 'edit') {
    editing.value = row
    formErrors.value = null
    formOpen.value = true
  } else if (key === 'berkas') {
    berkasRow.value = row
    berkasOpen.value = true
  } else if (key === 'hapus') {
    deleting.value = row
    deleteOpen.value = true
  } else if (key === 'setujui' || key === 'tolak') {
    processing.value = row
    processMode.value = key
    processError.value = ''
    processOpen.value = true
  }
}

function openCreate(): void {
  editing.value = null
  formErrors.value = null
  formOpen.value = true
}

async function onSubmit(values: Record<string, string>, berkas: BerkasMap): Promise<void> {
  const nip = saya.value?.pegawai.nip ?? ''
  saving.value = true
  formErrors.value = null
  try {
    if (editing.value) await riwayatService.update(nip, props.config.jenis, rowId(editing.value), props.config.fields, values, berkas)
    else await riwayatService.create(nip, props.config.jenis, props.config.fields, values, berkas)
    formOpen.value = false
    notice.value = { tone: 'success', text: `${judul.value}: usulan tersimpan dan menunggu proses.` }
    await load()
  } catch (error) {
    const kunci = [...props.config.fields.map((f) => f.name), ...props.config.berkas.map((b) => `berkas.${b.kolom}`)]
    const split = splitValidationErrors(error, kunci)
    const failed = describeApiError(error)
    formErrors.value = failed.kind === 'validation' ? { fields: split.fields, umum: split.general } : { fields: {}, umum: [failed.message] }
  } finally {
    saving.value = false
  }
}

async function confirmDelete(): Promise<void> {
  deleteOpen.value = false
  const row = deleting.value
  if (!row) return
  try {
    await riwayatService.remove(rowNip(row), props.config.jenis, rowId(row))
    notice.value = { tone: 'success', text: 'Usulan dihapus.' }
    await load()
  } catch (error) {
    notice.value = { tone: 'danger', text: describeApiError(error).message }
  }
}

async function onProcess(payload: ApprovalPayload): Promise<void> {
  const row = processing.value
  if (!row) return
  processBusy.value = true
  processError.value = ''
  try {
    await riwayatService.process(rowNip(row), props.config.jenis, rowId(row), payload)
    processOpen.value = false
    notice.value = { tone: 'success', text: payload.aksi === 'setujui' ? 'Usulan disetujui.' : 'Usulan ditolak.' }
    await load()
  } catch (error) {
    const split = splitValidationErrors(error, ['reason_note', 'aksi', 'status'])
    processError.value = split.fields.reason_note ?? split.fields.status ?? split.general[0] ?? describeApiError(error).message
  } finally {
    processBusy.value = false
  }
}

async function unduh(row: RiwayatRow, kolom: string, label: string): Promise<void> {
  try {
    const blob = await usulanService.unduh(rowNip(row), props.config.jenis, rowId(row), kolom)
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `${label}.${String(row[kolom] ?? '').split('.').pop() ?? 'bin'}`
    document.body.appendChild(link)
    link.click()
    link.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (error) {
    notice.value = { tone: 'danger', text: describeApiError(error).message }
  }
}

const jumlahBerkas = (row: RiwayatRow): number => props.config.berkas.filter((b) => String(row[b.kolom] ?? '') !== '').length
</script>

<template>
  <div class="space-y-4" :data-testid="`usulan-${config.jenis}`">
    <UiNotice v-if="notice" :tone="notice.tone" dismissible data-testid="usulan-notice" @dismiss="notice = null">{{ notice.text }}</UiNotice>

    <UiCard :title="modeSaya ? `Usulan ${judul}` : `Antrean Usulan ${config.title}`" :subtitle="modeSaya ? 'Daftar usulan Anda.' : 'Usulan seluruh pegawai.'" flush>
      <template #actions>
        <UiButton v-if="modeSaya && saya?.can_create" :disabled="!saya.syarat_terpenuhi" data-testid="usulan-add" @click="openCreate">
          <template #icon-left><Plus class="h-4 w-4" aria-hidden="true" /></template>
          Ajukan
        </UiButton>
      </template>

      <UiNotice v-if="modeSaya && saya && !saya.syarat_terpenuhi" tone="info" class="mx-5 mt-3" data-testid="usulan-syarat">
        Lengkapi data riwayat keluarga terlebih dahulu sebelum mengajukan {{ judul }}.
      </UiNotice>

      <UiNotice v-if="failure" :tone="failure.kind === 'unavailable' ? 'info' : 'danger'" class="mx-5 mt-3" data-testid="usulan-failure">{{ failure.message }}</UiNotice>

      <template v-else>
        <div v-if="!modeSaya" class="mt-3 flex flex-wrap items-center gap-3 border-t border-slate-200 px-5 py-4">
          <div class="w-56"><UiSelect v-model="statusFilter" :options="STATUS_OPTIONS" placeholder="Semua status" clearable aria-label="Saring status" data-field="status" /></div>
        </div>

        <div class="scrollbar-slim overflow-x-auto">
          <table class="w-full min-w-[44rem] border-collapse text-left text-body1" data-testid="usulan-table">
            <thead>
              <tr class="border-y border-slate-200 text-body2 font-semibold text-slate-900">
                <template v-if="!modeSaya">
                  <th scope="col" class="px-5 py-3">NIP</th>
                  <th scope="col" class="px-5 py-3">Nama</th>
                  <th scope="col" class="px-5 py-3">Satuan/Unit Kerja</th>
                </template>
                <th scope="col" class="px-5 py-3">Tgl. Permohonan</th>
                <th v-if="config.fields.length" scope="col" class="px-5 py-3">Jenis Permohonan</th>
                <th scope="col" class="px-5 py-3">Berkas</th>
                <th scope="col" class="px-5 py-3">Status</th>
                <th scope="col" class="px-5 py-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
              <tr v-if="loading"><td colspan="8" class="px-5 py-4"><div class="h-4 w-2/3 animate-pulse rounded bg-slate-200" /></td></tr>
              <tr v-else-if="rows.length === 0">
                <td colspan="8" class="px-5 py-12 text-center" data-testid="usulan-empty">
                  <FileSearch class="mx-auto mb-3 h-8 w-8 text-slate-300" aria-hidden="true" />
                  <p class="text-body1 font-medium text-slate-700">Belum ada usulan</p>
                </td>
              </tr>
              <tr v-for="row in rows" v-else :key="rowId(row)" :data-testid="`usulan-row-${rowId(row)}`">
                <template v-if="!modeSaya">
                  <td class="px-5 py-3 text-slate-800">{{ row.nip }}</td>
                  <td class="px-5 py-3 text-slate-800">{{ [row.glr_awal, row.nama, row.glr_akhir].filter(Boolean).join(' ') }}</td>
                  <td class="px-5 py-3 text-slate-600">{{ row.satker || row.unit || '—' }}</td>
                </template>
                <td class="px-5 py-3 text-slate-800">{{ formatApiDateTime(String(row.created_at ?? '')) || '—' }}</td>
                <td v-if="config.fields.length" class="px-5 py-3 text-slate-800">{{ row.jenis_permohonan ?? '—' }}</td>
                <td class="px-5 py-3 text-slate-600">{{ jumlahBerkas(row) }} berkas</td>
                <td class="px-5 py-3">
                  <StatusBadge :status="rowStatus(row)" />
                  <p v-if="rowStatus(row) === STATUS_RIWAYAT.DITOLAK && row.reason_note" class="mt-1 max-w-56 text-caption text-slate-500">{{ row.reason_note }}</p>
                </td>
                <td class="px-5 py-3 text-right">
                  <RowActionsMenu
                    :actions="rowActions(row)"
                    :label="`Aksi untuk ${rowLabel(row)}`"
                    :testid="`usulan-actions-${rowId(row)}`"
                    @select="(key) => onAction(key, row)"
                  />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </UiCard>

    <UsulanFormDialog v-model:open="formOpen" :config="config" :row="editing" :busy="saving" :server-errors="formErrors" @submit="onSubmit" />

    <DialogRoot :open="berkasOpen" @update:open="(v) => (berkasOpen = v)">
      <DialogPortal>
        <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
        <DialogContent
          class="fixed left-1/2 top-1/2 z-50 w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
          data-testid="usulan-berkas-dialog"
          :aria-describedby="undefined"
        >
          <div class="mb-4 flex items-start justify-between gap-3">
            <DialogTitle class="text-h5 text-slate-900">Berkas {{ berkasRow ? rowLabel(berkasRow) : '' }}</DialogTitle>
            <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
              <X class="h-5 w-5" aria-hidden="true" />
            </DialogClose>
          </div>
          <ul v-if="berkasRow" class="divide-y divide-slate-200 rounded-lg border border-slate-200">
            <li v-for="b in config.berkas" :key="b.kolom" class="flex items-center justify-between gap-3 px-4 py-2.5 text-body2">
              <span class="text-slate-800">{{ b.label }}</span>
              <UiButton
                v-if="berkasRow[b.kolom]"
                variant="secondary"
                appearance="soft"
                :data-testid="`usulan-unduh-${b.kolom}`"
                @click="unduh(berkasRow, b.kolom, b.label)"
              >
                Unduh
              </UiButton>
              <span v-else class="text-slate-400">—</span>
            </li>
          </ul>
        </DialogContent>
      </DialogPortal>
    </DialogRoot>

    <ApprovalDialog
      v-model:open="processOpen"
      :mode="processMode"
      :subject="processing ? rowLabel(processing) : ''"
      :loading="processBusy"
      :error="processError"
      @confirm="onProcess"
    />

    <ConfirmDialog
      :open="deleteOpen"
      title="Hapus usulan?"
      :description="deleting ? `${rowLabel(deleting)} akan dihapus.` : ''"
      confirm-label="Ya, hapus"
      danger
      @update:open="(v) => (deleteOpen = v)"
      @confirm="confirmDelete"
    />
  </div>
</template>
