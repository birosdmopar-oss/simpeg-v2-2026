<script setup lang="ts">
/**
 * Master Angka Kredit JF `dm_ak_jf` (MAKE-021, role 1; legacy Data Master > JABATAN > "Angka Kredit JF",
 * `C_jabatan::akjf`). Daftar seluruh pasangan jabatan × gol. ruang (urut sub group, jenjang, pangkat) dengan pencarian
 * di sisi klien (legacy DataTables). Aksi baris lewat menu ⋮ (AGENTS.md §1): hanya Edit — tanpa hapus [K] (hapus legacy
 * tidak berfungsi). Kolom Aksi menempel di kanan (`sticky right-0`, pola CR-038) supaya ⋮ terlihat di layar sempit (CR-052).
 */
import { Plus, Search } from 'lucide-vue-next'
import { computed, onMounted, ref } from 'vue'

import { isApiError } from '@/lib/axios'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'

import AkJfFormDialog from './AkJfFormDialog.vue'
import { akJfService, type AkJfRow } from './akJf.service'

const items = ref<AkJfRow[]>([])
const loading = ref(false)
const error = ref('')
const notice = ref('')
const search = ref('')
const formOpen = ref(false)
const editing = ref<AkJfRow | null>(null)

const angka = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 3 })

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (q === '') return items.value
  return items.value.filter((r) =>
    [r.jabatan, r.group_jabatan, r.sub_group_jabatan, r.jenjang_jf, r.gol_ruang, r.pangkat].some((v) => String(v ?? '').toLowerCase().includes(q)),
  )
})

const idOf = (row: AkJfRow): string => `${row.id_jabatan}-${row.id_pangkat}`

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    items.value = await akJfService.list()
  } catch (err) {
    error.value = isApiError(err) ? err.message : 'Gagal memuat data.'
  } finally {
    loading.value = false
  }
}

function openCreate(): void {
  editing.value = null
  formOpen.value = true
}

function rowActions(): RowAction[] {
  return [{ key: 'edit', label: 'Edit' }]
}

function onRowAction(row: AkJfRow, key: string): void {
  if (key === 'edit') {
    editing.value = row
    formOpen.value = true
  }
}

async function onSaved(row: AkJfRow): Promise<void> {
  notice.value = `Angka kredit JF ${row.jabatan} gol. ${row.gol_ruang} tersimpan.`
  await load()
}

onMounted(() => void load())
</script>

<template>
  <section class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-slate-900">Angka Kredit JF</h1>
        <p class="text-sm text-slate-500">Angka kredit per jabatan fungsional dan gol. ruang — dipakai menghitung AK kumulatif riwayat angka kredit.</p>
      </div>
      <button
        type="button"
        class="flex items-center gap-1.5 rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-white hover:bg-brand-primary/90"
        data-testid="ak-jf-add"
        @click="openCreate"
      >
        <Plus class="h-4 w-4" /> Tambah Angka Kredit JF
      </button>
    </div>

    <p v-if="notice" class="rounded-md border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-800" role="status">{{ notice }}</p>
    <p v-if="error" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <div class="flex flex-wrap gap-3 rounded-lg border border-slate-200 bg-white p-3">
      <label class="relative min-w-[200px] flex-1">
        <Search class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
        <input
          v-model="search"
          type="search"
          placeholder="Cari jabatan, jenjang, atau gol. ruang"
          aria-label="Cari angka kredit JF"
          class="w-full rounded-md border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-brand-tertiary focus:outline-none focus:ring-2 focus:ring-brand-tertiary/40"
          data-testid="ak-jf-search"
        />
      </label>
    </div>

    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3">Jabatan</th>
            <th class="px-4 py-3">Group Jabatan</th>
            <th class="px-4 py-3">Sub Group Jabatan</th>
            <th class="px-4 py-3">Jenjang JF</th>
            <th class="px-4 py-3">Gol. Ruang</th>
            <th class="px-4 py-3 text-right">Angka Kredit</th>
            <th class="sticky right-0 z-10 whitespace-nowrap bg-slate-50 px-4 py-3 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading">
            <td colspan="7" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
          </tr>
          <tr v-else-if="filtered.length === 0">
            <td colspan="7" class="px-4 py-8 text-center text-slate-500" data-testid="ak-jf-kosong">Belum ada angka kredit JF yang cocok.</td>
          </tr>
          <tr v-for="row in filtered" v-else :key="idOf(row)" class="group hover:bg-slate-50" :data-testid="`ak-jf-row-${idOf(row)}`">
            <td class="px-4 py-3 font-medium text-slate-800">{{ row.jabatan }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.group_jabatan ?? '—' }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.sub_group_jabatan ?? '—' }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.jenjang_jf ? `${row.kategori_jf} — ${row.jenjang_jf}` : '—' }}</td>
            <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ row.gol_ruang }}</td>
            <td class="px-4 py-3 text-right tabular-nums text-slate-800">{{ angka.format(row.ak) }}</td>
            <td class="sticky right-0 z-10 bg-white px-4 py-3 text-right group-hover:bg-slate-50">
              <RowActionsMenu
                :actions="rowActions()"
                :label="`Aksi untuk ${row.jabatan} gol. ${row.gol_ruang}`"
                :testid="`ak-jf-actions-${idOf(row)}`"
                @select="onRowAction(row, $event)"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <AkJfFormDialog v-model:open="formOpen" :row="editing" @saved="onSaved" />
  </section>
</template>
