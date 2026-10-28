<script setup lang="ts">
/**
 * Pemilih pegawai untuk input admin (role 1): `UiCombobox` dengan pencarian server `GET pegawai?search=` (min. 3 huruf,
 * debounce 300 ms). Memilih saran memancarkan `pilih(nip)`; mengetik ulang memancarkan `pilih('')` sampai saran dipilih lagi.
 * Dipakai form PMK (dan dapat dipakai form Modul C lain).
 */
import { onBeforeUnmount, ref, watch } from 'vue'

import { pegawaiService } from '@/features/kepegawaian/services/pegawai.service'
import { UiCombobox } from '@/shared/ui'

const props = withDefaults(defineProps<{ nip?: string; invalid?: boolean; error?: string; label?: string }>(), { nip: '', invalid: false, error: '', label: 'Pegawai' })
const emit = defineEmits<{ pilih: [nip: string] }>()

const teks = ref('')
const saran = ref<{ label: string; nip: string }[]>([])
let timer: ReturnType<typeof setTimeout> | undefined

watch(
  () => props.nip,
  (nip) => {
    if (nip === '') teks.value = ''
  },
)
onBeforeUnmount(() => clearTimeout(timer))

function labelPegawai(p: { nip: string; nama: string; glr_awal: string | null; glr_akhir: string | null }): string {
  const nama = [p.glr_awal, p.nama].filter(Boolean).join(' ') + (p.glr_akhir ? `, ${p.glr_akhir}` : '')
  return `${p.nip} — ${nama}`
}

async function cari(kata: string): Promise<void> {
  try {
    const hasil = await pegawaiService.list({ page: 1, per_page: 10, search: kata })
    saran.value = hasil.items.map((p) => ({ nip: p.nip, label: labelPegawai(p) }))
  } catch {
    saran.value = []
  }
}

function onInput(nilai: string): void {
  teks.value = nilai
  const dipilih = saran.value.find((s) => s.label === nilai)
  if (dipilih) {
    emit('pilih', dipilih.nip)
    return
  }
  emit('pilih', '')
  clearTimeout(timer)
  if (nilai.trim().length < 3) {
    saran.value = []
    return
  }
  timer = setTimeout(() => void cari(nilai.trim()), 300)
}
</script>

<template>
  <div data-testid="pegawai-picker">
    <span class="mb-1.5 block text-body2 font-medium text-slate-700">{{ label }}<span class="text-danger" aria-hidden="true"> *</span></span>
    <UiCombobox
      :model-value="teks"
      :suggestions="saran.map((x) => x.label)"
      placeholder="Cari NIP atau nama pegawai (min. 3 huruf)"
      :aria-label="label"
      :invalid="invalid || !!error"
      @update:model-value="onInput"
    />
    <span v-if="error" role="alert" class="mt-1 block text-caption text-danger">{{ error }}</span>
    <span v-else-if="nip" class="mt-1 block text-caption text-slate-500" data-testid="pegawai-picker-terpilih">NIP terpilih: {{ nip }}</span>
  </div>
</template>
