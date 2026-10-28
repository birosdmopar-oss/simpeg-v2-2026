<script setup lang="ts">
/**
 * Tabel Riwayat PMK final (`riwayat_pmk`, dari impor/SIASN — tidak dibuat lewat aplikasi). Hanya role 1 (`can_edit`
 * server) yang mendapat menu ⋮: Kelola arsip → Hapus (`danger`). Aksi baris lewat ⋮ (AGENTS.md §1, `riwayat-pmk-actions-<id>`).
 */
import { formatTanggalIndonesia as tgl } from '@/shared/utils/dateTime'
import { ref } from 'vue'

import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { useStickyActionsShadow } from '@/shared/composables/useStickyActionsShadow'

import type { RiwayatPmkItem } from '../types'

const props = withDefaults(defineProps<{ items: RiwayatPmkItem[]; memuat?: boolean; tampilkanNip?: boolean; bisaUbah?: boolean }>(), {
  memuat: false,
  tampilkanNip: false,
  bisaUbah: false,
})

const emit = defineEmits<{ action: [key: string, row: RiwayatPmkItem] }>()

const wadah = ref<HTMLElement | null>(null)
const { actionsShadow, updateActionsShadow } = useStickyActionsShadow(wadah, [() => props.items, () => props.memuat])

const aksi = (): RowAction[] => [
  { key: 'arsip', label: 'Kelola arsip' },
  { key: 'hapus', label: 'Hapus', danger: true },
]

const periode = (r: RiwayatPmkItem): string => (r.tgl_awal && r.tgl_akhir ? `${tgl(r.tgl_awal)} s.d. ${tgl(r.tgl_akhir)}` : '—')
</script>

<template>
  <div ref="wadah" class="scrollbar-slim overflow-x-auto" @scroll.passive="updateActionsShadow">
    <table class="w-full min-w-[52rem] border-collapse text-left text-body1" data-testid="riwayat-pmk-table">
      <thead>
        <tr class="border-b border-slate-200 text-body2 font-semibold text-slate-900">
          <th v-if="tampilkanNip" scope="col" class="px-4 py-3">NIP</th>
          <th scope="col" class="px-4 py-3">No. SK</th>
          <th scope="col" class="px-4 py-3">Tanggal SK</th>
          <th scope="col" class="px-4 py-3">Instansi</th>
          <th scope="col" class="px-4 py-3">Periode</th>
          <th scope="col" class="px-4 py-3">Masa Kerja</th>
          <th scope="col" class="px-4 py-3">Arsip SK</th>
          <th v-if="bisaUbah" scope="col" class="sticky right-0 bg-white px-4 py-3 text-right" :class="{ 'shadow-sticky-end': actionsShadow }">Aksi</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-200">
        <tr v-if="memuat && items.length === 0">
          <td colspan="8" class="px-4 py-8 text-center text-body2 text-slate-500">Memuat…</td>
        </tr>
        <tr v-else-if="items.length === 0">
          <td colspan="8" class="px-4 py-10 text-center text-body2 text-slate-500" data-testid="riwayat-pmk-empty">Belum ada riwayat PMK.</td>
        </tr>
        <tr v-for="row in items" v-else :key="row.id" :data-testid="`riwayat-pmk-row-${row.id}`">
          <td v-if="tampilkanNip" class="px-4 py-3 text-slate-700">{{ row.nip }}</td>
          <td class="px-4 py-3 text-slate-700">{{ row.no_sk }}</td>
          <td class="px-4 py-3 text-slate-700">{{ tgl(row.tgl_sk) }}</td>
          <td class="px-4 py-3 text-slate-700">{{ row.instansi ?? '—' }}</td>
          <td class="px-4 py-3 text-slate-700">{{ periode(row) }}</td>
          <td class="px-4 py-3 text-slate-700">{{ row.mker_th }} th {{ row.mker_bl }} bln</td>
          <td class="px-4 py-3 text-body2 text-slate-700">
            <span v-if="row.arsip.length === 0" class="text-slate-500">Belum ada</span>
            <template v-else>{{ row.arsip.length }} berkas</template>
          </td>
          <td v-if="bisaUbah" class="sticky right-0 bg-white px-4 py-3 text-right" :class="{ 'shadow-sticky-end': actionsShadow }">
            <RowActionsMenu
              :actions="aksi()"
              :label="`Aksi untuk riwayat PMK ${row.no_sk}`"
              :testid="`riwayat-pmk-actions-${row.id}`"
              @select="(key) => emit('action', key, row)"
            />
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
