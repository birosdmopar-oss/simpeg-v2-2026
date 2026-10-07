<script setup lang="ts">
/**
 * FormField generik (ADR-026): label + input/select + pesan error. Dipakai lintas modul.
 * Dipasangkan dengan VeeValidate `useField`/`defineField` di komponen pemanggil.
 *
 * CR-028 (MIG-001b): tampilan mengikuti design system redesign (Laporan Redesign §3.4–§3.6). Tipe text/password/
 * number/date dirender lewat `UiTextField` (tampilan stacked: label di atas kotak), tipe `select` lewat `UiSelect`, dan
 * tipe `checkbox` lewat `UiCheckbox`;
 * `error` dipetakan ke state error komponen itu (pesan role="alert", aria-invalid, aria-describedby), `hint` ke teks
 * bantu. API FormField tidak berubah, jadi pemanggil lama (form admin, login, ganti/reset password) cukup memakainya.
 *
 * Tipe `html`: textarea tinggi (kode HTML) + tombol "Pratinjau" yang merender isi lewat sanitizeHtml() (SafeHtml).
 * Kontainer pratinjau dirender v-if, jadi aria-controls tombol hanya dipasang selama pratinjau terbuka.
 * Tipe `checkbox` (flag 1/0 master, CR-009): kotak centang dengan label di sampingnya; nilai '1' tercentang, emit
 * '1'/'0'.
 */
import { computed, defineAsyncComponent, ref, useId } from 'vue'

import UiCheckbox from '@/shared/ui/UiCheckbox.vue'
import UiSelect from '@/shared/ui/UiSelect.vue'
import UiTextField from '@/shared/ui/UiTextField.vue'

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

const isTextLike = computed(() => ['text', 'password', 'number', 'date'].includes(props.type))
const state = computed<'default' | 'error'>(() => (props.error ? 'error' : 'default'))
/** Error menang atas hint (pola lama: hanya satu baris teks di bawah field). */
const helpText = computed(() => props.error || props.hint)

/** Kelas kotak untuk textarea/html — senada dengan UiTextField (§3.4). */
const areaClass = computed(() => [
  'block w-full rounded-lg border bg-white px-3 py-2.5 text-body1 text-slate-900 outline-none transition placeholder:text-slate-400',
  'disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400',
  props.error ? 'border-danger' : 'border-slate-300 focus:border-brand-tertiary focus:ring-2 focus:ring-brand-tertiary/25',
])

const labelClass = computed(() => ['block text-body2 font-medium', props.error ? 'text-danger' : 'text-slate-700'])

function onInput(event: Event): void {
  emit('update:modelValue', (event.target as HTMLInputElement | HTMLTextAreaElement).value)
}

const checked = computed(() => String(props.modelValue ?? '') === '1')

function onCheck(value: boolean): void {
  emit('update:modelValue', value ? '1' : '0')
}
</script>

<template>
  <UiTextField
    v-if="isTextLike"
    :model-value="modelValue ?? ''"
    :name="name"
    :label="label"
    :type="type"
    :placeholder="placeholder"
    :required="required"
    :disabled="disabled"
    :autocomplete="autocomplete"
    :inputmode="inputmode"
    :state="state"
    :help-text="helpText"
    @update:model-value="emit('update:modelValue', $event)"
    @blur="emit('blur')"
  >
    <template v-if="$slots.suffix" #suffix>
      <slot name="suffix" />
    </template>
  </UiTextField>

  <UiSelect
    v-else-if="type === 'select'"
    :model-value="modelValue ?? ''"
    :name="name"
    :label="label"
    :placeholder="placeholder || 'Pilih...'"
    :options="options"
    :required="required"
    :disabled="disabled"
    :clearable="allowEmpty"
    :state="state"
    :help-text="helpText"
    @update:model-value="emit('update:modelValue', $event)"
    @blur="emit('blur')"
  />

  <div v-else class="w-full">
    <UiCheckbox
      v-if="type === 'checkbox'"
      :model-value="checked"
      :name="name"
      :disabled="disabled"
      :invalid="Boolean(error)"
      :described-by="error ? `${id}-error` : undefined"
      @update:model-value="onCheck"
    >
      <span class="text-body1" :class="error ? 'text-danger' : disabled ? 'text-slate-400' : 'text-slate-700'">
        {{ label }}<span v-if="required" class="text-danger"> *</span>
      </span>
    </UiCheckbox>

    <div v-else-if="type === 'html'" class="mb-1 flex items-center justify-between gap-2">
      <label :for="id" :class="labelClass">
        {{ label }}<span v-if="required" class="text-danger"> *</span>
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
    <label v-else :for="id" :class="[labelClass, 'mb-1']">
      {{ label }}<span v-if="required" class="text-danger"> *</span>
    </label>

    <textarea
      v-if="type === 'textarea'"
      :id="id"
      :name="name"
      :value="String(modelValue ?? '')"
      :placeholder="placeholder"
      :class="areaClass"
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
        :class="[areaClass, 'font-mono text-xs leading-relaxed']"
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
        class="max-h-[50vh] min-h-[10rem] overflow-y-auto rounded-lg border border-dashed border-slate-300 bg-white p-3"
        data-testid="html-preview"
      >
        <p class="mb-2 text-xs text-slate-400">Pratinjau — tag/atribut di luar daftar yang diizinkan tidak ditampilkan.</p>
        <SafeHtml :html="String(modelValue ?? '')" empty-text="Belum ada konten untuk dipratinjau." />
      </div>
    </template>

    <p v-if="error" :id="`${id}-error`" class="mt-1 text-caption text-danger" role="alert">{{ error }}</p>
    <p v-else-if="hint" class="mt-1 text-caption text-slate-500">{{ hint }}</p>
  </div>
</template>
