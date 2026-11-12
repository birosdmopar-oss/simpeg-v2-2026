<script setup lang="ts">
/**
 * Pemilih pegawai halaman Arsip untuk role 1/3/4/5 (F-10, MAKE-091, PRD §9.2): `UiCombobox` dengan pencarian server
 * `GET pegawai?search=` (min. 3 huruf, debounce 300 ms). Memilih saran memancarkan `pilih(nip)`. Role 3 tersaring lingkup
 * *daftar*; pegawai di luar lingkup detail tetap dijawab 403 oleh halaman Arsip (sama dengan Detail Pegawai, PRD §5.1).
 */
import { onBeforeUnmount, ref } from 'vue'

import { UiCombobox } from '@/shared/ui'

import { pegawaiService } from '../../services/pegawai.service'

const emit = defineEmits<{ pilih: [nip: string] }>()

const teks = ref('')
const saran = ref<{ label: string; nip: string }[]>([])
const galat = ref(false)
let timer: ReturnType<typeof setTimeout> | undefined
let permintaan = 0

onBeforeUnmount(() => clearTimeout(timer))

function labelPegawai(p: { nip: string; nama: string; glr_awal: string | null; glr_akhir: string | null }): string {
  const nama = [p.glr_awal, p.nama].filter(Boolean).join(' ') + (p.glr_akhir ? `, ${p.glr_akhir}` : '')
  return `${p.nip} — ${nama}`
}

async function cari(kata: string): Promise<void> {
  const ini = ++permintaan
  try {
    const hasil = await pegawaiService.list({ page: 1, per_page: 10, search: kata })
    if (ini !== permintaan) return
    galat.value = false
    saran.value = hasil.items.map((p) => ({ nip: p.nip, label: labelPegawai(p) }))
  } catch {
    if (ini !== permintaan) return
    galat.value = true
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
  clearTimeout(timer)
  if (nilai.trim().length < 3) {
    permintaan++
    saran.value = []
    return
  }
  timer = setTimeout(() => void cari(nilai.trim()), 300)
}
</script>

<template>
  <div data-testid="arsip-pemilih-pegawai">
    <label class="mb-1.5 block text-body2 font-medium text-slate-700" for="arsip-pemilih-input">Pegawai</label>
    <UiCombobox
      id="arsip-pemilih-input"
      :model-value="teks"
      :suggestions="saran.map((x) => x.label)"
      placeholder="Cari NIP atau nama pegawai (min. 3 huruf)"
      aria-label="Pegawai"
      @update:model-value="onInput"
    />
    <span v-if="galat" role="alert" class="mt-1 block text-caption text-danger" data-testid="arsip-pemilih-galat">Pencarian pegawai gagal. Coba lagi.</span>
  </div>
</template>
