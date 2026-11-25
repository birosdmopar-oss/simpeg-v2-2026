<script setup lang="ts">
/**
 * Input password + tombol tampilkan/sembunyikan (pola LoginView), membungkus FormField. Dipakai halaman ganti dan
 * reset password. `autocomplete` wajib diisi supaya password manager membedakan password lama dan baru.
 * CR-190: `appearance="v5"` (aditif) hanya untuk halaman auth V5; halaman Ganti Password memakai tampilan bawaan.
 */
import { Eye, EyeOff } from 'lucide-vue-next'
import { ref } from 'vue'

import FormField from '@/shared/components/FormField.vue'

withDefaults(
  defineProps<{
    label: string
    name: string
    autocomplete: 'current-password' | 'new-password'
    error?: string
    placeholder?: string
    required?: boolean
    disabled?: boolean
    appearance?: 'default' | 'v5'
  }>(),
  { error: undefined, placeholder: undefined, required: false, disabled: false, appearance: 'default' },
)

const model = defineModel<string>({ default: '' })
const visible = ref(false)
</script>

<template>
  <FormField
    v-model="model"
    :name="name"
    :label="label"
    :type="visible ? 'text' : 'password'"
    :placeholder="placeholder"
    :autocomplete="autocomplete"
    :required="required"
    :disabled="disabled"
    :error="error"
    :appearance="appearance"
  >
    <template #suffix>
      <button
        type="button"
        :class="
          appearance === 'v5'
            ? 'absolute inset-y-0 right-3 my-auto flex h-7 w-7 items-center justify-center rounded-v5-sm text-v5-text-muted hover:text-v5-text focus-visible:ring-2 focus-visible:ring-v5-blue focus-visible:ring-offset-2 focus-visible:ring-offset-white focus-visible:outline-none disabled:cursor-not-allowed disabled:text-v5-text-disabled'
            : 'absolute inset-y-0 right-2 flex items-center text-slate-400 hover:text-slate-600 disabled:cursor-not-allowed'
        "
        :aria-label="visible ? `Sembunyikan ${label.toLowerCase()}` : `Tampilkan ${label.toLowerCase()}`"
        :disabled="disabled"
        :data-testid="`toggle-${name}`"
        @click="visible = !visible"
      >
        <EyeOff v-if="visible" class="h-4 w-4" />
        <Eye v-else class="h-4 w-4" />
      </button>
    </template>
  </FormField>
</template>
