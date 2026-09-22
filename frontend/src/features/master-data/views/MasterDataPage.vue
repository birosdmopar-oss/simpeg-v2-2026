<script setup lang="ts">
// Pembungkus route /master/:entity?: MasterDataView di dalam AppShell.
import AppShell from '@/shared/components/AppShell.vue'

import MasterDataView from './MasterDataView.vue'
</script>

<template>
  <AppShell>
    <MasterDataView />
  </AppShell>
</template>
