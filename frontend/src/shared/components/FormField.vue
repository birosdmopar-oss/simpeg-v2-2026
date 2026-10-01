<script setup lang="ts">
/**
 * FormField generik (ADR-026): label + input/select + pesan error. Dipakai lintas modul.
 * Dipasangkan dengan VeeValidate `useField`/`defineField` di komponen pemanggil.
 * Tipe `html`: textarea tinggi (kode HTML) + tombol "Pratinjau" yang merender isi lewat sanitizeHtml() (SafeHtml).
 * Kontainer pratinjau dirender v-if, jadi aria-controls tombol hanya dipasang selama pratinjau terbuka.
 * Tipe `checkbox` (flag 1/0 master, CR-009): kotak centang dengan label di sampingnya; nilai '1' tercentang, emit
 * '1'/'0'.
 */
import { computed, defineAsyncComponent, ref, useId } from 'vue'

// Dimuat saat pratinjau dibuka saja: FormField dipakai halaman login, DOMPurify tidak perlu ikut di bundel itu.
const SafeHtml = defineAsyncComponent(() => import('./SafeHtml.vue'))

const props = withDefaults(
  defineProps<{
    label: string
    modelValue: string | number | null | undefined
    type?: 'text' | 'password' | 'select' | 'number' | 'date' | 'textarea' | 'html' | 'checkbox'
    placeholder?: string
    error?: string
    hint?: string
    required?: boolean
    disabled?: boolean
    autocomplete?: string
    inputmode?: 'text' | 'numeric'
    options?: Array<{ value: string | number; label: string }>
    name?: string
    /** Select opsional: pilihan kosong (placeholder) bisa dipilih lagi untuk mengosongkan nilai. */
    allowEmpty?: boolean
  }>(),
  {
    type: 'text',
    placeholder: '',
    error: '',
    hint: '',
    required: false,
    disabled: false,
    autocomplete: undefined,
    inputmode: undefined,
    options: () => [],
    name: undefined,
    allowEmpty: false,
  },
)

const emit = defineEmits<{ 'update:modelValue': [value: string]; blur: [] }>()

const id = useId()
const previewing = ref(false)
const inputClass = computed(() => [
  'block w-full rounded-lg border px-3 py-2.5 text-body1 text-slate-900 outline-none transition placeholder:text-slate-400',
  'focus:ring-2 focus:ring-brand-tertiary/25',
  'disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400',
  props.error ? 'border-danger bg-white focus:border-danger focus:ring-danger/20' : 'border-slate-300 bg-white focus:border-brand-tertiary',
])

function onInput(event: Event): void {
  emit('update:modelValue', (event.target as HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement).value)
}

const checked = computed(() => String(props.modelValue ?? '') === '1')

function onCheck(event: Event): void {
  emit('update:modelValue', (event.target as HTMLInputElement).checked ? '1' : '0')
}
</script>

<template>
  <div class="space-y-1">
    <div v-if="type === 'checkbox'" class="flex items-center gap-2">
      <input
        :id="id"
        :name="name"
        type="checkbox"
        :checked="checked"
        class="h-5 w-5 rounded-md border-slate-300 text-brand-tertiary focus:ring-2 focus:ring-brand-tertiary/40 disabled:cursor-not-allowed"
        :disabled="disabled"
        :aria-invalid="Boolean(error)"
        :aria-describedby="error ? `${id}-error` : undefined"
        @change="onCheck"
        @blur="emit('blur')"
      />
      <label :for="id" class="text-body1" :class="error ? 'text-danger' : 'text-slate-700'">
        {{ label }}<span v-if="required" class="text-red-600"> *</span>
      </label>
    </div>

    <div v-else-if="type === 'html'" class="flex items-center justify-between gap-2">
      <label :for="id" class="block text-body2 font-medium" :class="error ? 'text-danger' : 'text-slate-700'">
        {{ label }}<span v-if="required" class="text-red-600"> *</span>
      </label>
      <button
        type="button"
        class="rounded-lg border border-slate-300 px-2.5 py-1 text-caption font-medium text-slate-700 hover:bg-slate-50"
        :aria-pressed="previewing"
        :aria-controls="previewing ? `${id}-preview` : undefined"
        data-testid="html-preview-toggle"
        @click="previewing = !previewing"
      >
        {{ previewing ? 'Tutup pratinjau' : 'Pratinjau' }}
      </button>
    </div>
    <label v-else :for="id" class="block text-body2 font-medium" :class="error ? 'text-danger' : 'text-slate-700'">
      {{ label }}<span v-if="required" class="text-red-600"> *</span>
    </label>

    <select
      v-if="type === 'select'"
      :id="id"
      :name="name"
      :value="modelValue ?? ''"
      :class="inputClass"
      :disabled="disabled"
      :aria-invalid="Boolean(error)"
      @change="onInput"
      @blur="emit('blur')"
    >
      <option value="" :disabled="!allowEmpty">{{ placeholder || 'Pilih...' }}</option>
      <option v-for="opt in options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
    </select>

    <textarea
      v-else-if="type === 'textarea'"
      :id="id"
      :name="name"
      :value="String(modelValue ?? '')"
      :placeholder="placeholder"
      :class="inputClass"
      :disabled="disabled"
      rows="3"
      :aria-invalid="Boolean(error)"
      :aria-describedby="error ? `${id}-error` : undefined"
      @input="onInput"
      @blur="emit('blur')"
    />

    <template v-else-if="type === 'html'">
      <textarea
        v-show="!previewing"
        :id="id"
        :name="name"
        :value="String(modelValue ?? '')"
        :placeholder="placeholder"
        :class="[inputClass, 'font-mono text-xs leading-relaxed']"
        :disabled="disabled"
        rows="14"
        spellcheck="false"
        :aria-invalid="Boolean(error)"
        :aria-describedby="error ? `${id}-error` : undefined"
        @input="onInput"
        @blur="emit('blur')"
      />
      <div
        v-if="previewing"
        :id="`${id}-preview`"
        class="max-h-[50vh] min-h-[10rem] overflow-y-auto rounded-md border border-dashed border-slate-300 bg-white p-3"
        data-testid="html-preview"
      >
        <p class="mb-2 text-xs text-slate-400">Pratinjau — tag/atribut di luar daftar yang diizinkan tidak ditampilkan.</p>
        <SafeHtml :html="String(modelValue ?? '')" empty-text="Belum ada konten untuk dipratinjau." />
      </div>
    </template>

    <div v-else-if="type !== 'checkbox'" class="relative">
      <input
        :id="id"
        :name="name"
        :type="type"
        :value="modelValue ?? ''"
        :placeholder="placeholder"
        :class="inputClass"
        :disabled="disabled"
        :autocomplete="autocomplete"
        :inputmode="inputmode"
        :aria-invalid="Boolean(error)"
        :aria-describedby="error ? `${id}-error` : undefined"
        @input="onInput"
        @blur="emit('blur')"
      />
      <slot name="suffix" />
    </div>

    <p v-if="error" :id="`${id}-error`" class="text-caption text-danger" role="alert">{{ error }}</p>
    <p v-else-if="hint" class="text-caption text-slate-500">{{ hint }}</p>
  </div>
</template>
