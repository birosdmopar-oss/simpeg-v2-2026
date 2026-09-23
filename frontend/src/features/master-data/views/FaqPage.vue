<script setup lang="ts">
// Pembungkus route: FaqView di dalam AppShell.
import AppShell from '@/shared/components/AppShell.vue'

import FaqView from './FaqView.vue'
</script>

<template>
  <AppShell>
    <FaqView />
  </AppShell>
</template>
