<script setup lang="ts">
/**
 * Tabel daftar cuti (tab Cuti Saya / Perlu Persetujuan / Monitoring dan pengingat). Aksi baris lewat ⋮ (AGENTS.md §1,
 * `cuti-actions-<id>`), kolom Aksi sticky kanan dengan bayangan saat tergulir (`useStickyActionsShadow`). Status =
 * `StatusLayananBadge` dari data server; stepper tidak ditampilkan di tabel (ada di detail).
 */
import { formatTanggalIndonesia as tgl } from '@/shared/utils/dateTime'
import { computed, ref } from 'vue'

import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import { useStickyActionsShadow } from '@/shared/composables/useStickyActionsShadow'

import { aksiBarisCuti, labelAksiCuti } from '../rowActionsCuti'
import type { CutiItem } from '../types'

import StatusLayananBadge from './StatusLayananBadge.vue'

const props = withDefaults(
  defineProps<{ items: CutiItem[]; memuat?: boolean; tampilkanPegawai?: boolean; busyId?: number | null; kosong?: string }>(),
  { memuat: false, tampilkanPegawai: false, busyId: null, kosong: 'Belum ada pengajuan cuti.' },
)

const emit = defineEmits<{ action: [key: string, row: CutiItem] }>()

const wadah = ref<HTMLElement | null>(null)
const { actionsShadow, updateActionsShadow } = useStickyActionsShadow(wadah, [() => props.items, () => props.memuat])

const kolom = computed(() => (props.tampilkanPegawai ? 7 : 6))

const periode = (r: CutiItem): string => (r.tgl_mulai === r.tgl_akhir ? tgl(r.tgl_mulai) : `${tgl(r.tgl_mulai)} s.d. ${tgl(r.tgl_akhir)}`)
</script>

<template>
  <div ref="wadah" class="scrollbar-slim overflow-x-auto" @scroll.passive="updateActionsShadow">
    <table class="w-full min-w-[52rem] border-collapse text-left text-body1" data-testid="cuti-table">
      <thead>
        <tr class="border-b border-slate-200 text-body2 font-semibold text-slate-900">
          <th v-if="tampilkanPegawai" scope="col" class="px-4 py-3">Pegawai</th>
          <th scope="col" class="px-4 py-3">Jenis Cuti</th>
          <th scope="col" class="px-4 py-3">Tanggal</th>
          <th scope="col" class="px-4 py-3">Lama</th>
          <th scope="col" class="px-4 py-3">Atasan / Pejabat</th>
          <th scope="col" class="px-4 py-3">Status</th>
          <th scope="col" class="sticky right-0 bg-white px-4 py-3 text-right" :class="{ 'shadow-sticky-end': actionsShadow }">Aksi</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-200">
        <tr v-if="memuat && items.length === 0">
          <td :colspan="kolom" class="px-4 py-8 text-center text-body2 text-slate-500">Memuat…</td>
        </tr>
        <tr v-else-if="items.length === 0">
          <td :colspan="kolom" class="px-4 py-10 text-center text-body2 text-slate-500" data-testid="cuti-empty">{{ kosong }}</td>
        </tr>
        <tr v-for="row in items" v-else :key="row.id" :data-testid="`cuti-row-${row.id}`">
          <td v-if="tampilkanPegawai" class="px-4 py-3">
            <span class="block font-medium text-slate-900">{{ row.nama }}</span>
            <span class="block text-body2 text-slate-500">{{ row.nip }}</span>
          </td>
          <td class="px-4 py-3 text-slate-700">{{ row.nama_jenis_cuti }}</td>
          <td class="px-4 py-3 text-slate-700">{{ periode(row) }}</td>
          <td class="px-4 py-3 text-slate-700">{{ row.lama_cuti }} hari</td>
          <td class="px-4 py-3 text-body2 text-slate-700">
            <span class="block">{{ row.nama_atasan_langsung ?? '—' }}</span>
            <span class="block text-slate-500">{{ row.nama_yang_menyetujui ?? '—' }}</span>
          </td>
          <td class="px-4 py-3"><StatusLayananBadge :label="row.label_status" :nada="row.nada_status" /></td>
          <td class="sticky right-0 bg-white px-4 py-3 text-right" :class="{ 'shadow-sticky-end': actionsShadow }">
            <RowActionsMenu
              :actions="aksiBarisCuti(row, { busy: busyId === row.id })"
              :label="labelAksiCuti(row, periode(row), tampilkanPegawai)"
              :testid="`cuti-actions-${row.id}`"
              @select="(key) => emit('action', key, row)"
            />
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
