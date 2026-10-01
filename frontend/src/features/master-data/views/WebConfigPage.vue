<script setup lang="ts">
// Pembungkus route /web-config: WebConfigView di dalam AppShell.
import AppShell from '@/shared/components/AppShell.vue'

import WebConfigView from './WebConfigView.vue'
</script>

<template>
  <AppShell>
    <WebConfigView />
  </AppShell>
</template>
