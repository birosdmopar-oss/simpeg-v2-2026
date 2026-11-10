<script setup lang="ts">
/**
 * Portal berita (semua role; PRD Fase 7 v2.0 §5.5, route `konten-berita`): daftar kartu berita yang boleh dilihat aktor
 * (penargetan & jadwal terbit ditegakkan server), dengan pencarian, filter kategori, dan paginasi. Membuka portal
 * mereset penanda berita baru (Q7-09). Galat memuat → pesan, bukan halaman kosong.
 */
import { Search } from 'lucide-vue-next'
import { onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'

import { isApiError } from '@/lib/axios'
import type { SelectOption } from '@/shared/ui'
import { UiPagination, UiSelect } from '@/shared/ui'

import { ROUTE_DETAIL_BERITA } from '../routes'
import { beritaPortalService } from '../services/konten.service'
import type { BeritaRingkas } from '../types'

const perPage = 9
const items = ref<BeritaRingkas[]>([])
const total = ref(0)
const page = ref(1)
const q = ref('')
const kategori = ref('')
const opsiKategori = ref<SelectOption[]>([])
const loading = ref(false)
const error = ref('')

const tanggal = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Asia/Jakarta' })
function fmt(v: string | null): string {
  const t = v ? Date.parse(v) : Number.NaN
  return Number.isNaN(t) ? '' : tanggal.format(t)
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const r = await beritaPortalService.list({ page: page.value, per_page: perPage, q: q.value.trim() || undefined, kategori: kategori.value || undefined })
    items.value = r.items
    total.value = r.total
  } catch (e) {
    error.value = isApiError(e) ? e.message : 'Gagal memuat berita.'
  } finally {
    loading.value = false
  }
}

let timer: ReturnType<typeof setTimeout> | undefined
watch(q, () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    if (page.value === 1) void load()
    else page.value = 1
  }, 300)
})
watch(kategori, () => {
  if (page.value === 1) void load()
  else page.value = 1
})
watch(page, () => void load())

onMounted(async () => {
  void load()
  void beritaPortalService.dibuka().catch(() => undefined)
  try {
    opsiKategori.value = (await beritaPortalService.kategori()).map((k) => ({ value: k.id, label: k.nama }))
  } catch {
    // filter kategori opsional: tanpa daftar, portal tetap jalan
  }
})
</script>

<template>
  <section class="flex flex-col gap-4" data-testid="konten-berita-portal">
    <div>
      <h1 class="text-xl font-semibold text-slate-900">Berita</h1>
      <p class="text-sm text-slate-500">Berita dan pengumuman terbaru untuk Anda.</p>
    </div>

    <div class="flex flex-wrap gap-3 rounded-lg border border-slate-200 bg-white p-3">
      <label class="relative min-w-[200px] flex-1">
        <span class="sr-only">Cari berita</span>
        <Search class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" aria-hidden="true" />
        <input
          v-model="q"
          type="search"
          placeholder="Cari berita"
          class="w-full rounded-md border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-brand-tertiary focus:outline-none focus:ring-2 focus:ring-brand-tertiary/40"
          data-testid="berita-cari"
        />
      </label>
      <UiSelect v-model="kategori" :options="opsiKategori" clearable size="sm" placeholder="Semua kategori" aria-label="Filter kategori" class="w-52" />
    </div>

    <p v-if="error" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>
    <p v-else-if="loading" class="text-sm text-slate-500" role="status">Memuat...</p>
    <p v-else-if="items.length === 0" class="rounded-lg border border-slate-200 bg-white p-6 text-center text-sm text-slate-500" data-testid="berita-kosong">
      Belum ada berita yang cocok.
    </p>

    <ul v-if="!loading && !error && items.length > 0" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3" data-testid="berita-daftar">
      <li v-for="b in items" :key="b.id" class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <RouterLink :to="{ name: ROUTE_DETAIL_BERITA, params: { id: b.id } }" class="flex h-full flex-col focus-visible:ring-2 focus-visible:ring-brand-tertiary/50">
          <img v-if="b.foto_url" :src="b.foto_url" :alt="b.judul" class="h-40 w-full object-cover" loading="lazy" />
          <div class="flex flex-1 flex-col gap-1 p-4">
            <span v-if="b.kategori" class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ b.kategori }}</span>
            <h2 class="text-base font-semibold text-slate-900">{{ b.judul }}</h2>
            <p v-if="b.ringkasan" class="line-clamp-3 text-sm text-slate-600">{{ b.ringkasan }}</p>
            <time v-if="fmt(b.tanggal)" class="mt-auto pt-2 text-xs text-slate-500" :datetime="b.tanggal ?? undefined">{{ fmt(b.tanggal) }}</time>
          </div>
        </RouterLink>
      </li>
    </ul>

    <UiPagination v-if="total > perPage" v-model:page="page" :per-page="perPage" :total="total" />
  </section>
</template>
