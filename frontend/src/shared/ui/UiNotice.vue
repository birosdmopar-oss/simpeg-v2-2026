<script setup lang="ts">
/**
 * Banner pesan inline (info / sukses / peringatan / bahaya) dengan tombol tutup opsional.
 * Dipakai untuk memberi tahu bahwa suatu aksi belum tersambung ke backend pada fase ini, dan untuk hasil simpan form.
 *
 * Reusable. `role="status"` (sopan) untuk info/sukses; `role="alert"` untuk peringatan/bahaya.
 *
 * CR-190: `appearance="v5"` (aditif) = V5 · Alert (spesifikasi §3.3): latar tint per tipe, radius 12, ikon 20, judul
 * opsional (`title`, Body/Medium warna *-strong) di atas isi slot. Tanpa `title`, isi slot memakai gaya judul supaya
 * pesan satu baris tetap menonjol. Aturan role sama dengan tampilan bawaan.
 */
import { CircleAlert, CircleCheck, Info, TriangleAlert, X } from 'lucide-vue-next'

withDefaults(
  defineProps<{
    tone?: 'info' | 'success' | 'warning' | 'danger'
    dismissible?: boolean
    appearance?: 'default' | 'v5'
    /** Judul Alert V5 (hanya `appearance="v5"`). */
    title?: string
  }>(),
  {
    tone: 'info',
    dismissible: false,
    appearance: 'default',
    title: '',
  },
)

defineEmits<{ dismiss: [] }>()

const TONES = {
  info: { box: 'border-info/30 bg-info-soft text-[#00646f]', icon: Info },
  success: { box: 'border-success/30 bg-success-soft text-[#1c7a4a]', icon: CircleCheck },
  warning: { box: 'border-warning/40 bg-warning-soft text-[#8a4f0f]', icon: TriangleAlert },
  danger: { box: 'border-danger/30 bg-danger-soft text-[#a52b2c]', icon: CircleAlert },
} as const

/** V5: latar `*-subtle`, ikon warna status, judul `*-strong` (kontras >= 4.5:1 di atas tint). */
const TONES_V5 = {
  info: { box: 'bg-v5-info-subtle', icon: 'text-v5-info', title: 'text-v5-info-strong' },
  success: { box: 'bg-v5-success-subtle', icon: 'text-v5-success', title: 'text-v5-success-strong' },
  warning: { box: 'bg-v5-warning-subtle', icon: 'text-v5-warning', title: 'text-v5-warning-strong' },
  danger: { box: 'bg-v5-error-subtle', icon: 'text-v5-error', title: 'text-v5-error-strong' },
} as const
</script>

<template>
  <div
    v-if="appearance === 'v5'"
    class="flex w-full items-start gap-3 rounded-v5-md border border-[rgb(15_23_41/0.06)] py-3 pl-4 pr-3"
    :class="TONES_V5[tone].box"
    :role="tone === 'warning' || tone === 'danger' ? 'alert' : 'status'"
    data-appearance="v5"
  >
    <component :is="TONES[tone].icon" class="h-5 w-5 shrink-0" :class="TONES_V5[tone].icon" aria-hidden="true" />
    <div class="min-w-0 flex-1">
      <p v-if="title" class="text-[14px] font-medium leading-[22px] tracking-[-0.1px]" :class="TONES_V5[tone].title">
        {{ title }}
      </p>
      <div
        :class="
          title
            ? 'mt-0.5 text-[13px] leading-5 tracking-[-0.05px] text-v5-text'
            : ['text-[14px] font-medium leading-[22px] tracking-[-0.1px]', TONES_V5[tone].title]
        "
      >
        <slot />
      </div>
    </div>
    <button
      v-if="dismissible"
      type="button"
      class="-m-1 rounded-v5-sm p-1 text-v5-text-muted transition hover:text-v5-text focus-visible:shadow-v5-focus focus-visible:outline-none"
      aria-label="Tutup pesan"
      @click="$emit('dismiss')"
    >
      <X class="h-4 w-4" aria-hidden="true" />
    </button>
  </div>

  <div
    v-else
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
