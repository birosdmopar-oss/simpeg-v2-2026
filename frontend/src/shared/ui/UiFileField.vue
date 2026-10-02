<script setup lang="ts">
/**
 * Kolom unggah berkas bergaya input (§4.1.6, Gambar 22): ikon berkas + "Upload File", lalu petunjuk jenis file
 * yang diperbolehkan dan batas ukuran di bawahnya. Validasi ekstensi & ukuran dilakukan di sini (UX) — validasi
 * yang mengikat tetap di backend (B-18). Memakai <input type="file"> asli supaya bisa dioperasikan keyboard.
 *
 * Reusable.
 */
import { File as FileIcon } from 'lucide-vue-next'
import { computed, ref, useId } from 'vue'

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
const fileName = ref('')
const error = ref('')

const accept = computed(() => props.allowed.map((ext) => `.${ext}`).join(','))

function onPick(event: Event): void {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  error.value = ''

  if (!file) {
    fileName.value = ''
    emit('change', null)
    return
  }

  const ext = file.name.split('.').pop()?.toLowerCase() ?? ''
  if (!props.allowed.includes(ext)) {
    error.value = `Jenis file .${ext || '?'} tidak diperbolehkan.`
  } else if (file.size > props.maxMb * 1024 * 1024) {
    error.value = `Ukuran file melebihi ${props.maxMb} MB.`
  }

  if (error.value) {
    input.value = ''
    fileName.value = ''
    emit('change', null)
    return
  }

  fileName.value = file.name
  emit('change', file)
}
</script>

<template>
  <div>
    <label :for="id" class="mb-1 block text-body2 font-medium text-slate-700">{{ label }}</label>

    <div class="relative">
      <input
        :id="id"
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
        :class="[error ? 'border-danger' : 'border-slate-300', disabled ? 'bg-slate-100' : '']"
      >
        <FileIcon class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
        <span class="truncate" :class="fileName ? 'text-slate-900' : 'text-slate-400'">{{ fileName || 'Upload File' }}</span>
      </div>
    </div>

    <p v-if="error" class="mt-1 text-caption text-danger" role="alert">{{ error }}</p>
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
