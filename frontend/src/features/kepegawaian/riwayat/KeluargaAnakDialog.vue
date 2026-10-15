<script setup lang="ts">
/**
 * Dialog anak satu baris riwayat keluarga (B-16, WS-1 M4 MAKE-007): daftar `detail_anak` (sub-resource
 * `…/keluarga/{id}/anak`), tambah/ubah lewat RiwayatFieldForm (akte lahir kode 3 wajib saat tambah dan saat ubah bila
 * anak belum punya akte), arsip akte per anak (ArsipTable), dan hapus (ConfirmDialog). Aksi baris lewat menu ⋮
 * (AGENTS.md §1): Edit → Akte → Hapus.
 *
 * Hak dari pemanggil (AGENTS.md §1.4): `izinUbah` (descriptor `can_edit`) menentukan aksi tampil (hidden bila tidak berhak);
 * `terkunciUbah`/`terkunciHapus` (keluarga Disetujui terkunci untuk role pengguna, salinan aturan Definisi) membuat aksi
 * tampil nonaktif. Server tetap menegakkan izin, lingkup, dan kunci baris (galatnya ditampilkan).
 */
import { FileSearch, Plus, X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { UiButton, UiNotice } from '@/shared/ui'

import ArsipTable from '../components/ArsipTable.vue'
import { describeApiError, splitValidationErrors, type RiwayatValidationErrors } from '../services/apiErrors'
import { lampiranService } from '../services/lampiran.service'
import type { RiwayatRow } from '../types'

import { anakService, LAMPIRAN_AKTE_ANAK } from './anak.service'
import RiwayatFieldForm from './RiwayatFieldForm.vue'
import { A, D, N, T, type LampiranDef } from './riwayat.config'
import type { BerkasMap } from './riwayat.service'

const props = defineProps<{
  open: boolean
  nip: string
  idKeluarga: string
  subject: string
  /** Berhak mengubah keluarga (izin `ubah` + lingkup): tambah/ubah/hapus anak & akte tampil. */
  izinUbah: boolean
  /** Keluarga Disetujui terkunci untuk mengubah anak/akte (aksi tampil nonaktif). */
  terkunciUbah?: boolean
  /** Keluarga Disetujui terkunci untuk menghapus anak (aksi tampil nonaktif). */
  terkunciHapus?: boolean
}>()
const emit = defineEmits<{ 'update:open': [value: boolean]; changed: [] }>()

/** Isian `detail_anak` (salinan AnakKeluarga::definisiAnak() backend). */
const FIELDS = [
  N('urutan', 'Anak Ke-', { required: true }),
  T('nama', 'Nama Anak', { required: true }),
  T('tempat_lahir', 'Tempat Lahir', { required: true }),
  D('tgl_lahir', 'Tanggal Lahir', { required: true }),
  A('keterangan', 'Keterangan', { max: 255 }),
]
const AKTE: LampiranDef = { id_riwayat: LAMPIRAN_AKTE_ANAK, label: 'Akte Lahir', wajib: true, batas_mb: 5, ekstensi: ['jpg', 'jpeg', 'png', 'gif', 'pdf'] }

type Mode = 'daftar' | 'form' | 'akte'

const rows = ref<RiwayatRow[]>([])
const loading = ref(false)
const failure = ref('')
const notice = ref<{ tone: 'success' | 'danger'; text: string } | null>(null)
const mode = ref<Mode>('daftar')
const editing = ref<RiwayatRow | null>(null)
const saving = ref(false)
const formErrors = ref<RiwayatValidationErrors | null>(null)
const akteTersimpan = ref<Record<number, number>>({})
const deleting = ref<RiwayatRow | null>(null)
const deleteOpen = ref(false)

const idAnak = (row: RiwayatRow): string => String(row.id_detail_anak ?? '')
const namaAnak = (row: RiwayatRow): string => String(row.nama ?? `Anak ${idAnak(row)}`)

async function load(): Promise<void> {
  loading.value = true
  failure.value = ''
  try {
    rows.value = await anakService.list(props.nip, props.idKeluarga)
  } catch (error) {
    rows.value = []
    failure.value = describeApiError(error).message
  } finally {
    loading.value = false
  }
}

watch(
  () => [props.open, props.idKeluarga] as const,
  ([open]) => {
    if (!open) return
    mode.value = 'daftar'
    notice.value = null
    void load()
  },
  { immediate: true },
)

function rowActions(): RowAction[] {
  return [
    { key: 'edit', label: 'Edit', hidden: !props.izinUbah, disabled: props.terkunciUbah },
    { key: 'akte', label: 'Akte' },
    { key: 'hapus', label: 'Hapus', danger: true, hidden: !props.izinUbah, disabled: props.terkunciHapus },
  ]
}

const initial = computed<Record<string, string> | null>(() =>
  editing.value ? Object.fromEntries(FIELDS.map((f) => [f.name, editing.value?.[f.name] == null ? '' : String(editing.value[f.name])])) : null,
)

function openCreate(): void {
  editing.value = null
  formErrors.value = null
  akteTersimpan.value = {}
  mode.value = 'form'
}

async function onAction(key: string, row: RiwayatRow): Promise<void> {
  editing.value = row
  if (key === 'edit') {
    formErrors.value = null
    akteTersimpan.value = {}
    mode.value = 'form'
    try {
      akteTersimpan.value = { [LAMPIRAN_AKTE_ANAK]: (await lampiranService.list(props.nip, { id_riwayat: LAMPIRAN_AKTE_ANAK, id_entri: idAnak(row) })).length }
    } catch {
      // Tidak diketahui → tidak diwajibkan di form; server tetap menegakkan (422 errors.berkas).
    }
  } else if (key === 'akte') {
    mode.value = 'akte'
  } else if (key === 'hapus') {
    deleting.value = row
    deleteOpen.value = true
  }
}

async function onSubmit(values: Record<string, string>, berkas: BerkasMap): Promise<void> {
  saving.value = true
  formErrors.value = null
  const akte = berkas[LAMPIRAN_AKTE_ANAK] ?? null
  try {
    if (editing.value) await anakService.update(props.nip, props.idKeluarga, idAnak(editing.value), FIELDS, values, akte)
    else await anakService.create(props.nip, props.idKeluarga, FIELDS, values, akte)
    notice.value = { tone: 'success', text: `Data anak ${values.nama ?? ''} tersimpan.` }
    mode.value = 'daftar'
    await load()
    emit('changed')
  } catch (error) {
    const split = splitValidationErrors(error, FIELDS.map((f) => f.name))
    const failed = describeApiError(error)
    // errors.berkas endpoint anak = akte → pesan di bawah isian akte.
    const pesanAkte = isApiError(error) ? error.errors?.berkas?.[0] : undefined
    formErrors.value =
      failed.kind === 'validation'
        ? { fields: split.fields, berkas: pesanAkte ? { [LAMPIRAN_AKTE_ANAK]: pesanAkte } : {}, general: split.general.filter((m) => m !== pesanAkte) }
        : { fields: {}, berkas: {}, general: [failed.message] }
  } finally {
    saving.value = false
  }
}

async function confirmDelete(): Promise<void> {
  deleteOpen.value = false
  const row = deleting.value
  if (!row) return
  try {
    await anakService.remove(props.nip, props.idKeluarga, idAnak(row))
    notice.value = { tone: 'success', text: `Data ${namaAnak(row)} dihapus.` }
    await load()
    emit('changed')
  } catch (error) {
    notice.value = { tone: 'danger', text: describeApiError(error).message }
  }
}

function tanggal(value: unknown): string {
  const d = new Date(`${String(value ?? '').slice(0, 10)}T00:00:00`)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })
}
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[calc(100%-2rem)] max-w-3xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="keluarga-anak-dialog"
      >
        <div class="mb-5 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">Anak — {{ subject }}</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">Data anak dan akte lahir.</DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <UiNotice v-if="notice" :tone="notice.tone" dismissible class="mb-4" data-testid="anak-notice" @dismiss="notice = null">{{ notice.text }}</UiNotice>

        <template v-if="mode === 'daftar'">
          <div class="mb-3 flex justify-end">
            <UiButton v-if="izinUbah" :disabled="terkunciUbah" data-testid="anak-add" @click="openCreate">
              <template #icon-left><Plus class="h-4 w-4" aria-hidden="true" /></template>
              Tambah Anak
            </UiButton>
          </div>
          <UiNotice v-if="failure" tone="danger" data-testid="anak-failure">{{ failure }}</UiNotice>
          <div v-else class="scrollbar-slim overflow-x-auto rounded-lg border border-slate-200">
            <table class="w-full min-w-[32rem] border-collapse text-left text-body2" data-testid="anak-table">
              <thead>
                <tr class="border-b border-slate-200 font-semibold text-slate-900">
                  <th scope="col" class="px-4 py-2.5">Anak Ke-</th>
                  <th scope="col" class="px-4 py-2.5">Nama</th>
                  <th scope="col" class="px-4 py-2.5">Tempat, Tanggal Lahir</th>
                  <th scope="col" class="sticky right-0 bg-white px-4 py-2.5 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-200">
                <tr v-if="loading"><td colspan="4" class="px-4 py-3"><div class="h-4 w-2/3 animate-pulse rounded bg-slate-200" /></td></tr>
                <tr v-else-if="rows.length === 0">
                  <td colspan="4" class="px-4 py-8 text-center" data-testid="anak-empty">
                    <FileSearch class="mx-auto mb-2 h-6 w-6 text-slate-300" aria-hidden="true" />
                    <p class="text-slate-600">Belum ada data anak.</p>
                  </td>
                </tr>
                <tr v-for="row in rows" v-else :key="idAnak(row)" :data-testid="`anak-row-${idAnak(row)}`">
                  <td class="px-4 py-2.5">{{ row.urutan }}</td>
                  <td class="px-4 py-2.5 text-slate-800">{{ row.nama }}</td>
                  <td class="px-4 py-2.5 text-slate-600">{{ row.tempat_lahir || '—' }}, {{ tanggal(row.tgl_lahir) }}</td>
                  <td class="sticky right-0 bg-white px-4 py-2.5 text-right">
                    <RowActionsMenu
                      :actions="rowActions()"
                      :label="`Aksi untuk ${namaAnak(row)}`"
                      :testid="`anak-actions-${idAnak(row)}`"
                      @select="(key) => onAction(key, row)"
                    />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>

        <RiwayatFieldForm
          v-else-if="mode === 'form'"
          :key="editing ? idAnak(editing) : 'baru'"
          :fields="FIELDS"
          :lampiran="[AKTE]"
          :mode="editing ? 'edit' : 'create'"
          :initial="initial ?? {}"
          :lampiran-tersimpan="akteTersimpan"
          :server-errors="formErrors"
          :busy="saving"
          :submit-label="editing ? 'Simpan Perubahan' : 'Simpan'"
          testid="anak"
          @submit="onSubmit"
          @cancel="mode = 'daftar'"
        />

        <div v-else-if="editing" class="space-y-4">
          <ArsipTable
            :nip="nip"
            :target="{ id_riwayat: AKTE.id_riwayat, id_entri: idAnak(editing) }"
            :aturan="AKTE"
            :title="`Akte Lahir ${namaAnak(editing)}`"
            :can-upload="izinUbah && !terkunciUbah"
            :can-delete="izinUbah && !terkunciUbah"
          />
          <div class="flex justify-end"><UiButton variant="secondary" appearance="soft" data-testid="anak-kembali" @click="mode = 'daftar'">Kembali</UiButton></div>
        </div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>

  <ConfirmDialog
    :open="deleteOpen"
    title="Hapus data anak?"
    :description="deleting ? `Data ${namaAnak(deleting)} beserta akte lahirnya akan dihapus.` : ''"
    confirm-label="Ya, hapus"
    danger
    @update:open="(v) => (deleteOpen = v)"
    @confirm="confirmDelete"
  />
</template>
