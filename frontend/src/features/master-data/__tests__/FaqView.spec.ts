/**
 * G-10 — halaman FAQ pegawai: navigasi topik → sub topik → artikel, pencarian (?q=), detail artikel (konten via
 * sanitizeHtml, artikel terkait, widget rating), 404 artikel tersembunyi. CR-003: timer debounce pencarian tidak
 * boleh membajak navigasi lain (menu, klik artikel, Back), dan riwayat (push/replace) menjaga tombol Back.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { type Component, defineComponent, h, ref } from 'vue'
import { createMemoryHistory, createRouter, type Router, RouterView } from 'vue-router'

vi.mock('../services/faq.service', () => ({
  FAQ_SEARCH_MAX_LENGTH: 100,
  faqService: { tree: vi.fn(), search: vi.fn(), article: vi.fn(), rate: vi.fn() },
}))

import { faqService } from '../services/faq.service'
import type { FaqArticleDetail, FaqTopic } from '../types'
import FaqView from '../views/FaqView.vue'

const tree: FaqTopic[] = [
  {
    id: '1',
    nama: 'Akun',
    sub_topics: [
      { id: '10', nama: 'Login', articles: [{ id: '100', title: 'Lupa password' }] },
      { id: '11', nama: 'Profil', articles: [] },
    ],
  },
  { id: '2', nama: 'Presensi', sub_topics: [{ id: '20', nama: 'Absen', articles: [{ id: '200', title: 'Cara absen' }] }] },
]

const detail: FaqArticleDetail = {
  id: '100',
  title: 'Lupa password',
  content: '<p>Buka menu <strong>Akun</strong>.</p><script>alert(1)</script><img src="x" onerror="alert(2)">',
  topic: { id: '1', nama: 'Akun' },
  sub_topic: { id: '10', nama: 'Login' },
  updated_at: '2026-09-24 03:00:00',
  related: [{ id: '99', title: 'Ganti password' }],
  rating: { can_rate: true, rated: false, rate: null },
}

let router: Router

/** Aplikasi mini: RouterView, sehingga pindah ke route lain benar-benar melepas FaqView (seperti App.vue). */
const Shell = defineComponent({ render: () => h(RouterView) })

/** `root` default FaqView di-mount langsung; Shell → FaqView dirender lewat RouterView. */
async function mountAt(path: string, root: Component = FaqView) {
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { render: () => h('p', { 'data-testid': 'home' }, 'Beranda') } },
      { path: '/faq/:id?', name: 'faq', component: FaqView },
      { path: '/master/:entity?', name: 'master-data', component: { render: () => null } },
    ],
  })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(root, { global: { plugins: [router] } })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(faqService.tree).mockReset().mockResolvedValue(tree)
  vi.mocked(faqService.search).mockReset()
  vi.mocked(faqService.article).mockReset()
})

describe('FaqView', () => {
  it('memuat pohon topik; topik pertama terbuka, sub topik kosong diberi keterangan', async () => {
    const wrapper = await mountAt('/faq')

    expect(faqService.tree).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[data-testid="faq-article-100"]').text()).toBe('Lupa password')
    expect(wrapper.text()).toContain('Belum ada artikel.')
    // Topik kedua masih tertutup; klik untuk membuka.
    expect(wrapper.find('[data-testid="faq-article-200"]').exists()).toBe(false)
    await wrapper.get('[data-testid="faq-topic-2"]').trigger('click')
    expect(wrapper.find('[data-testid="faq-article-200"]').exists()).toBe(true)
    expect(faqService.article).not.toHaveBeenCalled()
  })

  it('/faq/:id → detail: breadcrumb, konten tersanitasi, artikel terkait, widget rating', async () => {
    vi.mocked(faqService.article).mockResolvedValue(detail)
    const wrapper = await mountAt('/faq/100')

    expect(faqService.article).toHaveBeenCalledWith('100')
    const article = wrapper.get('[data-testid="faq-detail"]')
    expect(article.text()).toContain('Akun')
    expect(article.text()).toContain('Login')
    expect(article.get('h2').text()).toBe('Lupa password')
    const html = article.get('[data-testid="safe-html"]').html()
    expect(html).toContain('<strong>Akun</strong>')
    expect(html).not.toContain('script')
    expect(html).not.toContain('onerror')
    expect(wrapper.get('[data-testid="faq-related-99"]').attributes('href')).toBe('/faq/99')
    expect(wrapper.find('[data-testid="faq-rate-yes"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="faq-article-100"]').attributes('aria-current')).toBe('page')
  })

  it('?q= → pencarian; klik hasil membuka artikel dengan tautan kembali ke hasil', async () => {
    vi.mocked(faqService.search).mockResolvedValue([
      { id: '100', title: 'Lupa password', topic: { id: '1', nama: 'Akun' }, sub_topic: { id: '10', nama: 'Login' }, snippet: 'Buka menu Akun.' },
    ])
    vi.mocked(faqService.article).mockResolvedValue(detail)
    const wrapper = await mountAt('/faq?q=password')

    expect(faqService.search).toHaveBeenCalledWith('password')
    expect((wrapper.get('[data-testid="faq-search"]').element as HTMLInputElement).value).toBe('password')
    expect(wrapper.get('[data-testid="faq-results"]').text()).toContain('Buka menu Akun.')

    await wrapper.get('[data-testid="faq-result-100"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/faq/100?q=password')
    expect(wrapper.find('[data-testid="faq-detail"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="faq-back-results"]').attributes('href')).toBe('/faq?q=password')
  })

  it('mengetik kata kunci memperbarui ?q= (debounce) dan menjalankan pencarian', async () => {
    vi.mocked(faqService.search).mockResolvedValue([])
    const wrapper = await mountAt('/faq')

    await wrapper.get('[data-testid="faq-search"]').setValue('  cuti  ')
    await new Promise((resolve) => setTimeout(resolve, 350))
    await flushPromises()

    expect(router.currentRoute.value.query.q).toBe('cuti')
    expect(faqService.search).toHaveBeenCalledWith('cuti')
    expect(wrapper.text()).toContain('Tidak ada artikel yang cocok.')
  })

  it('artikel tersembunyi/tidak ada → pesan 404 backend', async () => {
    vi.mocked(faqService.article).mockRejectedValue({
      status: 404,
      message: 'Artikel tidak ditemukan.',
      errors: null,
      isNetworkError: false,
      original: new AxiosError('x'),
    })
    const wrapper = await mountAt('/faq/999')

    expect(wrapper.text()).toContain('Artikel tidak ditemukan.')
    expect(wrapper.find('[data-testid="faq-detail"]').exists()).toBe(false)
  })
})

describe('FaqView — debounce pencarian & riwayat navigasi (CR-003)', () => {
  const DEBOUNCE_MS = 300

  function searchBox(wrapper: Awaited<ReturnType<typeof mountAt>>) {
    return wrapper.get('[data-testid="faq-search"]')
  }

  /** Jalankan timer debounce yang tertunda, lalu tunggu navigasi & render selesai. */
  async function runDebounce(): Promise<void> {
    vi.advanceTimersByTime(DEBOUNCE_MS)
    await flushPromises()
  }

  async function goBack(): Promise<void> {
    router.back()
    await flushPromises()
  }

  beforeEach(() => {
    // Hanya setTimeout/clearTimeout yang dipalsukan: flushPromises & navigasi router tetap berjalan normal.
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    vi.mocked(faqService.search).mockResolvedValue([])
    vi.mocked(faqService.article).mockResolvedValue(detail)
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('pindah ke halaman lain sebelum 300 ms → timer dibatalkan, pengguna tidak dilempar kembali ke FAQ', async () => {
    const wrapper = await mountAt('/faq', Shell)

    await searchBox(wrapper).setValue('cuti')
    await router.push('/')
    await flushPromises()
    expect(wrapper.find('[data-testid="home"]').exists()).toBe(true)
    expect(vi.getTimerCount()).toBe(0)

    await runDebounce()
    expect(router.currentRoute.value.fullPath).toBe('/')
    expect(wrapper.find('[data-testid="home"]').exists()).toBe(true)
    expect(faqService.search).not.toHaveBeenCalled()
  })

  it('FaqView dilepas sebelum 300 ms (route tetap /faq) → timer dibatalkan saat unmount', async () => {
    // Induk yang bisa melepas FaqView tanpa melepas aplikasi (unmount aplikasi ikut mereset router).
    const visible = ref(true)
    const Host = defineComponent({ render: () => (visible.value ? h(FaqView) : null) })
    const host = await mountAt('/faq', Host)

    await host.get('[data-testid="faq-search"]').setValue('cuti')
    expect(vi.getTimerCount()).toBe(1)
    visible.value = false
    await flushPromises()
    expect(host.find('[data-testid="faq-search"]').exists()).toBe(false)
    expect(vi.getTimerCount()).toBe(0)

    await runDebounce()
    expect(router.currentRoute.value.fullPath).toBe('/faq')
  })

  it('klik judul artikel sebelum 300 ms → artikel tetap terbuka; kolom cari disamakan dengan URL', async () => {
    const wrapper = await mountAt('/faq')

    await searchBox(wrapper).setValue('cuti')
    await wrapper.get('[data-testid="faq-article-100"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/faq/100')
    expect(vi.getTimerCount()).toBe(0)

    await runDebounce()
    expect(router.currentRoute.value.fullPath).toBe('/faq/100')
    expect(wrapper.find('[data-testid="faq-detail"]').exists()).toBe(true)
    expect((searchBox(wrapper).element as HTMLInputElement).value).toBe('')
    expect(faqService.search).not.toHaveBeenCalled()
  })

  it('Back sebelum 300 ms → tetap di halaman tujuan Back, bukan diganti hasil pencarian', async () => {
    const wrapper = await mountAt('/faq')
    await wrapper.get('[data-testid="faq-article-100"]').trigger('click')
    await flushPromises()

    await searchBox(wrapper).setValue('cuti')
    await goBack()
    expect(router.currentRoute.value.fullPath).toBe('/faq')
    expect(vi.getTimerCount()).toBe(0)

    await runDebounce()
    expect(router.currentRoute.value.fullPath).toBe('/faq')
    expect((searchBox(wrapper).element as HTMLInputElement).value).toBe('')
    expect(faqService.search).not.toHaveBeenCalled()
  })

  it('mengetik saat artikel terbuka → entri baru (push): Back kembali ke artikel', async () => {
    const wrapper = await mountAt('/faq')
    await wrapper.get('[data-testid="faq-article-100"]').trigger('click')
    await flushPromises()

    await searchBox(wrapper).setValue('cuti')
    await runDebounce()
    expect(router.currentRoute.value.fullPath).toBe('/faq?q=cuti')
    expect(faqService.search).toHaveBeenCalledWith('cuti')
    expect(wrapper.find('[data-testid="faq-detail"]').exists()).toBe(false)

    await goBack()
    expect(router.currentRoute.value.fullPath).toBe('/faq/100')
    expect(wrapper.find('[data-testid="faq-detail"]').exists()).toBe(true)
    expect((searchBox(wrapper).element as HTMLInputElement).value).toBe('')
  })

  it('pencarian baru dari keadaan tanpa q → push; ketikan lanjutan di halaman hasil → replace', async () => {
    const wrapper = await mountAt('/faq')

    await searchBox(wrapper).setValue('cuti')
    await runDebounce()
    expect(router.currentRoute.value.fullPath).toBe('/faq?q=cuti')

    await searchBox(wrapper).setValue('cutibesar')
    await runDebounce()
    expect(router.currentRoute.value.fullPath).toBe('/faq?q=cutibesar')

    // Riwayat: /faq → /faq?q=cutibesar (entri ?q=cuti diganti, bukan ditumpuk per ketikan).
    await goBack()
    expect(router.currentRoute.value.fullPath).toBe('/faq')
    expect((searchBox(wrapper).element as HTMLInputElement).value).toBe('')
  })
})
