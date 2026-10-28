<script setup lang="ts">
/**
 * Tabel Status Layanan (Gambar 17 / PRD §6.11.1): Nama Pegawai (avatar + NIP) · Jenis Layanan (+ ringkasan) · Status Layanan
 * (`UiStepper` dari `tahap[]` + badge server) · PIC/Petugas · Aksi ⋮. Read-only: satu aksi "Lihat detail" (`aksi.lihat_detail`
 * dari server; tanpa itu — mis. role 4/5 — tidak ada menu). `layanan-actions-<jenis>-<id>`, kolom Aksi sticky kanan.
 */
import { formatTanggalIndonesia as tgl } from '@/shared/utils/dateTime'
import { ref } from 'vue'

import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { useStickyActionsShadow } from '@/shared/composables/useStickyActionsShadow'
import { UiAvatar, UiStepper } from '@/shared/ui'

import { nadaStepper, tahapKeSteps } from '../tahapStepper'
import type { LayananStatusItem } from '../types'

import StatusLayananBadge from './StatusLayananBadge.vue'

const props = withDefaults(defineProps<{ items: LayananStatusItem[]; memuat?: boolean; kosong?: string }>(), {
  memuat: false,
  kosong: 'Tidak ada data pada lingkup ini.',
})

const emit = defineEmits<{ action: [key: string, row: LayananStatusItem] }>()

const wadah = ref<HTMLElement | null>(null)
const { actionsShadow, updateActionsShadow } = useStickyActionsShadow(wadah, [() => props.items, () => props.memuat])

const aksi = (row: LayananStatusItem): RowAction[] => [{ key: 'detail', label: 'Lihat detail', hidden: !row.lihat_detail }]
const kunci = (row: LayananStatusItem): string => `${row.jenis}-${row.id}`
</script>

<template>
  <div ref="wadah" class="table-fill scrollbar-slim overflow-x-auto" @scroll.passive="updateActionsShadow">
    <table class="w-full min-w-[60rem] border-collapse text-left text-body1" data-testid="layanan-status-table">
      <thead>
        <tr class="border-b border-slate-200 text-body2 font-semibold text-slate-900">
          <th scope="col" class="px-4 py-3">Nama Pegawai</th>
          <th scope="col" class="px-4 py-3">Jenis Layanan</th>
          <th scope="col" class="px-4 py-3">Status Layanan</th>
          <th scope="col" class="px-4 py-3">PIC / Petugas</th>
          <th scope="col" class="sticky right-0 bg-white px-4 py-3 text-right" :class="{ 'shadow-sticky-end': actionsShadow }">Aksi</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-200">
        <tr v-if="memuat && items.length === 0">
          <td colspan="5" class="px-4 py-8 text-center text-body2 text-slate-500">Memuat…</td>
        </tr>
        <tr v-else-if="items.length === 0">
          <td colspan="5" class="px-4 py-10 text-center text-body2 text-slate-500" data-testid="layanan-status-empty">{{ kosong }}</td>
        </tr>
        <tr v-for="row in items" v-else :key="kunci(row)" class="align-top" :data-testid="`layanan-row-${kunci(row)}`">
          <td class="px-4 py-3">
            <div class="flex items-center gap-3">
              <UiAvatar :name="row.nama" size="md" alt="" />
              <div class="min-w-0">
                <span class="block font-medium text-slate-900">{{ row.nama }}</span>
                <span class="block text-body2 text-slate-500">{{ row.nip }}</span>
                <span v-if="row.satker" class="block text-caption text-slate-500">{{ row.satker }}</span>
              </div>
            </div>
          </td>
          <td class="px-4 py-3">
            <span class="block font-medium text-slate-900" data-testid="layanan-jenis">{{ row.jenis_layanan }}</span>
            <span v-if="row.ringkasan" class="block max-w-xs text-body2 text-slate-600">{{ row.ringkasan }}</span>
            <span v-if="row.tanggal_pengajuan" class="block text-caption text-slate-500">Diajukan {{ tgl(row.tanggal_pengajuan.slice(0, 10)) }}</span>
          </td>
          <td class="min-w-[18rem] px-4 py-3">
            <StatusLayananBadge :label="row.label_status" :nada="row.nada_status" class="mb-2" />
            <UiStepper :steps="tahapKeSteps(row.tahap)" :tone="nadaStepper(row.nada_status)" />
          </td>
          <td class="px-4 py-3 text-body2 text-slate-700" data-testid="layanan-petugas">
            <ul v-if="row.petugas.length" class="space-y-1">
              <li v-for="p in row.petugas" :key="`${p.peran}-${p.nip}`">
                <span class="text-slate-500">{{ p.peran }}:</span> {{ p.nama }}
              </li>
            </ul>
            <span v-else class="text-slate-500">{{ row.catatan ?? '—' }}</span>
          </td>
          <td class="sticky right-0 bg-white px-4 py-3 text-right" :class="{ 'shadow-sticky-end': actionsShadow }">
            <RowActionsMenu
              v-if="row.lihat_detail"
              :actions="aksi(row)"
              :label="`Aksi untuk ${row.jenis_layanan} ${row.nama}`"
              :testid="`layanan-actions-${kunci(row)}`"
              @select="(key) => emit('action', key, row)"
            />
            <span v-else class="text-slate-400" aria-hidden="true">—</span>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
