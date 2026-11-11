<script setup lang="ts">
/**
 * DIBO untuk semua role (PRD Fase 7 v2.0 §5.6, QASMTASK-109 "View UL_ALL"; Q7-12 tanpa "ask"): daftar dokumen berstatus
 * Aktif (server hanya mengirim status 1), dengan filter jenis dan tahun, pencarian nomor/tentang, paginasi, dan unduh
 * berkas 1–3 lewat permintaan terautentikasi (bukan tautan publik, Q7-14).
 */
import { Download, Search } from 'lucide-vue-next'
import { onMounted, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'
import type { SelectOption } from '@/shared/ui'
import { UiPagination, UiSelect } from '@/shared/ui'

import { diboBacaService } from '../services/konten.service'
import type { DiboBacaRow } from '../types'

const perPage = 10
const items = ref<DiboBacaRow[]>([])
const total = ref(0)
const page = ref(1)
const q = ref('')
const jenis = ref('')
const tahun = ref('')
const opsiJenis = ref<SelectOption[]>([])
const loading = ref(false)
const error = ref('')
const mengunduh = ref('')

const tahunIni = new Date().getFullYear()
const opsiTahun: SelectOption[] = Array.from({ length: 15 }, (_, i) => ({ value: String(tahunIni - i), label: String(tahunIni - i) }))

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const r = await diboBacaService.list({
      page: page.value,
      per_page: perPage,
      q: q.value.trim() || undefined,
      jenis: jenis.value || undefined,
      tahun: tahun.value || undefined,
    })
    items.value = r.items
    total.value = r.total
  } catch (e) {
    error.value = isApiError(e) ? e.message : 'Gagal memuat DIBO.'
  } finally {
    loading.value = false
  }
}

function dariFilter(): void {
  if (page.value === 1) void load()
  else page.value = 1
}

let timer: ReturnType<typeof setTimeout> | undefined
watch(q, () => {
  clearTimeout(timer)
  timer = setTimeout(dariFilter, 300)
})
watch([jenis, tahun], dariFilter)
watch(page, () => void load())

async function unduh(row: DiboBacaRow, no: number): Promise<void> {
  mengunduh.value = `${row.id}-${no}`
  error.value = ''
  try {
    await diboBacaService.unduh(row.id, no, `dibo-${row.id}-${no}`)
  } catch (e) {
    error.value = isApiError(e) ? e.message : 'Gagal mengunduh berkas.'
  } finally {
    mengunduh.value = ''
  }
}

onMounted(async () => {
  void load()
  try {
    opsiJenis.value = (await diboBacaService.jenis()).map((j) => ({ value: j.id, label: j.nama }))
  } catch {
    // filter jenis opsional
  }
})
</script>

<template>
  <section class="flex flex-col gap-4" data-testid="konten-dibo-baca">
    <div>
      <h1 class="text-xl font-semibold text-slate-900">DIBO</h1>
      <p class="text-sm text-slate-500">Dokumen dan peraturan kepegawaian yang dibagikan untuk Anda.</p>
    </div>

    <div class="flex flex-wrap gap-3 rounded-lg border border-slate-200 bg-white p-3">
      <label class="relative min-w-[200px] flex-1">
        <span class="sr-only">Cari nomor atau tentang</span>
        <Search class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" aria-hidden="true" />
        <input
          v-model="q"
          type="search"
          placeholder="Cari nomor atau tentang"
          class="w-full rounded-md border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-brand-tertiary focus:outline-none focus:ring-2 focus:ring-brand-tertiary/40"
          data-testid="dibo-cari"
        />
      </label>
      <UiSelect v-model="jenis" :options="opsiJenis" clearable size="sm" placeholder="Semua jenis" aria-label="Filter jenis" class="w-52" />
      <UiSelect v-model="tahun" :options="opsiTahun" clearable size="sm" placeholder="Semua tahun" aria-label="Filter tahun" class="w-40" />
    </div>

    <p v-if="error" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-4 py-3">Nomor</th>
            <th class="px-4 py-3">Tahun</th>
            <th class="px-4 py-3">Tentang</th>
            <th class="px-4 py-3">Jenis</th>
            <th class="px-4 py-3">Berkas</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading">
            <td colspan="5" class="px-4 py-8 text-center text-slate-500">Memuat...</td>
          </tr>
          <tr v-else-if="items.length === 0">
            <td colspan="5" class="px-4 py-8 text-center text-slate-500" data-testid="dibo-kosong">Belum ada DIBO yang cocok.</td>
          </tr>
          <tr v-for="row in items" v-else :key="row.id" class="hover:bg-slate-50" :data-testid="`dibo-row-${row.id}`">
            <td class="whitespace-nowrap px-4 py-3 font-medium text-slate-800">{{ row.nomor || '—' }}</td>
            <td class="px-4 py-3 text-slate-600">{{ row.tahun || '—' }}</td>
            <td class="px-4 py-3 text-slate-700">
              {{ row.tentang }}
              <span v-if="row.keterangan" class="mt-0.5 block text-xs text-slate-500">{{ row.keterangan }}</span>
            </td>
            <td class="px-4 py-3 text-slate-600">{{ row.jenis || '—' }}</td>
            <td class="px-4 py-3">
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="no in row.berkas"
                  :key="no"
                  type="button"
                  class="inline-flex items-center gap-1 rounded-md border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                  :disabled="mengunduh === `${row.id}-${no}`"
                  :aria-label="`Unduh berkas ${no} DIBO ${row.nomor}`"
                  :data-testid="`dibo-unduh-${row.id}-${no}`"
                  @click="unduh(row, no)"
                >
                  <Download class="h-3.5 w-3.5" aria-hidden="true" /> Berkas {{ no }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <UiPagination v-if="total > perPage" v-model:page="page" :per-page="perPage" :total="total" />
  </section>
</template>
