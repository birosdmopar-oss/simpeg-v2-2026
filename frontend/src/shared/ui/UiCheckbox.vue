<script setup lang="ts">
/**
 * Kotak Ceklis — Laporan Redesign §3.6. Empat state pada gambar: kosong, tercentang (biru #217AFF),
 * disabled kosong (abu), disabled tercentang (biru pudar).
 *
 * Reusable. Input asli disembunyikan secara visual (bukan display:none) agar tetap fokusable.
 * CR-028: dibungkus FormField.vue untuk tipe `checkbox` (flag 1/0 master); `invalid` + `describedBy` meneruskan
 * aria-invalid/aria-describedby ke <input> agar pesan error FormField terbaca pembaca layar.
 */
import { Check } from 'lucide-vue-next'
import { useId } from 'vue'

withDefaults(
  defineProps<{
    modelValue?: boolean
    label?: string
    disabled?: boolean
    name?: string
    invalid?: boolean
    describedBy?: string
  }>(),
  { modelValue: false, label: '', disabled: false, name: undefined, invalid: false, describedBy: undefined },
)

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()

const id = useId()
</script>

<template>
  <label
    :for="id"
    class="group inline-flex items-center gap-2.5"
    :class="disabled ? 'cursor-not-allowed' : 'cursor-pointer'"
  >
    <span class="relative inline-flex h-5 w-5 shrink-0">
      <input
        :id="id"
        :name="name"
        type="checkbox"
        class="peer absolute inset-0 h-full w-full opacity-0 disabled:cursor-not-allowed"
        :checked="modelValue"
        :disabled="disabled"
        :aria-invalid="invalid || undefined"
        :aria-describedby="describedBy"
        @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
      />
      <span
        class="pointer-events-none flex h-5 w-5 items-center justify-center rounded-md border-2 transition"
        :class="[
          modelValue
            ? disabled
              ? 'border-transparent bg-brand-tertiary/40'
              : 'border-transparent bg-brand-tertiary'
            : disabled
              ? 'border-slate-200 bg-slate-100'
              : invalid
                ? 'border-danger bg-white'
                : 'border-slate-300 bg-white',
          'peer-focus-visible:ring-2 peer-focus-visible:ring-brand-tertiary/50 peer-focus-visible:ring-offset-2',
        ]"
      >
        <Check v-if="modelValue" class="h-3.5 w-3.5 text-white" stroke-width="3" aria-hidden="true" />
      </span>
    </span>
    <span v-if="label" class="text-body1" :class="disabled ? 'text-slate-400' : 'text-slate-700'">{{ label }}</span>
    <slot />
  </label>
</template>
