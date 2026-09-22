<script setup lang="ts">
/**
 * Halaman Master Data generik (Modul G, MTC-008/009) — role 1 saja. Daftar master & kolomnya dari GET /master/meta.
 * Fitur per master: cari, filter status, filter induk berjenjang, tabel urut `order`, toggle switch status,
 * badge Aktif (hijau)/Non-aktif (abu), naik/turun urutan (entri lain bergeser), tambah/edit, hapus (soft delete).
 */
import { ArrowDown, ArrowUp, Pencil, Plus, Search, Trash2 } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { isApiError } from '@/lib/axios'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'

import MasterFormDialog from '../components/MasterFormDialog.vue'
import StatusBadge from '../components/StatusBadge.vue'
import StatusSwitch from '../components/StatusSwitch.vue'
import { useCascadeOptions } from '../composables/useCascadeOptions'
import { ancestorChain } from '../schemas/master.schema'
import { masterService } from '../services/master.service'
import type { MasterMeta, MasterRow, MasterStatus } from '../types'

const route = useRoute()
const router = useRouter()

const metas = ref<MasterMeta[]>([])
const metaError = ref('')
const activeKey = ref('')
const meta = computed(() => metas.value.find((m) => m.key === activeKey.value) ?? null)

const items = ref<MasterRow[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const loading = ref(false)
const error = ref('')
const notice = ref('')
const search = ref('')
const statusFilter = ref<MasterStatus | ''>('')
const busyId = ref('')

const formOpen = ref(false)
const editing = ref<MasterRow | null>(null)
const confirm = ref<{ open: boolean; row: MasterRow | null; loading: boolean }>({ open: false, row: null, loading: false })

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))
const filterChain = computed(() => (meta.value ? ancestorChain(meta.value, metas.value) : []))
const filterCascade = useCascadeOptions(filterChain)
const { levels: filterLevels } = filterCascade

/** Reorder via panah hanya bermakna saat daftar menampilkan satu kelompok induk utuh tanpa pencarian. */
const canReorder = computed(
  () => meta.value !== null && search.value.trim() === '' && statusFilter.value === '' && (!meta.value.parent || filterCascade.leafValue() !== ''),
)

function idOf(row: MasterRow): string {
  return meta.value ? String(row[meta.value.primary_key] ?? '') : ''
}

function nameOf(row: MasterRow): string {
  return meta.value ? String(row[meta.value.name_field] ?? '') : ''
}

async function loadMeta(): Promise<void> {
  try {
    metas.value = await masterService.meta()
    const requested = typeof route.params.entity === 'string' ? route.params.entity : ''
    activeKey.value = metas.value.some((m) => m.key === requested) ? requested : (metas.value[0]?.key ?? '')
  } catch (err) {
    metaError.value = isApiError(err) ? err.message : 'Gagal memuat daftar master.'
  }
}

async function load(): Promise<void> {
  if (!meta.value) return
  loading.value = true
  error.value = ''
  try {
    const result = await masterService.list(meta.value.key, {
      search: search.value.trim(),
      status: statusFilter.value,
      parent: meta.value.parent ? filterCascade.leafValue() : undefined,
      page: page.value,
      per_page: perPage.value,
    })
    items.value = result.items
    total.value = result.total
  } catch (err) {
    error.value = isApiError(err) ? err.message : 'Gagal memuat data.'
  } finally {
    loading.value = false
  }
}

watch(activeKey, async (key, previous) => {
  if (!key) return
  if (previous && route.params.entity !== key) void router.replace({ name: 'master-data', params: { entity: key } })
  search.value = ''
  statusFilter.value = ''
  page.value = 1
  notice.value = ''
  await filterCascade.init()
  await load()
})

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    void load()
  }, 300)
})
watch(statusFilter, () => {
  page.value = 1
  void load()
})

async function onFilterLevel(index: number, value: string): Promise<void> {
  await filterCascade.select(index, value)
  page.value = 1
  await load()
}

function goTo(next: number): void {
  page.value = Math.min(Math.max(1, next), totalPages.value)
  void load()
}

function openCreate(): void {
  editing.value = null
  formOpen.value = true
}

function openEdit(row: MasterRow): void {
  editing.value = row
  formOpen.value = true
}

function onSaved(row: MasterRow): void {
  notice.value = editing.value ? `${meta.value?.label} "${nameOf(row)}" diperbarui.` : `${meta.value?.label} "${nameOf(row)}" ditambahkan.`
  void load()
}

async function toggleStatus(row: MasterRow, active: boolean): Promise<void> {
  if (!meta.value) return
  busyId.value = idOf(row)
  error.value = ''
  try {
    const updated = await masterService.setStatus(meta.value.key, idOf(row), active ? '1' : '0')
    row.status = updated.status
    notice.value = active ? `"${nameOf(row)}" diaktifkan dan kembali muncul di dropdown.` : `"${nameOf(row)}" dinonaktifkan dan tidak lagi muncul di dropdown.`
  } catch (err) {
    error.value = isApiError(err) ? err.message : 'Gagal mengubah status.'
  } finally {
    busyId.value = ''
  }
}

async function move(row: MasterRow, delta: -1 | 1): Promise<void> {
  if (!meta.value) return
  const index = items.value.indexOf(row)
  const target = (page.value - 1) * perPage.value + index + 1 + delta
  if (target < 1 || target > total.value) return
  busyId.value = idOf(row)
  error.value = ''
  try {
    await masterService.reorder(meta.value.key, idOf(row), target)
    await load()
  } catch (err) {
    error.value = isApiError(err) ? err.message : 'Gagal mengubah urutan.'
  } finally {
    busyId.value = ''
  }
}

function askDelete(row: MasterRow): void {
  confirm.value = { open: true, row, loading: false }
}

async function onConfirmDelete(): Promise<void> {
  const row = confirm.value.row
  if (!row || !meta.value) return
  confirm.value.loading = true
  try {
    await masterService.remove(meta.value.key, idOf(row))
    notice.value = `"${nameOf(row)}" dihapus (dinonaktifkan). Data yang sudah memakainya tetap utuh.`
    confirm.value.open = false
    await load()
  } catch (err) {
    error.value = isApiError(err) ? err.message : 'Gagal menghapus.'
    confirm.value.open = false
  } finally {
    confirm.value.loading = false
  }
}

onMounted(() => {
  void loadMeta()
})
</script>

<template>
  <section class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-slate-900">Master Data</h1>
        <p class="text-sm text-slate-500">Referensi yang dipakai dropdown seluruh modul. Hanya Super Admin.</p>
      </div>
      <button
        v-if="meta"
        type="button"
        class="flex items-center gap-1.5 rounded-md bg-brand-primary px-4 py-2 text-sm font-medium text-white hover:bg-brand-primary/90"
        data-testid="master-add"
        @click="openCreate"
      >
        <Plus class="h-4 w-4" /> Tambah {{ meta.label }}
      </button>
    </div>

    <p v-if="metaError" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ metaError }}</p>

    <div class="grid gap-4 lg:grid-cols-[220px_1fr]">
      <nav class="flex gap-1 overflow-x-auto rounded-lg border border-slate-200 bg-white p-2 lg:flex-col" aria-label="Daftar master">
        <button
          v-for="m in metas"
          :key="m.key"
          type="button"
          class="whitespace-nowrap rounded-md px-3 py-2 text-left text-sm"
          :class="m.key === activeKey ? 'bg-slate-100 font-medium text-brand-primary' : 'text-slate-600 hover:bg-slate-50'"
          :aria-current="m.key === activeKey ? 'page' : undefined"
          :data-testid="`master-nav-${m.key}`"
          @click="activeKey = m.key"
        >
          {{ m.label }}
        </button>
      </nav>

      <div v-if="meta" class="min-w-0 space-y-4">
        <p v-if="notice" class="rounded-md border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-800" role="status">{{ notice }}</p>
        <p v-if="error" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

        <div class="flex flex-wrap gap-3 rounded-lg border border-slate-200 bg-white p-3">
          <label class="relative min-w-[200px] flex-1">
            <Search class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
            <input
              v-model="search"
              type="search"
              :placeholder="`Cari kode / ${meta.name_label.toLowerCase()}`"
              class="w-full rounded-md border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-brand-tertiary focus:outline-none focus:ring-2 focus:ring-brand-tertiary/40"
              data-testid="master-search"
            />
          </label>
          <select
            v-for="(level, index) in filterLevels"
            :key="level.meta.key"
            :value="level.value"
            class="rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100"
            :disabled="level.loading || (index > 0 && !filterLevels[index - 1]?.value)"
            :aria-label="`Filter ${level.meta.label}`"
            @change="onFilterLevel(index, ($event.target as HTMLSelectElement).value)"
          >
            <option value="">Semua {{ level.meta.label }}</option>
            <option v-for="o in level.options" :key="o.id" :value="o.id">{{ o.nama }}</option>
          </select>
          <select v-model="statusFilter" class="rounded-md border border-slate-300 px-3 py-2 text-sm" aria-label="Filter status">
            <option value="">Semua status</option>
            <option value="1">Aktif</option>
            <option value="0">Non-aktif</option>
          </select>
        </div>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th class="w-16 px-4 py-3">Urutan</th>
                <th class="px-4 py-3">Kode</th>
                <th class="px-4 py-3">{{ meta.name_label }}</th>
                <th v-if="meta.parent" class="px-4 py-3">{{ metas.find((m) => m.key === meta?.parent?.entity)?.label ?? 'Induk' }}</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-if="loading">
                <td colspan="6" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
              </tr>
              <tr v-else-if="items.length === 0">
                <td colspan="6" class="px-4 py-8 text-center text-slate-500">Belum ada data yang cocok.</td>
              </tr>
              <tr v-for="(row, index) in items" v-else :key="idOf(row)" class="hover:bg-slate-50" :data-testid="`master-row-${idOf(row)}`">
                <td class="px-4 py-3 text-slate-600">{{ row.order }}</td>
                <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ idOf(row) }}</td>
                <td class="px-4 py-3 font-medium text-slate-800">{{ nameOf(row) }}</td>
                <td v-if="meta.parent" class="px-4 py-3 text-slate-600">{{ row.parent_nama ?? '—' }}</td>
                <td class="px-4 py-3">
                  <div class="flex items-center gap-2">
                    <StatusSwitch
                      :checked="row.status === '1'"
                      :disabled="busyId === idOf(row)"
                      :label="`Status ${nameOf(row)}`"
                      @toggle="toggleStatus(row, $event)"
                    />
                    <StatusBadge :status="row.status" />
                  </div>
                </td>
                <td class="px-4 py-3">
                  <div class="flex justify-end gap-1">
                    <template v-if="canReorder">
                      <button
                        type="button"
                        class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-brand-primary disabled:opacity-30"
                        title="Naikkan urutan"
                        :disabled="busyId !== '' || (page === 1 && index === 0)"
                        @click="move(row, -1)"
                      >
                        <ArrowUp class="h-4 w-4" />
                      </button>
                      <button
                        type="button"
                        class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-brand-primary disabled:opacity-30"
                        title="Turunkan urutan"
                        :disabled="busyId !== '' || (page - 1) * perPage + index + 1 >= total"
                        @click="move(row, 1)"
                      >
                        <ArrowDown class="h-4 w-4" />
                      </button>
                    </template>
                    <button type="button" class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-brand-primary" title="Edit" @click="openEdit(row)">
                      <Pencil class="h-4 w-4" />
                    </button>
                    <button
                      type="button"
                      class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-red-600 disabled:opacity-40"
                      title="Hapus"
                      :disabled="row.status === '0'"
                      @click="askDelete(row)"
                    >
                      <Trash2 class="h-4 w-4" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <p v-if="meta.parent && !canReorder && !search && !statusFilter" class="text-xs text-slate-500">
          Pilih {{ metas.find((m) => m.key === meta?.parent?.entity)?.label ?? 'induk' }} untuk mengubah urutan (urutan berlaku per induk).
        </p>

        <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-slate-600">
          <span>{{ total }} data · halaman {{ page }} dari {{ totalPages }}</span>
          <div class="flex gap-1">
            <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 disabled:opacity-40" :disabled="page <= 1" @click="goTo(page - 1)">Sebelumnya</button>
            <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 disabled:opacity-40" :disabled="page >= totalPages" @click="goTo(page + 1)">Berikutnya</button>
          </div>
        </div>
      </div>
    </div>

    <MasterFormDialog v-if="meta" v-model:open="formOpen" :meta="meta" :all-meta="metas" :row="editing" @saved="onSaved" />

    <ConfirmDialog
      v-model:open="confirm.open"
      :title="`Hapus ${meta?.label ?? ''} &quot;${confirm.row ? nameOf(confirm.row) : ''}&quot;?`"
      description="Data tidak dihapus permanen: statusnya menjadi Non-aktif sehingga hilang dari dropdown, sementara data pegawai/riwayat yang sudah memakainya tetap utuh. Bisa diaktifkan kembali lewat toggle status."
      danger
      confirm-label="Hapus"
      :loading="confirm.loading"
      @confirm="onConfirmDelete"
    />
  </section>
</template>
