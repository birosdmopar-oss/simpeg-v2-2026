<script setup lang="ts">
// Pembungkus route /faq/:id?: FaqView di dalam RedesignShell + drawer Halo Simpeg (?chat=1).
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import HaloDrawer from '@/features/halo-simpeg/components/HaloDrawer.vue'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'

import FaqView from './FaqView.vue'

const route = useRoute()
const router = useRouter()
const chatOpen = computed(() => route.query.chat === '1')

function setChat(open: boolean): void {
  const query = { ...route.query }
  delete query.chat
  void router.replace({ query: open ? { ...query, chat: '1' } : query })
}
</script>

<template>
  <RedesignShell>
    <FaqView />
    <HaloDrawer :open="chatOpen" @update:open="setChat" />
  </RedesignShell>
</template>
