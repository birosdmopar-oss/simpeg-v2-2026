<script setup lang="ts">
/**
 * Pilihan tag ganda QnA (PRD Fase 8 v1.1 §7.10.4, keputusan reviewer Q3 = A): daftar `UiCheckbox` + `UiSearchInput` di fitur,
 * karena `shared/ui` belum punya kontrol pilihan ganda. Tag status 2 yang sudah terpasang tampil berbadge "Tidak Aktif" dan
 * tetap terpilih sampai dilepas admin (F8-49a); tag status 2 lain tidak bisa ditambahkan (opsi hanya tag aktif, F8-41).
 */
import { computed, ref } from 'vue'

import { UiBadge, UiCheckbox, UiSearchInput } from '@/shared/ui'

export interface OpsiTag {
  id: number
  judul: string
  /** false = tag Tidak Aktif yang sudah terpasang. */
  aktif: boolean
}

const props = defineProps<{ modelValue: number[]; opsi: OpsiTag[]; disabled?: boolean; error?: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: number[]] }>()

const cari = ref('')
const terlihat = computed(() => {
  const kunci = cari.value.trim().toLowerCase()
  return kunci === '' ? props.opsi : props.opsi.filter((t) => t.judul.toLowerCase().includes(kunci))
})
const terpilih = computed(() => new Set(props.modelValue))

function ubah(id: number, centang: boolean): void {
  const set = new Set(props.modelValue)
  if (centang) set.add(id)
  else set.delete(id)
  emit(
    'update:modelValue',
    props.opsi.map((t) => t.id).filter((x) => set.has(x)),
  )
}
</script>

<template>
  <fieldset class="rounded-lg border border-slate-300 p-3" :aria-invalid="error ? 'true' : undefined" data-testid="chatbot-qna-tag">
    <legend class="px-1 text-body2 font-medium text-slate-700">Tag <span class="font-normal text-slate-500">({{ modelValue.length }} dipilih)</span></legend>
    <UiSearchInput v-model="cari" placeholder="Cari tag" label="Cari tag" class="mb-1" />
    <p class="mb-2 text-caption text-slate-500">Opsional. Kata kunci tambahan yang ikut dicocokkan mesin NLP.</p>
    <ul class="scrollbar-slim max-h-48 space-y-1 overflow-y-auto pr-1">
      <li v-if="terlihat.length === 0" class="py-2 text-sm text-slate-500">Tidak ada tag yang cocok.</li>
      <li v-for="t in terlihat" :key="t.id" class="flex items-center gap-2" :data-testid="`chatbot-qna-tag-${t.id}`">
        <UiCheckbox :model-value="terpilih.has(t.id)" :label="t.judul" :disabled="disabled" @update:model-value="ubah(t.id, $event)" />
        <UiBadge v-if="!t.aktif" tone="neutral" size="sm" data-status="2">Tidak Aktif</UiBadge>
      </li>
    </ul>
    <p v-if="error" class="mt-1 text-body2 text-danger" role="alert">{{ error }}</p>
  </fieldset>
</template>
