<script setup lang="ts">
// Pembungkus route F-06 (MAKE-083): view dipilih lewat prop `halaman` di dalam RedesignShell (pola konten/KontenPage).
import RedesignShell from '@/shared/layouts/RedesignShell.vue'

import FormDigitalKelolaView from './FormDigitalKelolaView.vue'
import FormDigitalResponsView from './FormDigitalResponsView.vue'

defineProps<{ halaman: 'kelola' | 'respons'; idForm?: number }>()
</script>

<template>
  <RedesignShell>
    <FormDigitalResponsView v-if="halaman === 'respons' && idForm !== undefined" :id-form="idForm" />
    <FormDigitalKelolaView v-else />
  </RedesignShell>
</template>
