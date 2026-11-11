/**
 * Lampiran berita (paritas legacy): tombol "Lihat Lampiran" di detail mengambil blob lewat service lalu membuka tab baru;
 * galat → pesan; form kirim multipart dengan jam terbit + lampiran opsional.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

const portal = vi.hoisted(() => ({ list: vi.fn(), detail: vi.fn(), kategori: vi.fn(), dibuka: vi.fn(), lampiranBlob: vi.fn() }))
vi.mock('../services/konten.service', async (orig) => ({
  ...(await orig<typeof import('../services/konten.service')>()),
  beritaPortalService: portal,
}))

const post = vi.hoisted(() => vi.fn())
vi.mock('@/lib/axios', async (orig) => ({
  ...(await orig<typeof import('@/lib/axios')>()),
  api: { post, get: vi.fn(), put: vi.fn(), delete: vi.fn(), patch: vi.fn() },
}))

import { ROUTE_DETAIL_BERITA, ROUTE_PORTAL_BERITA } from '../routes'
import { beritaKelolaService } from '../services/kelolaTeks.service'
import BeritaDetailView from '../views/BeritaDetailView.vue'

const stub = { template: '<div />' }
const buatRouter = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/berita', name: ROUTE_PORTAL_BERITA, component: stub },
      { path: '/berita/:id', name: ROUTE_DETAIL_BERITA, component: stub },
    ],
  })

const detail = (lampiran: unknown) => ({
  id: '7',
  judul: 'Judul A',
  kategori: 'Umum',
  penulis: 'Budi',
  tanggal: '2026-11-01T03:00:00Z',
  foto_url: null,
  isi: '<p>Isi</p>',
  lampiran,
})

beforeEach(() => {
  vi.clearAllMocks()
  URL.createObjectURL = vi.fn(() => 'blob:lampiran')
  URL.revokeObjectURL = vi.fn()
})

async function pasangDetail(lampiran: unknown) {
  portal.detail.mockResolvedValue(detail(lampiran))
  const router = buatRouter()
  await router.push('/berita/7')
  const w = mount(BeritaDetailView, { global: { plugins: [router] } })
  await flushPromises()
  return w
}

describe('Detail berita — lampiran', () => {
  it('tanpa lampiran tidak ada tombol', async () => {
    const w = await pasangDetail(null)
    expect(w.find('[data-testid="berita-lihat-lampiran"]').exists()).toBe(false)
    w.unmount()
  })

  it('tombol mengambil blob dan membuka tab baru ke object URL', async () => {
    const tab = { location: { href: '' }, close: vi.fn() }
    vi.spyOn(window, 'open').mockReturnValue(tab as unknown as Window)
    portal.lampiranBlob.mockResolvedValue(new Blob(['x']))
    const w = await pasangDetail({ nama: 'lampiran-berita-7.pdf', tipe: 'application/pdf', url: '/konten/berita/7/lampiran' })

    await w.get('[data-testid="berita-lihat-lampiran"]').trigger('click')
    await flushPromises()

    expect(portal.lampiranBlob).toHaveBeenCalledWith('/konten/berita/7/lampiran')
    expect(window.open).toHaveBeenCalledWith('', '_blank')
    expect(tab.location.href).toBe('blob:lampiran')
    w.unmount()
  })

  it('galat → pesan server dan tab ditutup', async () => {
    const tab = { location: { href: '' }, close: vi.fn() }
    vi.spyOn(window, 'open').mockReturnValue(tab as unknown as Window)
    portal.lampiranBlob.mockRejectedValue({ status: 404, message: 'Lampiran tidak ditemukan.', errors: null, isNetworkError: false, original: new AxiosError('x') })
    const w = await pasangDetail({ nama: 'l.png', tipe: 'image/png', url: '/konten/berita/7/lampiran' })

    await w.get('[data-testid="berita-lihat-lampiran"]').trigger('click')
    await flushPromises()

    expect(w.get('[role="alert"]').text()).toBe('Lampiran tidak ditemukan.')
    expect(tab.close).toHaveBeenCalled()
    w.unmount()
  })
})

describe('beritaKelolaService — multipart', () => {
  const form = { judul: ' Judul ', id_kategori: '3', isi: '<p>x</p>', publish_at: '2026-11-01', publish_jam: '08:30', target: 'sat_5' }

  it('create mengirim FormData dengan jam terbit dan lampiran', async () => {
    post.mockResolvedValue({ data: { id: 1, judul: 'Judul', status: 1 } })
    const berkas = new File(['x'], 'l.pdf', { type: 'application/pdf' })

    await beritaKelolaService.create(form, berkas)

    const [url, body] = post.mock.calls[0] as [string, FormData]
    expect(url).toBe('/konten/berita')
    expect(body.get('judul')).toBe('Judul')
    expect(body.get('publish_at')).toBe('2026-11-01 08:30')
    expect(body.get('target')).toBe('sat_5')
    expect((body.get('lampiran') as File).name).toBe('l.pdf')
  })

  it('update tanpa berkas tidak mengirim lampiran (lama dipertahankan) dan memakai POST ke id', async () => {
    post.mockResolvedValue({ data: { id: 9, judul: 'J', status: 0 } })

    await beritaKelolaService.update('9', form, null)

    const [url, body] = post.mock.calls[0] as [string, FormData]
    expect(url).toBe('/konten/berita/9')
    expect(body.has('lampiran')).toBe(false)
  })
})

import { diboKelolaService } from '../services/kelolaTeks.service'

describe('diboKelolaService — multipart', () => {
  const form = { id_jenis: '2', nomor: ' PER-1/2026 ', tahun: '2026', tentang: ' Cuti ', keterangan: '' }

  it('simpan mengirim file_1..3 dan penanda hapus berkas 2/3', async () => {
    post.mockResolvedValue({ data: { id: 5, nomor: 'PER-1/2026', tahun: 2026, tentang: 'Cuti', berkas: [1, 2], status: 1 } })
    const f1 = new File(['a'], 'a.pdf')
    const r = await diboKelolaService.simpan(null, form, { 1: f1, 2: null, 3: null, hapus2: false, hapus3: true })

    const [url, body] = post.mock.calls.at(-1) as [string, FormData]
    expect(url).toBe('/konten/dibo')
    expect(body.get('nomor')).toBe('PER-1/2026')
    expect(body.get('tentang')).toBe('Cuti')
    expect((body.get('file_1') as File).name).toBe('a.pdf')
    expect(body.has('file_2')).toBe(false)
    expect(body.get('hapus_file_3')).toBe('1')
    expect(body.has('hapus_file_2')).toBe(false)
    expect(r.berkas).toEqual([1, 2])
  })

  it('ubah memakai POST ke id tanpa _method', async () => {
    post.mockResolvedValue({ data: { id: 5, nomor: 'N', tahun: 2026, tentang: 'T', status: 1 } })
    await diboKelolaService.simpan('5', form, { 1: null, 2: null, 3: null, hapus2: false, hapus3: false })
    const [url, body] = post.mock.calls.at(-1) as [string, FormData]
    expect(url).toBe('/konten/dibo/5')
    expect(body.has('_method')).toBe(false)
  })
})
