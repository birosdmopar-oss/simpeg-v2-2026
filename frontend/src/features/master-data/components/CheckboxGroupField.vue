<script setup lang="ts">
/**
 * Pilihan ganda berupa daftar kotak centang (UiCheckbox, design system redesign) dengan label grup, teks bantu, dan
 * pesan error — dipakai form aturan lokasi presensi (G-03, CR-031) untuk target lokasi, unit/satker, jenis pegawai, dan
 * hari berlaku. Nilai = daftar `value` terpilih, urut sesuai urutan pilihan di `options`.
 */
import { computed, useId } from 'vue'

import UiCheckbox from '@/shared/ui/UiCheckbox.vue'

const props = withDefaults(
  defineProps<{
    label: string
    modelValue: string[]
    options: Array<{ value: string; label: string }>
    name: string
    required?: boolean
    error?: string
    hint?: string
    emptyText?: string
    columns?: 1 | 2 | 4
  }>(),
  { required: false, error: '', hint: '', emptyText: 'Belum ada pilihan.', columns: 1 },
)

const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>()

const id = useId()

const gridClass = computed(() =>
  props.columns === 4 ? 'grid grid-cols-2 gap-2 sm:grid-cols-4' : props.columns === 2 ? 'grid gap-2 sm:grid-cols-2' : 'grid gap-2',
)

function toggle(value: string, checked: boolean): void {
  const selected = new Set(props.modelValue)
  if (checked) selected.add(value)
  else selected.delete(value)
  // Urutan mengikuti daftar pilihan; nilai terpilih yang tidak ada di daftar dipertahankan di akhir.
  const known = props.options.map((o) => o.value).filter((v) => selected.has(v))
  const unknown = props.modelValue.filter((v) => selected.has(v) && !known.includes(v))
  emit('update:modelValue', [...known, ...unknown])
}
</script>

<template>
  <fieldset
    class="w-full"
    :aria-invalid="Boolean(error) || undefined"
    :aria-describedby="error || hint ? `${id}-help` : undefined"
    :data-testid="`checkbox-group-${name}`"
  >
    <legend class="mb-1 block text-body2 font-medium" :class="error ? 'text-danger' : 'text-slate-700'">
      {{ label }}<span v-if="required" class="text-danger"> *</span>
    </legend>
    <div
      v-if="options.length > 0"
      class="max-h-48 overflow-y-auto rounded-lg border bg-white px-3 py-2.5"
      :class="[error ? 'border-danger' : 'border-slate-300', gridClass]"
    >
      <UiCheckbox
        v-for="option in options"
        :key="option.value"
        :model-value="modelValue.includes(option.value)"
        :name="`${name}[]`"
        :label="option.label"
        :invalid="Boolean(error)"
        :data-testid="`${name}-option-${option.value}`"
        @update:model-value="toggle(option.value, $event)"
      />
    </div>
    <p v-else class="rounded-lg border border-dashed border-slate-300 px-3 py-2.5 text-caption text-slate-500">{{ emptyText }}</p>
    <p
      v-if="error || hint"
      :id="`${id}-help`"
      class="mt-1 text-caption"
      :class="error ? 'text-danger' : 'text-slate-500'"
      :role="error ? 'alert' : undefined"
    >
      {{ error || hint }}
    </p>
  </fieldset>
</template>
