<script setup lang="ts">
/**
 * Tabel perbandingan "Sekarang" ↔ "Diajukan" satu pengajuan perubahan Data Umum (`GET biodata/pengajuan/{id}` →
 * `perubahan`). Dipakai dialog proses (B-04) dan tampilan pemilik di Data Umum (B-20 penutup, MAKE-014).
 */
import { labelKolomBiodata } from '../dataUmum.config'
import type { PerubahanBiodata } from '../types'

defineProps<{ perubahan: PerubahanBiodata[] }>()

function tampil(p: PerubahanBiodata, sisi: 'lama' | 'baru'): string {
  const v = p[sisi]
  if (p.kolom === 'foto') return v ? 'Ada foto' : 'Tanpa foto'
  return v === null || v === '' ? '—' : String(v)
}
</script>

<template>
  <p v-if="perubahan.length === 0" class="text-body2 text-slate-500">Tidak ada kolom yang berubah.</p>
  <table v-else class="w-full border-collapse text-left text-body2" data-testid="perubahan-biodata">
    <thead>
      <tr class="border-b border-slate-200 text-slate-500">
        <th scope="col" class="py-2 pr-3 font-medium">Kolom</th>
        <th scope="col" class="py-2 pr-3 font-medium">Sekarang</th>
        <th scope="col" class="py-2 font-medium">Diajukan</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
      <tr v-for="p in perubahan" :key="p.kolom" :data-kolom="p.kolom">
        <td class="py-2 pr-3 text-slate-700">{{ labelKolomBiodata(p.kolom) }}</td>
        <td class="py-2 pr-3 text-slate-500">{{ tampil(p, 'lama') }}</td>
        <td class="py-2 font-medium text-slate-900">{{ tampil(p, 'baru') }}</td>
      </tr>
    </tbody>
  </table>
</template>
