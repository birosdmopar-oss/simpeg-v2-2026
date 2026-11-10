<script setup lang="ts">
// Pembungkus route modul konten: view yang dipilih lewat prop `halaman` di dalam RedesignShell.
import RedesignShell from '@/shared/layouts/RedesignShell.vue'

import BeritaDetailView from './BeritaDetailView.vue'
import BeritaKelolaView from './BeritaKelolaView.vue'
import BeritaPortalView from './BeritaPortalView.vue'
import DiboKelolaView from './DiboKelolaView.vue'
import KontenGambarKelolaView from './KontenGambarKelolaView.vue'
import MasterKontenView from './MasterKontenView.vue'

export type HalamanKonten = 'portal' | 'detail' | 'berita-kelola' | 'popup' | 'banner' | 'dibo' | 'berita-kategori' | 'dibo-jenis'

defineProps<{ halaman: HalamanKonten }>()
</script>

<template>
  <RedesignShell>
    <BeritaPortalView v-if="halaman === 'portal'" />
    <BeritaDetailView v-else-if="halaman === 'detail'" />
    <BeritaKelolaView v-else-if="halaman === 'berita-kelola'" />
    <KontenGambarKelolaView v-else-if="halaman === 'popup' || halaman === 'banner'" :jenis="halaman" />
    <DiboKelolaView v-else-if="halaman === 'dibo'" />
    <MasterKontenView v-else :jenis="halaman" />
  </RedesignShell>
</template>
