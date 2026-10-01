<script setup lang="ts">
/**
 * Grup radio — dipakai "Jenis Kelamin" dan "Status Perkawinan" pada form Detail Pegawai (§4.1.6, Gambar 22).
 * Bulatan biru #217AFF saat terpilih (senada checkbox §3.6). Memakai <input type="radio"> asli sehingga
 * navigasi panah, fokus, dan pembaca layar bekerja tanpa skrip tambahan.
 *
 * Reusable.
 */
import { useId } from 'vue'

export type RadioOption = { value: string; label: string; disabled?: boolean }

withDefaults(
  defineProps<{
    modelValue?: string
    options: RadioOption[]
    label?: string
    name?: string
    required?: boolean
    disabled?: boolean
    state?: 'default' | 'error'
    helpText?: string
  }>(),
  { modelValue: '', label: '', name: undefined, required: false, disabled: false, state: 'default', helpText: '' },
)

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const id = useId()
</script>

<template>
  <fieldset class="min-w-0" :aria-describedby="helpText ? `${id}-help` : undefined">
    <legend v-if="label" class="mb-2 text-body2 font-medium" :class="state === 'error' ? 'text-danger' : 'text-slate-700'">
      {{ label }}<span v-if="required" class="text-danger"> *</span>
    </legend>

    <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
      <label
        v-for="opt in options"
        :key="opt.value"
        class="inline-flex items-center gap-2 text-body1"
        :class="disabled || opt.disabled ? 'cursor-not-allowed text-slate-400' : 'cursor-pointer text-slate-700'"
      >
        <span class="relative inline-flex h-5 w-5 shrink-0">
          <input
            type="radio"
            class="peer absolute inset-0 h-full w-full opacity-0 disabled:cursor-not-allowed"
            :name="name ?? id"
            :value="opt.value"
            :checked="modelValue === opt.value"
            :disabled="disabled || opt.disabled"
            @change="emit('update:modelValue', opt.value)"
          />
          <span
            class="pointer-events-none flex h-5 w-5 items-center justify-center rounded-full border-2 bg-white transition peer-focus-visible:ring-2 peer-focus-visible:ring-brand-tertiary/50 peer-focus-visible:ring-offset-2"
            :class="[
              modelValue === opt.value ? (disabled ? 'border-brand-tertiary/40' : 'border-brand-tertiary') : state === 'error' ? 'border-danger' : 'border-slate-300',
            ]"
          >
            <span
              v-if="modelValue === opt.value"
              class="h-2.5 w-2.5 rounded-full"
              :class="disabled ? 'bg-brand-tertiary/40' : 'bg-brand-tertiary'"
            />
          </span>
        </span>
        {{ opt.label }}
      </label>
    </div>

    <p v-if="helpText" :id="`${id}-help`" class="mt-1 text-caption" :class="state === 'error' ? 'text-danger' : 'text-slate-500'">
      {{ helpText }}
    </p>
  </fieldset>
</template>
