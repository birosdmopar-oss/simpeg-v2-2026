<script setup lang="ts">
/**
 * Tabel pengajuan PMK (tab Pengajuan Saya / Perlu Persetujuan / Monitoring). Aksi baris lewat ⋮ (AGENTS.md §1,
 * `pmk-actions-<id>`), kolom Aksi sticky kanan dengan bayangan saat tergulir. Status = `StatusLayananBadge` dari server.
 */
import { formatTanggalIndonesia as tgl } from '@/shared/utils/dateTime'
import { computed, ref } from 'vue'

import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import { useStickyActionsShadow } from '@/shared/composables/useStickyActionsShadow'

import { aksiBarisPmk, labelAksiPmk } from '../rowActionsPmk'
import type { PmkItem } from '../types'

import StatusLayananBadge from './StatusLayananBadge.vue'

const props = withDefaults(
  defineProps<{ items: PmkItem[]; memuat?: boolean; tampilkanPegawai?: boolean; busyId?: number | null; kosong?: string; labelBerkas?: Record<string, string> }>(),
  { memuat: false, tampilkanPegawai: false, busyId: null, kosong: 'Belum ada pengajuan PMK.', labelBerkas: () => ({}) },
)

const emit = defineEmits<{ action: [key: string, row: PmkItem] }>()

const wadah = ref<HTMLElement | null>(null)
const { actionsShadow, updateActionsShadow } = useStickyActionsShadow(wadah, [() => props.items, () => props.memuat])
const kolom = computed(() => (props.tampilkanPegawai ? 7 : 6))

const periode = (r: PmkItem): string => `${tgl(r.tgl_awal)} s.d. ${tgl(r.tgl_akhir)}`
</script>

<template>
  <div ref="wadah" class="table-fill scrollbar-slim overflow-x-auto" @scroll.passive="updateActionsShadow">
    <table class="w-full min-w-[52rem] border-collapse text-left text-body1" data-testid="pmk-table">
      <thead>
        <tr class="border-b border-slate-200 text-body2 font-semibold text-slate-900">
          <th v-if="tampilkanPegawai" scope="col" class="px-4 py-3">NIP</th>
          <th scope="col" class="px-4 py-3">Jenis</th>
          <th scope="col" class="px-4 py-3">Instansi</th>
          <th scope="col" class="px-4 py-3">Periode</th>
          <th scope="col" class="px-4 py-3">Masa Kerja</th>
          <th scope="col" class="px-4 py-3">Status</th>
          <th scope="col" class="sticky right-0 bg-white px-4 py-3 text-right" :class="{ 'shadow-sticky-end': actionsShadow }">Aksi</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-200">
        <tr v-if="memuat && items.length === 0">
          <td :colspan="kolom" class="px-4 py-8 text-center text-body2 text-slate-500">Memuat…</td>
        </tr>
        <tr v-else-if="items.length === 0">
          <td :colspan="kolom" class="px-4 py-10 text-center text-body2 text-slate-500" data-testid="pmk-empty">{{ kosong }}</td>
        </tr>
        <tr v-for="row in items" v-else :key="row.id" :data-testid="`pmk-row-${row.id}`">
          <td v-if="tampilkanPegawai" class="px-4 py-3 text-slate-700">{{ row.nip }}</td>
          <td class="px-4 py-3 text-slate-700">{{ row.jenis_pmk }}</td>
          <td class="px-4 py-3 text-slate-700">{{ row.instansi }}</td>
          <td class="px-4 py-3 text-slate-700">{{ periode(row) }}</td>
          <td class="px-4 py-3 text-slate-700">{{ row.mker_th }} th {{ row.mker_bl }} bln</td>
          <td class="px-4 py-3">
            <StatusLayananBadge :label="row.label_status" :nada="row.nada_status" />
            <span v-if="row.status === 2 && row.reason_note" class="mt-1 block text-caption text-danger">Ditolak: {{ row.reason_note }}</span>
          </td>
          <td class="sticky right-0 bg-white px-4 py-3 text-right" :class="{ 'shadow-sticky-end': actionsShadow }">
            <RowActionsMenu
              :actions="aksiBarisPmk(row, { busy: busyId === row.id, labelBerkas })"
              :label="labelAksiPmk(row, periode(row))"
              :testid="`pmk-actions-${row.id}`"
              @select="(key) => emit('action', key, row)"
            />
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
