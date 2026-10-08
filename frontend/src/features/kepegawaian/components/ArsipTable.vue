<script setup lang="ts">
/**
 * Tabel arsip lampiran satu baris riwayat (B-18, MAKE-009): daftar `document_attachment`, unggah berkas baru, unduh,
 * dan hapus keras. Data dari `GET pegawai/{nip}/lampiran?id_riwayat=&id_entri=`.
 *
 * Hak aksi dari pemanggil (descriptor tab riwayat: unggah = can_create/can_edit, hapus = can_delete/can_edit); server
 * tetap menegakkan izin Definisi pemilik kode + lingkup pegawai — galatnya ditampilkan. Pemeriksaan jenis & ukuran
 * di sini hanya UX; validasi yang mengikat ada di backend (jenis dibaca dari isi berkas).
 *
 * Unggah selalu MENAMBAH lampiran; "ganti" = unggah yang baru lalu hapus yang lama (tidak ada endpoint ganti).
 * Aksi baris lewat menu ⋮ (AGENTS.md §1): Unduh → Hapus.
 */
import { FileSearch, Upload } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'

import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { UiButton, UiNotice } from '@/shared/ui'
import { formatApiDateTime } from '@/shared/utils/dateTime'

import { describeApiError, splitValidationErrors, type ApiFailure } from '../services/apiErrors'
import { lampiranService, type LampiranTarget } from '../services/lampiran.service'
import type { AturanLampiran, DocumentAttachment } from '../types'

const props = withDefaults(
  defineProps<{
    nip: string
    target: LampiranTarget
    /** Aturan kode ini (jenis & batas untuk petunjuk/cek awal); null = tanpa cek awal. */
    aturan?: AturanLampiran | null
    canUpload?: boolean
    canDelete?: boolean
    /** Judul kecil di atas tabel, mis. "Arsip Ijazah". */
    title?: string
  }>(),
  { aturan: null, canUpload: false, canDelete: false, title: 'Arsip' },
)

const emit = defineEmits<{ changed: [rows: DocumentAttachment[]] }>()

const rows = ref<DocumentAttachment[]>([])
const loading = ref(true)
const failure = ref<ApiFailure | null>(null)
const notice = ref<{ tone: 'success' | 'danger'; text: string } | null>(null)
const uploading = ref(false)
const busyId = ref<number | null>(null)

const deleting = ref<DocumentAttachment | null>(null)
/** Dipisah dari baris terpilih: klik konfirmasi menutup dialog SEBELUM handler confirm jalan. */
const deleteOpen = ref(false)

const fileInput = ref<HTMLInputElement | null>(null)

const accept = computed(() => (props.aturan ? props.aturan.ekstensi.map((ext) => `.${ext}`).join(',') : undefined))
const hint = computed(() =>
  props.aturan ? `Jenis ${props.aturan.ekstensi.map((e) => e.toUpperCase()).join(', ')}, maksimal ${props.aturan.batas_mb} MB.` : '',
)

let requestId = 0
async function load(): Promise<void> {
  const current = ++requestId
  loading.value = true
  failure.value = null
  try {
    const result = await lampiranService.list(props.nip, props.target)
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
  () => [props.nip, props.target.id_riwayat, props.target.id_entri],
  () => {
    notice.value = null
    void load()
  },
)

function nama(row: DocumentAttachment): string {
  return row.display_name || row.basename || `Lampiran ${row.id_attachment}`
}

function ukuran(bytes: number | null): string {
  if (bytes === null || bytes === undefined) return '—'
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1).replace('.', ',')} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`
}

function rowActions(row: DocumentAttachment): RowAction[] {
  const busy = busyId.value === row.id_attachment
  return [
    { key: 'unduh', label: 'Unduh', disabled: busy },
    { key: 'hapus', label: 'Hapus', danger: true, hidden: !props.canDelete, disabled: busy },
  ]
}

function onAction(key: string, row: DocumentAttachment): void {
  if (key === 'unduh') void download(row)
  else if (key === 'hapus') {
    deleting.value = row
    deleteOpen.value = true
  }
}

function pickFile(): void {
  fileInput.value?.click()
}

async function onPick(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  input.value = ''
  if (!file) return

  const aturan = props.aturan
  if (aturan) {
    const ext = file.name.split('.').pop()?.toLowerCase() ?? ''
    if (!aturan.ekstensi.includes(ext)) {
      notice.value = { tone: 'danger', text: `Jenis file .${ext || '?'} tidak diperbolehkan.` }
      return
    }
    if (file.size > aturan.batas_mb * 1024 * 1024) {
      notice.value = { tone: 'danger', text: `Ukuran file melebihi ${aturan.batas_mb} MB.` }
      return
    }
  }

  uploading.value = true
  notice.value = null
  try {
    await lampiranService.upload(props.nip, props.target, file)
    notice.value = { tone: 'success', text: `${file.name} diunggah.` }
    await load()
    emit('changed', rows.value)
  } catch (error) {
    const failed = describeApiError(error)
    const split = splitValidationErrors(error)
    notice.value = { tone: 'danger', text: failed.kind === 'validation' ? (split.general[0] ?? failed.message) : failed.message }
  } finally {
    uploading.value = false
  }
}

async function download(row: DocumentAttachment): Promise<void> {
  busyId.value = row.id_attachment
  try {
    const blob = await lampiranService.download(props.nip, row.id_attachment)
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = nama(row)
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  } catch (error) {
    notice.value = { tone: 'danger', text: describeApiError(error).message }
  } finally {
    busyId.value = null
  }
}

async function confirmDelete(): Promise<void> {
  deleteOpen.value = false
  const row = deleting.value
  if (!row) return
  busyId.value = row.id_attachment
  try {
    await lampiranService.remove(props.nip, row.id_attachment)
    notice.value = { tone: 'success', text: `${nama(row)} dihapus.` }
    await load()
    emit('changed', rows.value)
  } catch (error) {
    notice.value = { tone: 'danger', text: describeApiError(error).message }
  } finally {
    busyId.value = null
  }
}

const colspan = 4
</script>

<template>
  <section class="space-y-3" :data-testid="`arsip-${target.id_riwayat}-${target.id_entri}`">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h3 class="text-body1 font-semibold text-slate-900">{{ title }}</h3>
        <p v-if="hint" class="text-caption text-slate-500">{{ hint }}</p>
      </div>
      <template v-if="canUpload">
        <input ref="fileInput" type="file" class="sr-only" :accept="accept" data-testid="arsip-input" :aria-label="`Pilih berkas ${title}`" @change="onPick" />
        <UiButton variant="secondary" :loading="uploading" data-testid="arsip-upload" @click="pickFile">
          <template #icon-left><Upload class="h-4 w-4" aria-hidden="true" /></template>
          Unggah berkas
        </UiButton>
      </template>
    </div>

    <UiNotice v-if="notice" :tone="notice.tone" dismissible data-testid="arsip-notice" @dismiss="notice = null">{{ notice.text }}</UiNotice>

    <UiNotice v-if="failure" :tone="failure.kind === 'unavailable' ? 'info' : 'danger'" data-testid="arsip-failure">{{ failure.message }}</UiNotice>

    <div v-else class="scrollbar-slim overflow-x-auto rounded-lg border border-slate-200">
      <table class="w-full min-w-[32rem] border-collapse text-left text-body2" data-testid="arsip-table">
        <thead>
          <tr class="border-b border-slate-200 font-semibold text-slate-900">
            <th scope="col" class="px-4 py-2.5">Nama berkas</th>
            <th scope="col" class="px-4 py-2.5">Ukuran</th>
            <th scope="col" class="px-4 py-2.5">Diunggah</th>
            <th scope="col" class="px-4 py-2.5 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          <template v-if="loading">
            <tr v-for="n in 2" :key="`s-${n}`" aria-hidden="true">
              <td :colspan="colspan" class="px-4 py-3"><div class="h-4 w-2/3 animate-pulse rounded bg-slate-200" /></td>
            </tr>
          </template>
          <tr v-else-if="rows.length === 0">
            <td :colspan="colspan" class="px-4 py-8 text-center" data-testid="arsip-empty">
              <FileSearch class="mx-auto mb-2 h-6 w-6 text-slate-300" aria-hidden="true" />
              <p class="text-slate-600">Belum ada berkas.</p>
            </td>
          </tr>
          <template v-else>
            <tr v-for="row in rows" :key="row.id_attachment" :data-testid="`arsip-row-${row.id_attachment}`">
              <td class="max-w-72 truncate px-4 py-2.5 text-slate-800" :title="nama(row)">{{ nama(row) }}</td>
              <td class="px-4 py-2.5 text-slate-600">{{ ukuran(row.file_size) }}</td>
              <td class="px-4 py-2.5 text-slate-600">{{ formatApiDateTime(row.created_at) || '—' }}</td>
              <td class="px-4 py-2.5 text-right">
                <RowActionsMenu
                  :actions="rowActions(row)"
                  :label="`Aksi untuk ${nama(row)}`"
                  :testid="`lampiran-actions-${row.id_attachment}`"
                  @select="(key) => onAction(key, row)"
                />
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <ConfirmDialog
      :open="deleteOpen"
      title="Hapus berkas?"
      :description="deleting ? `${nama(deleting)} akan dihapus permanen.` : ''"
      confirm-label="Ya, hapus"
      danger
      @update:open="(v) => (deleteOpen = v)"
      @confirm="confirmDelete"
    />
  </section>
</template>
