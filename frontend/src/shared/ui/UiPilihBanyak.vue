<script setup lang="ts">
/**
 * Pilihan ganda berupa daftar kotak ceklis dengan pencarian (CR-134, R10 langkah 1): pengganti `<select multiple>` bawaan
 * browser (AGENTS.md bagian 3). Dipakai tag QnA chatbot dan kategori petugas Halo Simpeg.
 *
 * - `fieldset` + `legend` (jumlah terpilih ikut dibacakan); petunjuk dan galat terhubung lewat `aria-describedby`. Saat galat,
 *   tiap kotak ceklis `aria-invalid` + `aria-describedby` ke pesan galat (role group tidak mendukung `aria-invalid`).
 * - Tiap opsi `UiCheckbox` (input asli tersembunyi, tetap fokusable: Tab berpindah opsi, Spasi mencentang).
 * - `disabled` hanya mengunci kotak ceklis; kotak cari tetap aktif agar fokus tidak lepas saat form dikirim.
 * - Penyaringan hanya menyembunyikan opsi; nilai terpilih yang tidak cocok kata kunci atau tidak ada di `opsi`
 *   (opsi belum/gagal termuat) tetap dipertahankan.
 * - `testid` = akar; tiap opsi `<testid>-<id>`.
 */
import { computed, ref, useId } from 'vue'

import UiBadge from './UiBadge.vue'
import UiCheckbox from './UiCheckbox.vue'
import UiSearchInput from './UiSearchInput.vue'

export interface OpsiPilihBanyak {
  id: number
  label: string
  /** Penanda di samping label, mis. "Tidak Aktif" untuk opsi nonaktif yang sudah terpasang. */
  badge?: { teks: string; status?: number | string }
}

const props = withDefaults(
  defineProps<{
    modelValue: number[]
    opsi: OpsiPilihBanyak[]
    legend: string
    labelCari: string
    placeholderCari?: string
    petunjuk?: string
    teksKosong: string
    /** false = tanpa kotak pencarian (daftar pendek). */
    cari?: boolean
    /** Asterisk wajib di legend (pola `UiRadioGroup`/`FormField`). */
    required?: boolean
    disabled?: boolean
    error?: string
    testid?: string
  }>(),
  { placeholderCari: undefined, petunjuk: undefined, cari: true, required: false, disabled: false, error: undefined, testid: undefined },
)
const emit = defineEmits<{ 'update:modelValue': [value: number[]] }>()

const uid = useId()
const idPetunjuk = `${uid}-petunjuk`
const idGalat = `${uid}-galat`
const describedBy = computed(() => [props.petunjuk ? idPetunjuk : '', props.error ? idGalat : ''].filter(Boolean).join(' ') || undefined)

const kunci = ref('')
const terlihat = computed(() => {
  const k = kunci.value.trim().toLowerCase()
  return k === '' ? props.opsi : props.opsi.filter((o) => o.label.toLowerCase().includes(k))
})
const terpilih = computed(() => new Set(props.modelValue))

function ubah(id: number, centang: boolean): void {
  const set = new Set(props.modelValue)
  if (centang) set.add(id)
  else set.delete(id)
  // Urutan opsi, lalu id terpilih yang tidak ada di opsi tetap dipertahankan.
  const dariOpsi = props.opsi.map((o) => o.id).filter((x) => set.has(x))
  const lain = props.modelValue.filter((x) => set.has(x) && !dariOpsi.includes(x))
  emit('update:modelValue', [...dariOpsi, ...lain])
}
</script>

<template>
  <fieldset
    class="rounded-lg border p-3"
    :class="error ? 'border-danger' : 'border-slate-300'"
    :aria-describedby="describedBy"
    :data-testid="testid"
  >
    <legend class="px-1 text-body2 font-medium" :class="error ? 'text-danger' : 'text-slate-700'">
      {{ legend }}<span v-if="required" class="text-danger"> *</span>{{ ' ' }}<span class="font-normal text-slate-500"
        >({{ modelValue.length }} dipilih)</span
      >
    </legend>
    <!-- Enter di kotak cari hanya menyaring, tidak mengirim form induk. -->
    <div v-if="cari" class="mb-1" @keydown.enter.prevent>
      <UiSearchInput v-model="kunci" :placeholder="placeholderCari ?? labelCari" :label="labelCari" />
    </div>
    <p v-if="petunjuk" :id="idPetunjuk" class="mb-2 text-caption text-slate-500">{{ petunjuk }}</p>
    <ul class="scrollbar-slim max-h-48 space-y-1 overflow-y-auto pr-1">
      <li v-if="terlihat.length === 0" class="py-2 text-sm text-slate-500" data-kosong>{{ teksKosong }}</li>
      <li v-for="o in terlihat" :key="o.id" class="flex items-center gap-2" :data-testid="testid ? `${testid}-${o.id}` : undefined">
        <UiCheckbox
          :model-value="terpilih.has(o.id)"
          :label="o.label"
          :disabled="disabled"
          :invalid="Boolean(error)"
          :described-by="error ? idGalat : undefined"
          @update:model-value="ubah(o.id, $event)"
        />
        <UiBadge v-if="o.badge" tone="neutral" size="sm" :data-status="o.badge.status">{{ o.badge.teks }}</UiBadge>
      </li>
    </ul>
    <p v-if="error" :id="idGalat" class="mt-1 text-caption text-danger" role="alert">{{ error }}</p>
  </fieldset>
</template>
