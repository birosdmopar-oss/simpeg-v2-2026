<script setup lang="ts">
/**
 * Detail berita (route `konten-berita-detail`, param `id`; PRD Fase 7 v2.0 §5.5). Isi HTML terbatas dibersihkan lagi
 * di FE (whitelist yang sama dengan editor) sebelum ditampilkan. 403/404 → pesan, dengan tautan kembali ke portal.
 */
import { ArrowLeft } from 'lucide-vue-next'
import { onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import { isApiError } from '@/lib/axios'
import { bersihkanHtml } from '@/shared/ui/teksSederhana'

import { ROUTE_PORTAL_BERITA } from '../routes'
import { beritaPortalService } from '../services/konten.service'
import type { BeritaDetail } from '../types'

const route = useRoute()
const berita = ref<BeritaDetail | null>(null)
const isi = ref('')
const loading = ref(false)
const error = ref('')

const tanggal = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Asia/Jakarta' })
function fmt(v: string | null): string {
  const t = v ? Date.parse(v) : Number.NaN
  return Number.isNaN(t) ? '' : tanggal.format(t)
}

async function load(): Promise<void> {
  const id = String(route.params.id ?? '')
  if (id === '') return
  loading.value = true
  error.value = ''
  berita.value = null
  try {
    const b = await beritaPortalService.detail(id)
    berita.value = b
    isi.value = bersihkanHtml(b.isi)
  } catch (e) {
    if (isApiError(e) && (e.status === 403 || e.status === 404)) error.value = 'Berita tidak ditemukan atau tidak tersedia untuk Anda.'
    else error.value = isApiError(e) ? e.message : 'Gagal memuat berita.'
  } finally {
    loading.value = false
  }
}

watch(() => route.params.id, () => void load())
onMounted(() => void load())
</script>

<template>
  <article class="mx-auto flex max-w-3xl flex-col gap-4" data-testid="konten-berita-detail">
    <RouterLink :to="{ name: ROUTE_PORTAL_BERITA }" class="inline-flex items-center gap-1 text-sm text-brand-tertiary hover:underline">
      <ArrowLeft class="h-4 w-4" aria-hidden="true" /> Kembali ke berita
    </RouterLink>

    <p v-if="loading" class="text-sm text-slate-500" role="status">Memuat...</p>
    <p v-else-if="error" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <template v-else-if="berita">
      <header class="flex flex-col gap-1">
        <span v-if="berita.kategori" class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ berita.kategori }}</span>
        <h1 class="text-2xl font-semibold text-slate-900">{{ berita.judul }}</h1>
        <p class="text-sm text-slate-500">
          <time v-if="fmt(berita.tanggal)" :datetime="berita.tanggal ?? undefined">{{ fmt(berita.tanggal) }}</time>
          <span v-if="berita.penulis"> · {{ berita.penulis }}</span>
        </p>
      </header>
      <img v-if="berita.foto_url" :src="berita.foto_url" :alt="berita.judul" class="max-h-96 w-full rounded-xl object-cover" />
      <!-- eslint-disable-next-line vue/no-v-html -- HTML sudah dibersihkan ke whitelist (bersihkanHtml) -->
      <div class="isi-berita text-body1 text-slate-800" data-testid="berita-isi" v-html="isi" />
    </template>
  </article>
</template>

<style scoped>
.isi-berita :deep(ul) {
  list-style: disc;
  padding-left: 1.25rem;
}
.isi-berita :deep(ol) {
  list-style: decimal;
  padding-left: 1.25rem;
}
.isi-berita :deep(p + p),
.isi-berita :deep(p + ul),
.isi-berita :deep(p + ol),
.isi-berita :deep(ul + p),
.isi-berita :deep(ol + p) {
  margin-top: 0.75rem;
}
.isi-berita :deep(a) {
  text-decoration: underline;
}
</style>
