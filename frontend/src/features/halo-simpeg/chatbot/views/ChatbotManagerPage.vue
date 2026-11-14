<script setup lang="ts">
// Pembungkus route Chatbot Manager (role 1): bagian dipilih lewat prop `bagian` di dalam RedesignShell (pola KontenPage).
import { computed } from 'vue'

import type { Crumb } from '@/shared/ui'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'

import { BAGIAN_CHATBOT, type BagianChatbot } from '../routes.chatbot'
import MasterChatbotView from './admin/MasterChatbotView.vue'
import QnaChatbotView from './admin/QnaChatbotView.vue'
import RiwayatChatbotView from './admin/RiwayatChatbotView.vue'
import StatistikChatbotView from './admin/StatistikChatbotView.vue'

const props = defineProps<{ bagian: BagianChatbot }>()

const crumbs = computed<Crumb[]>(() => [{ label: 'Home', to: { name: 'home' } }, { label: 'Chatbot Manager' }, { label: BAGIAN_CHATBOT[props.bagian] }])
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <!-- :key = bagian: kategori↔tag memakai komponen yang sama; tanpa key data lama terbawa (pola CR-117). -->
    <MasterChatbotView v-if="bagian === 'kategori' || bagian === 'tag'" :key="bagian" :jenis="bagian" />
    <QnaChatbotView v-else-if="bagian === 'qna'" />
    <StatistikChatbotView v-else-if="bagian === 'statistik'" />
    <RiwayatChatbotView v-else />
  </RedesignShell>
</template>
