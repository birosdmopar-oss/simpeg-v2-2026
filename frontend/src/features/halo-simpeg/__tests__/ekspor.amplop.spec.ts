/**
 * Klien ekspor rekap petugas (B4, MAKE-074 FE) memakai `api` asli + adapter tiruan berbalasan blob (seperti `responseType:
 * 'blob'` di browser). Fixture = SALINAN `backend/tests/_support/HaloSimpeg/*.json` ekspor dari `main` @947b94fd
 * (README MAKE-074). AC-E08.14 / AC-E09.5: 200 JSON = periode kosong tanpa unduhan; 200 xlsx = nama dari header; 403/422 =
 * pesan BE (blob JSON diurai `ambilBerkas`, `code` ikut).
 */
import { AxiosError, AxiosHeaders, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const server = vi.hoisted(() => ({
  balasan: { status: 200, body: null as unknown, headers: {} as Record<string, string> },
  panggilan: [] as { url: string; params: unknown; responseType: unknown }[],
}))

vi.mock('@/lib/axios', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/axios')>()
  const adapter = async (config: InternalAxiosRequestConfig): Promise<AxiosResponse> => {
    server.panggilan.push({ url: config.url ?? '', params: config.params ?? null, responseType: config.responseType ?? null })
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
import { tiketHaloService } from '../services/tiket.service'
import eksporKosong from './fixtures/ekspor-kosong.json'
import galat403 from './fixtures/galat-403-bukan-petugas.json'
import galat422 from './fixtures/galat-422-ekspor.json'
import galat422Besar from './fixtures/galat-422-ekspor-terlalu-besar.json'

const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
const blobJson = (body: unknown) => new Blob([JSON.stringify(body)], { type: 'application/json' })
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
})

describe('ekspor rekap petugas (fixture kontrak MAKE-074)', () => {
  it('GET tiket/ekspor?ds&de&format=xlsx dengan responseType blob', async () => {
    jawab(new Blob(['PK'], { type: XLSX }), 200, { 'content-type': XLSX, 'content-disposition': 'attachment; filename="Rekap_Halo_Simpeg_2026-11-01_2026-11-18.xlsx"' })
    await tiketHaloService.ekspor('2026-11-01', '2026-11-18')
    expect(server.panggilan[0]).toEqual({
      url: '/halo-simpeg/tiket/ekspor',
      params: { ds: '2026-11-01', de: '2026-11-18', format: 'xlsx' },
      responseType: 'blob',
    })
  })

  it('200 xlsx → jenis berkas, nama dari Content-Disposition', async () => {
    const blob = new Blob(['PK'], { type: XLSX })
    jawab(blob, 200, { 'content-type': XLSX, 'content-disposition': 'attachment; filename="Rekap_Halo_Simpeg_2026-11-01_2026-11-18.xlsx"' })
    await expect(tiketHaloService.ekspor('2026-11-01', '2026-11-18')).resolves.toEqual({
      jenis: 'berkas',
      nama: 'Rekap_Halo_Simpeg_2026-11-01_2026-11-18.xlsx',
      blob,
    })
  })

  it('200 JSON (Content-Type application/json, tanpa Content-Disposition) → jenis kosong + pesan BE (AC-E09.5)', async () => {
    jawab(blobJson(eksporKosong), 200, { 'content-type': 'application/json' })
    await expect(tiketHaloService.ekspor('2026-11-01', '2026-11-01')).resolves.toEqual({ jenis: 'kosong', pesan: eksporKosong.message })
  })

  it('200 JSON di luar kontrak → KontrakHaloError (bukan berkas rusak)', async () => {
    jawab(blobJson({ status: 'success', data: { kosong: false } }), 200, { 'content-type': 'application/json' })
    await expect(tiketHaloService.ekspor('2026-11-01', '2026-11-01')).rejects.toBeInstanceOf(KontrakHaloError)
  })

  it.each([
    ['403 bukan_petugas', galat403, 403, 'bukan_petugas'],
    ['422 ekspor_terlalu_besar', galat422Besar, 422, 'ekspor_terlalu_besar'],
  ])('%s (body blob JSON) → ApiError + code + pesan BE', async (_n, body, status, code) => {
    jawab(blobJson(body), status, { 'content-type': 'application/json' })
    const e = await galatDari(tiketHaloService.ekspor('2026-11-01', '2026-11-18'))
    expect(isApiError(e) && [e.status, e.message]).toEqual([status, body.message])
    expect(kodeGalat(e)).toBe(code)
  })

  it('422 per kolom ds/de → galatPerKolom', async () => {
    jawab(blobJson(galat422), 422, { 'content-type': 'application/json' })
    const e = await galatDari(tiketHaloService.ekspor('', ''))
    expect(galatPerKolom(e)).toEqual({ ds: 'Tanggal awal dan akhir wajib diisi.', de: 'Tanggal awal dan akhir wajib diisi.' })
  })
})
