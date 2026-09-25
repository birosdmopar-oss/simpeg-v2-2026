<script setup lang="ts">
/**
 * G-10 — FAQ untuk pegawai (MTC-013, DBV-002/CR-003 §6): semua role yang login. Hanya entri yang seluruh rantainya
 * Aktif (topik, sub topik, artikel) — disaring backend. Navigasi kiri topik → sub topik → judul artikel, pencarian
 * judul & isi, panel detail (breadcrumb, konten HTML lewat SafeHtml/sanitizeHtml, artikel terkait, widget rating).
 * State ada di URL: /faq/:id? (artikel terbuka) + ?q= (kata kunci), sehingga tombol kembali & tautan berfungsi.
 * Kelola konten ada di halaman Master Data (role 1).
 */
import { ArrowLeft, ChevronDown, ChevronRight, FileText, Search } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { type RouteLocationRaw, useRoute, useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { isApiError } from '@/lib/axios'
import SafeHtml from '@/shared/components/SafeHtml.vue'

import FaqRatingWidget from '../components/FaqRatingWidget.vue'
import { FAQ_SEARCH_MAX_LENGTH, faqService } from '../services/faq.service'
import type { FaqArticleDetail, FaqRate, FaqSearchResult, FaqTopic } from '../types'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const topics = ref<FaqTopic[]>([])
const treeLoading = ref(false)
const treeError = ref('')
const expanded = ref(new Set<string>())

const activeQuery = computed(() => (typeof route.query.q === 'string' ? route.query.q.trim() : ''))
const articleId = computed(() => (typeof route.params.id === 'string' ? route.params.id : ''))

const searchInput = ref(activeQuery.value)
const results = ref<FaqSearchResult[]>([])
const searchLoading = ref(false)
const searchError = ref('')

const article = ref<FaqArticleDetail | null>(null)
const articleLoading = ref(false)
const articleError = ref('')

/** Nomor urut request: respons lama (pencarian/artikel sebelumnya) diabaikan. */
let searchSeq = 0
let articleSeq = 0

const panelFirst = computed(() => articleId.value !== '' || activeQuery.value !== '')

function articleLink(id: string): RouteLocationRaw {
  return { name: 'faq', params: { id }, query: activeQuery.value ? { q: activeQuery.value } : {} }
}

function toggleTopic(id: string): void {
  const next = new Set(expanded.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  expanded.value = next
}

function expandTopic(id: string): void {
  if (!expanded.value.has(id)) expanded.value = new Set([...expanded.value, id])
}

async function loadTree(): Promise<void> {
  treeLoading.value = true
  treeError.value = ''
  try {
    topics.value = await faqService.tree()
    const first = topics.value[0]
    if (first && expanded.value.size === 0) expandTopic(first.id)
  } catch (err) {
    treeError.value = isApiError(err) ? err.message : 'Gagal memuat daftar FAQ.'
  } finally {
    treeLoading.value = false
  }
}

async function runSearch(query: string): Promise<void> {
  const seq = ++searchSeq
  searchError.value = ''
  if (query === '') {
    results.value = []
    searchLoading.value = false
    return
  }
  searchLoading.value = true
  try {
    const found = await faqService.search(query)
    if (seq === searchSeq) results.value = found
  } catch (err) {
    if (seq !== searchSeq) return
    results.value = []
    searchError.value = isApiError(err) ? (err.errors?.search?.[0] ?? err.message) : 'Gagal mencari FAQ.'
  } finally {
    if (seq === searchSeq) searchLoading.value = false
  }
}

async function loadArticle(id: string): Promise<void> {
  const seq = ++articleSeq
  articleError.value = ''
  if (id === '') {
    article.value = null
    articleLoading.value = false
    return
  }
  articleLoading.value = true
  try {
    const detail = await faqService.article(id)
    if (seq !== articleSeq) return
    article.value = detail
    expandTopic(detail.topic.id)
  } catch (err) {
    if (seq !== articleSeq) return
    article.value = null
    articleError.value = isApiError(err) ? err.message : 'Artikel tidak dapat dibuka.'
  } finally {
    if (seq === articleSeq) articleLoading.value = false
  }
}

function onRated(rate: FaqRate): void {
  if (article.value) article.value.rating = { can_rate: false, rated: true, rate }
}

/** Tanggal diperbarui (backend menulis timestamp UTC "YYYY-MM-DD HH:MM:SS"). */
function formatUpdated(value: string | null): string {
  if (!value) return ''
  const iso = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/.test(value) ? `${value.replace(' ', 'T')}Z` : value
  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })
}

watch(
  activeQuery,
  (query) => {
    if (searchInput.value.trim() !== query) searchInput.value = query
    void runSearch(query)
  },
  { immediate: true },
)

watch(articleId, (id) => void loadArticle(id), { immediate: true })

let searchTimer: ReturnType<typeof setTimeout> | undefined

function cancelSearchTimer(): void {
  clearTimeout(searchTimer)
  searchTimer = undefined
}

watch(searchInput, (value) => {
  cancelSearchTimer()
  // Sama dengan kata kunci di URL (termasuk setelah kolom cari disamakan dengan URL): tidak ada yang perlu dijalankan.
  if (value.trim() === activeQuery.value) return
  const scheduledAt = route.fullPath
  searchTimer = setTimeout(() => {
    searchTimer = undefined
    // Pengaman: route sudah berganti sejak timer dipasang (menu, tautan artikel, Back) → jangan membajak navigasi itu.
    if (route.name !== 'faq' || route.fullPath !== scheduledAt) return
    const query = value.trim()
    // Kata kunci baru → tampilkan hasil pencarian (artikel yang terbuka ditutup). Keluar dari artikel atau memulai
    // pencarian dari keadaan tanpa ?q= menambah entri history (Back kembali ke artikel/daftar); ketikan lanjutan di
    // halaman hasil hanya mengganti entri, agar history tidak bertambah per ketikan.
    const target: RouteLocationRaw = { name: 'faq', query: query ? { q: query } : {} }
    void (articleId.value !== '' || activeQuery.value === '' ? router.push(target) : router.replace(target))
  }, 300)
})

// Setiap perpindahan route membatalkan pencarian yang masih menunggu debounce, lalu kolom cari disamakan dengan URL.
// Navigasi milik timer sendiri tidak terdampak: timernya sudah selesai berjalan sebelum route berganti.
watch(
  () => route.fullPath,
  () => {
    cancelSearchTimer()
    if (route.name === 'faq' && searchInput.value.trim() !== activeQuery.value) searchInput.value = activeQuery.value
  },
)

onMounted(() => {
  void loadTree()
})

onBeforeUnmount(cancelSearchTimer)
</script>

<template>
  <section class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-slate-900">FAQ</h1>
        <p class="text-sm text-slate-500">Pertanyaan yang sering diajukan dan panduan penggunaan SIMPEG.</p>
      </div>
      <RouterLink
        v-if="auth.canManageMasterData"
        :to="{ name: 'master-data', params: { entity: 'faq-article' } }"
        class="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
        data-testid="faq-manage"
      >
        Kelola FAQ
      </RouterLink>
    </div>

    <label class="relative block max-w-xl">
      <span class="sr-only">Cari FAQ</span>
      <Search class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
      <input
        v-model="searchInput"
        type="search"
        :maxlength="FAQ_SEARCH_MAX_LENGTH"
        placeholder="Cari pertanyaan atau panduan…"
        class="w-full rounded-md border border-slate-300 bg-white py-2 pl-9 pr-3 text-sm focus:border-brand-tertiary focus:outline-none focus:ring-2 focus:ring-brand-tertiary/40"
        data-testid="faq-search"
      />
    </label>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,320px)_minmax(0,1fr)]">
      <nav class="h-fit rounded-lg border border-slate-200 bg-white p-2" aria-label="Topik FAQ">
        <p v-if="treeLoading" class="px-3 py-6 text-center text-sm text-slate-500">Memuat...</p>
        <p v-else-if="treeError" class="m-1 rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ treeError }}</p>
        <p v-else-if="topics.length === 0" class="px-3 py-6 text-center text-sm text-slate-500">Belum ada FAQ.</p>
        <ul v-else class="space-y-1">
          <li v-for="topic in topics" :key="topic.id">
            <button
              type="button"
              class="flex w-full items-center gap-1.5 rounded-md px-2 py-2 text-left text-sm font-semibold text-slate-800 hover:bg-slate-50"
              :aria-expanded="expanded.has(topic.id)"
              :data-testid="`faq-topic-${topic.id}`"
              @click="toggleTopic(topic.id)"
            >
              <component :is="expanded.has(topic.id) ? ChevronDown : ChevronRight" class="h-4 w-4 shrink-0 text-slate-400" />
              {{ topic.nama }}
            </button>
            <div v-if="expanded.has(topic.id)" class="mb-2 space-y-2 pl-6">
              <p v-if="topic.sub_topics.length === 0" class="px-2 text-sm text-slate-400">Belum ada sub topik.</p>
              <div v-for="sub in topic.sub_topics" :key="sub.id">
                <p class="px-2 pt-1 text-xs font-medium uppercase tracking-wide text-slate-500">{{ sub.nama }}</p>
                <ul class="mt-1 space-y-0.5">
                  <li v-for="item in sub.articles" :key="item.id">
                    <RouterLink
                      :to="articleLink(item.id)"
                      class="block rounded-md px-2 py-1.5 text-sm text-slate-700 hover:bg-slate-50 hover:text-brand-primary"
                      :class="articleId === item.id ? 'bg-slate-100 font-medium text-brand-primary' : ''"
                      :aria-current="articleId === item.id ? 'page' : undefined"
                      :data-testid="`faq-article-${item.id}`"
                    >
                      {{ item.title }}
                    </RouterLink>
                  </li>
                  <li v-if="sub.articles.length === 0" class="px-2 py-1 text-sm text-slate-400">Belum ada artikel.</li>
                </ul>
              </div>
            </div>
          </li>
        </ul>
      </nav>

      <div class="min-w-0 rounded-lg border border-slate-200 bg-white p-5" :class="panelFirst ? 'order-first lg:order-none' : ''">
        <!-- Detail artikel -->
        <template v-if="articleId">
          <RouterLink
            v-if="activeQuery"
            :to="{ name: 'faq', query: { q: activeQuery } }"
            class="mb-3 inline-flex items-center gap-1 text-sm text-brand-tertiary hover:underline"
            data-testid="faq-back-results"
          >
            <ArrowLeft class="h-4 w-4" /> Kembali ke hasil pencarian
          </RouterLink>

          <p v-if="articleLoading" class="py-8 text-center text-sm text-slate-500">Memuat artikel...</p>
          <div v-else-if="articleError" class="space-y-2">
            <p class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ articleError }}</p>
            <RouterLink :to="{ name: 'faq' }" class="text-sm text-brand-tertiary hover:underline">Kembali ke daftar FAQ</RouterLink>
          </div>
          <article v-else-if="article" class="space-y-5" data-testid="faq-detail">
            <header>
              <nav aria-label="Breadcrumb">
                <ol class="flex flex-wrap items-center gap-1 text-xs text-slate-500">
                  <li>FAQ</li>
                  <li aria-hidden="true">›</li>
                  <li>{{ article.topic.nama }}</li>
                  <li aria-hidden="true">›</li>
                  <li>{{ article.sub_topic.nama }}</li>
                </ol>
              </nav>
              <h2 class="mt-1 text-lg font-semibold text-slate-900">{{ article.title }}</h2>
              <p v-if="formatUpdated(article.updated_at)" class="text-xs text-slate-400">Diperbarui {{ formatUpdated(article.updated_at) }}</p>
            </header>

            <SafeHtml :html="article.content" empty-text="Artikel ini belum berisi konten." />

            <FaqRatingWidget :key="article.id" :article-id="article.id" :rating="article.rating" @rated="onRated" />

            <section class="border-t border-slate-100 pt-4">
              <h3 class="text-sm font-semibold text-slate-800">Artikel Terkait</h3>
              <ul v-if="article.related.length > 0" class="mt-2 space-y-1">
                <li v-for="item in article.related" :key="item.id">
                  <RouterLink
                    :to="articleLink(item.id)"
                    class="inline-flex items-start gap-1.5 text-sm text-brand-tertiary hover:underline"
                    :data-testid="`faq-related-${item.id}`"
                  >
                    <FileText class="mt-0.5 h-4 w-4 shrink-0" /> {{ item.title }}
                  </RouterLink>
                </li>
              </ul>
              <p v-else class="mt-2 text-sm text-slate-400">Belum ada artikel terkait.</p>
            </section>
          </article>
        </template>

        <!-- Hasil pencarian -->
        <template v-else-if="activeQuery">
          <h2 class="text-sm font-semibold text-slate-800">Hasil pencarian "{{ activeQuery }}"</h2>
          <p v-if="searchLoading" class="py-8 text-center text-sm text-slate-500">Mencari...</p>
          <p v-else-if="searchError" class="mt-3 rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">
            {{ searchError }}
          </p>
          <p v-else-if="results.length === 0" class="py-8 text-center text-sm text-slate-500">
            Tidak ada artikel yang cocok. Coba kata kunci lain.
          </p>
          <ul v-else class="mt-3 divide-y divide-slate-100" data-testid="faq-results">
            <li v-for="item in results" :key="item.id" class="py-3">
              <RouterLink :to="articleLink(item.id)" class="font-medium text-brand-primary hover:underline" :data-testid="`faq-result-${item.id}`">
                {{ item.title }}
              </RouterLink>
              <p class="text-xs text-slate-500">{{ item.topic.nama }} › {{ item.sub_topic.nama }}</p>
              <p v-if="item.snippet" class="mt-1 text-sm text-slate-600">{{ item.snippet }}</p>
            </li>
          </ul>
        </template>

        <p v-else class="text-sm text-slate-500">Pilih pertanyaan di daftar topik, atau gunakan kolom pencarian.</p>
      </div>
    </div>
  </section>
</template>
