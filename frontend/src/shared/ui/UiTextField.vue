<script setup lang="ts">
/**
 * Formulir Input — Laporan Redesign §3.4. Dua tampilan:
 *  - `outlined`: label duduk di takik garis tepi (notch), placeholder di dalam kotak.
 *  - `stacked`  : label di atas kotak (dipakai mayoritas form Detail Pegawai §4.1.6).
 * State: default / error / success / disabled, masing-masing mewarnai label, border, dan help text.
 *
 * Reusable. FormField.vue (VeeValidate) membungkus komponen ini untuk tipe text/password/number/date (CR-028), jadi
 * semua form lama ikut tampilan §3.4 tanpa mengubah pemanggilnya.
 *
 * CR-028: wajib ditandai `aria-required` (bukan atribut `required` native) supaya validasi tetap milik Zod/VeeValidate
 * dan browser tidak memblokir submit lebih dulu; pesan bantu pada state error memakai role="alert" agar diumumkan
 * pembaca layar; wadah input selalu `relative` supaya slot `suffix` (mis. tombol lihat password) bisa diposisikan.
 */
import { computed, useId, useSlots } from 'vue'

const props = withDefaults(
  defineProps<{
    modelValue?: string | number | null
    label?: string
    placeholder?: string
    type?: string
    appearance?: 'stacked' | 'outlined'
    state?: 'default' | 'error' | 'success'
    helpText?: string
    required?: boolean
    disabled?: boolean
    readonly?: boolean
    autocomplete?: string
    name?: string
    inputmode?: 'text' | 'numeric' | 'tel' | 'email' | 'search'
  }>(),
  {
    modelValue: '',
    label: '',
    placeholder: '',
    type: 'text',
    appearance: 'stacked',
    state: 'default',
    helpText: '',
    required: false,
    disabled: false,
    readonly: false,
    autocomplete: undefined,
    name: undefined,
    inputmode: undefined,
  },
)

const emit = defineEmits<{ 'update:modelValue': [value: string]; blur: []; focus: [] }>()

const id = useId()
const slots = useSlots()

const tone = computed(() => {
  // Field terkunci tetap menampilkan pesan error berwarna merah (mis. dropdown induk belum dipilih saat form dikirim).
  if (props.disabled) {
    return { border: 'border-slate-200', label: 'text-slate-400', help: props.state === 'error' ? 'text-danger' : 'text-slate-400' }
  }
  if (props.state === 'error') return { border: 'border-danger', label: 'text-danger', help: 'text-danger' }
  if (props.state === 'success') return { border: 'border-success', label: 'text-success', help: 'text-success' }
  return { border: 'border-slate-300', label: 'text-slate-700', help: 'text-slate-500' }
})

const controlClass = computed(() => [
  'block w-full rounded-lg bg-white px-3 text-body1 text-slate-900 outline-none transition',
  'placeholder:text-slate-400',
  props.appearance === 'outlined' ? 'h-12' : 'h-11',
  slots.suffix ? 'pr-10' : '',
  'border',
  tone.value.border,
  props.state === 'default' && !props.disabled ? 'focus:border-brand-tertiary focus:ring-2 focus:ring-brand-tertiary/25' : '',
  props.disabled ? 'cursor-not-allowed bg-slate-100 text-slate-400' : '',
])
</script>

<template>
  <div class="w-full">
    <!-- Tampilan stacked: label di atas kotak. -->
    <label v-if="label && appearance === 'stacked'" :for="id" class="mb-1 block text-body2 font-medium" :class="tone.label">
      {{ label }}<span v-if="required" class="text-danger"> *</span>
    </label>

    <div class="relative">
      <!-- Tampilan outlined: label mengambang menimpa garis tepi (takik). -->
      <label
        v-if="label && appearance === 'outlined'"
        :for="id"
        class="absolute -top-2 left-3 z-10 bg-white px-1 text-caption font-medium"
        :class="tone.label"
      >
        {{ label }}<span v-if="required" class="text-danger"> *</span>
      </label>

      <input
        :id="id"
        :name="name"
        :type="type"
        :value="modelValue ?? ''"
        :placeholder="placeholder"
        :disabled="disabled"
        :readonly="readonly"
        :autocomplete="autocomplete"
        :inputmode="inputmode"
        :aria-required="required || undefined"
        :class="controlClass"
        :aria-invalid="state === 'error' || undefined"
        :aria-describedby="helpText ? `${id}-help` : undefined"
        @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
        @blur="emit('blur')"
        @focus="emit('focus')"
      />
      <slot name="suffix" />
    </div>

    <p
      v-if="helpText"
      :id="`${id}-help`"
      class="mt-1 text-caption"
      :class="tone.help"
      :role="state === 'error' ? 'alert' : undefined"
    >
      {{ helpText }}
    </p>
  </div>
</template>
