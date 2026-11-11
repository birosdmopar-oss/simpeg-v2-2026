<script setup lang="ts">
// Pembungkus route F-06 (MAKE-083): view dipilih lewat prop `halaman` di dalam RedesignShell (pola konten/KontenPage).
// CR-118: `:key="idForm"` — pindah respons form A → B pada instans yang sama memasang ulang view (tanpa data basi).
import RedesignShell from '@/shared/layouts/RedesignShell.vue'

import FormDigitalKelolaView from './FormDigitalKelolaView.vue'
import FormDigitalResponsView from './FormDigitalResponsView.vue'

defineProps<{ halaman: 'kelola' | 'respons'; idForm?: number }>()
</script>

<template>
  <RedesignShell>
    <FormDigitalResponsView v-if="halaman === 'respons' && idForm !== undefined" :key="idForm" :id-form="idForm" />
    <FormDigitalKelolaView v-else />
  </RedesignShell>
</template>
