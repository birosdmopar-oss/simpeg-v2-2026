/**
 * Komponen konten untuk beranda (F-04/F-05/F-07, PRD Fase 7 v2.0 §7): kosong/galat → tidak render, popup sekali per sesi
 * login, carousel banner (navigasi, jeda, gerak berkurang), daftar berita terbaru.
 */
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/konten.service', () => ({
  kontenService: { popupAktif: vi.fn(), bannerAktif: vi.fn(), beritaTerbaru: vi.fn(), gambarBlob: vi.fn(async () => new Blob(['x'])) },
}))

import BannerCarousel from '../components/BannerCarousel.vue'
import BeritaTerbaruWidget from '../components/BeritaTerbaruWidget.vue'
import PopupAktifDialog from '../components/PopupAktifDialog.vue'
import { useAntreanDialog, resetAntreanDialog } from '@/shared/composables/antreanDialog'

import { resetSesiKonten } from '../sesi'
import { kontenService } from '../services/konten.service'

const popupAktif = vi.mocked(kontenService.popupAktif)
const bannerAktif = vi.mocked(kontenService.bannerAktif)
const beritaTerbaru = vi.mocked(kontenService.beritaTerbaru)

const gambar = (id: string, url: string | null = null) => ({ id, gambar_url: `/g/${id}.png`, url, keterangan: `Ket ${id}` })

function stubMatchMedia(reduce: boolean): void {
  vi.stubGlobal('matchMedia', vi.fn().mockReturnValue({ matches: reduce }))
}

beforeEach(() => {
  vi.clearAllMocks()
  resetSesiKonten()
  stubMatchMedia(false)
  URL.createObjectURL = vi.fn(() => 'blob:uji')
  URL.revokeObjectURL = vi.fn()
})
afterEach(() => {
  resetAntreanDialog()
  vi.useRealTimers()
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('PopupAktifDialog', () => {
  const tampil = () => document.body.querySelector('[data-testid="konten-popup-aktif"]')

  it('daftar kosong atau galat → tidak merender dan tidak melempar', async () => {
    popupAktif.mockResolvedValueOnce([])
    mount(PopupAktifDialog, { attachTo: document.body })
    await flushPromises()
    expect(tampil()).toBeNull()

    resetSesiKonten()
    popupAktif.mockRejectedValueOnce(new Error('403'))
    mount(PopupAktifDialog, { attachTo: document.body })
    await flushPromises()
    expect(tampil()).toBeNull()
  })

  it('tampil sekali per sesi: mount ulang tidak memanggil API dan tidak muncul lagi', async () => {
    popupAktif.mockResolvedValue([gambar('1', 'https://contoh.test/a')])
    const w = mount(PopupAktifDialog, { attachTo: document.body })
    await flushPromises()
    expect(tampil()).not.toBeNull()
    expect(document.body.querySelector('[data-testid="konten-popup-tautan"]')?.getAttribute('rel')).toBe('noopener noreferrer')
    w.unmount()
    document.body.innerHTML = ''

    mount(PopupAktifDialog, { attachTo: document.body })
    await flushPromises()
    expect(popupAktif).toHaveBeenCalledTimes(1)
    expect(tampil()).toBeNull()
  })

  it('login baru (resetSesiKonten) menampilkan popup lagi', async () => {
    popupAktif.mockResolvedValue([gambar('1')])
    const w = mount(PopupAktifDialog, { attachTo: document.body })
    await flushPromises()
    w.unmount()
    document.body.innerHTML = ''
    resetSesiKonten()
    mount(PopupAktifDialog, { attachTo: document.body })
    await flushPromises()
    expect(popupAktif).toHaveBeenCalledTimes(2)
    expect(tampil()).not.toBeNull()
  })

  it('ikut legacy: tidak bisa ditutup (Esc/X) sebelum gambar terakhir; di gambar terakhir X menutup', async () => {
    popupAktif.mockResolvedValue([gambar('1'), gambar('2')])
    mount(PopupAktifDialog, { attachTo: document.body })
    await flushPromises()
    const q = (id: string) => document.body.querySelector<HTMLButtonElement>(`[data-testid="${id}"]`)
    expect(q('konten-popup-tutup')).toBeNull()
    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()
    expect(tampil()).not.toBeNull()

    q('konten-popup-berikutnya')?.click()
    await flushPromises()
    expect(q('konten-popup-tutup')).not.toBeNull()
    q('konten-popup-tutup')?.click()
    await flushPromises()
    expect(tampil()).toBeNull()
  })

  it('menunggu antrean: form digital/pengingat cuti tampil dulu, popup baru setelah keduanya selesai', async () => {
    const { minta } = useAntreanDialog()
    const selesaiForm = await minta('form-digital')
    popupAktif.mockResolvedValue([gambar('1')])
    mount(PopupAktifDialog, { attachTo: document.body })
    await flushPromises()
    expect(popupAktif).toHaveBeenCalledTimes(1)
    expect(tampil()).toBeNull()

    const cuti = minta('pengingat-cuti')
    selesaiForm()
    const selesaiCuti = await cuti
    await flushPromises()
    expect(tampil()).toBeNull()

    selesaiCuti()
    await flushPromises()
    expect(tampil()).not.toBeNull()
  })

  it('tanpa popup tidak meminta giliran; menutup popup membebaskan antrean', async () => {
    const { minta, aktif } = useAntreanDialog()
    popupAktif.mockResolvedValueOnce([])
    mount(PopupAktifDialog, { attachTo: document.body })
    await flushPromises()
    expect(aktif.value).toBeNull()

    resetSesiKonten()
    popupAktif.mockResolvedValueOnce([gambar('1')])
    mount(PopupAktifDialog, { attachTo: document.body })
    await flushPromises()
    expect(aktif.value).toBe('popup-konten')
    const skl = minta('skl')
    document.body.querySelector<HTMLButtonElement>('[data-testid="konten-popup-tutup"]')?.click()
    await flushPromises()
    expect(tampil()).toBeNull()
    expect(aktif.value).toBe('skl')
    ;(await skl)()
  })

  it('carousel: berikutnya/sebelumnya; tombol Berikutnya hilang di gambar terakhir', async () => {
    popupAktif.mockResolvedValue([gambar('1'), gambar('2')])
    mount(PopupAktifDialog, { attachTo: document.body })
    await flushPromises()
    const q = (id: string) => document.body.querySelector<HTMLButtonElement>(`[data-testid="${id}"]`)
    expect(document.body.querySelector('img')?.getAttribute('data-gambar')).toBe('/g/1.png')
    expect(q('konten-popup-sebelumnya')?.disabled).toBe(true)
    q('konten-popup-berikutnya')?.click()
    await flushPromises()
    expect(document.body.querySelector('img')?.getAttribute('data-gambar')).toBe('/g/2.png')
    expect(q('konten-popup-berikutnya')).toBeNull()
    q('konten-popup-sebelumnya')?.click()
    await flushPromises()
    expect(document.body.querySelector('img')?.getAttribute('data-gambar')).toBe('/g/1.png')
  })
})

describe('BannerCarousel', () => {
  it('kosong atau galat → tidak merender', async () => {
    bannerAktif.mockResolvedValueOnce([])
    const a = mount(BannerCarousel)
    await flushPromises()
    expect(a.find('[data-testid="konten-banner"]').exists()).toBe(false)

    bannerAktif.mockRejectedValueOnce(new Error('galat'))
    const b = mount(BannerCarousel)
    await flushPromises()
    expect(b.find('[data-testid="konten-banner"]').exists()).toBe(false)
  })

  it('satu banner: tanpa navigasi; banyak: navigasi, indikator, dan panah keyboard memutar', async () => {
    bannerAktif.mockResolvedValueOnce([gambar('1')])
    const satu = mount(BannerCarousel)
    await flushPromises()
    expect(satu.find('[data-testid="konten-banner"]').exists()).toBe(true)
    expect(satu.find('[data-testid="konten-banner-berikutnya"]').exists()).toBe(false)

    bannerAktif.mockResolvedValueOnce([gambar('1'), gambar('2'), gambar('3')])
    const w = mount(BannerCarousel, { attrs: { class: 'mt-4' } })
    await flushPromises()
    const root = w.get('[data-testid="konten-banner"]')
    expect(root.classes()).toContain('mt-4')
    expect(w.get('img').attributes('data-gambar')).toBe('/g/1.png')
    expect(w.findAll('[data-testid="konten-banner-indikator"]')).toHaveLength(3)
    await w.get('[data-testid="konten-banner-sebelumnya"]').trigger('click')
    expect(w.get('img').attributes('data-gambar')).toBe('/g/3.png')
    await root.trigger('keydown', { key: 'ArrowRight' })
    expect(w.get('img').attributes('data-gambar')).toBe('/g/1.png')
    await w.findAll('[data-testid="konten-banner-indikator"]')[1]!.trigger('click')
    expect(w.get('img').attributes('data-gambar')).toBe('/g/2.png')
  })

  it('autoplay berjalan, berhenti saat hover dan saat dijeda', async () => {
    vi.useFakeTimers()
    bannerAktif.mockResolvedValueOnce([gambar('1'), gambar('2')])
    const w = mount(BannerCarousel, { props: { intervalMs: 1000 } })
    await flushPromises()
    vi.advanceTimersByTime(1000)
    await flushPromises()
    expect(w.get('img').attributes('data-gambar')).toBe('/g/2.png')

    await w.get('[data-testid="konten-banner"]').trigger('mouseenter')
    vi.advanceTimersByTime(3000)
    await flushPromises()
    expect(w.get('img').attributes('data-gambar')).toBe('/g/2.png')
    await w.get('[data-testid="konten-banner"]').trigger('mouseleave')

    await w.get('[data-testid="konten-banner-jeda"]').trigger('click')
    vi.advanceTimersByTime(3000)
    await flushPromises()
    expect(w.get('img').attributes('data-gambar')).toBe('/g/2.png')
  })

  it('prefers-reduced-motion: tanpa autoplay dan tanpa tombol jeda', async () => {
    vi.useFakeTimers()
    stubMatchMedia(true)
    bannerAktif.mockResolvedValueOnce([gambar('1'), gambar('2')])
    const w = mount(BannerCarousel, { props: { intervalMs: 1000 } })
    await flushPromises()
    vi.advanceTimersByTime(5000)
    await flushPromises()
    expect(w.get('img').attributes('data-gambar')).toBe('/g/1.png')
    expect(w.find('[data-testid="konten-banner-jeda"]').exists()).toBe(false)
  })
})

describe('BeritaTerbaruWidget', () => {
  const global = { stubs: { RouterLink: RouterLinkStub } }

  it('kosong atau galat → tidak merender', async () => {
    beritaTerbaru.mockResolvedValueOnce([])
    const a = mount(BeritaTerbaruWidget, { global })
    await flushPromises()
    expect(a.find('[data-testid="konten-berita-terbaru"]').exists()).toBe(false)

    beritaTerbaru.mockRejectedValueOnce(new Error('galat'))
    const b = mount(BeritaTerbaruWidget, { global })
    await flushPromises()
    expect(b.find('[data-testid="konten-berita-terbaru"]').exists()).toBe(false)
  })

  it('limit bawaan 5, judul bawaan, tautan memakai nama route, tanggal WIB', async () => {
    beritaTerbaru.mockResolvedValueOnce([
      { id: '7', judul: 'Judul A', ringkasan: '', kategori: 'Diklat', tanggal: '2026-11-10T17:30:00Z', foto_url: null },
    ])
    const w = mount(BeritaTerbaruWidget, { global })
    await flushPromises()
    expect(beritaTerbaru).toHaveBeenCalledWith(5)
    expect(w.text()).toContain('Berita Terbaru')
    expect(w.text()).toContain('Judul A')
    expect(w.text()).toContain('Diklat')
    expect(w.text()).toContain('11 November 2026') // 17:30 UTC = 00:30 WIB hari berikutnya
    const links = w.findAllComponents(RouterLinkStub)
    expect(links[0]!.props('to')).toEqual({ name: 'konten-berita' })
    expect(links[1]!.props('to')).toEqual({ name: 'konten-berita-detail', params: { id: '7' } })
  })

  it('props limit dan judul diteruskan', async () => {
    beritaTerbaru.mockResolvedValueOnce([
      { id: '1', judul: 'X', ringkasan: '', kategori: '', tanggal: null, foto_url: null },
    ])
    const w = mount(BeritaTerbaruWidget, { global, props: { limit: 3, judul: 'Kabar' } })
    await flushPromises()
    expect(beritaTerbaru).toHaveBeenCalledWith(3)
    expect(w.text()).toContain('Kabar')
    expect(w.get('[data-testid="konten-berita-terbaru"]').attributes('aria-label')).toBe('Kabar')
  })
})
