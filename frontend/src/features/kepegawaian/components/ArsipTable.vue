<script setup lang="ts">
/**
 * Tabel arsip lampiran satu baris riwayat (B-18, MAKE-009): daftar `document_attachment`, unggah berkas baru, unduh,
 * dan hapus keras. Data dari `GET pegawai/{nip}/lampiran?id_riwayat=&id_entri=`.
 *
 * Hak aksi dari pemanggil (descriptor tab riwayat: unggah = can_create/can_edit, hapus = can_delete/can_edit); server
 * tetap menegakkan izin Definisi pemilik kode + lingkup pegawai — galatnya ditampilkan. Pemeriksaan jenis & ukuran
 * di sini hanya UX; validasi yang mengikat ada di backend (jenis dibaca dari isi berkas).
 *
 * Unggah selalu MENAMBAH lampiran; "ganti" = unggah yang baru dulu, lalu hapus yang lama (tidak ada endpoint ganti).
 * Lampiran terakhir kode ber-`wajib` tidak bisa dihapus (server 422 `errors.berkas`): item Hapus-nya disabled. Selama
 * satu aksi berjalan (unduh/hapus), SEMUA item Hapus disabled supaya dua hapus tidak bisa menghabiskan lampiran wajib;
 * 422 dari server (mis. hitungan di server berbeda) tampil dengan pesan `errors.berkas`-nya.
 * Aksi baris lewat menu ⋮ (AGENTS.md §1): Unduh → Hapus.
 */
import { FileSearch, FileText, Upload } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { UiButton, UiNotice, UiTooltip } from '@/shared/ui'
import { formatApiDateTime } from '@/shared/utils/dateTime'
import { fileExtensionError } from '@/shared/utils/fileExtension'

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
    /** Kolom tambahan saat unggah (aditif MAKE-014, mis. jenis arsip Data Umum → `tag`). */
    isianUnggah?: Record<string, string>
    /** Unggah ditahan (mis. jenis arsip belum dipilih); `alasan` tampil sebagai petunjuk. */
    unggahDitahan?: string
    /** Tampilkan kolom "Jenis" (`tag`). */
    tampilTag?: boolean
  }>(),
  { aturan: null, canUpload: false, canDelete: false, title: 'Arsip', isianUnggah: () => ({}), unggahDitahan: '', tampilTag: false },
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

/** Lampiran terakhir kode wajib: harus diganti (unggah baru dulu), bukan dihapus. */
const terakhirWajib = computed(() => Boolean(props.aturan?.wajib) && rows.value.length <= 1)

function rowActions(row: DocumentAttachment): RowAction[] {
  const busy = busyId.value === row.id_attachment
  // Hapus dikunci untuk semua baris selama ada aksi yang berjalan (bukan hanya baris itu).
  const adaProses = busyId.value !== null
  return [
    { key: 'unduh', label: 'Unduh', disabled: busy },
    { key: 'hapus', label: 'Hapus', danger: true, hidden: !props.canDelete, disabled: adaProses || terakhirWajib.value },
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
  if (file) await unggah(file)
}

/** Seret-lepas berkas ke area unggah (CR-049 D-F6); aturan & pesan sama dengan memilih berkas. */
const seret = ref(false)
function onDrop(event: DragEvent): void {
  seret.value = false
  if (!props.canUpload || props.unggahDitahan || uploading.value) return
  const file = event.dataTransfer?.files?.[0]
  if (file) void unggah(file)
}

/** Pratinjau berkas yang sedang diunggah (gambar kecil atau nama + ukuran). */
const sedangDiunggah = ref<{ nama: string; ukuran: number; url: string | null } | null>(null)

function lepasPratinjau(): void {
  if (sedangDiunggah.value?.url) URL.revokeObjectURL(sedangDiunggah.value.url)
  sedangDiunggah.value = null
}
onBeforeUnmount(lepasPratinjau)

async function unggah(file: File): Promise<void> {
  const aturan = props.aturan
  if (aturan) {
    const salahJenis = fileExtensionError(file.name, aturan.ekstensi)
    if (salahJenis !== null) {
      notice.value = { tone: 'danger', text: salahJenis }
      return
    }
    if (file.size > aturan.batas_mb * 1024 * 1024) {
      notice.value = { tone: 'danger', text: `Ukuran file melebihi ${aturan.batas_mb} MB.` }
      return
    }
  }

  uploading.value = true
  notice.value = null
  const gambar = /\.(jpe?g|png|gif|webp)$/i.test(file.name) && typeof URL.createObjectURL === 'function'
  sedangDiunggah.value = { nama: file.name, ukuran: file.size, url: gambar ? URL.createObjectURL(file) : null }
  try {
    await lampiranService.upload(props.nip, props.target, file, props.isianUnggah)
    notice.value = { tone: 'success', text: `${file.name} diunggah.` }
    await load()
    emit('changed', rows.value)
  } catch (error) {
    const failed = describeApiError(error)
    const split = splitValidationErrors(error)
    notice.value = { tone: 'danger', text: failed.kind === 'validation' ? (split.general[0] ?? Object.values(split.fields)[0] ?? failed.message) : failed.message }
  } finally {
    uploading.value = false
    lepasPratinjau()
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
    // Dicabut tertunda: sebagian browser baru membaca blob setelah click() kembali.
    setTimeout(() => URL.revokeObjectURL(url), 1000)
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
    const failed = describeApiError(error)
    const split = splitValidationErrors(error)
    notice.value = { tone: 'danger', text: failed.kind === 'validation' ? (split.general[0] ?? Object.values(split.fields)[0] ?? failed.message) : failed.message }
  } finally {
    busyId.value = null
  }
}

const colspan = computed(() => (props.tampilTag ? 5 : 4))
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
        <UiButton
          appearance="soft"
          :loading="uploading"
          :disabled="Boolean(unggahDitahan)"
          :aria-describedby="unggahDitahan ? `arsip-tahan-${target.id_riwayat}` : undefined"
          data-testid="arsip-upload"
          @click="pickFile"
        >
          <template #icon-left><Upload class="h-4 w-4" aria-hidden="true" /></template>
          Unggah berkas
        </UiButton>
      </template>
      <div
        v-if="canUpload"
        class="flex w-full flex-col items-center justify-center gap-1 rounded-lg border border-dashed px-4 py-4 text-center text-body2 transition"
        :class="seret ? 'border-brand-tertiary bg-brand-tertiary/5 text-brand-tertiary' : 'border-slate-300 text-slate-500'"
        data-testid="arsip-dropzone"
        @dragenter.prevent="seret = !unggahDitahan"
        @dragover.prevent="seret = !unggahDitahan"
        @dragleave.prevent="seret = false"
        @drop.prevent="onDrop"
      >
        <template v-if="sedangDiunggah">
          <img v-if="sedangDiunggah.url" :src="sedangDiunggah.url" alt="Pratinjau berkas yang diunggah" class="h-14 w-14 rounded object-cover" />
          <!-- Non-gambar: ikon berkas seperti UiFileField (merah untuk PDF) — CR-051 R1D-I1. -->
          <FileText
            v-else
            class="h-8 w-8"
            :class="/\.pdf$/i.test(sedangDiunggah.nama) ? 'text-danger' : 'text-slate-400'"
            aria-hidden="true"
            data-testid="arsip-pratinjau-ikon"
          />
          <span data-testid="arsip-pratinjau">Mengunggah {{ sedangDiunggah.nama }} ({{ ukuran(sedangDiunggah.ukuran) }})…</span>
        </template>
        <span v-else>{{ unggahDitahan ? 'Lengkapi isian di atas untuk mengunggah.' : 'Seret berkas ke sini, atau gunakan tombol "Unggah berkas".' }}</span>
      </div>
      <p
        v-if="canUpload && unggahDitahan"
        :id="`arsip-tahan-${target.id_riwayat}`"
        class="w-full text-right text-caption text-slate-500"
        data-testid="arsip-tahan"
      >
        {{ unggahDitahan }}
      </p>
    </div>

    <UiNotice v-if="notice" :tone="notice.tone" dismissible data-testid="arsip-notice" @dismiss="notice = null">{{ notice.text }}</UiNotice>

    <UiNotice v-if="failure" :tone="failure.kind === 'unavailable' ? 'info' : 'danger'" data-testid="arsip-failure">{{ failure.message }}</UiNotice>

    <div v-else class="scrollbar-slim overflow-x-auto rounded-lg border border-slate-200">
      <table class="w-full min-w-[32rem] border-collapse text-left text-body2" data-testid="arsip-table">
        <thead>
          <tr class="border-b border-slate-200 font-semibold text-slate-900">
            <th v-if="tampilTag" scope="col" class="px-4 py-2.5">Jenis</th>
            <th scope="col" class="px-4 py-2.5">Nama berkas</th>
            <th scope="col" class="px-4 py-2.5">Ukuran</th>
            <th scope="col" class="px-4 py-2.5">Diunggah</th>
            <th scope="col" class="sticky right-0 bg-white px-4 py-2.5 text-right">Aksi</th>
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
              <td v-if="tampilTag" class="px-4 py-2.5 text-slate-800">{{ row.tag || '—' }}</td>
              <td class="max-w-72 px-4 py-2.5 text-slate-800">
                <UiTooltip :content="nama(row)"><span class="block truncate">{{ nama(row) }}</span></UiTooltip>
              </td>
              <td class="px-4 py-2.5 text-slate-600">{{ ukuran(row.file_size) }}</td>
              <td class="px-4 py-2.5 text-slate-600">{{ formatApiDateTime(row.created_at) || '—' }}</td>
              <td class="sticky right-0 bg-white px-4 py-2.5 text-right">
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
