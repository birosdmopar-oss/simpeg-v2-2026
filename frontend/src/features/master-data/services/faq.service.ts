/**
 * G-10 — FAQ untuk pegawai (UL_ALL): hanya konten published. Kelola konten memakai masterService
 * (entity faq-topic / faq-sub-topic / faq-article, role 1).
 */
import { api } from '@/lib/axios'

import type { FaqArticle, FaqTopic } from '../types'

export const faqService = {
  async browse(search = ''): Promise<FaqTopic[]> {
    const { data } = await api.get<FaqTopic[]>('/faq', { params: search ? { search } : {} })
    return data
  },

  async article(id: string): Promise<FaqArticle> {
    const { data } = await api.get<FaqArticle>(`/faq/${encodeURIComponent(id)}`)
    return data
  },
}
