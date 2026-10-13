<script setup lang="ts">
/**
 * Tabel Daftar Pegawai (§4.1.5, Gambar 18–21): kolom dinamis (dipilih lewat "Filter Kolom"), sel Nama/NIP berisi
 * avatar inisial + nama (tautan ke detail) + NIP.
 * TODO(B-20, WS-2): baris filter per kolom (Gambar 18) dikembalikan setelah parameter filter `GET pegawai` ditetapkan.
 *
 * Penyimpangan disengaja dari mockup: tombol ⋮ berada di kolom "Aksi" paling KANAN (menempel saat digulir),
 * bukan di kiri seperti Gambar 18 — aturan proyek AGENTS.md §1 mewajibkan semua tabel seragam. Menunggu keputusan.
 */
import { SearchX } from 'lucide-vue-next'
import { computed } from 'vue'

import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { UiAvatar } from '@/shared/ui'

import { COLUMN_BY_KEY, fullName } from '../columns'
import type { PegawaiListItem } from '../types'

const props = defineProps<{
  rows: PegawaiListItem[]
  columnKeys: string[]
  loading: boolean
  canDelete: boolean
}>()

const emit = defineEmits<{
  action: [key: 'detail' | 'hapus', row: PegawaiListItem]
}>()

const columns = computed(() => props.columnKeys.map((k) => COLUMN_BY_KEY[k]).filter(Boolean))

function actionsFor(): RowAction[] {
  return [
    { key: 'detail', label: 'Lihat detail' },
    { key: 'hapus', label: 'Hapus', danger: true, hidden: !props.canDelete },
  ]
}
</script>

<template>
  <div class="scrollbar-slim overflow-x-auto">
    <table class="w-full min-w-[52rem] border-collapse text-left text-body1" data-testid="pegawai-table">
      <thead>
        <tr class="text-body2 font-semibold text-slate-900">
          <th v-for="column in columns" :key="column.key" scope="col" class="px-4 pt-4 align-bottom">
            {{ column.label }}
          </th>
          <th scope="col" class="sticky right-0 bg-white px-4 pt-4 text-right align-bottom">Aksi</th>
        </tr>
        <tr class="border-b border-slate-200" aria-hidden="true"><th :colspan="columns.length + 1" class="pb-3" /></tr>
      </thead>

      <tbody class="divide-y divide-slate-200">
        <template v-if="loading && rows.length === 0">
          <tr v-for="n in 5" :key="`s-${n}`" aria-hidden="true">
            <td v-for="column in columns" :key="column.key" class="px-4 py-4">
              <div class="h-4 w-3/4 animate-pulse rounded bg-slate-200" />
            </td>
            <td class="sticky right-0 bg-white px-4 py-4" />
          </tr>
        </template>

        <tr v-else-if="rows.length === 0">
          <td :colspan="columns.length + 1" class="px-4 py-14 text-center" data-testid="pegawai-empty">
            <SearchX class="mx-auto mb-3 h-8 w-8 text-slate-300" aria-hidden="true" />
            <p class="text-body1 font-medium text-slate-700">Tidak ada pegawai yang cocok</p>
            <p class="text-body2 text-slate-500">Ubah kata kunci untuk melihat data lain.</p>
          </td>
        </tr>

        <template v-else>
        <tr v-for="row in rows" :key="row.nip" class="transition-colors hover:bg-slate-50/70" :data-testid="`pegawai-row-${row.nip}`">
          <td v-for="column in columns" :key="column.key" class="px-4 py-3 align-middle">
            <div v-if="column.key === 'nama_nip'" class="flex min-w-[14rem] items-center gap-3">
              <UiAvatar :name="row.nama" size="md" alt="" />
              <div class="min-w-0">
                <RouterLink
                  :to="{ name: 'pegawai-detail', params: { nip: row.nip } }"
                  class="block font-medium text-slate-900 hover:text-brand-tertiary hover:underline"
                >
                  {{ fullName(row) }}
                </RouterLink>
                <span class="block text-body2 text-slate-500">{{ row.nip }}</span>
              </div>
            </div>
            <span v-else class="text-slate-700">{{ column.value(row) }}</span>
          </td>

          <td class="sticky right-0 bg-white px-4 py-3 text-right align-middle">
            <RowActionsMenu
              :actions="actionsFor()"
              :label="`Aksi untuk ${row.nama}`"
              :testid="`pegawai-actions-${row.nip}`"
              @select="(key) => emit('action', key as 'detail' | 'hapus', row)"
            />
          </td>
        </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>
