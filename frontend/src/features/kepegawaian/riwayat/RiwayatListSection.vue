<script setup lang="ts">
/**
 * Tab riwayat engine berbentuk daftar: kartu dengan judul, pencarian, tombol "+ Tambah …", tabel (kolom dari
 * konfigurasi jenis + badge status verifikasi + kolom Aksi), paginasi, dialog tambah/ubah, proses (Setujui/Tolak),
 * dan konfirmasi hapus. Data dari `GET pegawai/{nip}/riwayat/{jenis}`.
 *
 * Hak aksi HANYA dari descriptor tab (`can_create/can_edit/can_delete/can_process`); penguncian per baris (mis. baris
 * Disetujui untuk UL_PEGAWAI) ditegakkan server — galatnya ditampilkan. Aksi baris lewat menu ⋮ (AGENTS.md §1):
 * Edit → Setujui/Tolak → Hapus.
 */
import { FileSearch, Plus } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'

import ApprovalDialog from '@/shared/components/ApprovalDialog.vue'
import { approvalRowActions, type ApprovalAksi, type ApprovalPayload } from '@/shared/components/approvalActions'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import StatusBadge from '@/shared/components/StatusBadge.vue'
import { UiButton, UiCard, UiNotice, UiPagination, UiSearchInput } from '@/shared/ui'

import { describeApiError, splitValidationErrors, type ApiFailure, type RiwayatValidationErrors } from '../services/apiErrors'
import { STATUS_RIWAYAT, type JenisEngine, type RiwayatRow, type RiwayatTabDescriptor } from '../types'

import RiwayatFormDialog from './RiwayatFormDialog.vue'
import type { ColumnDef, RiwayatJenisConfig } from './riwayat.config'
import { riwayatService, type BerkasMap } from './riwayat.service'

const props = defineProps<{ nip: string; config: RiwayatJenisConfig; descriptor: RiwayatTabDescriptor }>()

const PER_PAGE = 5

const jenis = computed(() => props.config.jenis as JenisEngine)
const fieldNames = computed(() => props.config.fields.map((f) => f.name))

const rows = ref<RiwayatRow[]>([])
const loading = ref(true)
const failure = ref<ApiFailure | null>(null)
const search = ref('')
const page = ref(1)
const notice = ref<{ tone: 'success' | 'danger'; text: string } | null>(null)

const formOpen = ref(false)
const editing = ref<RiwayatRow | null>(null)
const saving = ref(false)
const formErrors = ref<RiwayatValidationErrors | null>(null)

const deleting = ref<RiwayatRow | null>(null)
/** Dipisah dari baris terpilih: klik konfirmasi menutup dialog SEBELUM handler confirm jalan. */
const deleteOpen = ref(false)

const processing = ref<RiwayatRow | null>(null)
const processOpen = ref(false)
const processMode = ref<ApprovalAksi>('setujui')
const processBusy = ref(false)
const processError = ref('')

const canCreate = computed(() => props.descriptor.can_create && props.config.fields.length > 0)
const canEdit = computed(() => props.descriptor.can_edit && props.config.fields.length > 0)
const hasActions = computed(() => canEdit.value || props.descriptor.can_delete || props.descriptor.can_process)

const rowId = (row: RiwayatRow): string => String(row[props.config.primaryKey] ?? '')
const rowStatus = (row: RiwayatRow): number => Number(row.status ?? STATUS_RIWAYAT.MENUNGGU)

let requestId = 0
async function load(): Promise<void> {
  const current = ++requestId
  loading.value = true
  failure.value = null
  try {
    const result = await riwayatService.list(props.nip, jenis.value)
    if (current !== requestId) return
    rows.value = result
  } catch (error) {
    if (current !== requestId) return
    rows.value = []
    failure.value = describeApiError(error)
  } finally {
    if (current === requestId) loading.value = false
  }
}
onMounted(load)
watch(
  () => [props.nip, props.config.jenis],
  () => {
    page.value = 1
    search.value = ''
    notice.value = null
    void load()
  },
)
watch(search, () => (page.value = 1))

const filtered = computed(() => {
  const needle = search.value.trim().toLowerCase()
  if (!needle) return rows.value
  return rows.value.filter((r) => props.config.columns.some((c) => cell(r, c).toLowerCase().includes(needle)))
})
const paged = computed(() => filtered.value.slice((page.value - 1) * PER_PAGE, page.value * PER_PAGE))

function cell(row: RiwayatRow, col: ColumnDef): string {
  const raw = row[col.key]
  if (raw === null || raw === undefined || raw === '') return '—'
  const value = String(raw)
  if (col.options) return col.options.find((o) => o.value === value)?.label ?? value
  if (col.format === 'date') {
    const d = new Date(`${value.slice(0, 10)}T00:00:00`)
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })
  }
  return value
}

function rowLabel(row: RiwayatRow): string {
  const first = props.config.columns[0]
  const text = first ? cell(row, first) : ''
  return text && text !== '—' ? text : props.config.title
}

function rowActions(row: RiwayatRow): RowAction[] {
  const busy = processBusy.value && processing.value === row
  return [
    { key: 'edit', label: 'Edit', hidden: !canEdit.value },
    ...approvalRowActions({ canProcess: props.descriptor.can_process, status: rowStatus(row), busy }),
    { key: 'hapus', label: 'Hapus', danger: true, hidden: !props.descriptor.can_delete },
  ]
}

function openCreate(): void {
  editing.value = null
  formErrors.value = null
  formOpen.value = true
}

function onAction(key: string, row: RiwayatRow): void {
  if (key === 'edit') {
    editing.value = row
    formErrors.value = null
    formOpen.value = true
  } else if (key === 'setujui' || key === 'tolak') {
    processing.value = row
    processMode.value = key
    processError.value = ''
    processOpen.value = true
  } else if (key === 'hapus') {
    deleting.value = row
    deleteOpen.value = true
  }
}

const initialValues = computed<Record<string, string> | null>(() => {
  const row = editing.value
  if (!row) return null
  return Object.fromEntries(props.config.fields.map((f) => [f.name, row[f.name] === null || row[f.name] === undefined ? '' : String(row[f.name])]))
})

async function onSubmit(values: Record<string, string>, berkas: BerkasMap): Promise<void> {
  saving.value = true
  formErrors.value = null
  try {
    if (editing.value) await riwayatService.update(props.nip, jenis.value, rowId(editing.value), props.config.fields, values, berkas)
    else await riwayatService.create(props.nip, jenis.value, props.config.fields, values, berkas)
    formOpen.value = false
    page.value = 1
    notice.value = { tone: 'success', text: `Data ${props.config.singular} tersimpan.` }
    await load()
  } catch (error) {
    const split = splitValidationErrors(error, fieldNames.value, props.config.lampiran.map((l) => l.id_riwayat))
    const failed = describeApiError(error)
    formErrors.value = failed.kind === 'validation' ? split : { fields: {}, berkas: {}, general: [failed.message] }
  } finally {
    saving.value = false
  }
}

async function confirmDelete(): Promise<void> {
  deleteOpen.value = false
  const row = deleting.value
  if (!row) return
  try {
    await riwayatService.remove(props.nip, jenis.value, rowId(row))
    notice.value = { tone: 'success', text: `Data ${rowLabel(row)} dihapus.` }
    await load()
    if (page.value > 1 && paged.value.length === 0) page.value -= 1
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
    await riwayatService.process(props.nip, jenis.value, rowId(row), payload)
    processOpen.value = false
    notice.value = { tone: 'success', text: payload.aksi === 'setujui' ? `Data ${rowLabel(row)} disetujui.` : `Data ${rowLabel(row)} ditolak.` }
    await load()
  } catch (error) {
    const split = splitValidationErrors(error, ['reason_note', 'aksi'])
    processError.value = split.fields.reason_note ?? split.general[0] ?? describeApiError(error).message
  } finally {
    processBusy.value = false
  }
}

const colspan = computed(() => props.config.columns.length + 1 + (hasActions.value ? 1 : 0))
</script>

<template>
  <div class="space-y-4" :data-testid="`riwayat-section-${config.jenis}`">
    <UiNotice v-if="notice" :tone="notice.tone" dismissible data-testid="riwayat-notice" @dismiss="notice = null">{{ notice.text }}</UiNotice>

    <UiCard :title="config.title" :subtitle="config.subtitle" flush>
      <template #actions>
        <UiButton v-if="canCreate" data-testid="riwayat-add" @click="openCreate">
          <template #icon-left><Plus class="h-4 w-4" aria-hidden="true" /></template>
          Tambah {{ config.singular }}
        </UiButton>
      </template>

      <UiNotice v-if="failure" :tone="failure.kind === 'unavailable' ? 'info' : 'danger'" class="mx-5 mt-3" data-testid="riwayat-failure">
        {{ failure.message }}
      </UiNotice>

      <template v-else>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-4">
          <div class="w-64 max-w-full"><UiSearchInput v-model="search" placeholder="Search" :label="`Cari ${config.title.toLowerCase()}`" /></div>
          <p class="text-body2 text-slate-500" aria-live="polite">{{ filtered.length }} data</p>
        </div>

        <div class="scrollbar-slim overflow-x-auto">
          <table class="w-full min-w-[40rem] border-collapse text-left text-body1" data-testid="riwayat-table">
            <thead>
              <tr class="border-y border-slate-200 text-body2 font-semibold text-slate-900">
                <th v-for="col in config.columns" :key="col.key" scope="col" class="px-5 py-3">{{ col.label }}</th>
                <th scope="col" class="px-5 py-3">Status</th>
                <th v-if="hasActions" scope="col" class="px-5 py-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
              <template v-if="loading">
                <tr v-for="n in 3" :key="`s-${n}`" aria-hidden="true">
                  <td :colspan="colspan" class="px-5 py-4"><div class="h-4 w-2/3 animate-pulse rounded bg-slate-200" /></td>
                </tr>
              </template>
              <tr v-else-if="filtered.length === 0">
                <td :colspan="colspan" class="px-5 py-12 text-center" data-testid="riwayat-empty">
                  <FileSearch class="mx-auto mb-3 h-8 w-8 text-slate-300" aria-hidden="true" />
                  <p class="text-body1 font-medium text-slate-700">{{ search ? 'Tidak ada data yang cocok' : `Belum ada ${config.title.toLowerCase()}` }}</p>
                  <p v-if="canCreate && !search" class="text-body2 text-slate-500">Gunakan "Tambah {{ config.singular }}" untuk menambahkan.</p>
                </td>
              </tr>
              <template v-else>
                <tr v-for="row in paged" :key="rowId(row)" class="transition-colors hover:bg-slate-50/70" :data-testid="`riwayat-row-${rowId(row)}`">
                  <td v-for="col in config.columns" :key="col.key" class="px-5 py-3 text-slate-800">{{ cell(row, col) }}</td>
                  <td class="px-5 py-3">
                    <StatusBadge :status="rowStatus(row)" />
                    <p v-if="rowStatus(row) === STATUS_RIWAYAT.DITOLAK && row.reason_note" class="mt-1 max-w-56 text-caption text-slate-500">
                      {{ row.reason_note }}
                    </p>
                  </td>
                  <td v-if="hasActions" class="px-5 py-3 text-right">
                    <RowActionsMenu
                      :actions="rowActions(row)"
                      :label="`Aksi untuk ${rowLabel(row)}`"
                      :testid="`riwayat-actions-${rowId(row)}`"
                      @select="(key) => onAction(key, row)"
                    />
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>

        <div class="border-t border-slate-200 px-5 py-4"><UiPagination v-model:page="page" :per-page="PER_PAGE" :total="filtered.length" /></div>
      </template>
    </UiCard>

    <RiwayatFormDialog
      v-model:open="formOpen"
      :config="config"
      :initial="initialValues"
      :server-errors="formErrors"
      :busy="saving"
      @submit="onSubmit"
    />

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
      :title="`Hapus ${config.singular}?`"
      :description="deleting ? `Data ${rowLabel(deleting)} akan dihapus.` : ''"
      confirm-label="Ya, hapus"
      danger
      @update:open="(v) => (deleteOpen = v)"
      @confirm="confirmDelete"
    />
  </div>
</template>
