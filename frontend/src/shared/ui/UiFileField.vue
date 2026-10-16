<script setup lang="ts">
/**
 * Kolom unggah berkas bergaya input (§4.1.6, Gambar 22): ikon berkas + "Upload File", lalu petunjuk jenis file
 * yang diperbolehkan dan batas ukuran di bawahnya. Validasi ekstensi & ukuran dilakukan di sini (UX) — validasi
 * yang mengikat tetap di backend (B-18). Memakai <input type="file"> asli supaya bisa dioperasikan keyboard.
 * Berkas juga bisa diseret-lepas ke kolom; berkas yang lolos ditampilkan pratinjaunya (gambar = gambar kecil, selain
 * itu nama + ukuran) — CR-049 D-F6. Pesan tolak jenis/ukuran tetap sama untuk pilih maupun seret.
 *
 * Reusable.
 */
import { File as FileIcon, FileText, Upload } from 'lucide-vue-next'
import { computed, onBeforeUnmount, ref, useId } from 'vue'

import { fileExtension, fileExtensionError } from '@/shared/utils/fileExtension'

const props = withDefaults(
  defineProps<{
    label: string
    /** Ekstensi tanpa titik, huruf kecil. */
    allowed: string[]
    maxMb: number
    /** Baris pertama petunjuk, mis. "File yang diunggah Arsip status". */
    hint?: string
    disabled?: boolean
  }>(),
  { hint: '', disabled: false },
)

const emit = defineEmits<{ change: [file: File | null] }>()

const id = useId()
const input = ref<HTMLInputElement | null>(null)
const fileName = ref('')
const fileSize = ref(0)
const error = ref('')
const seret = ref(false)
const pratinjau = ref<string | null>(null)
const pdf = ref(false)

const accept = computed(() => props.allowed.map((ext) => `.${ext}`).join(','))

function lepasPratinjau(): void {
  if (pratinjau.value) URL.revokeObjectURL(pratinjau.value)
  pratinjau.value = null
}
onBeforeUnmount(lepasPratinjau)

/** Ukuran manusiawi (KB/MB, koma desimal). */
function ukuran(bytes: number): string {
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1).replace('.', ',')} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`
}

function proses(file: File | null): void {
  error.value = ''
  lepasPratinjau()
  pdf.value = false

  if (!file) {
    fileName.value = ''
    emit('change', null)
    return
  }

  const salahJenis = fileExtensionError(file.name, props.allowed)
  if (salahJenis !== null) {
    error.value = salahJenis
  } else if (file.size > props.maxMb * 1024 * 1024) {
    error.value = `Ukuran file melebihi ${props.maxMb} MB.`
  }

  if (error.value) {
    if (input.value) input.value.value = ''
    fileName.value = ''
    emit('change', null)
    return
  }

  fileName.value = file.name
  const ext = fileExtension(file.name)
  fileSize.value = file.size
  pdf.value = ext === 'pdf'
  if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext) && typeof URL.createObjectURL === 'function') {
    pratinjau.value = URL.createObjectURL(file)
  }
  emit('change', file)
}

function onPick(event: Event): void {
  proses((event.target as HTMLInputElement).files?.[0] ?? null)
}

function onDrop(event: DragEvent): void {
  seret.value = false
  if (props.disabled) return
  const file = event.dataTransfer?.files?.[0]
  if (file) proses(file)
}
</script>

<template>
  <div>
    <label :for="id" class="mb-1 block text-body2 font-medium text-slate-700">{{ label }}</label>

    <div
      class="relative"
      data-testid="file-dropzone"
      @dragenter.prevent="seret = !disabled"
      @dragover.prevent="seret = !disabled"
      @dragleave.prevent="seret = false"
      @drop.prevent="onDrop"
    >
      <input
        :id="id"
        ref="input"
        type="file"
        class="peer absolute inset-0 h-full w-full cursor-pointer opacity-0 disabled:cursor-not-allowed"
        :accept="accept"
        :disabled="disabled"
        :aria-describedby="`${id}-hint`"
        :aria-invalid="error ? true : undefined"
        @change="onPick"
      />
      <div
        class="flex h-11 items-center gap-2 rounded-lg border bg-white px-3 text-body1 transition peer-focus-visible:ring-2 peer-focus-visible:ring-brand-tertiary/40"
        :class="[
          error ? 'border-danger' : seret ? 'border-dashed border-brand-tertiary bg-brand-tertiary/5' : 'border-slate-300',
          disabled ? 'bg-slate-100' : '',
        ]"
      >
        <Upload v-if="seret" class="h-4 w-4 shrink-0 text-brand-tertiary" aria-hidden="true" />
        <FileIcon v-else class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
        <span class="truncate" :class="fileName ? 'text-slate-900' : 'text-slate-400'">
          {{ seret ? 'Lepaskan berkas di sini' : fileName || 'Upload File atau seret berkas ke sini' }}
        </span>
      </div>
    </div>

    <p v-if="error" class="mt-1 text-caption text-danger" role="alert">{{ error }}</p>
    <div v-else-if="fileName" class="mt-2 flex items-center gap-3 rounded-lg border border-slate-200 p-2" data-testid="file-preview">
      <img v-if="pratinjau" :src="pratinjau" alt="Pratinjau berkas terpilih" class="h-14 w-14 rounded object-cover" />
      <FileText v-else class="h-8 w-8 shrink-0" :class="pdf ? 'text-danger' : 'text-slate-400'" aria-hidden="true" />
      <div class="min-w-0 text-body2">
        <p class="truncate font-medium text-slate-800">{{ fileName }}</p>
        <p class="text-caption text-slate-500">{{ ukuran(fileSize) }}</p>
      </div>
    </div>
    <div :id="`${id}-hint`" class="mt-1 space-y-0.5 text-[0.6875rem] leading-4 text-slate-400">
      <p v-if="hint">{{ hint }}</p>
      <p>
        Jenis file yang diperbolehkan <strong class="font-semibold">{{ allowed.join(', ') }}</strong>
      </p>
      <p>
        Ukuran file max. <strong class="font-semibold">{{ maxMb }}mb</strong>
      </p>
    </div>
  </div>
</template>
