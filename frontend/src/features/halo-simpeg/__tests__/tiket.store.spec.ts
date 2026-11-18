/**
 * State tiket (B2/B3): daftar "Tiket saya": daftar `peran=saya` + bawaan baca `semua`, buka tiket + `POST baca` sekali hanya bila
 * `belum_dibaca` (lewat `bacaTiket`), tutup/hapus/balas menerapkan respons BE, penjaga generasi/urutan, dan reset dari
 * `resetHaloSimpeg()` (AC-E08.8).
 */
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const svc = vi.hoisted(() => ({ list: vi.fn(), detail: vi.fn(), baca: vi.fn(), tutup: vi.fn(), hapus: vi.fn(), balas: vi.fn(), buat: vi.fn(), ringkasan: vi.fn() }))
vi.mock('../services/tiket.service', async (asli) => ({ ...(await asli<typeof import('../services/tiket.service')>()), tiketHaloService: svc }))

import { haloState, muatRingkasan, resetHaloSimpeg } from '../stores/haloSimpeg'
import { balasTiket, bukaTiket, daftarAntrean, daftarPerluTindak, daftarSaya, detailTiket, hapusTiket, tutupTiket } from '../stores/tiket'
import type { Halaman, HasilBalas, Ringkasan, TiketBaris, TiketDetail } from '../types'
import baca from './fixtures/baca.json'
import tiketBalas from './fixtures/tiket-balas.json'
import detailDitutup from './fixtures/tiket-detail-ditutup.json'
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
    await daftarSaya.muat()
    expect(svc.list).toHaveBeenLastCalledWith(expect.objectContaining({ peran: 'saya', baca: 'semua', page: 1 }))
    expect(daftarSaya.state.daftar).toEqual(DAFTAR)
    await daftarSaya.ubahFilter({ page: 3 })
    await daftarSaya.ubahFilter({ status: 'open' })
    expect(svc.list).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'open', page: 1, peran: 'saya' }))
  })

  it('galat → status galat + status HTTP + pesan amplop', async () => {
    svc.list.mockRejectedValueOnce({ status: 403, message: 'Akses ditolak.', errors: null, isNetworkError: false, original: {} })
    await daftarSaya.muat()
    expect(daftarSaya.state.statusDaftar).toBe('galat')
    expect(daftarSaya.state.galatDaftar).toEqual({ status: 403, pesan: 'Akses ditolak.' })
  })
})

describe('buka tiket + POST baca', () => {
  it('belum_dibaca → POST baca sekali, ringkasan terpasang, baris & detail ditandai dibaca', async () => {
    svc.list.mockResolvedValueOnce({ ...DAFTAR, items: [{ ...DAFTAR.items[0]!, id: DETAIL.id, belum_dibaca: true }] })
    await daftarSaya.muat()
    svc.detail.mockResolvedValueOnce({ ...DETAIL, belum_dibaca: true })
    await bukaTiket(DETAIL.id)
    expect(svc.baca).toHaveBeenCalledTimes(1)
    expect(haloState.ringkasan).toEqual(baca.data)
    expect(detailTiket.detail?.belum_dibaca).toBe(false)
    // P6 (3): baris daftar juga ditandai dibaca.
    expect(daftarSaya.state.daftar?.items.find((t) => t.id === DETAIL.id)?.belum_dibaca).toBe(false)
  })

  it('sudah dibaca → tanpa POST baca', async () => {
    svc.detail.mockResolvedValueOnce({ ...DETAIL, belum_dibaca: false })
    await bukaTiket(DETAIL.id)
    expect(svc.baca).not.toHaveBeenCalled()
  })

  it('galat 404 → statusDetail galat dengan pesan BE', async () => {
    svc.detail.mockRejectedValueOnce({ status: 404, message: 'Data percakapan tidak ditemukan.', errors: null, isNetworkError: false, original: {} })
    await bukaTiket(99)
    expect(detailTiket.galatDetail).toEqual({ status: 404, pesan: 'Data percakapan tidak ditemukan.' })
  })

  it('penjaga urutan: detail lama yang tiba sesudah membuka tiket lain dibuang', async () => {
    const lama = tertunda<TiketDetail>()
    svc.detail.mockReturnValueOnce(lama.janji).mockResolvedValueOnce({ ...DETAIL, id: 2, belum_dibaca: false })
    const pertama = bukaTiket(1)
    await bukaTiket(2)
    lama.lepas({ ...DETAIL, id: 1, belum_dibaca: false })
    await pertama
    expect(detailTiket.detail?.id).toBe(2)
  })
})

describe('tutup, hapus, balas', () => {
  it('tutup → baris dari BE diterapkan ke daftar dan detail', async () => {
    await daftarSaya.muat()
    await bukaTiket(DETAIL.id)
    svc.tutup.mockResolvedValueOnce({ ...(tiketTutup.data as TiketBaris), id: DETAIL.id })
    // Detail dimuat ulang sesudah tutup (P1 (c)): BE kini mengembalikan tiket Closed.
    svc.detail.mockResolvedValueOnce({ ...DETAIL, belum_dibaca: false, status: 'closed', bisa_balas: false })
    await tutupTiket(DETAIL.id)
    expect(daftarSaya.state.daftar?.items.find((t) => t.id === DETAIL.id)?.status ?? 'closed').toBe('closed')
    await vi.waitFor(() => expect(detailTiket.detail?.status).toBe('closed'))
  })

  it('hapus → baris dibuang, total berkurang, panel tertutup bila tiket itu terbuka', async () => {
    await daftarSaya.muat()
    const id = DAFTAR.items[0]!.id
    await bukaTiket(id)
    svc.hapus.mockResolvedValueOnce(id)
    await hapusTiket(id)
    expect(daftarSaya.state.daftar?.items.some((t) => t.id === id)).toBe(false)
    expect(daftarSaya.state.daftar?.total).toBe(DAFTAR.total - 1)
    expect(detailTiket.idDibuka).toBeNull()
  })

  it('balas → pesan ditambahkan ke thread (T9)', async () => {
    const hasil = tiketBalas.data as HasilBalas
    svc.detail.mockResolvedValueOnce({ ...DETAIL, id: hasil.tiket.id, belum_dibaca: false })
    await bukaTiket(hasil.tiket.id)
    const jumlah = detailTiket.detail?.pesan.length ?? 0
    svc.balas.mockResolvedValueOnce(hasil)
    await balasTiket(hasil.tiket.id, { pesan: 'x', arsip: null, ingatkan_24_jam: false })
    expect(detailTiket.detail?.pesan).toHaveLength(jumlah + 1)
  })
})

describe('reset (AC-E08.8)', () => {
  it('resetHaloSimpeg mengosongkan daftar & detail; respons sesudah reset dibuang', async () => {
    await daftarSaya.muat()
    const t = tertunda<Halaman<TiketBaris>>()
    svc.list.mockReturnValueOnce(t.janji)
    const muat = daftarSaya.muat()
    resetHaloSimpeg()
    t.lepas(DAFTAR)
    await muat
    expect(daftarSaya.state.daftar).toBeNull()
    expect(detailTiket.detail).toBeNull()
    expect(daftarSaya.state.filter.baca).toBe('semua')
  })
})

describe('konsistensi daftar ↔ detail ↔ ringkasan (review B2 P1)', () => {
  it('(a) baca A tertunda → buka B → lepas: baris A belum_dibaca=false, detail B utuh', async () => {
    const a = DAFTAR.items[0]!
    svc.list.mockResolvedValueOnce({ ...DAFTAR, items: [{ ...a, belum_dibaca: true }] })
    await daftarSaya.muat()
    const t = tertunda<Ringkasan>()
    svc.baca.mockReturnValueOnce(t.janji)
    svc.detail.mockResolvedValueOnce({ ...DETAIL, id: a.id, belum_dibaca: true }).mockResolvedValueOnce({ ...DETAIL, id: 77, subjek: 'B', belum_dibaca: false })
    const bukaA = bukaTiket(a.id)
    await vi.waitFor(() => expect(svc.baca).toHaveBeenCalledWith(a.id))
    await bukaTiket(77)
    t.lepas(baca.data as Ringkasan)
    await bukaA
    expect(daftarSaya.state.daftar?.items.find((x) => x.id === a.id)?.belum_dibaca).toBe(false)
    expect(detailTiket.detail).toMatchObject({ id: 77, subjek: 'B' })
  })

  it('(b) tutup baris belum dibaca → satu GET ringkasan; balas tiket belum dibaca → satu GET ringkasan', async () => {
    const a = DAFTAR.items[0]!
    svc.list.mockResolvedValueOnce({ ...DAFTAR, items: [{ ...a, belum_dibaca: true, status: 'open' }] })
    await daftarSaya.muat()
    svc.ringkasan.mockResolvedValue(baca.data as Ringkasan)
    svc.tutup.mockResolvedValueOnce({ ...a, status: 'closed', bisa_tutup: false, belum_dibaca: false })
    await tutupTiket(a.id)
    expect(svc.ringkasan).toHaveBeenCalledTimes(1)
    svc.tutup.mockResolvedValueOnce({ ...a, status: 'closed', bisa_tutup: false, belum_dibaca: false })
    await tutupTiket(a.id)
    expect(svc.ringkasan).toHaveBeenCalledTimes(1)
  })

  it('(c) tutup tiket yang sedang terbuka → detail dimuat ulang (ditutup_oleh_label dari BE)', async () => {
    const ditutup = detailDitutup.data as TiketDetail
    svc.detail.mockResolvedValueOnce({ ...DETAIL, id: ditutup.id, belum_dibaca: false }).mockResolvedValueOnce(ditutup)
    await bukaTiket(ditutup.id)
    svc.tutup.mockResolvedValueOnce({ ...(tiketTutup.data as TiketBaris), id: ditutup.id })
    await tutupTiket(ditutup.id)
    await vi.waitFor(() => expect(detailTiket.detail?.ditutup_oleh_label).toBe(ditutup.ditutup_oleh_label))
    expect(svc.detail).toHaveBeenCalledTimes(2)
  })
})

describe('pabrik daftar per peran (B3, keputusan reviewer)', () => {
  it('dua instance: filter/halaman antrean tidak bocor ke tab saya; bawaan baca antrean = belum', async () => {
    await daftarSaya.ubahFilter({ q: 'cuti', page: 2 })
    await daftarAntrean.muat()
    expect(svc.list).toHaveBeenLastCalledWith(expect.objectContaining({ peran: 'petugas', baca: 'belum', page: 1 }))
    await daftarAntrean.ubahFilter({ status: 'open', page: 3 })
    await bukaTiket(DETAIL.id)
    expect(daftarSaya.state.filter).toMatchObject({ peran: 'saya', q: 'cuti', page: 2, baca: 'semua' })
    expect(daftarSaya.state.filter.status).toBeUndefined()
    expect(daftarAntrean.state.filter).toMatchObject({ peran: 'petugas', status: 'open', page: 3 })
  })

  it('panel Perlu ditindak: peran=petugas, perlu_tindak=1, 5 butir; filter tetap tidak bisa dilepas', async () => {
    await daftarPerluTindak.muat()
    expect(svc.list).toHaveBeenLastCalledWith(expect.objectContaining({ peran: 'petugas', perlu_tindak: true, per_page: 5 }))
    await daftarPerluTindak.ubahFilter({ perlu_tindak: false })
    expect(svc.list).toHaveBeenLastCalledWith(expect.objectContaining({ perlu_tindak: true }))
  })

  it('tutup di antrean memperbarui baris sama di tab saya (sudah dimuat), memuat ulang ringkasan + panel', async () => {
    const t = { ...DAFTAR.items[0]!, id: 50, status: 'open' as const, bisa_tutup: true, belum_dibaca: false }
    svc.list.mockResolvedValue({ ...DAFTAR, items: [t] })
    svc.ringkasan.mockResolvedValue(baca.data as Ringkasan)
    await daftarSaya.muat()
    await daftarAntrean.muat()
    await daftarPerluTindak.muat()
    const panggilanList = svc.list.mock.calls.length
    svc.tutup.mockResolvedValueOnce({ ...t, status: 'closed', bisa_tutup: false })
    await tutupTiket(50)
    expect(daftarSaya.state.daftar?.items[0]?.status).toBe('closed')
    expect(daftarAntrean.state.daftar?.items[0]?.status).toBe('closed')
    expect(svc.ringkasan).toHaveBeenCalledTimes(1)
    expect(svc.list.mock.calls.length).toBe(panggilanList + 1)
  })

  it('hapus dari antrean membuang baris di semua daftar; resetHaloSimpeg mengosongkan ketiga daftar + detail', async () => {
    const t = { ...DAFTAR.items[0]!, id: 60 }
    svc.list.mockResolvedValue({ ...DAFTAR, items: [t], total: 1 })
    svc.ringkasan.mockResolvedValue(baca.data as Ringkasan)
    await daftarSaya.muat()
    await daftarAntrean.muat()
    svc.hapus.mockResolvedValueOnce(60)
    await hapusTiket(60)
    expect(daftarSaya.state.daftar?.items).toHaveLength(0)
    expect(daftarAntrean.state.daftar?.total).toBe(0)
    resetHaloSimpeg()
    expect([daftarSaya.state.daftar, daftarAntrean.state.daftar, daftarPerluTindak.state.daftar, detailTiket.detail]).toEqual([null, null, null, null])
    expect(daftarAntrean.state.filter.baca).toBe('belum')
  })
})

describe('Q5: penyegaran ringkasan sesudah aksi tidak tertelan dedupe', () => {
  it('GET ringkasan (polling) berjalan → tutup baris belum dibaca → lepas: tepat 2 GET, badge memakai hasil kedua', async () => {
    const a = { ...DAFTAR.items[0]!, id: 70, status: 'open' as const, belum_dibaca: true, bisa_tutup: true }
    svc.list.mockResolvedValueOnce({ ...DAFTAR, items: [a] })
    await daftarSaya.muat()
    const pertama = tertunda<Ringkasan>()
    const hasilKedua = { ...(baca.data as Ringkasan), badge: 7 }
    svc.ringkasan.mockReturnValueOnce(pertama.janji).mockResolvedValueOnce(hasilKedua)
    const polling = muatRingkasan()
    svc.tutup.mockResolvedValueOnce({ ...a, status: 'closed', bisa_tutup: false, belum_dibaca: false })
    await tutupTiket(70)
    pertama.lepas({ ...(baca.data as Ringkasan), badge: 3 })
    await polling
    await vi.waitFor(() => expect(svc.ringkasan).toHaveBeenCalledTimes(2))
    await vi.waitFor(() => expect(haloState.ringkasan?.badge).toBe(7))
    expect(svc.ringkasan).toHaveBeenCalledTimes(2)
  })
})
