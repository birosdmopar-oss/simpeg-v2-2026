<script setup lang="ts">
/**
 * Tabel daftar TB/IB: NIP · Nama · Status (badge dari server) · Tanggal · Aksi ⋮ (AGENTS §1; item dari `aksi {}` server).
 * Kolom Aksi sticky kanan; wadah tabel memakai pola gulir CR-069/CR-070.
 */
import { ref, toRef } from 'vue'

import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import { useStickyActionsShadow } from '@/shared/composables/useStickyActionsShadow'
import { UiBadge } from '@/shared/ui'

import { aksiBarisStudi, labelAksiStudi } from '../rowActionsStudi'
import type { StudiConfig } from '../studiConfig'
import type { StudiItem } from '../services/studi.service'
import type { NadaStatus } from '../types'

const props = defineProps<{ rows: StudiItem[]; cfg: StudiConfig; memuat: boolean; kosongPesan: string }>()
const emit = defineEmits<{ aksi: [row: StudiItem, key: string] }>()

const tableScroller = ref<HTMLElement | null>(null)
const { actionsShadow, updateActionsShadow } = useStickyActionsShadow(tableScroller, [toRef(props, 'rows'), toRef(props, 'memuat')])

/** Nada status dari server → nada badge; nilai tak dikenal jatuh ke netral. */
const TONE: Partial<Record<NadaStatus, 'success' | 'warning' | 'danger' | 'info' | 'neutral'>> = {
  success: 'success',
  warning: 'warning',
  danger: 'danger',
  info: 'info',
  primary: 'info',
  neutral: 'neutral',
  secondary: 'neutral',
}
const nadaTone = (nada: NadaStatus) => TONE[nada] ?? 'neutral'

const tanggal = (t: string): string => (t ? t.split('-').reverse().join('/') : '-')
</script>

<template>
  <div ref="tableScroller" class="table-fill scrollbar-slim overflow-x-auto" :data-testid="`${cfg.testid}-table-scroll`" @scroll.passive="updateActionsShadow">
    <table class="min-w-full text-sm" :data-testid="`${cfg.testid}-tabel`">
      <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
        <tr>
          <th class="whitespace-nowrap px-4 py-3">NIP</th>
          <th class="min-w-[12rem] px-4 py-3">Nama</th>
          <th class="min-w-[14rem] px-4 py-3">Institusi</th>
          <th class="whitespace-nowrap px-4 py-3">Periode</th>
          <th class="whitespace-nowrap px-4 py-3">Status</th>
          <th class="sticky right-0 z-10 whitespace-nowrap bg-slate-50 px-4 py-3 text-right" :class="{ 'shadow-sticky-end': actionsShadow }">Aksi</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <tr v-if="memuat">
          <td colspan="6" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
        </tr>
        <tr v-else-if="rows.length === 0">
          <td colspan="6" class="px-4 py-8 text-center text-slate-500" :data-testid="`${cfg.testid}-kosong`">{{ kosongPesan }}</td>
        </tr>
        <tr v-for="row in rows" v-else :key="row.id" class="group hover:bg-slate-50" :data-testid="`${cfg.testid}-baris-${row.id}`">
          <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-700">{{ row.nip }}</td>
          <td class="px-4 py-3 font-medium text-slate-800">{{ row.nama ?? '-' }}</td>
          <td class="px-4 py-3 text-slate-600">{{ row.institusi_pendidikan || '-' }}</td>
          <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ tanggal(row.tgl_mulai) }} – {{ tanggal(row.tgl_akhir) }}</td>
          <td class="px-4 py-3" :data-testid="`${cfg.testid}-status-${row.id}`">
            <UiBadge :tone="nadaTone(row.nada_status)" size="sm" class="whitespace-nowrap">
              {{ row.label_status }}
            </UiBadge>
          </td>
          <td class="sticky right-0 z-10 bg-white px-4 py-3 text-right group-hover:bg-slate-50" :class="{ 'shadow-sticky-end': actionsShadow }">
            <RowActionsMenu
              :actions="aksiBarisStudi(row, cfg)"
              :label="labelAksiStudi(row, cfg)"
              :testid="`${cfg.testid}-actions-${row.id}`"
              @select="emit('aksi', row, $event)"
            />
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
