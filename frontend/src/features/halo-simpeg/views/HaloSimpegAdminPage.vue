<script setup lang="ts">
// Admin master Halo Simpeg (role 1): Kategori | Petugas | Kontak lewat prop `bagian`. KERANGKA B1: tabel ⋮ (AGENTS.md §1),
// form, dan status (PATCH …/status) dibangun di B3 (pola Master Data / PIC layanan, H8-9).
import { computed } from 'vue'

import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { type Crumb, UiCard } from '@/shared/ui'

import { BAGIAN_ADMIN_HALO, type BagianAdminHalo } from '../routes.halo'

const props = defineProps<{ bagian: BagianAdminHalo }>()

const crumbs = computed<Crumb[]>(() => [{ label: 'Home', to: { name: 'home' } }, { label: 'Halo Simpeg' }, { label: BAGIAN_ADMIN_HALO[props.bagian] }])
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <UiCard>
      <h1 class="text-h5 font-semibold text-slate-900" data-testid="halo-admin-judul">{{ BAGIAN_ADMIN_HALO[bagian] }} Halo Simpeg</h1>
      <p class="mt-2 text-body2 text-slate-500" data-testid="halo-kerangka">Layar master sedang disiapkan.</p>
    </UiCard>
  </RedesignShell>
</template>
