<script setup lang="ts">
/**
 * Tabel daftar pegawai Laporan Tukin (PRD 5C §7.3; API `GET presensi/tukin/pegawai[-il]`). Kolom mengikuti respons API
 * (Nama + NIP, Gol/Ruang, Jabatan, Eselon, Unit/Satker, Pendidikan, Jenis Pegawai), bukan semua kolom legacy `list_lt.php`.
 * Aksi ⋮ (AGENTS.md §1): "Lihat laporan" lalu "Unduh PDF"; testid `tukin-actions-<nip>`.
 */
import { ref } from 'vue'

import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { useStickyActionsShadow } from '@/shared/composables/useStickyActionsShadow'
import { UiAvatar } from '@/shared/ui'

import type { PegawaiTukin } from '../types.laporan.tukin'

const props = withDefaults(defineProps<{ items: PegawaiTukin[]; memuat?: boolean; mengunduh?: string | null }>(), { memuat: false, mengunduh: null })

const emit = defineEmits<{ action: [key: 'lihat' | 'pdf', row: PegawaiTukin] }>()

const wadah = ref<HTMLElement | null>(null)
const { actionsShadow, updateActionsShadow } = useStickyActionsShadow(wadah, [() => props.items, () => props.memuat])

const aksi = (): RowAction[] => [
  { key: 'lihat', label: 'Lihat laporan' },
  { key: 'pdf', label: 'Unduh PDF', disabled: props.mengunduh !== null },
]
const pendidikan = (p: PegawaiTukin['pendidikan']): string => [p.strata, p.jurusan].filter((s) => s !== '').join(' - ')
</script>

<template>
  <div ref="wadah" class="table-fill scrollbar-slim overflow-x-auto [--table-fill-min:20rem]" @scroll.passive="updateActionsShadow">
    <table class="w-full border-collapse text-left text-body2" data-testid="tukin-pegawai-table">
      <thead>
        <tr class="border-b border-slate-200 font-semibold text-slate-900">
          <th scope="col" class="col-sticky-kiri px-4 py-3">Nama</th>
          <th scope="col" class="px-4 py-3">Gol/Ruang</th>
          <th scope="col" class="px-4 py-3">Jabatan</th>
          <th scope="col" class="px-4 py-3">Eselon</th>
          <th scope="col" class="px-4 py-3">Unit / Satker</th>
          <th scope="col" class="px-4 py-3">Pendidikan</th>
          <th scope="col" class="px-4 py-3">Jenis Pegawai</th>
          <th scope="col" class="sticky right-0 bg-white px-4 py-3 text-right" :class="{ 'shadow-sticky-end': actionsShadow }">Aksi</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-200">
        <tr v-if="memuat && items.length === 0">
          <td colspan="8" class="px-4 py-8 text-center text-slate-500">Memuat…</td>
        </tr>
        <tr v-else-if="items.length === 0">
          <td colspan="8" class="px-4 py-10 text-center text-slate-500" data-testid="tukin-pegawai-kosong">Tidak ada pegawai untuk filter ini.</td>
        </tr>
        <tr v-for="row in items" v-else :key="row.nip" class="align-top" :data-testid="`tukin-row-${row.nip}`">
          <td class="col-sticky-kiri min-w-[20rem] px-4 py-3">
            <div class="flex items-center gap-3">
              <UiAvatar :name="row.nama" size="md" alt="" />
              <div class="min-w-0">
                <span class="block font-medium text-slate-900">{{ row.nama }}</span>
                <span class="block text-slate-500">{{ row.nip }}</span>
              </div>
            </div>
          </td>
          <td class="px-4 py-3 text-slate-700">{{ row.gol_ruang || '-' }}</td>
          <td class="min-w-[18rem] px-4 py-3 text-slate-700">{{ row.jabatan || '-' }}</td>
          <td class="px-4 py-3 text-slate-700">{{ row.eselon || '-' }}</td>
          <td class="min-w-[24rem] px-4 py-3 text-slate-700">
            <span class="block">{{ row.unit || '-' }}</span>
            <span v-if="row.satker" class="block text-caption text-slate-500">{{ row.satker }}</span>
          </td>
          <td class="min-w-[18rem] px-4 py-3 text-slate-700">{{ pendidikan(row.pendidikan) || '-' }}</td>
          <td class="min-w-[10rem] px-4 py-3 text-slate-700">{{ row.jenis_pegawai || '-' }}</td>
          <td class="sticky right-0 bg-white px-4 py-3 text-right" :class="{ 'shadow-sticky-end': actionsShadow }">
            <RowActionsMenu :actions="aksi()" :label="`Aksi untuk ${row.nama}`" :testid="`tukin-actions-${row.nip}`" @select="(key) => emit('action', key as 'lihat' | 'pdf', row)" />
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
