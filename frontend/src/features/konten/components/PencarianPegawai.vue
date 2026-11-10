<script setup lang="ts">
/**
 * Pencarian pegawai sisi-server untuk pemilih target (PRD Fase 7 v2.0): mengetik ≥ 3 huruf (NIP/nama) memanggil `cari`
 * (debounce 300 ms) dan hasilnya menjadi saran; memilih saran memancarkan `pilih(nip, label)` lalu kolom dikosongkan.
 * Tidak pernah memuat seluruh pegawai sekaligus. Pola sama dengan `PegawaiPicker` Modul C (GET pegawai?search=).
 */
import { onBeforeUnmount, ref } from 'vue'

import { UiCombobox } from '@/shared/ui'

export interface HasilPegawai {
  value: string
  label: string
}

const props = withDefaults(
  defineProps<{
    cari: (kata: string) => Promise<HasilPegawai[]>
    label: string
    placeholder?: string
    disabled?: boolean
  }>(),
  { placeholder: 'Cari NIP atau nama (min. 3 huruf)…', disabled: false },
)

const emit = defineEmits<{ pilih: [nip: string, label: string] }>()

const teks = ref('')
const saran = ref<HasilPegawai[]>([])
let timer: ReturnType<typeof setTimeout> | undefined
let token = 0

onBeforeUnmount(() => clearTimeout(timer))

async function jalankan(kata: string): Promise<void> {
  const saya = ++token
  try {
    const hasil = await props.cari(kata)
    if (saya === token) saran.value = hasil
  } catch {
    if (saya === token) saran.value = []
  }
}

function onInput(nilai: string): void {
  const dipilih = saran.value.find((s) => s.label === nilai)

  if (dipilih) {
    emit('pilih', dipilih.value, dipilih.label)
    teks.value = ''
    saran.value = []
    token++
    return
  }

  teks.value = nilai
  clearTimeout(timer)

  if (nilai.trim().length < 3) {
    token++
    saran.value = []
    return
  }

  timer = setTimeout(() => void jalankan(nilai.trim()), 300)
}
</script>

<template>
  <UiCombobox
    :model-value="teks"
    :suggestions="saran.map((s) => s.label)"
    :placeholder="placeholder"
    :aria-label="label"
    :disabled="disabled"
    data-testid="pencarian-pegawai"
    @update:model-value="onInput"
  />
</template>
