/**
 * CR-117 lanjutan (T-08): endpoint gambar `no-store`, jadi carousel banner dan dialog popup tidak boleh mengunduh ulang gambar
 * yang sama saat slide bolak-balik. Gambar per path diunduh SEKALI selama komponen penyedia hidup; unmount → semua object URL
 * di-revoke (tanpa kebocoran). Di luar penyedia (kolom tabel kelola) GambarKonten tetap memuat sendiri.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/konten.service', () => ({
  kontenService: { popupAktif: vi.fn(), bannerAktif: vi.fn(), beritaTerbaru: vi.fn(), gambarBlob: vi.fn(async () => new Blob(['x'])) },
}))

import { resetAntreanDialog } from '@/shared/composables/antreanDialog'

import { buatCacheGambar } from '../cacheGambar'
import BannerCarousel from '../components/BannerCarousel.vue'
import GambarKonten from '../components/GambarKonten.vue'
import PopupAktifDialog from '../components/PopupAktifDialog.vue'
import { kontenService } from '../services/konten.service'
import { resetSesiKonten } from '../sesi'

const gambarBlob = vi.mocked(kontenService.gambarBlob)
const item = (id: string) => ({ id, gambar_url: `/konten/x/${id}/gambar`, url: null, keterangan: `Ket ${id}` })
const q = (id: string) => document.body.querySelector<HTMLElement>(`[data-testid="${id}"]`)
const panggilPerPath = () => gambarBlob.mock.calls.map((c) => c[0]).sort()

let n = 0

beforeEach(() => {
  vi.clearAllMocks()
  n = 0
  resetSesiKonten()
  vi.stubGlobal('matchMedia', vi.fn().mockReturnValue({ matches: true })) // tanpa autoplay: navigasi hanya lewat klik
  URL.createObjectURL = vi.fn(() => `blob:uji-${++n}`)
  URL.revokeObjectURL = vi.fn()
})
afterEach(() => {
  resetAntreanDialog()
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('BannerCarousel — cache gambar per path', () => {
  it('bolak-balik slide mengunduh tiap gambar sekali; unmount me-revoke semua object URL', async () => {
    vi.mocked(kontenService.bannerAktif).mockResolvedValue([item('1'), item('2'), item('3')])
    const w = mount(BannerCarousel, { attachTo: document.body })
    await flushPromises()

    const maju = () => w.get('[data-testid="konten-banner-berikutnya"]').trigger('click')
    const mundur = () => w.get('[data-testid="konten-banner-sebelumnya"]').trigger('click')
    for (const aksi of [maju, maju, maju, mundur, mundur, maju, maju, maju, mundur]) {
      await aksi()
      await flushPromises()
    }

    expect(panggilPerPath()).toEqual(['/konten/x/1/gambar', '/konten/x/2/gambar', '/konten/x/3/gambar'])
    expect(URL.createObjectURL).toHaveBeenCalledTimes(3)
    expect(URL.revokeObjectURL).not.toHaveBeenCalled() // selama carousel hidup, URL tidak dilepas (slide kembali memakainya)

    w.unmount()
    await flushPromises()
    expect(URL.revokeObjectURL).toHaveBeenCalledTimes(3)
    for (const u of ['blob:uji-1', 'blob:uji-2', 'blob:uji-3']) expect(URL.revokeObjectURL).toHaveBeenCalledWith(u)
  })
})

describe('PopupAktifDialog — cache gambar per path', () => {
  it('Sebelumnya/Berikutnya bolak-balik tanpa unduh ulang; tutup/unmount me-revoke semua', async () => {
    vi.mocked(kontenService.popupAktif).mockResolvedValue([item('1'), item('2')])
    const w = mount(PopupAktifDialog, { attachTo: document.body })
    await flushPromises()
    expect(q('konten-popup-aktif')).not.toBeNull()

    for (const id of ['konten-popup-berikutnya', 'konten-popup-sebelumnya', 'konten-popup-berikutnya', 'konten-popup-sebelumnya']) {
      q(id)?.click()
      await flushPromises()
    }

    expect(panggilPerPath()).toEqual(['/konten/x/1/gambar', '/konten/x/2/gambar'])
    expect(URL.createObjectURL).toHaveBeenCalledTimes(2)

    w.unmount()
    await flushPromises()
    expect(URL.revokeObjectURL).toHaveBeenCalledTimes(2)
  })
})

describe('buatCacheGambar', () => {
  it('permintaan bersamaan untuk path yang sama memakai satu unduhan', async () => {
    const muat = vi.fn(async () => new Blob(['x']))
    const c = buatCacheGambar(muat)
    const [a, b] = await Promise.all([c.ambil('/p'), c.ambil('/p')])
    expect(a).toBe(b)
    expect(muat).toHaveBeenCalledTimes(1)
    c.lepasSemua()
  })

  it('unduhan gagal tidak di-cache (percobaan berikutnya mengunduh lagi)', async () => {
    const muat = vi.fn().mockRejectedValueOnce(new Error('x')).mockResolvedValue(new Blob(['x']))
    const c = buatCacheGambar(muat)
    await expect(c.ambil('/p')).rejects.toThrow()
    await expect(c.ambil('/p')).resolves.toMatch(/^blob:/)
    expect(muat).toHaveBeenCalledTimes(2)
    c.lepasSemua()
  })

  it('unduhan yang selesai SETELAH lepasSemua langsung di-revoke (tanpa kebocoran)', async () => {
    let selesai: (b: Blob) => void = () => undefined
    const c = buatCacheGambar(() => new Promise<Blob>((r) => (selesai = r)))
    const p = c.ambil('/p')
    p.catch(() => undefined)
    c.lepasSemua()
    selesai(new Blob(['x']))
    await expect(p).rejects.toThrow()
    expect(URL.revokeObjectURL).toHaveBeenCalledTimes(1)
  })
})

describe('GambarKonten tanpa penyedia cache', () => {
  it('tetap memuat sendiri dan me-revoke saat dilepas (kolom tabel kelola)', async () => {
    const w = mount(GambarKonten, { props: { path: '/konten/x/9/gambar', alt: 'A' } })
    await flushPromises()
    expect(gambarBlob).toHaveBeenCalledTimes(1)
    w.unmount()
    expect(URL.revokeObjectURL).toHaveBeenCalledTimes(1)
  })
})
