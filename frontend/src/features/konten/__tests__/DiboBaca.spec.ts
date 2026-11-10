/**
 * DIBO baca (semua role): daftar, filter/cari memanggil service, tombol unduh per berkas, kosong/galat.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const svc = vi.hoisted(() => ({ list: vi.fn(), jenis: vi.fn(), unduh: vi.fn() }))
vi.mock('../services/konten.service', async (orig) => ({
  ...(await orig<typeof import('../services/konten.service')>()),
  diboBacaService: svc,
}))

import DiboBacaView from '../views/DiboBacaView.vue'

const baris = { id: '3', nomor: 'PER-1/2026', tahun: '2026', tentang: 'Cuti', keterangan: '', jenis: 'Peraturan', berkas: [1, 2] }

beforeEach(() => {
  vi.clearAllMocks()
  svc.jenis.mockResolvedValue([{ id: '1', nama: 'Peraturan' }])
  svc.list.mockResolvedValue({ items: [baris], total: 1 })
})

describe('DiboBacaView', () => {
  it('menampilkan daftar dan tombol unduh hanya untuk berkas yang ada', async () => {
    const w = mount(DiboBacaView)
    await flushPromises()
    expect(svc.list).toHaveBeenCalledWith({ page: 1, per_page: 10, q: undefined, jenis: undefined, tahun: undefined })
    expect(w.get('[data-testid="dibo-row-3"]').text()).toContain('PER-1/2026')
    expect(w.find('[data-testid="dibo-unduh-3-1"]').exists()).toBe(true)
    expect(w.find('[data-testid="dibo-unduh-3-2"]').exists()).toBe(true)
    expect(w.find('[data-testid="dibo-unduh-3-3"]').exists()).toBe(false)
    w.unmount()
  })

  it('unduh memanggil service dengan id dan nomor berkas', async () => {
    svc.unduh.mockResolvedValue(undefined)
    const w = mount(DiboBacaView)
    await flushPromises()
    await w.get('[data-testid="dibo-unduh-3-2"]').trigger('click')
    await flushPromises()
    expect(svc.unduh).toHaveBeenCalledWith('3', 2, 'dibo-3-2')
    w.unmount()
  })

  it('galat unduh tampil sebagai pesan server', async () => {
    svc.unduh.mockRejectedValue({ status: 403, message: 'Tidak berhak.', errors: null, isNetworkError: false, original: new AxiosError('x') })
    const w = mount(DiboBacaView)
    await flushPromises()
    await w.get('[data-testid="dibo-unduh-3-1"]').trigger('click')
    await flushPromises()
    expect(w.get('[role="alert"]').text()).toBe('Tidak berhak.')
    w.unmount()
  })

  it('kosong → pesan', async () => {
    svc.list.mockResolvedValue({ items: [], total: 0 })
    const w = mount(DiboBacaView)
    await flushPromises()
    expect(w.find('[data-testid="dibo-kosong"]').exists()).toBe(true)
    w.unmount()
  })
})
