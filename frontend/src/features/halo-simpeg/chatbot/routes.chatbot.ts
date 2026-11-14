/**
 * Route Chatbot Manager (role 1; PRD Fase 8 v1.1 F8-32/F8-43, keputusan reviewer Q5): `/chatbot-manager/:bagian` dengan
 * bagian kategori | tag | qna | statistik | riwayat (nama route `chatbot-<bagian>`); `/chatbot-manager` → kategori.
 * Diimpor sekali dari `router/index.ts`. Menu `phase: 8` tanpa `rilis` (F8-50): selama `ACTIVE_PHASE < 8` QA lewat URL langsung.
 * Guard FE hanya UX (ADR-024); backend tetap menegakkan role 1.
 */
import type { RouteRecordRaw } from 'vue-router'

import { CHATBOT_ADMIN_ROLES } from './constants'

export const BAGIAN_CHATBOT = {
  kategori: 'Kategori',
  tag: 'Tag',
  qna: 'QnA',
  statistik: 'Statistik',
  riwayat: 'Riwayat',
} as const

export type BagianChatbot = keyof typeof BAGIAN_CHATBOT

const page = () => import('./views/ChatbotManagerPage.vue')

export const chatbotRoutes: RouteRecordRaw[] = [
  { path: '/chatbot-manager', redirect: { name: 'chatbot-kategori' } },
  ...(Object.keys(BAGIAN_CHATBOT) as BagianChatbot[]).map(
    (bagian): RouteRecordRaw => ({
      path: `/chatbot-manager/${bagian}`,
      name: `chatbot-${bagian}`,
      component: page,
      props: { bagian },
      meta: { roles: CHATBOT_ADMIN_ROLES, title: `Chatbot Manager · ${BAGIAN_CHATBOT[bagian]}` },
    }),
  ),
]
