<script setup lang="ts">
/**
 * FormField generik (ADR-026): label + input/select + pesan error. Dipakai lintas modul.
 * Dipasangkan dengan VeeValidate `useField`/`defineField` di komponen pemanggil.
 */
import { computed, useId } from 'vue'

const props = withDefaults(
  defineProps<{
    label: string
    modelValue: string | number | null | undefined
    type?: 'text' | 'password' | 'select' | 'number'
    placeholder?: string
    error?: string
    hint?: string
    required?: boolean
    disabled?: boolean
    autocomplete?: string
    inputmode?: 'text' | 'numeric'
    options?: Array<{ value: string | number; label: string }>
    name?: string
  }>(),
  { type: 'text', placeholder: '', error: '', hint: '', required: false, disabled: false, autocomplete: undefined, inputmode: undefined, options: () => [], name: undefined },
)

const emit = defineEmits<{ 'update:modelValue': [value: string]; blur: [] }>()

const id = useId()
const inputClass = computed(() => [
  'block w-full rounded-md border px-3 py-2 text-sm shadow-sm outline-none transition',
  'focus:ring-2 focus:ring-brand-tertiary/40 focus:border-brand-tertiary',
  'disabled:cursor-not-allowed disabled:bg-slate-100',
  props.error ? 'border-red-500 bg-red-50' : 'border-slate-300 bg-white',
])

function onInput(event: Event): void {
  emit('update:modelValue', (event.target as HTMLInputElement | HTMLSelectElement).value)
}
</script>

<template>
  <div class="space-y-1">
    <label :for="id" class="block text-sm font-medium text-slate-700">
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
      <option value="" disabled>{{ placeholder || 'Pilih...' }}</option>
      <option v-for="opt in options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
    </select>

    <div v-else class="relative">
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

    <p v-if="error" :id="`${id}-error`" class="text-xs text-red-600" role="alert">{{ error }}</p>
    <p v-else-if="hint" class="text-xs text-slate-500">{{ hint }}</p>
  </div>
</template>
