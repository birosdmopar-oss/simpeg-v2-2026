<script setup lang="ts">
/**
 * Banner pesan inline (info / sukses / peringatan / bahaya) dengan tombol tutup opsional.
 * Dipakai untuk memberi tahu bahwa suatu aksi belum tersambung ke backend pada fase ini, dan untuk hasil simpan form.
 *
 * Reusable. `role="status"` (sopan) untuk info/sukses; `role="alert"` untuk peringatan/bahaya.
 */
import { CircleAlert, CircleCheck, Info, TriangleAlert, X } from 'lucide-vue-next'

withDefaults(defineProps<{ tone?: 'info' | 'success' | 'warning' | 'danger'; dismissible?: boolean }>(), {
  tone: 'info',
  dismissible: false,
})

defineEmits<{ dismiss: [] }>()

const TONES = {
  info: { box: 'border-info/30 bg-info-soft text-[#00646f]', icon: Info },
  success: { box: 'border-success/30 bg-success-soft text-[#1c7a4a]', icon: CircleCheck },
  warning: { box: 'border-warning/40 bg-warning-soft text-[#8a4f0f]', icon: TriangleAlert },
  danger: { box: 'border-danger/30 bg-danger-soft text-[#a52b2c]', icon: CircleAlert },
} as const
</script>

<template>
  <div
    class="flex items-start gap-3 rounded-xl border px-4 py-3 text-body2"
    :class="TONES[tone].box"
    :role="tone === 'warning' || tone === 'danger' ? 'alert' : 'status'"
  >
    <component :is="TONES[tone].icon" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
    <div class="min-w-0 flex-1"><slot /></div>
    <button
      v-if="dismissible"
      type="button"
      class="-m-1 rounded p-1 opacity-70 transition hover:opacity-100"
      aria-label="Tutup pesan"
      @click="$emit('dismiss')"
    >
      <X class="h-4 w-4" aria-hidden="true" />
    </button>
  </div>
</template>
