<script setup lang="ts">
/**
 * G-10 — FAQ untuk pegawai (MTC-013, DBV-002/CR-003 §6): semua role yang login. Hanya entri yang seluruh rantainya
 * Aktif (topik, sub topik, artikel) — disaring backend. Navigasi kiri topik → sub topik → judul artikel, pencarian
 * judul & isi, panel detail (breadcrumb, konten HTML lewat SafeHtml/sanitizeHtml, artikel terkait, widget rating).
 * State ada di URL: /faq/:id? (artikel terbuka) + ?q= (kata kunci), sehingga tombol kembali & tautan berfungsi.
 * Kelola konten ada di halaman Master Data (role 1).
 */
import { ArrowLeft, ChevronDown, ChevronRight, FileText, Mail, MessageCircle, Search } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { type RouteLocationRaw, RouterLink, useRoute, useRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { isApiError } from '@/lib/axios'
import SafeHtml from '@/shared/components/SafeHtml.vue'

import FaqRatingWidget from '../components/FaqRatingWidget.vue'
import { FAQ_SEARCH_MAX_LENGTH, faqService } from '../services/faq.service'
import type { FaqArticleDetail, FaqRate, FaqSearchResult, FaqTopic } from '../types'

/** Kontak dari Gambar 29 (Laporan Redesign). Alamat ini dari mockup — konfirmasi ke pemilik sistem sebelum rilis. */
const CONTACT_EMAIL = 'simpeg@kemenpar.go.id'

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
  <section class="space-y-8">
    <!-- Hero pencarian (Gambar 29). Dekorasi murni CSS, bukan aset gambar. -->
    <div class="relative overflow-hidden rounded-card bg-gradient-to-br from-[#DFEAFF] to-[#F2F6FF] px-5 pb-8 pt-12 text-center sm:px-10">
      <span class="pointer-events-none absolute -left-6 top-6 h-24 w-24 rotate-12 rounded-3xl bg-brand-tertiary/10" aria-hidden="true" />
      <span class="pointer-events-none absolute right-10 top-10 h-16 w-16 -rotate-12 rounded-2xl bg-brand-tertiary/10" aria-hidden="true" />
      <span class="pointer-events-none absolute -bottom-8 left-1/3 h-28 w-28 rotate-6 rounded-3xl bg-brand-tertiary/10" aria-hidden="true" />

      <RouterLink
        v-if="auth.canManageMasterData"
        :to="{ name: 'master-data', params: { entity: 'faq-article' } }"
        class="absolute right-4 top-4 rounded-lg border border-brand-tertiary/40 bg-white/80 px-3 py-1.5 text-body2 font-medium text-brand-tertiary transition hover:bg-white"
        data-testid="faq-manage"
      >
        Kelola FAQ
      </RouterLink>

      <h1 class="relative text-h4 text-slate-800 sm:text-h3">Halo, ada yang bisa kami bantu?</h1>
      <p class="relative mt-1 text-body1 text-slate-600">Silakan cari pertanyaan serupa atau panduan yang dibutuhkan.</p>

      <label class="relative mx-auto mt-6 block max-w-2xl">
        <span class="sr-only">Cari FAQ</span>
        <Search class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-500" aria-hidden="true" />
        <input
          v-model="searchInput"
          type="search"
          :maxlength="FAQ_SEARCH_MAX_LENGTH"
          placeholder="Cari pertanyaan atau panduan…"
          class="h-12 w-full rounded-xl border border-slate-200 bg-white pl-12 pr-4 text-body1 text-slate-900 shadow-card outline-none transition placeholder:text-slate-400 focus:border-brand-tertiary focus:ring-2 focus:ring-brand-tertiary/25"
          data-testid="faq-search"
        />
      </label>

      <!--
        Mockup punya dua tab (Pertanyaan Umum / Panduan Pengguna). Backend FAQ hanya menyimpan satu jenis konten,
        jadi hanya "Pertanyaan Umum" yang ditampilkan; tab kedua menunggu dukungan data.
      -->
      <div class="relative mt-8 flex justify-center">
        <span class="w-full max-w-md rounded-t-xl bg-white px-6 py-3 text-body1 font-medium text-brand-tertiary shadow-card">Pertanyaan Umum</span>
      </div>
    </div>

    <div class="grid gap-8 lg:grid-cols-[minmax(0,320px)_minmax(0,1fr)]">
      <nav class="h-fit" aria-label="Topik FAQ">
        <p v-if="treeLoading" class="px-3 py-6 text-center text-body2 text-slate-500">Memuat...</p>
        <p v-else-if="treeError" class="rounded-xl border border-danger/30 bg-danger-soft px-3 py-2 text-body2 text-[#a52b2c]" role="alert">{{ treeError }}</p>
        <p v-else-if="topics.length === 0" class="px-3 py-6 text-center text-body2 text-slate-500">Belum ada FAQ.</p>
        <ul v-else class="space-y-1 border-l border-slate-200">
          <li v-for="topic in topics" :key="topic.id">
            <button
              type="button"
              class="flex w-full items-center gap-2 py-2 pl-4 pr-2 text-left text-body1 transition hover:text-brand-tertiary"
              :class="expanded.has(topic.id) ? 'font-medium text-brand-tertiary' : 'text-slate-800'"
              :aria-expanded="expanded.has(topic.id)"
              :data-testid="`faq-topic-${topic.id}`"
              @click="toggleTopic(topic.id)"
            >
              <component :is="expanded.has(topic.id) ? ChevronDown : ChevronRight" class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
              {{ topic.nama }}
            </button>
            <div v-if="expanded.has(topic.id)" class="mb-2 space-y-2 pl-9">
              <p v-if="topic.sub_topics.length === 0" class="px-2 text-body2 text-slate-400">Belum ada sub topik.</p>
              <div v-for="sub in topic.sub_topics" :key="sub.id">
                <p class="px-2 pt-1 text-overline uppercase text-slate-400">{{ sub.nama }}</p>
                <ul class="mt-1 space-y-0.5">
                  <li v-for="item in sub.articles" :key="item.id">
                    <RouterLink
                      :to="articleLink(item.id)"
                      class="-ml-[calc(2.25rem+1px)] block border-l-2 py-1.5 pl-[calc(2.25rem-1px)] pr-2 text-body2 transition hover:text-brand-tertiary"
                      :class="articleId === item.id ? 'border-brand-tertiary font-medium text-brand-tertiary' : 'border-transparent text-slate-700'"
                      :aria-current="articleId === item.id ? 'page' : undefined"
                      :data-testid="`faq-article-${item.id}`"
                    >
                      {{ item.title }}
                    </RouterLink>
                  </li>
                  <li v-if="sub.articles.length === 0" class="px-2 py-1 text-body2 text-slate-400">Belum ada artikel.</li>
                </ul>
              </div>
            </div>
          </li>
        </ul>
      </nav>

      <div class="min-w-0" :class="panelFirst ? 'order-first lg:order-none' : ''">
        <!-- Detail artikel -->
        <template v-if="articleId">
          <RouterLink
            v-if="activeQuery"
            :to="{ name: 'faq', query: { q: activeQuery } }"
            class="mb-3 inline-flex items-center gap-1 text-body2 text-brand-tertiary hover:underline"
            data-testid="faq-back-results"
          >
            <ArrowLeft class="h-4 w-4" aria-hidden="true" /> Kembali ke hasil pencarian
          </RouterLink>

          <p v-if="articleLoading" class="py-8 text-center text-body2 text-slate-500">Memuat artikel...</p>
          <div v-else-if="articleError" class="space-y-2">
            <p class="rounded-xl border border-danger/30 bg-danger-soft px-3 py-2 text-body2 text-[#a52b2c]" role="alert">{{ articleError }}</p>
            <RouterLink :to="{ name: 'faq' }" class="text-body2 text-brand-tertiary hover:underline">Kembali ke daftar FAQ</RouterLink>
          </div>
          <article v-else-if="article" class="space-y-5" data-testid="faq-detail">
            <header>
              <nav aria-label="Breadcrumb">
                <ol class="flex flex-wrap items-center gap-1 text-caption text-slate-500">
                  <li>FAQ</li>
                  <li aria-hidden="true">›</li>
                  <li>{{ article.topic.nama }}</li>
                  <li aria-hidden="true">›</li>
                  <li>{{ article.sub_topic.nama }}</li>
                </ol>
              </nav>
              <h2 class="mt-2 text-h4 text-slate-900">{{ article.title }}</h2>
              <p v-if="formatUpdated(article.updated_at)" class="text-caption text-slate-400">Diperbarui {{ formatUpdated(article.updated_at) }}</p>
            </header>

            <div class="space-y-5 rounded-card border border-slate-200 bg-white p-6 shadow-card">
              <SafeHtml :html="article.content" empty-text="Artikel ini belum berisi konten." />
              <FaqRatingWidget :key="article.id" :article-id="article.id" :rating="article.rating" @rated="onRated" />
            </div>

            <section class="rounded-card border border-slate-200 bg-white p-5 shadow-card">
              <h3 class="text-h6 text-slate-800">Artikel Terkait</h3>
              <ul v-if="article.related.length > 0" class="mt-2 space-y-1">
                <li v-for="item in article.related" :key="item.id">
                  <RouterLink
                    :to="articleLink(item.id)"
                    class="inline-flex items-start gap-1.5 text-body2 text-brand-tertiary hover:underline"
                    :data-testid="`faq-related-${item.id}`"
                  >
                    <FileText class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" /> {{ item.title }}
                  </RouterLink>
                </li>
              </ul>
              <p v-else class="mt-2 text-body2 text-slate-400">Belum ada artikel terkait.</p>
            </section>
          </article>
        </template>

        <!-- Hasil pencarian -->
        <template v-else-if="activeQuery">
          <h2 class="text-h5 text-slate-800">Hasil pencarian "{{ activeQuery }}"</h2>
          <p v-if="searchLoading" class="py-8 text-center text-body2 text-slate-500">Mencari...</p>
          <p v-else-if="searchError" class="mt-3 rounded-xl border border-danger/30 bg-danger-soft px-3 py-2 text-body2 text-[#a52b2c]" role="alert">
            {{ searchError }}
          </p>
          <p v-else-if="results.length === 0" class="py-8 text-center text-body2 text-slate-500">
            Tidak ada artikel yang cocok. Coba kata kunci lain.
          </p>
          <ul v-else class="mt-3 divide-y divide-slate-100 rounded-card border border-slate-200 bg-white px-5 shadow-card" data-testid="faq-results">
            <li v-for="item in results" :key="item.id" class="py-4">
              <RouterLink :to="articleLink(item.id)" class="text-body1 font-medium text-brand-primary hover:text-brand-tertiary hover:underline" :data-testid="`faq-result-${item.id}`">
                {{ item.title }}
              </RouterLink>
              <p class="text-caption text-slate-500">{{ item.topic.nama }} › {{ item.sub_topic.nama }}</p>
              <p v-if="item.snippet" class="mt-1 text-body2 text-slate-600">{{ item.snippet }}</p>
            </li>
          </ul>
        </template>

        <p v-else class="rounded-card border border-dashed border-slate-300 bg-white/60 px-5 py-10 text-center text-body1 text-slate-500">
          Pilih pertanyaan di daftar topik, atau gunakan kolom pencarian.
        </p>
      </div>
    </div>

    <!-- Kontak (Gambar 29 bagian bawah) -->
    <section class="text-center" aria-labelledby="faq-contact-title">
      <h2 id="faq-contact-title" class="text-h4 text-slate-800">Ada hal lain yang bisa kami bantu?</h2>
      <p class="mt-1 text-body1 text-slate-500">Jika Anda tidak menemukan jawaban pada FAQ kami, silakan hubungi kami secara langsung.</p>
      <div class="mt-6 grid gap-4 md:grid-cols-2">
        <a
          :href="`mailto:${CONTACT_EMAIL}`"
          class="group rounded-card border border-brand-tertiary/25 bg-white px-6 py-8 shadow-card transition hover:border-brand-tertiary/60"
          data-testid="faq-contact-email"
        >
          <span class="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-xl bg-brand-tertiary/10 text-brand-tertiary"><Mail class="h-6 w-6" aria-hidden="true" /></span>
          <span class="block text-h5 text-slate-900">{{ CONTACT_EMAIL }}</span>
          <span class="block text-body1 text-slate-500">Kirim ke email kami</span>
        </a>
        <!-- Chat Admin = Halo Simpeg (Fase 8): belum ada halamannya, jadi tidak dibuat seolah-olah bisa diklik. -->
        <RouterLink
          :to="{ name: 'faq', query: { chat: '1' } }"
          class="block rounded-card border border-slate-200 bg-white px-6 py-8 shadow-card transition hover:border-brand-tertiary"
          data-testid="faq-contact-chat"
        >
          <span class="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-xl bg-brand-tertiary/10 text-brand-tertiary"><MessageCircle class="h-6 w-6" aria-hidden="true" /></span>
          <span class="block text-h5 text-slate-900">Chat Admin</span>
          <span class="block text-body1 text-slate-500">Halo Simpeg — tanya langsung ke admin</span>
        </RouterLink>
      </div>
    </section>
  </section>
</template>
