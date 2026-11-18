/**
 * Klien API tiket Halo Simpeg memakai `api` asli (interceptor amplop v2) dengan adapter tiruan — pelajaran amplop CR-059.
 * Fixture = SALINAN contoh respons sintetis BE `backend/tests/_support/HaloSimpeg/*.json` (README tiket beku MAKE-071 di
 * `main` @64d68377). Multipart dicatat per entri; lampiran diunduh sebagai blob lewat E-04 (AC-E08.9).
 */
import { AxiosError, AxiosHeaders, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const server = vi.hoisted(() => ({
  balasan: { status: 200, body: {} as unknown, headers: {} as Record<string, string> },
  panggilan: [] as { method: string; url: string; params: unknown; body: unknown; responseType: unknown }[],
}))

vi.mock('@/lib/axios', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/axios')>()
  const adapter = async (config: InternalAxiosRequestConfig): Promise<AxiosResponse> => {
    const data = config.data
    server.panggilan.push({
      method: String(config.method),
      url: config.url ?? '',
      params: config.params ?? null,
      body:
        data instanceof FormData
          ? [...data.entries()].map(([k, v]) => [k, typeof v === 'string' ? v : `file:${(v as File).name}`])
          : data
            ? JSON.parse(String(data))
            : null,
      responseType: config.responseType ?? null,
    })
    const response: AxiosResponse = {
      status: server.balasan.status,
      statusText: 'x',
      headers: new AxiosHeaders(server.balasan.headers),
      config,
      data: server.balasan.body,
    }
    if (server.balasan.status >= 400) throw new AxiosError('gagal', 'ERR_BAD_REQUEST', config, null, response)
    return response
  }
  return { ...actual, api: actual.createApiClient({ adapter }) }
})

import { isApiError } from '@/lib/axios'
import { galatPerKolom } from '@/shared/utils/galatKolom'

import { KontrakHaloError } from '../schemas'
import { kodeGalat } from '../services/master.service'
import { queryTiket, tiketHaloService } from '../services/tiket.service'
import baca from './fixtures/baca.json'
import galat403 from './fixtures/galat-403-bukan-pihak-berwenang.json'
import galat404 from './fixtures/galat-404.json'
import galat422Ditutup from './fixtures/galat-422-ditutup.json'
import galat422TanpaKategori from './fixtures/galat-422-tanpa-kategori.json'
import galat422Tiket from './fixtures/galat-422-tiket.json'
import galat429 from './fixtures/galat-429.json'
import tiketBalas from './fixtures/tiket-balas.json'
import tiketBuat from './fixtures/tiket-buat.json'
import daftarPemilik from './fixtures/tiket-daftar-pemilik.json'
import daftarPetugas from './fixtures/tiket-daftar-petugas.json'
import detailPetugas from './fixtures/tiket-detail-petugas.json'
import tiketHapus from './fixtures/tiket-hapus.json'
import tiketRingkasan from './fixtures/tiket-ringkasan.json'
import tiketTutup from './fixtures/tiket-tutup.json'

const jawab = (body: unknown, status = 200, headers: Record<string, string> = {}): void => {
  server.balasan = { status, body, headers }
}

async function galatDari(p: Promise<unknown>): Promise<unknown> {
  return p.then(
    () => {
      throw new Error('seharusnya gagal')
    },
    (e: unknown) => e,
  )
}

beforeEach(() => {
  server.panggilan = []
  jawab({ status: 'success', data: null })
})

describe('daftar & ringkasan (fixture kontrak MAKE-071)', () => {
  it('GET tiket: Halaman<TiketBaris>; filter peran/status/kategori/q/baca/page', async () => {
    jawab(daftarPemilik)
    await expect(tiketHaloService.list({ peran: 'saya', baca: 'semua', page: 1 })).resolves.toEqual(daftarPemilik.data)
    jawab(daftarPetugas)
    await tiketHaloService.list({ peran: 'petugas', status: 'open', kategori: 3, q: '  cuti ', baca: 'belum', per_page: 500 })
    expect(server.panggilan.map((p) => [p.method, p.url, p.params])).toEqual([
      ['get', '/halo-simpeg/tiket', { peran: 'saya', baca: 'semua', page: 1 }],
      ['get', '/halo-simpeg/tiket', { peran: 'petugas', status: 'open', kategori: 3, q: 'cuti', baca: 'belum', per_page: 100 }],
    ])
  })

  it('panel "Perlu ditindak" = filter server perlu_tindak=1 (bukan menyaring halaman aktif); false tidak dikirim', () => {
    expect(queryTiket({ peran: 'petugas', perlu_tindak: true })).toEqual({ peran: 'petugas', perlu_tindak: 1 })
    expect(queryTiket({ peran: 'saya', perlu_tindak: false, status: null, kategori: null })).toEqual({ peran: 'saya' })
  })

  it('GET tiket/ringkasan dan POST baca → Ringkasan', async () => {
    jawab(tiketRingkasan)
    await expect(tiketHaloService.ringkasan()).resolves.toEqual(tiketRingkasan.data)
    jawab(baca)
    await expect(tiketHaloService.baca(7)).resolves.toEqual(baca.data)
    expect(server.panggilan.map((p) => `${p.method} ${p.url}`)).toEqual(['get /halo-simpeg/tiket/ringkasan', 'post /halo-simpeg/tiket/7/baca'])
  })

  it('respons di luar kontrak → KontrakHaloError', async () => {
    jawab({ status: 'success', data: { belum_dibaca_saya: 1 } })
    await expect(tiketHaloService.ringkasan()).rejects.toBeInstanceOf(KontrakHaloError)
  })
})

describe('detail & tulis (fixture kontrak MAKE-071)', () => {
  it('GET tiket/{id} → TiketDetail', async () => {
    jawab(detailPetugas)
    await expect(tiketHaloService.detail(1)).resolves.toEqual(detailPetugas.data)
    expect(server.panggilan[0]).toMatchObject({ method: 'get', url: '/halo-simpeg/tiket/1' })
  })

  it('CR-142 T-B1: 201 dengan message amplop (fixture CR-143) → pesan ikut; message kosong/bukan string/tanpa → null', async () => {
    expect(tiketBuat.message).toBe('Berhasil menambahkan data halo simpeg.')
    jawab(tiketBuat, 201)
    await expect(tiketHaloService.buat({ id_kategori: 1, subjek: 'a', pesan: 'b', arsip: null })).resolves.toEqual({
      tiket: tiketBuat.data,
      pesan: tiketBuat.message,
    })
    jawab({ status: tiketBuat.status, data: tiketBuat.data }, 201)
    await expect(tiketHaloService.buat({ id_kategori: 1, subjek: 'a', pesan: 'b', arsip: null })).resolves.toMatchObject({ pesan: null })
    jawab({ ...tiketBuat, message: '  ' }, 201)
    await expect(tiketHaloService.buat({ id_kategori: 1, subjek: 'a', pesan: 'b', arsip: null })).resolves.toMatchObject({ pesan: null })
    jawab({ ...tiketBuat, message: 5 }, 201)
    await expect(tiketHaloService.buat({ id_kategori: 1, subjek: 'a', pesan: 'b', arsip: null })).resolves.toMatchObject({ pesan: null })
  })

  it('POST tiket multipart nama legacy {id_question_category, subject, msg, arsip}', async () => {
    jawab(tiketBuat, 201)
    const arsip = new File(['%PDF'], 'surat.pdf', { type: 'application/pdf' })
    await expect(tiketHaloService.buat({ id_kategori: 1, subjek: ' Sisa cuti ', pesan: ' Berapa? ', arsip })).resolves.toEqual({ tiket: tiketBuat.data, pesan: tiketBuat.message })
    expect(server.panggilan[0]?.body).toEqual([
      ['id_question_category', '1'],
      ['subject', 'Sisa cuti'],
      ['msg', 'Berapa?'],
      ['arsip', 'file:surat.pdf'],
    ])
  })

  it('POST pesan → {pesan, tiket} (T9); ingatkan_24_jam hanya dikirim bila dicentang', async () => {
    jawab(tiketBalas, 201)
    await expect(tiketHaloService.balas(1, { pesan: 'Sisa cuti 10 hari.', arsip: null, ingatkan_24_jam: true })).resolves.toEqual(tiketBalas.data)
    jawab(tiketBalas, 201)
    await tiketHaloService.balas(1, { pesan: 'x', arsip: null, ingatkan_24_jam: false })
    expect(server.panggilan.map((p) => [p.url, p.body])).toEqual([
      ['/halo-simpeg/tiket/1/pesan', [['msg', 'Sisa cuti 10 hari.'], ['ingatkan_24_jam', '1']]],
      ['/halo-simpeg/tiket/1/pesan', [['msg', 'x']]],
    ])
  })

  it('POST tutup → TiketBaris; DELETE → id', async () => {
    jawab(tiketTutup)
    await expect(tiketHaloService.tutup(1)).resolves.toEqual(tiketTutup.data)
    jawab(tiketHapus)
    await expect(tiketHaloService.hapus(1)).resolves.toBe(tiketHapus.data.id)
    expect(server.panggilan.map((p) => `${p.method} ${p.url}`)).toEqual(['post /halo-simpeg/tiket/1/tutup', 'delete /halo-simpeg/tiket/1'])
  })

  it('lampiran lewat E-04: blob + nama dari Content-Disposition, tanpa path /berkas/', async () => {
    jawab(new Blob(['%PDF'], { type: 'application/pdf' }), 200, {
      'content-type': 'application/pdf',
      'content-disposition': 'inline; filename="lampiran-1-1.pdf"',
    })
    const berkas = await tiketHaloService.lampiran(1, 1)
    expect(berkas.nama).toBe('lampiran-1-1.pdf')
    expect(server.panggilan[0]).toMatchObject({ method: 'get', url: '/halo-simpeg/tiket/1/pesan/1/lampiran', responseType: 'blob' })
  })
})

describe('lampiran E-04 — galat blob dan nama cadangan (review B1 N1)', () => {
  it('404 dengan body Blob JSON amplop → ApiError 404 + pesan BE persis', async () => {
    const body = new Blob([JSON.stringify({ status: 'error', message: 'Lampiran tidak ditemukan.' })], { type: 'application/json' })
    jawab(body, 404, { 'content-type': 'application/json' })
    const e = await galatDari(tiketHaloService.lampiran(1, 2))
    expect(isApiError(e) && [e.status, e.message]).toEqual([404, 'Lampiran tidak ditemukan.'])
  })

  it('tanpa Content-Disposition → nama cadangan lampiran-{id}-{idPesan} + ekstensi dari tipe', async () => {
    jawab(new Blob(['%PDF'], { type: 'application/pdf' }), 200, { 'content-type': 'application/pdf' })
    await expect(tiketHaloService.lampiran(1, 2)).resolves.toMatchObject({ nama: 'lampiran-1-2.pdf' })
  })
})

describe('galat tiket (fixture kontrak MAKE-071)', () => {
  it('422 per kolom termasuk errors.arsip', async () => {
    jawab(galat422Tiket, 422)
    const e = await galatDari(tiketHaloService.buat({ id_kategori: 1, subjek: '', pesan: '', arsip: null }))
    expect(galatPerKolom(e)).toEqual({
      subject: 'Subjek wajib diisi.',
      msg: 'Pesan wajib diisi.',
      arsip: galat422Tiket.errors.arsip[0],
    })
  })

  it.each([
    ['422 tiket_ditutup', galat422Ditutup, 422, 'tiket_ditutup'],
    ['422 tiket_tanpa_kategori', galat422TanpaKategori, 422, 'tiket_tanpa_kategori'],
    ['403 bukan_pihak_berwenang', galat403, 403, 'bukan_pihak_berwenang'],
    ['429 throttle', galat429, 429, 'throttle'],
  ])('%s → ApiError + code + pesan BE', async (_n, body, status, code) => {
    jawab(body, status)
    const e = await galatDari(tiketHaloService.balas(1, { pesan: 'x', arsip: null, ingatkan_24_jam: false }))
    expect(isApiError(e) && [e.status, e.message]).toEqual([status, body.message])
    expect(kodeGalat(e)).toBe(code)
  })

  it('404 → "Data percakapan tidak ditemukan." tanpa code', async () => {
    jawab(galat404, 404)
    const e = await galatDari(tiketHaloService.detail(99))
    expect(isApiError(e) && e.message).toBe('Data percakapan tidak ditemukan.')
    expect(kodeGalat(e)).toBeNull()
  })
})
