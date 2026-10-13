<script setup lang="ts">
// Pembungkus route /web-config: WebConfigView di dalam RedesignShell.
import RedesignShell from '@/shared/layouts/RedesignShell.vue'

import WebConfigView from './WebConfigView.vue'
</script>

<template>
  <RedesignShell>
    <WebConfigView />
  </RedesignShell>
</template>
