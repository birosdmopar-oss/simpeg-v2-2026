<script setup lang="ts">
/**
 * Dropdown — Laporan Redesign §3.5. Label di atas, kotak dengan ikon chevron di kanan,
 * state default / error / success / disabled + help text (persis pola §3.4).
 *
 * Reusable. Memakai <select> native supaya keyboard & screen reader (WCAG 2.1 AA) ikut gratis.
 * CR-028: dibungkus FormField.vue untuk tipe `select`; wajib ditandai `aria-required` (validasi tetap milik Zod), pesan
 * error memakai role="alert", dan event `blur` diteruskan.
 */
import { ChevronDown } from 'lucide-vue-next'
import { computed, useId } from 'vue'

export type SelectOption = { value: string | number; label: string; disabled?: boolean }

const props = withDefaults(
  defineProps<{
    modelValue?: string | number | null
    label?: string
    placeholder?: string
    options?: SelectOption[]
    state?: 'default' | 'error' | 'success'
    helpText?: string
    required?: boolean
    disabled?: boolean
    name?: string
    /** true = opsi placeholder ('Semua …') bisa dipilih lagi untuk mengosongkan nilai (filter). */
    clearable?: boolean
    /** Label aksesibel bila tidak ada `label` yang terlihat (mis. pemilih jumlah baris). */
    ariaLabel?: string
  }>(),
  {
    modelValue: '',
    label: '',
    placeholder: 'Pilih...',
    options: () => [],
    state: 'default',
    helpText: '',
    required: false,
    disabled: false,
    name: undefined,
    clearable: false,
    ariaLabel: undefined,
  },
)

const emit = defineEmits<{ 'update:modelValue': [value: string]; blur: [] }>()

const id = useId()

const tone = computed(() => {
  // Field terkunci tetap menampilkan pesan error berwarna merah (mis. dropdown induk belum dipilih saat form dikirim).
  if (props.disabled) {
    return { border: 'border-slate-200', label: 'text-slate-400', help: props.state === 'error' ? 'text-danger' : 'text-slate-400' }
  }
  if (props.state === 'error') return { border: 'border-danger', label: 'text-danger', help: 'text-danger' }
  if (props.state === 'success') return { border: 'border-success', label: 'text-success', help: 'text-success' }
  return { border: 'border-slate-300', label: 'text-slate-700', help: 'text-slate-500' }
})
</script>

<template>
  <div class="w-full">
    <label v-if="label" :for="id" class="mb-1 block text-body2 font-medium" :class="tone.label">
      {{ label }}<span v-if="required" class="text-danger"> *</span>
    </label>

    <div class="relative">
      <select
        :id="id"
        :name="name"
        :value="modelValue ?? ''"
        :disabled="disabled"
        :aria-required="required || undefined"
        :aria-label="label ? undefined : ariaLabel"
        class="h-11 w-full appearance-none rounded-lg border bg-white pl-3 pr-10 text-body1 text-slate-900 outline-none transition"
        :class="[
          tone.border,
          state === 'default' && !disabled ? 'focus:border-brand-tertiary focus:ring-2 focus:ring-brand-tertiary/25' : '',
          disabled ? 'cursor-not-allowed bg-slate-100 text-slate-400' : '',
          (modelValue ?? '') === '' ? 'text-slate-400' : '',
        ]"
        :aria-invalid="state === 'error' || undefined"
        :aria-describedby="helpText ? `${id}-help` : undefined"
        @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
        @blur="emit('blur')"
      >
        <option value="" :disabled="!clearable">{{ placeholder }}</option>
        <option v-for="opt in options" :key="opt.value" :value="opt.value" :disabled="opt.disabled">
          {{ opt.label }}
        </option>
      </select>
      <ChevronDown
        class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2"
        :class="disabled ? 'text-slate-300' : 'text-slate-500'"
        aria-hidden="true"
      />
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
