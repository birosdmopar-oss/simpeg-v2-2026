<script setup lang="ts">
// Pembungkus route modul konten: view yang dipilih lewat prop `halaman` di dalam RedesignShell.
import RedesignShell from '@/shared/layouts/RedesignShell.vue'

import BeritaDetailView from './BeritaDetailView.vue'
import BeritaKelolaView from './BeritaKelolaView.vue'
import BeritaPortalView from './BeritaPortalView.vue'
import DiboBacaView from './DiboBacaView.vue'
import DiboKelolaView from './DiboKelolaView.vue'
import KontenGambarKelolaView from './KontenGambarKelolaView.vue'
import MasterKontenView from './MasterKontenView.vue'

export type HalamanKonten = 'portal' | 'detail' | 'berita-kelola' | 'popup' | 'banner' | 'dibo-baca' | 'dibo' | 'berita-kategori' | 'dibo-jenis'

defineProps<{ halaman: HalamanKonten }>()
</script>

<template>
  <RedesignShell>
    <BeritaPortalView v-if="halaman === 'portal'" />
    <BeritaDetailView v-else-if="halaman === 'detail'" />
    <BeritaKelolaView v-else-if="halaman === 'berita-kelola'" />
    <!-- :key = halaman: route popup↔banner dan berita-kategori↔dibo-jenis memakai instans komponen yang sama; tanpa key data
         lama (dan service/testid aksi lama) terbawa ke halaman baru karena view hanya memuat data di onMounted (CR-117, T-06). -->
    <KontenGambarKelolaView v-else-if="halaman === 'popup' || halaman === 'banner'" :key="halaman" :jenis="halaman" />
    <DiboBacaView v-else-if="halaman === 'dibo-baca'" />
    <DiboKelolaView v-else-if="halaman === 'dibo'" />
    <MasterKontenView v-else :key="halaman" :jenis="halaman" />
  </RedesignShell>
</template>
