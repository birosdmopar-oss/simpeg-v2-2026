/**
 * Portal berita: daftar kartu + tautan ke route detail, kosong/galat, mereset penanda saat dibuka; detail membersihkan
 * HTML (script/atribut/tautan berbahaya) dan menampilkan pesan untuk 403/404.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

const svc = vi.hoisted(() => ({ list: vi.fn(), detail: vi.fn(), kategori: vi.fn(), dibuka: vi.fn() }))
vi.mock('../services/konten.service', async (orig) => ({
  ...(await orig<typeof import('../services/konten.service')>()),
  beritaPortalService: svc,
}))

import { ROUTE_DETAIL_BERITA, ROUTE_PORTAL_BERITA } from '../routes'
import BeritaDetailView from '../views/BeritaDetailView.vue'
import BeritaPortalView from '../views/BeritaPortalView.vue'

const stub = { template: '<div />' }
const buatRouter = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/berita', name: ROUTE_PORTAL_BERITA, component: stub },
      { path: '/berita/:id', name: ROUTE_DETAIL_BERITA, component: stub },
    ],
  })

beforeEach(() => {
  vi.clearAllMocks()
  svc.kategori.mockResolvedValue([{ id: '1', nama: 'Umum' }])
  svc.dibuka.mockResolvedValue(undefined)
})

describe('BeritaPortalView', () => {
  it('menampilkan kartu berita bertautan ke detail dan mereset penanda', async () => {
    svc.list.mockResolvedValue({
      items: [{ id: '7', judul: 'Judul A', ringkasan: 'Ringkas', kategori: 'Umum', tanggal: '2026-11-01T03:00:00Z', foto_url: null }],
      total: 1,
    })
    const router = buatRouter()
    await router.push('/berita')
    const w = mount(BeritaPortalView, { global: { plugins: [router] } })
    await flushPromises()
    expect(svc.list).toHaveBeenCalledWith({ page: 1, per_page: 9, q: undefined, kategori: undefined })
    expect(svc.dibuka).toHaveBeenCalledTimes(1)
    const tautan = w.get('[data-testid="berita-daftar"] a')
    expect(tautan.attributes('href')).toBe('/berita/7')
    expect(tautan.text()).toContain('Judul A')
    w.unmount()
  })

  it('kosong → pesan; galat → alert', async () => {
    svc.list.mockResolvedValueOnce({ items: [], total: 0 })
    const router = buatRouter()
    await router.push('/berita')
    const w = mount(BeritaPortalView, { global: { plugins: [router] } })
    await flushPromises()
    expect(w.find('[data-testid="berita-kosong"]').exists()).toBe(true)
    w.unmount()

    svc.list.mockRejectedValueOnce(new Error('x'))
    const w2 = mount(BeritaPortalView, { global: { plugins: [router] } })
    await flushPromises()
    expect(w2.get('[role="alert"]').text()).toContain('Gagal memuat berita.')
    w2.unmount()
  })
})

describe('BeritaDetailView', () => {
  it('membersihkan isi HTML sebelum ditampilkan', async () => {
    svc.detail.mockResolvedValue({
      id: '7',
      judul: 'Judul A',
      kategori: 'Umum',
      penulis: 'Budi',
      tanggal: '2026-11-01T03:00:00Z',
      foto_url: null,
      isi: '<p onclick="x()">Halo <strong>dunia</strong><script>alert(1)</script> <a href="javascript:alert(1)">jahat</a></p>',
    })
    const router = buatRouter()
    await router.push('/berita/7')
    const w = mount(BeritaDetailView, { global: { plugins: [router] } })
    await flushPromises()
    expect(svc.detail).toHaveBeenCalledWith('7')
    const isi = w.get('[data-testid="berita-isi"]').html()
    expect(isi).toContain('<strong>dunia</strong>')
    expect(isi).not.toMatch(/script|onclick|javascript:/i)
    expect(w.get('h1').text()).toBe('Judul A')
    w.unmount()
  })

  it.each([403, 404])('%i → pesan tidak tersedia, bukan pesan server', async (status) => {
    svc.detail.mockRejectedValue({ status, message: 'pesan server', errors: null, isNetworkError: false, original: new AxiosError('x') })
    const router = buatRouter()
    await router.push('/berita/9')
    const w = mount(BeritaDetailView, { global: { plugins: [router] } })
    await flushPromises()
    expect(w.get('[role="alert"]').text()).toBe('Berita tidak ditemukan atau tidak tersedia untuk Anda.')
    w.unmount()
  })

  it('500 → pesan server', async () => {
    svc.detail.mockRejectedValue({ status: 500, message: 'pesan server', errors: null, isNetworkError: false, original: new AxiosError('x') })
    const router = buatRouter()
    await router.push('/berita/9')
    const w = mount(BeritaDetailView, { global: { plugins: [router] } })
    await flushPromises()
    expect(w.get('[role="alert"]').text()).toBe('pesan server')
    w.unmount()
  })
})
