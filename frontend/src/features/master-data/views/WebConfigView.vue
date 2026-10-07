<script setup lang="ts">
/**
 * Halaman Web Config (G-09, DBV-006/CR-030) — role 1. Menampilkan seluruh key katalog backend per kelompok dengan nilai
 * yang berlaku (tersimpan atau bawaan), lalu key tak dikenal hasil impor. Aksi baris lewat menu titik tiga
 * (AGENTS.md bagian 1): Edit (key katalog) → Hapus (danger, hanya bila ada nilai tersimpan; nilai kembali ke bawaan,
 * lewat ConfirmDialog). Tabel ini tidak punya status 1/2/10, jadi kolom Keterangan hanya badge Bawaan/Tidak valid/
 * Tidak dikenal. Nilai HTML ditampilkan sebagai teks (tidak dirender).
 */
import { Pencil, Search, Trash2 } from 'lucide-vue-next'
import { computed, onMounted, ref } from 'vue'

import { isApiError } from '@/lib/axios'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'

import WebConfigFormDialog from '../components/WebConfigFormDialog.vue'
import { webConfigService } from '../services/webConfig.service'
import { WEB_CONFIG_TYPE_LABELS, type WebConfigItem } from '../webConfig.types'

const items = ref<WebConfigItem[]>([])
const search = ref('')
const loading = ref(false)
const error = ref('')
const notice = ref('')

const formOpen = ref(false)
const editing = ref<WebConfigItem | null>(null)
const confirm = ref<{ open: boolean; item: WebConfigItem | null; loading: boolean }>({ open: false, item: null, loading: false })

/** Kelompok berurutan sesuai katalog, setelah filter pencarian (label atau config_name). */
const groups = computed(() => {
  const q = search.value.trim().toLowerCase()
  const result: Array<{ name: string; items: WebConfigItem[] }> = []
  for (const item of items.value) {
    if (q !== '' && !item.label.toLowerCase().includes(q) && !item.config_name.toLowerCase().includes(q)) continue
    let group = result.find((g) => g.name === item.group)
    if (!group) {
      group = { name: item.group, items: [] }
      result.push(group)
    }
    group.items.push(item)
  }
  return result
})

/** Teks tampilan nilai (HTML sebagai teks, dipotong). */
function display(item: WebConfigItem): string {
  const value = item.effective ?? ''
  if (value === '') return '—'
  return value.length > 120 ? `${value.slice(0, 117)}...` : value
}

function testidOf(item: WebConfigItem): string {
  return item.config_name.replace(/[^A-Za-z0-9_-]/g, '-')
}

function messageOf(err: unknown, fallback: string): string {
  return isApiError(err) ? err.message : fallback
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    items.value = await webConfigService.list()
  } catch (err) {
    error.value = messageOf(err, 'Gagal memuat web config.')
  } finally {
    loading.value = false
  }
}

function rowActions(item: WebConfigItem): RowAction[] {
  return [
    { key: 'edit', label: 'Edit', icon: Pencil, hidden: !item.known },
    { key: 'delete', label: 'Hapus', icon: Trash2, danger: true, hidden: item.is_default },
  ]
}

function onRowAction(item: WebConfigItem, key: string): void {
  if (key === 'edit') {
    editing.value = item
    formOpen.value = true
  } else if (key === 'delete') {
    confirm.value = { open: true, item, loading: false }
  }
}

function onSaved(item: WebConfigItem): void {
  notice.value = `"${item.label}" disimpan.`
  void load()
}

const confirmDescription = computed(() => {
  const item = confirm.value.item
  if (!item) return ''
  if (!item.known) return 'Baris key tak dikenal ini dihapus permanen (tercatat di log audit).'
  const fallback = item.default === null || item.default === '' ? 'kosong / belum diatur' : item.default
  return `Nilai tersimpan dihapus dan aplikasi kembali memakai nilai bawaan (${fallback}). Perubahan tercatat di log audit.`
})

async function onConfirmDelete(): Promise<void> {
  const item = confirm.value.item
  if (!item) return
  confirm.value.loading = true
  try {
    await webConfigService.remove(item.config_name)
    notice.value = item.known ? `"${item.label}" kembali ke nilai bawaan.` : `Key "${item.config_name}" dihapus.`
    confirm.value.open = false
    await load()
  } catch (err) {
    error.value = messageOf(err, 'Gagal menghapus.')
    confirm.value.open = false
  } finally {
    confirm.value.loading = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="space-y-4">
    <div>
      <h1 class="text-xl font-semibold text-slate-900">Web Config</h1>
      <p class="text-sm text-slate-500">
        Parameter sistem (identitas instansi, kop PDF, tarif potongan tukin, uang makan, dll.). Perubahan langsung dipakai
        aplikasi; menghapus nilai mengembalikannya ke nilai bawaan.
      </p>
    </div>

    <p v-if="notice" class="rounded-md border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-800" role="status">{{ notice }}</p>
    <p v-if="error" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <div class="flex flex-wrap gap-3 rounded-lg border border-slate-200 bg-white p-3">
      <label class="relative min-w-[200px] flex-1">
        <Search class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
        <input
          v-model="search"
          type="search"
          placeholder="Cari nama atau key"
          class="w-full rounded-md border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-brand-tertiary focus:outline-none focus:ring-2 focus:ring-brand-tertiary/40"
          data-testid="web-config-search"
        />
      </label>
    </div>

    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3">Nama</th>
            <th class="px-4 py-3">Nilai</th>
            <th class="px-4 py-3">Tipe</th>
            <th class="px-4 py-3">Keterangan</th>
            <th class="px-4 py-3 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody v-if="loading">
          <tr>
            <td colspan="5" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
          </tr>
        </tbody>
        <tbody v-else-if="groups.length === 0">
          <tr>
            <td colspan="5" class="px-4 py-8 text-center text-slate-500">Tidak ada web config yang cocok.</td>
          </tr>
        </tbody>
        <tbody v-for="group in loading ? [] : groups" v-else :key="group.name" class="divide-y divide-slate-100">
          <tr class="bg-slate-50/60">
            <th colspan="5" class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600" scope="colgroup">{{ group.name }}</th>
          </tr>
          <tr v-for="item in group.items" :key="item.config_name" class="hover:bg-slate-50" :data-testid="`web-config-row-${testidOf(item)}`">
            <td class="px-4 py-3">
              <div class="font-medium text-slate-800">{{ item.label }}</div>
              <code class="text-xs text-slate-500">{{ item.config_name }}</code>
            </td>
            <td class="max-w-md whitespace-pre-line break-words px-4 py-3 text-slate-700" data-testid="web-config-value">{{ display(item) }}</td>
            <td class="px-4 py-3 text-slate-600">{{ item.type ? WEB_CONFIG_TYPE_LABELS[item.type] : '—' }}</td>
            <td class="space-x-1 px-4 py-3">
              <span v-if="!item.known" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800" data-badge="unknown">Tidak dikenal</span>
              <span v-else-if="item.is_default" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600" data-badge="default">Bawaan</span>
              <span v-else-if="!item.valid" class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700" data-badge="invalid">Tidak valid — memakai bawaan</span>
            </td>
            <td class="px-4 py-3 text-right">
              <RowActionsMenu
                :actions="rowActions(item)"
                :label="`Aksi untuk ${item.label}`"
                :testid="`web-config-actions-${testidOf(item)}`"
                @select="onRowAction(item, $event)"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <WebConfigFormDialog v-model:open="formOpen" :item="editing" @saved="onSaved" />

    <ConfirmDialog
      v-model:open="confirm.open"
      :title="`Hapus nilai &quot;${confirm.item?.label ?? ''}&quot;?`"
      :description="confirmDescription"
      danger
      confirm-label="Hapus"
      :loading="confirm.loading"
      @confirm="onConfirmDelete"
    />
  </section>
</template>
