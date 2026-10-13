<script setup lang="ts">
/**
 * Popup "Filter Kolom" Daftar Pegawai (§4.1.5, Gambar 20 & 21): tombol terbelah "Filter Kolom ▾" membuka panel
 * berisi pencarian kolom, "Toggle All Column/Result", daftar kolom dikelompokkan per huruf dalam dua lajur,
 * tombol "Apply Column" dan tutup (×). Saat mencari, judul berubah jadi "Hasil pencarian ditemukan" dan
 * keadaan kosong menampilkan "Tidak ada hasil ditemukan…". Pilihan baru berlaku setelah "Apply Column".
 */
import { ChevronDown, Search, X } from 'lucide-vue-next'
import { PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'radix-vue'
import { computed, ref, watch } from 'vue'

import { UiButton, UiCheckbox } from '@/shared/ui'

import { COLUMNS, groupPickableColumns } from '../columns'

const props = defineProps<{ modelValue: string[] }>()
const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>()

const open = ref(false)
const query = ref('')
const draft = ref<Set<string>>(new Set(props.modelValue))

watch(open, (isOpen) => {
  if (isOpen) {
    draft.value = new Set(props.modelValue)
    query.value = ''
  }
})

const groups = computed(() => groupPickableColumns(query.value))
const listed = computed(() => groups.value.flatMap((g) => g.columns))
const searching = computed(() => query.value.trim() !== '')
const allChecked = computed(() => listed.value.length > 0 && listed.value.every((c) => draft.value.has(c.key)))

function toggle(key: string, checked: boolean): void {
  const next = new Set(draft.value)
  if (checked) next.add(key)
  else next.delete(key)
  draft.value = next
}

function toggleAll(checked: boolean): void {
  const next = new Set(draft.value)
  for (const column of listed.value) {
    if (checked) next.add(column.key)
    else next.delete(column.key)
  }
  draft.value = next
}

function apply(): void {
  // Urutan mengikuti definisi COLUMNS; kolom terkunci (Nama/NIP) selalu ikut.
  emit(
    'update:modelValue',
    COLUMNS.filter((c) => c.locked || draft.value.has(c.key)).map((c) => c.key),
  )
  open.value = false
}
</script>

<template>
  <PopoverRoot v-model:open="open">
    <PopoverTrigger as-child>
      <UiButton variant="secondary" appearance="soft" data-testid="column-picker-trigger" aria-haspopup="dialog">
        Filter Kolom
        <template #icon-right><ChevronDown class="h-4 w-4" aria-hidden="true" /></template>
      </UiButton>
    </PopoverTrigger>

    <PopoverPortal>
      <PopoverContent
        align="end"
        :side-offset="8"
        class="z-50 w-[min(42rem,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-4 shadow-panel"
        data-testid="column-picker"
        aria-label="Pilih kolom tabel"
      >
        <div class="flex items-center gap-3 border-b border-slate-200 pb-3">
          <Search class="h-5 w-5 shrink-0 text-slate-500" aria-hidden="true" />
          <input
            v-model="query"
            type="search"
            placeholder="Cari kolom..."
            aria-label="Cari kolom"
            class="min-w-0 flex-1 bg-transparent text-body1 text-slate-900 outline-none placeholder:text-slate-400"
            data-testid="column-picker-search"
          />
          <UiButton size="sm" data-testid="column-picker-apply" @click="apply">Apply Column</UiButton>
          <button
            type="button"
            class="rounded-lg p-1.5 text-slate-500 transition hover:bg-slate-100"
            aria-label="Tutup"
            @click="open = false"
          >
            <X class="h-5 w-5" aria-hidden="true" />
          </button>
        </div>

        <div class="scrollbar-slim max-h-80 overflow-y-auto pt-3">
          <UiCheckbox
            :model-value="allChecked"
            :label="searching ? 'Toggle All Result' : 'Toggle All Column'"
            :disabled="listed.length === 0"
            @update:model-value="toggleAll"
          />

          <p class="mb-2 mt-4 text-overline uppercase text-slate-400">
            {{ searching ? 'Hasil pencarian ditemukan' : 'Daftar kolom' }}
          </p>

          <p v-if="listed.length === 0" class="py-3 text-body2 text-slate-500" data-testid="column-picker-empty">
            Tidak ada hasil ditemukan...
          </p>

          <div v-else class="columns-1 gap-x-8 sm:columns-2">
            <section v-for="group in groups" :key="group.letter" class="mb-3 break-inside-avoid">
              <p class="mb-1 text-overline text-slate-400">{{ group.letter }}</p>
              <ul class="space-y-2">
                <li v-for="column in group.columns" :key="column.key">
                  <UiCheckbox
                    :model-value="draft.has(column.key)"
                    :label="column.label"
                    @update:model-value="(v: boolean) => toggle(column.key, v)"
                  />
                </li>
              </ul>
            </section>
          </div>
        </div>
      </PopoverContent>
    </PopoverPortal>
  </PopoverRoot>
</template>
