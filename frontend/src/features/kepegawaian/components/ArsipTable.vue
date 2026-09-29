<script setup lang="ts">
/**
 * Kartu "Arsip Data Umum" (§4.1.6, Gambar 22): judul + subjudul, kotak pencarian, tombol "+ Tambah Arsip", dan tabel
 * Jenis Arsip / Arsip (tombol folder biru untuk membuka berkas) / Aksi.
 * Aksi Edit & Hapus berada di menu ⋮ (AGENTS.md §1), bukan dua ikon terpisah seperti pada mockup.
 */
import { FolderOpen, Plus } from 'lucide-vue-next'
import { computed, ref } from 'vue'

import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import { UiButton, UiCard, UiSearchInput } from '@/shared/ui'

import type { ArsipItem } from '../types'

const props = defineProps<{ items: ArsipItem[]; readonly: boolean }>()
const emit = defineEmits<{ add: []; action: [key: 'buka' | 'edit' | 'hapus', item: ArsipItem] }>()

const search = ref('')
const filtered = computed(() => props.items.filter((i) => i.jenis.toLowerCase().includes(search.value.trim().toLowerCase())))

const actions = (): RowAction[] => [
  { key: 'edit', label: 'Edit' },
  { key: 'hapus', label: 'Hapus', danger: true },
]
</script>

<template>
  <UiCard title="Arsip Data Umum" subtitle="Penambahan arsip data umum" flush data-testid="arsip-card">
    <div class="mt-2 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-4">
      <div class="w-64 max-w-full"><UiSearchInput v-model="search" placeholder="Search" label="Cari arsip" /></div>
      <UiButton v-if="!readonly" data-testid="arsip-add" @click="emit('add')">
        <template #icon-left><Plus class="h-4 w-4" aria-hidden="true" /></template>
        Tambah Arsip
      </UiButton>
    </div>

    <div class="scrollbar-slim overflow-x-auto">
      <table class="w-full min-w-[32rem] border-collapse text-left text-body1">
        <thead>
          <tr class="border-y border-slate-200 text-body2 font-semibold text-slate-900">
            <th scope="col" class="px-5 py-3">Jenis Arsip</th>
            <th scope="col" class="px-5 py-3 text-center">Arsip</th>
            <th v-if="!readonly" scope="col" class="px-5 py-3 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          <tr v-for="item in filtered" :key="item.id" :data-testid="`arsip-row-${item.id}`">
            <td class="px-5 py-3 text-slate-800">{{ item.jenis }}</td>
            <td class="px-5 py-3 text-center">
              <button
                type="button"
                class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-brand-tertiary text-white transition hover:bg-[#1667e0]"
                :aria-label="`Buka arsip ${item.jenis}`"
                @click="emit('action', 'buka', item)"
              >
                <FolderOpen class="h-4 w-4" aria-hidden="true" />
              </button>
            </td>
            <td v-if="!readonly" class="px-5 py-3 text-right">
              <RowActionsMenu
                :actions="actions()"
                :label="`Aksi untuk ${item.jenis}`"
                :testid="`arsip-actions-${item.id}`"
                @select="(key) => emit('action', key as 'edit' | 'hapus', item)"
              />
            </td>
          </tr>
          <tr v-if="filtered.length === 0">
            <td :colspan="readonly ? 2 : 3" class="px-5 py-8 text-center text-body2 text-slate-500">Belum ada arsip.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </UiCard>
</template>
