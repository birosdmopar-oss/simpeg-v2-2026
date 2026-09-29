<script setup lang="ts">
// Pembungkus route /hari-libur: HariLiburView di dalam AppShell.
import AppShell from '@/shared/components/AppShell.vue'

import HariLiburView from './HariLiburView.vue'
</script>

<template>
  <AppShell>
    <HariLiburView />
  </AppShell>
</template>
