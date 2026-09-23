<script setup lang="ts">
/**
 * G-10 — FAQ untuk pegawai (MTC-013): terbuka untuk semua role yang login, hanya konten published,
 * bisa dicari (judul & isi). Kelola konten ada di halaman Master Data (role 1).
 * Rating artikel belum tersedia — tabel faq_rate menunggu DDL legacy & modul Pegawai (Fase 3).
 */
import { ChevronRight, Search } from 'lucide-vue-next'
import { onMounted, ref, watch } from 'vue'

import { isApiError } from '@/lib/axios'

import { faqService } from '../services/faq.service'
import type { FaqArticle, FaqTopic } from '../types'

const topics = ref<FaqTopic[]>([])
const article = ref<FaqArticle | null>(null)
const loading = ref(false)
const error = ref('')
const search = ref('')

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    topics.value = await faqService.browse(search.value.trim())
  } catch (err) {
    error.value = isApiError(err) ? err.message : 'Gagal memuat FAQ.'
  } finally {
    loading.value = false
  }
}

async function openArticle(id: string): Promise<void> {
  error.value = ''
  try {
    article.value = await faqService.article(id)
  } catch (err) {
    error.value = isApiError(err) ? err.message : 'Artikel tidak dapat dibuka.'
  }
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => void load(), 300)
})

onMounted(() => void load())
</script>

<template>
  <section class="space-y-4">
    <div>
      <h1 class="text-xl font-semibold text-slate-900">FAQ Kepegawaian</h1>
      <p class="text-sm text-slate-500">Pertanyaan yang sering diajukan seputar kepegawaian, presensi, dan aplikasi SIMPEG.</p>
    </div>

    <p v-if="error" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <label class="relative block max-w-md">
      <Search class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
      <input
        v-model="search"
        type="search"
        placeholder="Cari pertanyaan atau kata kunci"
        class="w-full rounded-md border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-brand-tertiary focus:outline-none focus:ring-2 focus:ring-brand-tertiary/40"
        data-testid="faq-search"
      />
    </label>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
      <div class="space-y-3">
        <p v-if="loading" class="rounded-lg border border-slate-200 bg-white px-4 py-8 text-center text-sm text-slate-500">Memuat...</p>
        <p v-else-if="topics.length === 0" class="rounded-lg border border-slate-200 bg-white px-4 py-8 text-center text-sm text-slate-500">
          Tidak ada FAQ yang cocok dengan pencarian.
        </p>

        <article v-for="topic in topics" v-else :key="topic.id_topic" class="rounded-lg border border-slate-200 bg-white p-4">
          <h2 class="font-semibold text-slate-900">{{ topic.nama_topic }}</h2>
          <div v-for="sub in topic.sub_topics" :key="sub.id_sub_topic" class="mt-3">
            <h3 class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ sub.nama_sub_topic }}</h3>
            <ul class="mt-1 space-y-1">
              <li v-for="item in sub.articles" :key="item.id_article">
                <button
                  type="button"
                  class="flex w-full items-center gap-1 rounded-md px-2 py-1.5 text-left text-sm text-slate-700 hover:bg-slate-50 hover:text-brand-primary"
                  :class="article?.id_article === item.id_article ? 'bg-slate-100 font-medium text-brand-primary' : ''"
                  :data-testid="`faq-article-${item.id_article}`"
                  @click="openArticle(item.id_article)"
                >
                  <ChevronRight class="h-4 w-4 shrink-0 text-slate-400" />
                  {{ item.judul }}
                </button>
              </li>
              <li v-if="sub.articles.length === 0" class="px-2 py-1.5 text-sm text-slate-400">Belum ada artikel.</li>
            </ul>
          </div>
          <p v-if="topic.sub_topics.length === 0" class="mt-2 text-sm text-slate-400">Belum ada sub topik.</p>
        </article>
      </div>

      <div class="rounded-lg border border-slate-200 bg-white p-5">
        <template v-if="article">
          <p class="text-xs uppercase tracking-wide text-slate-500">{{ article.nama_topic }} · {{ article.nama_sub_topic }}</p>
          <h2 class="mt-1 text-lg font-semibold text-slate-900">{{ article.judul }}</h2>
          <p class="mt-3 whitespace-pre-line text-sm leading-relaxed text-slate-700">{{ article.isi }}</p>
        </template>
        <p v-else class="text-sm text-slate-500">Pilih salah satu pertanyaan untuk melihat jawabannya.</p>
      </div>
    </div>
  </section>
</template>
