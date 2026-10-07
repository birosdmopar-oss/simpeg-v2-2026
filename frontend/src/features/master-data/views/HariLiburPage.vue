<script setup lang="ts">
// Pembungkus route /hari-libur: HariLiburView di dalam RedesignShell.
import RedesignShell from '@/shared/layouts/RedesignShell.vue'

import HariLiburView from './HariLiburView.vue'
</script>

<template>
  <RedesignShell>
    <HariLiburView />
  </RedesignShell>
</template>
