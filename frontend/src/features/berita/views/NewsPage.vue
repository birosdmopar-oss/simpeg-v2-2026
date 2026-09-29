<script setup lang="ts">
/**
 * Portal Berita (§4.2.3, Gambar 28) — Fase 7, F-07. Semua role login boleh melihat (hr/news = UL_ALL; CRUD role 1 & 3
 * di luar cakupan UI ini). Hero pencarian, daftar berita dengan thumbnail, paginasi, serta sidebar "Berita Terbaru"
 * dan "Kategori Berita" (klik kategori = filter).
 *
 * DATA CONTOH & ASET SEMENTARA: berita dari mock; foto dari registry placeholder (Unsplash). Halaman detail berita
 * tidak ada di dokumen sehingga kartu belum bisa dibuka.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiCard, UiNotice, UiPagination, UiSearchInput } from '@/shared/ui'
import { NEWS_PHOTOS } from '@/shared/ui/placeholderAssets'

import { formatTanggalWaktu } from '../format'
import { beritaService } from '../berita.service'
import type { BeritaItem, BeritaKategoriCount } from '../types'

const PER_PAGE = 5

const search = ref('')
const kategori = ref('')
const page = ref(1)
const items = ref<BeritaItem[]>([])
const total = ref(0)
const latest = ref<BeritaItem[]>([])
const kategoriList = ref<BeritaKategoriCount[]>([])
const loading = ref(true)
const error = ref(false)

const photo = (b: BeritaItem) => NEWS_PHOTOS[b.foto_index % NEWS_PHOTOS.length]

let requestId = 0
let timer: ReturnType<typeof setTimeout> | null = null

async function load(): Promise<void> {
  const current = ++requestId
  loading.value = true
  error.value = false
  try {
    const result = await beritaService.list({ page: page.value, per_page: PER_PAGE, search: search.value || undefined, kategori: kategori.value || undefined })
    if (current !== requestId) return
    items.value = result.items
    total.value = result.total
  } catch {
    if (current === requestId) error.value = true
  } finally {
    if (current === requestId) loading.value = false
  }
}

function schedule(reset: boolean): void {
  if (reset) page.value = 1
  if (timer) clearTimeout(timer)
  timer = setTimeout(load, 250)
}

watch([search, kategori], () => schedule(true))
watch(page, () => schedule(false))

onMounted(async () => {
  ;[latest.value, kategoriList.value] = await Promise.all([beritaService.latest(5), beritaService.kategori()])
  await load()
})
onBeforeUnmount(() => {
  if (timer) clearTimeout(timer)
})

const activeLabel = computed(() => (kategori.value ? `Kategori: ${kategori.value}` : ''))
const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: 'Portal Berita' }]
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
      <div class="min-w-0 space-y-4">
        <div class="rounded-card bg-gradient-to-r from-[#DFEAFF] to-[#EEF3FF] p-4">
          <UiSearchInput v-model="search" placeholder="Cari berita..." label="Cari berita" size="lg" />
        </div>

        <UiNotice v-if="error" tone="danger">Berita gagal dimuat. Coba lagi.</UiNotice>
        <p v-if="activeLabel" class="text-body2 text-slate-500" data-testid="berita-filter-label">
          {{ activeLabel }} ·
          <button type="button" class="text-brand-tertiary hover:underline" data-testid="berita-clear-filter" @click="kategori = ''">Hapus filter</button>
        </p>

        <ul v-if="loading && items.length === 0" class="space-y-4" aria-busy="true">
          <li v-for="n in 3" :key="n" class="h-40 animate-pulse rounded-card bg-white/70" />
        </ul>

        <div v-else-if="items.length === 0" class="rounded-card border border-dashed border-slate-300 bg-white/60 px-5 py-14 text-center" data-testid="berita-empty">
          <p class="text-body1 font-medium text-slate-700">Tidak ada berita yang cocok</p>
          <p class="text-body2 text-slate-500">Ubah kata kunci atau kategori.</p>
        </div>

        <ul v-else class="space-y-4" data-testid="berita-list">
          <li v-for="b in items" :key="b.id">
            <article class="flex gap-4 rounded-card border border-slate-200 bg-white p-4 shadow-card sm:gap-5" :data-testid="`berita-item-${b.id}`">
              <img :src="photo(b).url" :alt="photo(b).alt" loading="lazy" class="h-28 w-28 shrink-0 rounded-xl bg-slate-100 object-cover sm:h-36 sm:w-36" />
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-baseline justify-between gap-x-4">
                  <h2 class="text-h6 text-slate-900">{{ b.judul }}</h2>
                  <time :datetime="b.tanggal" class="text-body2 text-slate-400">{{ formatTanggalWaktu(b.tanggal) }}</time>
                </div>
                <p class="mt-0.5 text-caption text-slate-400">oleh {{ b.penulis }} dalam {{ b.kategori }}</p>
                <p class="mt-3 line-clamp-3 text-body1 text-slate-600">{{ b.ringkasan }}</p>
              </div>
            </article>
          </li>
        </ul>

        <UiPagination v-model:page="page" :per-page="PER_PAGE" :total="total" />
      </div>

      <aside class="space-y-4" aria-label="Sidebar berita">
        <UiCard title="Berita Terbaru" subtitle="5 berita terakhir diposting">
          <ul class="space-y-4" data-testid="berita-latest">
            <li v-for="b in latest" :key="b.id" class="flex items-center gap-3">
              <img :src="photo(b).url" :alt="photo(b).alt" loading="lazy" class="h-12 w-12 shrink-0 rounded-lg bg-slate-100 object-cover" />
              <div class="min-w-0">
                <p class="truncate text-body2 text-slate-700">{{ b.judul }}</p>
                <time :datetime="b.tanggal" class="text-caption text-slate-400">{{ formatTanggalWaktu(b.tanggal) }}</time>
              </div>
            </li>
          </ul>
        </UiCard>

        <UiCard title="Kategori Berita">
          <ul class="space-y-2.5" data-testid="berita-kategori">
            <li v-for="k in kategoriList" :key="k.kategori">
              <button
                type="button"
                class="flex w-full items-center gap-2.5 rounded-lg px-1 py-1 text-left text-body1 transition hover:text-brand-tertiary"
                :class="kategori === k.kategori ? 'font-medium text-brand-tertiary' : 'text-slate-700'"
                :aria-pressed="kategori === k.kategori"
                :data-testid="`berita-kategori-${k.kategori}`"
                @click="kategori = kategori === k.kategori ? '' : k.kategori"
              >
                <span class="h-2 w-2 shrink-0 rounded-full bg-warning" aria-hidden="true" />
                {{ k.kategori }} <span class="text-slate-400">({{ k.jumlah }})</span>
              </button>
            </li>
          </ul>
        </UiCard>
      </aside>
    </div>
  </RedesignShell>
</template>
