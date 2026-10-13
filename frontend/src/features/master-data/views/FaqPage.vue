<script setup lang="ts">
// Pembungkus route /faq/:id?: FaqView di dalam RedesignShell.
import RedesignShell from '@/shared/layouts/RedesignShell.vue'

import FaqView from './FaqView.vue'
</script>

<template>
  <RedesignShell>
    <FaqView />
  </RedesignShell>
</template>
