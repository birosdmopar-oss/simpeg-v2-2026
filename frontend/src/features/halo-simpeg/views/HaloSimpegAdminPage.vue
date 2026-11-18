<script setup lang="ts">
// Admin master Halo Simpeg (role 1): Kategori | Petugas | Kontak lewat prop `bagian` (pola Chatbot Manager), di
// `/halo-simpeg-admin/:bagian` (keputusan CR-138: tidak ada dua item sidebar aktif bersamaan sesudah CR-135). `:key` = bagian
// agar data bagian lain tidak terbawa. Tanpa frame Figma (H8-9): pola Master Data.
import { computed } from 'vue'

import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import type { Crumb } from '@/shared/ui'

import { BAGIAN_ADMIN_HALO, type BagianAdminHalo } from '../routes.halo'
import MasterHaloView from './admin/MasterHaloView.vue'

const props = defineProps<{ bagian: BagianAdminHalo }>()

const crumbs = computed<Crumb[]>(() => [{ label: 'Home', to: { name: 'home' } }, { label: 'Halo Simpeg' }, { label: BAGIAN_ADMIN_HALO[props.bagian] }])
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <MasterHaloView :key="bagian" :bagian="bagian" />
  </RedesignShell>
</template>
