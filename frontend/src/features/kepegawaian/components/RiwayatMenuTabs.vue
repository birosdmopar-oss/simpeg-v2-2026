<script setup lang="ts">
/**
 * Baris tab riwayat Detail Pegawai (§4.1.6, Gambar 23–24): tab bergaya pil (aktif = kuning #FFC043) yang bisa
 * digulir dengan panah kiri/kanan, ditambah tombol "Semua Menu" yang membuka popup daftar lengkap dengan
 * kotak "Cari menu..." untuk mempersingkat waktu akses (anotasi dokumen).
 */
import { ChevronLeft, ChevronRight, Search } from 'lucide-vue-next'
import { PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'radix-vue'
import { computed, nextTick, ref } from 'vue'

import { UiButton, UiTabs } from '@/shared/ui'

import type { RiwayatMenu } from '../types'

const props = defineProps<{ modelValue: string; menus: RiwayatMenu[] }>()
const emit = defineEmits<{ 'update:modelValue': [key: string] }>()

const wrap = ref<HTMLElement | null>(null)
const open = ref(false)
const query = ref('')

const items = computed(() => props.menus.map((m) => ({ value: m.key, label: m.label })))
const filtered = computed(() => {
  const needle = query.value.trim().toLowerCase()
  return props.menus.filter((m) => m.label.toLowerCase().includes(needle))
})

function scroll(direction: -1 | 1): void {
  wrap.value?.querySelector('[role="tablist"]')?.scrollBy({ left: direction * 240, behavior: 'smooth' })
}

async function select(key: string): Promise<void> {
  emit('update:modelValue', key)
  open.value = false
  query.value = ''
  await nextTick()
  // Bawa tab terpilih ke area terlihat (mis. dipilih dari popup).
  wrap.value?.querySelector<HTMLElement>('[aria-selected="true"]')?.scrollIntoView?.({ inline: 'center', block: 'nearest' })
}
</script>

<template>
  <div class="flex items-center gap-2" data-testid="riwayat-tabs">
    <div ref="wrap" class="min-w-0 flex-1">
      <UiTabs
        :model-value="modelValue"
        :items="items"
        variant="pill"
        aria-label="Menu riwayat pegawai"
        @update:model-value="select"
      />
    </div>

    <div class="hidden shrink-0 items-center sm:flex">
      <button type="button" class="rounded-lg p-1.5 text-slate-400 transition hover:bg-white hover:text-slate-700" aria-label="Gulir menu ke kiri" @click="scroll(-1)">
        <ChevronLeft class="h-4 w-4" aria-hidden="true" />
      </button>
      <button type="button" class="rounded-lg p-1.5 text-slate-400 transition hover:bg-white hover:text-slate-700" aria-label="Gulir menu ke kanan" @click="scroll(1)">
        <ChevronRight class="h-4 w-4" aria-hidden="true" />
      </button>
    </div>

    <PopoverRoot v-model:open="open">
      <PopoverTrigger as-child>
        <UiButton variant="secondary" appearance="outline" class="shrink-0" data-testid="semua-menu">Semua Menu</UiButton>
      </PopoverTrigger>
      <PopoverPortal>
        <PopoverContent
          align="end"
          :side-offset="8"
          class="z-50 w-64 rounded-xl border border-slate-200 bg-white p-2 shadow-panel"
          aria-label="Semua menu riwayat"
        >
          <div class="relative mb-2">
            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
            <input
              v-model="query"
              type="search"
              placeholder="Cari menu..."
              aria-label="Cari menu"
              class="h-10 w-full rounded-lg border border-slate-300 pl-9 pr-3 text-body2 outline-none placeholder:text-slate-400 focus:border-brand-tertiary focus:ring-2 focus:ring-brand-tertiary/25"
              data-testid="semua-menu-search"
            />
          </div>
          <ul class="scrollbar-slim max-h-72 space-y-0.5 overflow-y-auto" role="listbox" aria-label="Daftar menu">
            <li v-for="menu in filtered" :key="menu.key" role="option" :aria-selected="menu.key === modelValue">
              <button
                type="button"
                class="w-full rounded-lg px-3 py-2 text-left text-body2 transition"
                :class="menu.key === modelValue ? 'bg-brand-secondary/25 font-medium text-[#5b4306]' : 'text-slate-700 hover:bg-slate-100'"
                :data-menu="menu.key"
                @click="select(menu.key)"
              >
                {{ menu.label }}
              </button>
            </li>
            <li v-if="filtered.length === 0" class="px-3 py-3 text-body2 text-slate-500">Menu tidak ditemukan.</li>
          </ul>
        </PopoverContent>
      </PopoverPortal>
    </PopoverRoot>
  </div>
</template>
