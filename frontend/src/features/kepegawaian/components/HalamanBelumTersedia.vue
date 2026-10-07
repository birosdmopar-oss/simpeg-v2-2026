<script setup lang="ts">
/**
 * Placeholder halaman Fase 3 yang belum dibangun (Sprint 0-2): judul + keterangan "belum tersedia", tanpa data contoh.
 * Diganti halaman sesungguhnya oleh WS pemiliknya di milestone berikutnya.
 */
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiCard } from '@/shared/ui'

const props = defineProps<{ title: string }>()

const crumbs = [{ label: 'Home', to: { name: 'home' } }, { label: props.title }]
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <UiCard :title="title">
      <p class="text-body2 text-slate-600" data-testid="halaman-belum-tersedia">Halaman ini belum tersedia.</p>
    </UiCard>
  </RedesignShell>
</template>
