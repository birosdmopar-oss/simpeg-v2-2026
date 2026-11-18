/**
 * State "Tiket saya" (B2): daftar `peran=saya` + bawaan baca `semua`, buka tiket + `POST baca` sekali hanya bila
 * `belum_dibaca` (lewat `bacaTiket`), tutup/hapus/balas menerapkan respons BE, penjaga generasi/urutan, dan reset dari
 * `resetHaloSimpeg()` (AC-E08.8).
 */
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const svc = vi.hoisted(() => ({ list: vi.fn(), detail: vi.fn(), baca: vi.fn(), tutup: vi.fn(), hapus: vi.fn(), balas: vi.fn(), buat: vi.fn(), ringkasan: vi.fn() }))
vi.mock('../services/tiket.service', async (asli) => ({ ...(await asli<typeof import('../services/tiket.service')>()), tiketHaloService: svc }))

import { haloState, resetHaloSimpeg } from '../stores/haloSimpeg'
import { balasTiket, bukaTiket, hapusTiket, muatDaftarSaya, tiketSayaState, tutupTiket, ubahFilterSaya } from '../stores/tiketSaya'
import type { Halaman, HasilBalas, Ringkasan, TiketBaris, TiketDetail } from '../types'
import baca from './fixtures/baca.json'
import tiketBalas from './fixtures/tiket-balas.json'
import daftarPemilik from './fixtures/tiket-daftar-pemilik.json'
import detailPemilik from './fixtures/tiket-detail-pemilik.json'
import tiketTutup from './fixtures/tiket-tutup.json'

const DAFTAR = daftarPemilik.data as Halaman<TiketBaris>
const DETAIL = detailPemilik.data as TiketDetail

function tertunda<T>() {
  let lepas: (v: T) => void = () => {}
  const janji = new Promise<T>((r) => (lepas = r))
  return { janji, lepas }
}

beforeEach(() => {
  setActivePinia(createPinia())
  resetHaloSimpeg()
  for (const f of Object.values(svc)) f.mockReset()
  svc.list.mockResolvedValue(DAFTAR)
  svc.detail.mockResolvedValue(DETAIL)
  svc.baca.mockResolvedValue(baca.data as Ringkasan)
})

describe('daftar tiket saya', () => {
  it('peran=saya, bawaan baca semua, halaman 1; ubah filter → halaman kembali 1', async () => {
    await muatDaftarSaya()
    expect(svc.list).toHaveBeenLastCalledWith(expect.objectContaining({ peran: 'saya', baca: 'semua', page: 1 }))
    expect(tiketSayaState.daftar).toEqual(DAFTAR)
    await ubahFilterSaya({ page: 3 })
    await ubahFilterSaya({ status: 'open' })
    expect(svc.list).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'open', page: 1, peran: 'saya' }))
  })

  it('galat → status galat + status HTTP + pesan amplop', async () => {
    svc.list.mockRejectedValueOnce({ status: 403, message: 'Akses ditolak.', errors: null, isNetworkError: false, original: {} })
    await muatDaftarSaya()
    expect(tiketSayaState.statusDaftar).toBe('galat')
    expect(tiketSayaState.galatDaftar).toEqual({ status: 403, pesan: 'Akses ditolak.' })
  })
})

describe('buka tiket + POST baca', () => {
  it('belum_dibaca → POST baca sekali, ringkasan terpasang, baris & detail ditandai dibaca', async () => {
    await muatDaftarSaya()
    svc.detail.mockResolvedValueOnce({ ...DETAIL, belum_dibaca: true })
    await bukaTiket(DETAIL.id)
    expect(svc.baca).toHaveBeenCalledTimes(1)
    expect(haloState.ringkasan).toEqual(baca.data)
    expect(tiketSayaState.detail?.belum_dibaca).toBe(false)
  })

  it('sudah dibaca → tanpa POST baca', async () => {
    svc.detail.mockResolvedValueOnce({ ...DETAIL, belum_dibaca: false })
    await bukaTiket(DETAIL.id)
    expect(svc.baca).not.toHaveBeenCalled()
  })

  it('galat 404 → statusDetail galat dengan pesan BE', async () => {
    svc.detail.mockRejectedValueOnce({ status: 404, message: 'Data percakapan tidak ditemukan.', errors: null, isNetworkError: false, original: {} })
    await bukaTiket(99)
    expect(tiketSayaState.galatDetail).toEqual({ status: 404, pesan: 'Data percakapan tidak ditemukan.' })
  })

  it('penjaga urutan: detail lama yang tiba sesudah membuka tiket lain dibuang', async () => {
    const lama = tertunda<TiketDetail>()
    svc.detail.mockReturnValueOnce(lama.janji).mockResolvedValueOnce({ ...DETAIL, id: 2, belum_dibaca: false })
    const pertama = bukaTiket(1)
    await bukaTiket(2)
    lama.lepas({ ...DETAIL, id: 1, belum_dibaca: false })
    await pertama
    expect(tiketSayaState.detail?.id).toBe(2)
  })
})

describe('tutup, hapus, balas', () => {
  it('tutup → baris dari BE diterapkan ke daftar dan detail', async () => {
    await muatDaftarSaya()
    await bukaTiket(DETAIL.id)
    svc.tutup.mockResolvedValueOnce({ ...(tiketTutup.data as TiketBaris), id: DETAIL.id })
    await tutupTiket(DETAIL.id)
    expect(tiketSayaState.detail?.status).toBe('closed')
  })

  it('hapus → baris dibuang, total berkurang, panel tertutup bila tiket itu terbuka', async () => {
    await muatDaftarSaya()
    const id = DAFTAR.items[0]!.id
    await bukaTiket(id)
    svc.hapus.mockResolvedValueOnce(id)
    await hapusTiket(id)
    expect(tiketSayaState.daftar?.items.some((t) => t.id === id)).toBe(false)
    expect(tiketSayaState.daftar?.total).toBe(DAFTAR.total - 1)
    expect(tiketSayaState.idDibuka).toBeNull()
  })

  it('balas → pesan ditambahkan ke thread (T9)', async () => {
    const hasil = tiketBalas.data as HasilBalas
    svc.detail.mockResolvedValueOnce({ ...DETAIL, id: hasil.tiket.id, belum_dibaca: false })
    await bukaTiket(hasil.tiket.id)
    const jumlah = tiketSayaState.detail?.pesan.length ?? 0
    svc.balas.mockResolvedValueOnce(hasil)
    await balasTiket(hasil.tiket.id, { pesan: 'x', arsip: null, ingatkan_24_jam: false })
    expect(tiketSayaState.detail?.pesan).toHaveLength(jumlah + 1)
  })
})

describe('reset (AC-E08.8)', () => {
  it('resetHaloSimpeg mengosongkan daftar & detail; respons sesudah reset dibuang', async () => {
    await muatDaftarSaya()
    const t = tertunda<Halaman<TiketBaris>>()
    svc.list.mockReturnValueOnce(t.janji)
    const muat = muatDaftarSaya()
    resetHaloSimpeg()
    t.lepas(DAFTAR)
    await muat
    expect(tiketSayaState.daftar).toBeNull()
    expect(tiketSayaState.detail).toBeNull()
    expect(tiketSayaState.filter.baca).toBe('semua')
  })
})
