<script setup lang="ts">
/**
 * Berita terbaru beranda (F-07, PRD Fase 7 v2.0 §7): daftar ringkas berita yang boleh dilihat aktor. Props: `limit` (5)
 * dan `judul` ("Berita Terbaru"). Tautan "Lihat semua" memakai NAMA route portal berita. Kosong/403/galat → tidak merender.
 */
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

import { ROUTE_DETAIL_BERITA, ROUTE_PORTAL_BERITA } from '../routes'
import { kontenService } from '../services/konten.service'
import type { BeritaRingkas } from '../types'

const props = withDefaults(defineProps<{ limit?: number; judul?: string }>(), { limit: 5, judul: 'Berita Terbaru' })

const daftar = ref<BeritaRingkas[]>([])

function tanggalTampil(iso: string | null): string {
  if (!iso) return ''
  const d = new Date(iso)
  return Number.isNaN(d.getTime())
    ? ''
    : d.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Asia/Jakarta' })
}

onMounted(async () => {
  try {
    daftar.value = await kontenService.beritaTerbaru(props.limit)
  } catch (e) {
    if (import.meta.env.DEV) console.warn('[konten] berita terbaru gagal dimuat', e)
  }
})
</script>

<template>
  <section v-if="daftar.length > 0" class="rounded-2xl bg-white p-4 shadow-panel" :aria-label="props.judul" data-testid="konten-berita-terbaru">
    <div class="flex items-center justify-between">
      <h2 class="text-h3 font-semibold text-slate-900">{{ props.judul }}</h2>
      <RouterLink :to="{ name: ROUTE_PORTAL_BERITA }" class="text-body1 text-brand-tertiary hover:underline" data-testid="konten-berita-semua">
        Lihat semua
      </RouterLink>
    </div>
    <ul class="mt-3 divide-y divide-slate-100">
      <li v-for="b in daftar" :key="b.id" class="py-2">
        <RouterLink
          :to="{ name: ROUTE_DETAIL_BERITA, params: { id: b.id } }"
          class="block rounded-lg px-1 py-1 hover:bg-slate-50"
          data-testid="konten-berita-item"
        >
          <span class="block text-body1 font-medium text-slate-900">{{ b.judul }}</span>
          <span class="block text-body2 text-slate-500">
            <template v-if="b.kategori">{{ b.kategori }}</template>
            <template v-if="b.kategori && tanggalTampil(b.tanggal)"> · </template>
            <template v-if="tanggalTampil(b.tanggal)">{{ tanggalTampil(b.tanggal) }}</template>
          </span>
        </RouterLink>
      </li>
    </ul>
  </section>
</template>
