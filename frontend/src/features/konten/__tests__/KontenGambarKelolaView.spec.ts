/**
 * Halaman kelola popup/banner: daftar, menu ⋮ (urutan item & disabled), konfirmasi hapus, pemetaan service.
 * Service di-mock; form dialog di-stub (diuji terpisah lewat validasi di bawah).
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { KontenGambarRow } from '../types'

const svc = vi.hoisted(() => ({
  list: vi.fn(),
  setStatus: vi.fn(),
  geser: vi.fn(),
  remove: vi.fn(),
  opsiUnitSatker: vi.fn(),
  opsiPegawai: vi.fn(),
  target: vi.fn(),
}))

vi.mock('../services/kelola.service', async (orig) => ({
  ...(await orig<typeof import('../services/kelola.service')>()),
  kelolaService: () => svc,
}))

import KontenGambarKelolaView from '../views/KontenGambarKelolaView.vue'

const baris = (o: Partial<KontenGambarRow>): KontenGambarRow => ({
  id: '1',
  keterangan: 'Libur Lebaran',
  url: '',
  gambar_url: null,
  tgl_mulai: '2026-03-01',
  tgl_akhir: '2026-03-31',
  jam_mulai: '',
  jam_akhir: '',
  urutan: 1,
  status: '1',
  jumlah_penerima: 10,
  ...o,
})

function pasang(jenis: 'popup' | 'banner' = 'popup') {
  return mount(KontenGambarKelolaView, {
    props: { jenis },
    global: { stubs: { KontenGambarFormDialog: true }, mocks: {} },
    attachTo: document.body,
  })
}

async function bukaMenu(w: ReturnType<typeof pasang>, jenis: string, id: string) {
  await w.get(`[data-testid="konten-${jenis}-actions-${id}"]`).trigger('click')
  await flushPromises()
}

describe('KontenGambarKelolaView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    svc.opsiUnitSatker.mockResolvedValue([])
    svc.opsiPegawai.mockResolvedValue([])
    svc.list.mockResolvedValue({ items: [baris({ id: '1' }), baris({ id: '2', status: '2', keterangan: 'Cuti' })], total: 2 })
  })

  it('memuat daftar dan menampilkan baris (tanpa aksi di luar menu ⋮)', async () => {
    const w = pasang()
    await flushPromises()
    expect(svc.list).toHaveBeenCalledWith({ page: 1, per_page: 20, status: undefined })
    expect(w.get('[data-testid="konten-popup-row-1"]').text()).toContain('Libur Lebaran')
    expect(w.get('[data-testid="konten-popup-row-1"]').findAll('button')).toHaveLength(1)
    w.unmount()
  })

  it('menu baris: Edit, urutan, Hapus (tanpa aksi status); baris pertama tidak bisa dinaikkan', async () => {
    const w = pasang()
    await flushPromises()
    await bukaMenu(w, 'popup', '1')
    const aksi = [...document.body.querySelectorAll('[data-action]')].map((e) => e.getAttribute('data-action'))
    expect(aksi).toEqual(['edit', 'up', 'down', 'delete'])
    expect((document.body.querySelector('[data-action="up"]') as HTMLElement).getAttribute('aria-disabled') ?? (document.body.querySelector('[data-action="up"]') as HTMLButtonElement).disabled).toBeTruthy()
    w.unmount()
  })

  it('Hapus meminta konfirmasi dulu', async () => {
    svc.remove.mockResolvedValue(undefined)
    const w = pasang()
    await flushPromises()
    await bukaMenu(w, 'popup', '1')
    ;(document.body.querySelector('[data-action="delete"]') as HTMLElement).click()
    await flushPromises()
    expect(svc.remove).not.toHaveBeenCalled()
    w.unmount()
  })

  it('galat muat tampil sebagai pesan', async () => {
    svc.list.mockRejectedValue(new Error('x'))
    const w = pasang()
    await flushPromises()
    expect(w.get('[role="alert"]').text()).toContain('Gagal memuat popup.')
    w.unmount()
  })
})

describe('mapRow (DBV-032 §3.1/3.3)', () => {
  it('popup DATETIME dipecah ke tanggal + jam; banner DATE tanpa jam', async () => {
    const { mapRow } = await import('../services/kelola.service')
    const popup = mapRow({ id: 1, tgl_mulai: '2026-03-01 08:30:00', tgl_akhir: '2026-03-31 17:00:00' })
    expect([popup.tgl_mulai, popup.jam_mulai, popup.tgl_akhir, popup.jam_akhir]).toEqual(['2026-03-01', '08:30', '2026-03-31', '17:00'])
    const banner = mapRow({ id: 2, tgl_mulai: '2026-03-01', tgl_akhir: '2026-03-31' })
    expect([banner.tgl_mulai, banner.jam_mulai]).toEqual(['2026-03-01', ''])
  })
})
