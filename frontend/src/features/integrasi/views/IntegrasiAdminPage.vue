<script setup lang="ts">
// Pembungkus route /integrasi: IntegrasiAdminView di dalam RedesignShell (MAKE-078).
import RedesignShell from '@/shared/layouts/RedesignShell.vue'

import IntegrasiAdminView from './IntegrasiAdminView.vue'
</script>

<template>
  <RedesignShell>
    <IntegrasiAdminView />
  </RedesignShell>
</template>
