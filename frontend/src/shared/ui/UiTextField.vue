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
 * CR-060 (AGENTS.md bagian 3): `type` "date" | "time" | "month" TIDAK lagi memakai input native bawaan browser,
 * melainkan UiDatePicker / UiTimePicker / UiMonthPicker (nilai tetap `YYYY-MM-DD` / `HH:mm` / `YYYY-MM`). Pesan format
 * salah dari picker tampil sebagai help text berstatus error bila pemanggil tidak memberi pesan sendiri.
 *
 * CR-028: wajib ditandai `aria-required` (bukan atribut `required` native) supaya validasi tetap milik Zod/VeeValidate
 * dan browser tidak memblokir submit lebih dulu; pesan bantu pada state error memakai role="alert" agar diumumkan
 * pembaca layar; wadah input selalu `relative` supaya slot `suffix` (mis. tombol lihat password) bisa diposisikan.
 */
import { computed, ref, useId, useSlots } from 'vue'

import UiCombobox from './UiCombobox.vue'
import UiDatePicker from './UiDatePicker.vue'
import UiMonthPicker from './UiMonthPicker.vue'
import UiTimePicker from './UiTimePicker.vue'

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
    inputmode?: 'text' | 'numeric' | 'decimal' | 'tel' | 'email' | 'search'
    /** id `<datalist>` saran nilai (aditif MAKE-007); nilai bebas tetap boleh. DEPRECATED: pakai `suggestions`. */
    list?: string
    /** Saran nilai teks bebas (UiCombobox custom, bukan <datalist> bawaan browser); `[]` = belum ada saran (CR-060). */
    suggestions?: string[]
    /** Batas tanggal `YYYY-MM-DD` (inklusif), hanya untuk type="date". */
    min?: string
    max?: string
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
    list: undefined,
    suggestions: undefined,
    min: undefined,
    max: undefined,
  },
)

const emit = defineEmits<{ 'update:modelValue': [value: string]; blur: []; focus: [] }>()

const id = useId()
const slots = useSlots()

const PICKERS = { date: UiDatePicker, time: UiTimePicker, month: UiMonthPicker } as const
const isCombobox = computed(() => props.suggestions !== undefined && props.type === 'text')
const picker = computed(() => (isCombobox.value ? UiCombobox : (PICKERS[props.type as keyof typeof PICKERS] ?? null)))
/** Props khusus per komponen kontrol (picker tanggal: batas; combobox: saran + atribut input). */
const pickerProps = computed(() =>
  isCombobox.value
    ? { suggestions: props.suggestions, inputmode: props.inputmode, autocomplete: props.autocomplete, placeholder: props.placeholder }
    : { min: props.min, max: props.max, ...(props.placeholder ? { placeholder: props.placeholder } : {}) },
)
/** Pesan format salah dari picker (kosong = sah); hanya dipakai bila pemanggil tidak memberi help text sendiri. */
const pickerMessage = ref('')
const help = computed(() => props.helpText || pickerMessage.value)
const effState = computed(() => (props.state === 'default' && pickerMessage.value && !props.helpText ? 'error' : props.state))

const tone = computed(() => {
  // Field terkunci tetap menampilkan pesan error berwarna merah (mis. dropdown induk belum dipilih saat form dikirim).
  if (props.disabled) {
    return { border: 'border-slate-200', label: 'text-slate-400', help: effState.value === 'error' ? 'text-danger' : 'text-slate-400' }
  }
  if (effState.value === 'error') return { border: 'border-danger', label: 'text-danger', help: 'text-danger' }
  if (effState.value === 'success') return { border: 'border-success', label: 'text-success', help: 'text-success' }
  return { border: 'border-slate-300', label: 'text-slate-700', help: 'text-slate-500' }
})

const controlClass = computed(() => [
  'block w-full rounded-lg bg-white px-3 text-body1 text-slate-900 outline-none transition',
  'placeholder:text-slate-400',
  props.appearance === 'outlined' ? 'h-12' : 'h-11',
  slots.suffix ? 'pr-10' : '',
  'border',
  tone.value.border,
  effState.value === 'default' && !props.disabled ? 'focus:border-brand-tertiary focus:ring-2 focus:ring-brand-tertiary/25' : '',
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

      <component
        :is="picker"
        v-if="picker"
        :id="id"
        :name="name"
        :model-value="String(modelValue ?? '')"
        :disabled="disabled"
        :readonly="readonly"
        :invalid="effState === 'error'"
        :required="required"
        v-bind="pickerProps"
        :control-class="controlClass"
        :describedby="help ? `${id}-help` : undefined"
        @update:model-value="emit('update:modelValue', $event)"
        @invalid="pickerMessage = $event"
        @blur="emit('blur')"
        @focus="emit('focus')"
      />
      <input
        v-else
        :id="id"
        :name="name"
        :type="type"
        :value="modelValue ?? ''"
        :placeholder="placeholder"
        :disabled="disabled"
        :readonly="readonly"
        :autocomplete="autocomplete"
        :inputmode="inputmode"
        :list="list"
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
      v-if="help"
      :id="`${id}-help`"
      class="mt-1 text-caption"
      :class="tone.help"
      :role="effState === 'error' ? 'alert' : undefined"
    >
      {{ help }}
    </p>
  </div>
</template>
