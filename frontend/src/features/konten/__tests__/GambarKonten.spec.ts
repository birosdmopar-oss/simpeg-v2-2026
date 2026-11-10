/**
 * Gambar privat popup/banner: diambil sebagai blob lewat service (bukan <img src> langsung), object URL dilepas saat
 * dilepas/ganti path, gagal → teks pengganti beraksesibilitas.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const svc = vi.hoisted(() => ({ gambarBlob: vi.fn() }))
vi.mock('../services/konten.service', () => ({ kontenService: svc }))

import GambarKonten from '../components/GambarKonten.vue'

beforeEach(() => {
  vi.clearAllMocks()
  let n = 0
  URL.createObjectURL = vi.fn(() => `blob:uji-${++n}`)
  URL.revokeObjectURL = vi.fn()
})

describe('GambarKonten', () => {
  it('mengambil blob lewat service dan memakai object URL', async () => {
    svc.gambarBlob.mockResolvedValue(new Blob(['x']))
    const w = mount(GambarKonten, { props: { path: '/konten/popup/1/gambar', alt: 'Pengumuman' } })
    await flushPromises()
    expect(svc.gambarBlob).toHaveBeenCalledWith('/konten/popup/1/gambar')
    expect(w.get('img').attributes('src')).toBe('blob:uji-1')
    expect(w.get('img').attributes('data-gambar')).toBe('/konten/popup/1/gambar')
    w.unmount()
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:uji-1')
  })

  it('ganti path memuat ulang dan melepas URL lama', async () => {
    svc.gambarBlob.mockResolvedValue(new Blob(['x']))
    const w = mount(GambarKonten, { props: { path: '/konten/popup/1/gambar', alt: 'A' } })
    await flushPromises()
    await w.setProps({ path: '/konten/popup/2/gambar' })
    await flushPromises()
    expect(w.get('img').attributes('src')).toBe('blob:uji-2')
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:uji-1')
    w.unmount()
  })

  it('gagal → teks pengganti ber-role img dan ber-label', async () => {
    svc.gambarBlob.mockRejectedValue(new Error('x'))
    const w = mount(GambarKonten, { props: { path: '/konten/popup/1/gambar', alt: 'Pengumuman' } })
    await flushPromises()
    const g = w.get('[data-testid="gambar-konten-gagal"]')
    expect(g.attributes('role')).toBe('img')
    expect(g.attributes('aria-label')).toBe('Pengumuman')
    expect(w.find('img').exists()).toBe(false)
    w.unmount()
  })

  it('respons lama yang terlambat tidak menimpa path terbaru', async () => {
    let lepasPertama: (b: Blob) => void = () => undefined
    svc.gambarBlob.mockImplementationOnce(() => new Promise<Blob>((r) => (lepasPertama = r)))
    svc.gambarBlob.mockResolvedValueOnce(new Blob(['b']))
    const w = mount(GambarKonten, { props: { path: '/konten/popup/1/gambar', alt: 'A' } })
    await w.setProps({ path: '/konten/popup/2/gambar' })
    await flushPromises()
    lepasPertama(new Blob(['a']))
    await flushPromises()
    expect(w.get('img').attributes('src')).toBe('blob:uji-1')
    expect(w.get('img').attributes('data-gambar')).toBe('/konten/popup/2/gambar')
    w.unmount()
  })
})
