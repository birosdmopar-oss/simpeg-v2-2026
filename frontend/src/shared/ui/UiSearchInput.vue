<script setup lang="ts">
/**
 * Kotak pencarian dengan ikon kaca pembesar — dipakai FAQ (§4.2.4), Portal Berita (§4.2.3),
 * tabel Daftar Pegawai (§4.1.5) dan daftar chat Halo Simpeg (§4.2.6).
 *
 * Reusable.
 */
import { Search } from 'lucide-vue-next'
import { useId } from 'vue'

withDefaults(
  defineProps<{
    modelValue?: string
    placeholder?: string
    label?: string
    size?: 'md' | 'lg'
  }>(),
  { modelValue: '', placeholder: 'Cari...', label: 'Cari', size: 'md' },
)

const emit = defineEmits<{ 'update:modelValue': [value: string]; submit: [] }>()

const id = useId()
</script>

<template>
  <div class="relative w-full">
    <label :for="id" class="sr-only">{{ label }}</label>
    <Search class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
    <input
      :id="id"
      type="search"
      :value="modelValue"
      :placeholder="placeholder"
      class="w-full rounded-lg border border-slate-300 bg-white pl-10 pr-3 text-body1 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-brand-tertiary focus:ring-2 focus:ring-brand-tertiary/25"
      :class="size === 'lg' ? 'h-12' : 'h-11'"
      @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
      @keyup.enter="emit('submit')"
    />
  </div>
</template>
