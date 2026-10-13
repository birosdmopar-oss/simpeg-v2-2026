<script setup lang="ts">
// Pembungkus route /master/:entity?: MasterDataView di dalam RedesignShell.
import RedesignShell from '@/shared/layouts/RedesignShell.vue'

import MasterDataView from './MasterDataView.vue'
</script>

<template>
  <RedesignShell>
    <MasterDataView />
  </RedesignShell>
</template>
