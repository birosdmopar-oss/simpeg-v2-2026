<script setup lang="ts">
/**
 * Input password + tombol tampilkan/sembunyikan (pola LoginView), membungkus FormField. Dipakai halaman ganti dan
 * reset password. `autocomplete` wajib diisi supaya password manager membedakan password lama dan baru.
 */
import { Eye, EyeOff } from 'lucide-vue-next'
import { ref } from 'vue'

import FormField from '@/shared/components/FormField.vue'

defineProps<{
  label: string
  name: string
  autocomplete: 'current-password' | 'new-password'
  error?: string
  placeholder?: string
  required?: boolean
  disabled?: boolean
}>()

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
  >
    <template #suffix>
      <button
        type="button"
        class="absolute inset-y-0 right-2 flex items-center text-slate-400 hover:text-slate-600 disabled:cursor-not-allowed"
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
