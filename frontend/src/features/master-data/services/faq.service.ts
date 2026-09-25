/**
 * G-10 — FAQ untuk pegawai (DBV-002/CR-003 §4): baca wajib login (semua role), hanya entri yang seluruh rantainya
 * Aktif (topik, sub topik, artikel). Rating hanya role 2/6/7 (ditegakkan backend). Kelola konten memakai
 * masterService (faq-topic / faq-sub-topic / faq-article, role 1).
 *
 * Respons dinormalkan di sini (id INT → string, array opsional → []) agar view tidak bergantung pada tipe JSON.
 */
import { api } from '@/lib/axios'

import type {
  FaqApiArticleRef,
  FaqApiArticleResponse,
  FaqApiId,
  FaqApiRateResponse,
  FaqApiRef,
  FaqApiSearchResponse,
  FaqApiTreeResponse,
  FaqArticleDetail,
  FaqArticleRef,
  FaqRate,
  FaqRatePayload,
  FaqRateResponse,
  FaqRef,
  FaqSearchResult,
  FaqTopic,
} from '../types'

/** Batas panjang kata kunci pencarian (backend 422 bila lebih). */
export const FAQ_SEARCH_MAX_LENGTH = 100

const toId = (id: FaqApiId): string => String(id)

const toRef = (ref: FaqApiRef): FaqRef => ({ id: toId(ref.id), nama: ref.nama })

const toArticleRef = (ref: FaqApiArticleRef): FaqArticleRef => ({ id: toId(ref.id), title: ref.title })

function toRate(value: FaqApiId | null | undefined): FaqRate | null {
  const n = Number(value)
  return n === 1 || n === 2 ? n : null
}

export function mapFaqTree(data: FaqApiTreeResponse): FaqTopic[] {
  return (data.topics ?? []).map((topic) => ({
    ...toRef(topic),
    sub_topics: (topic.sub_topics ?? []).map((sub) => ({
      ...toRef(sub),
      articles: (sub.articles ?? []).map(toArticleRef),
    })),
  }))
}

export function mapFaqSearch(data: FaqApiSearchResponse): FaqSearchResult[] {
  return (data.results ?? []).map((item) => ({
    ...toArticleRef(item),
    topic: toRef(item.topic),
    sub_topic: toRef(item.sub_topic),
    snippet: item.snippet ?? '',
  }))
}

export function mapFaqArticle(data: FaqApiArticleResponse): FaqArticleDetail {
  return {
    ...toArticleRef(data),
    content: data.content ?? '',
    topic: toRef(data.topic),
    sub_topic: toRef(data.sub_topic),
    updated_at: data.updated_at ?? null,
    related: (data.related ?? []).map(toArticleRef),
    rating: {
      can_rate: data.rating?.can_rate === true,
      rated: data.rating?.rated === true,
      rate: toRate(data.rating?.rate),
    },
  }
}

export const faqService = {
  /** Pohon topik → sub topik → judul artikel (GET /faq). */
  async tree(): Promise<FaqTopic[]> {
    const { data } = await api.get<FaqApiTreeResponse>('/faq')
    return mapFaqTree(data)
  },

  /** Pencarian judul & isi (GET /faq?search=, maks 50 hasil urut relevansi). */
  async search(query: string): Promise<FaqSearchResult[]> {
    const { data } = await api.get<FaqApiSearchResponse>('/faq', { params: { search: query } })
    return mapFaqSearch(data)
  },

  async article(id: string): Promise<FaqArticleDetail> {
    const { data } = await api.get<FaqApiArticleResponse>(`/faq/${encodeURIComponent(id)}`)
    return mapFaqArticle(data)
  },

  /** Rating sekali per artikel per pegawai; tidak bisa diubah/dihapus. `reason` hanya dikirim untuk rate 2. */
  async rate(id: string, payload: FaqRatePayload): Promise<FaqRateResponse> {
    const body: FaqRatePayload = payload.rate === 2 ? { rate: 2, reason: payload.reason ?? '' } : { rate: 1 }
    const { data } = await api.post<FaqApiRateResponse>(`/faq/${encodeURIComponent(id)}/rate`, body)
    return { rated: data.rated === true, rate: toRate(data.rate) ?? payload.rate }
  },
}
