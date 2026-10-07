<script setup lang="ts">
/**
 * Tab "Data Umum" Detail Pegawai — biodata dari kolom `pegawai` (DDL B-01), hanya-lihat.
 * Ubah biodata (form + alur verifikasi B-04 dengan perbandingan di ApprovalDialog slot `diff`) dibangun WS-2 di
 * B-03/B-04; sampai itu tidak ada tombol simpan agar halaman tidak berpura-pura menyimpan.
 * Kode `jenis_kelamin` / `status_pernikahan` mengikuti COMMENT DDL.
 */
import { computed } from 'vue'

import type { Pegawai } from '../types'

const props = defineProps<{ pegawai: Pegawai }>()

const JENIS_KELAMIN: Record<number, string> = { 1: 'Laki-laki', 2: 'Perempuan' }
const STATUS_PERNIKAHAN: Record<number, string> = { 1: 'Menikah', 2: 'Tidak Menikah' }

function tanggal(value: string | null): string {
  if (!value) return ''
  const d = new Date(`${value.slice(0, 10)}T00:00:00`)
  return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })
}

const rows = computed(() => {
  const p = props.pegawai
  const tempatLahir = p.kabupaten_kota_lahir || p.kabupaten_kota_lahir_lain || ''
  const provinsiLahir = p.provinsi_lahir || p.provinsi_lahir_lain || ''
  return [
    { key: 'nama', label: 'Nama', value: p.nama },
    { key: 'glr_awal', label: 'Gelar Depan', value: p.glr_awal },
    { key: 'glr_akhir', label: 'Gelar Belakang', value: p.glr_akhir },
    { key: 'nip_lama', label: 'NIP Lama', value: p.nip_lama },
    { key: 'tgl_lahir', label: 'Tanggal Lahir', value: tanggal(p.tgl_lahir) },
    { key: 'kabupaten_kota_lahir', label: 'Kabupaten/Kota Lahir', value: tempatLahir },
    { key: 'provinsi_lahir', label: 'Provinsi Lahir', value: provinsiLahir },
    { key: 'jenis_kelamin', label: 'Jenis Kelamin', value: JENIS_KELAMIN[p.jenis_kelamin] ?? '' },
    { key: 'agama', label: 'Agama', value: p.agama },
    { key: 'status_pernikahan', label: 'Status Pernikahan', value: p.status_pernikahan ? (STATUS_PERNIKAHAN[p.status_pernikahan] ?? '') : '' },
    { key: 'nik', label: 'NIK', value: p.nik },
    { key: 'npwp', label: 'NPWP', value: p.npwp },
    { key: 'bpjs_kes', label: 'BPJS Kesehatan', value: p.bpjs_kes },
    { key: 'bpjs_ket', label: 'BPJS Ketenagakerjaan', value: p.bpjs_ket },
    { key: 'no_taspen', label: 'No. Taspen', value: p.no_taspen },
    { key: 'no_hp', label: 'No. HP', value: p.no_hp },
    { key: 'jenis_kerabat', label: 'Jenis Kerabat', value: p.jenis_kerabat },
    { key: 'no_telp_kerabat', label: 'No. Telp Kerabat', value: p.no_telp_kerabat },
    { key: 'jenis_pegawai', label: 'Jenis Pegawai', value: p.jenis_pegawai },
    { key: 'jenis_status', label: 'Jenis Status', value: p.jenis_status },
    { key: 'tmt_status', label: 'TMT Status', value: tanggal(p.tmt_status) },
  ]
})
</script>

<template>
  <dl class="grid gap-x-6 gap-y-4 px-5 py-5 md:grid-cols-2" data-testid="data-umum">
    <div v-for="row in rows" :key="row.key" :data-field="row.key">
      <dt class="text-body2 font-medium text-slate-500">{{ row.label }}</dt>
      <dd class="mt-0.5 text-body1 text-slate-900">{{ row.value || '—' }}</dd>
    </div>
  </dl>
</template>
