/**
 * MAKE-078 (ISSUE-058) — logIntegrasiService dengan KLIEN ASLI (`createApiClient` + interceptor amplop) dan respons
 * berbentuk backend nyata `{status:'success', data:{...}}` (pelajaran amplop CR-059: hanya adapter jaringan yang palsu).
 */
import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const server = vi.hoisted(() => ({
  balasan: { status: 200, body: {} as unknown },
  panggilan: [] as { method: string; url: string; params: unknown; body: unknown }[],
}))

vi.mock('@/lib/axios', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/axios')>()
  const adapter = async (config: InternalAxiosRequestConfig): Promise<AxiosResponse> => {
    server.panggilan.push({
      method: String(config.method),
      url: config.url ?? '',
      params: config.params ?? null,
      body: config.data ? JSON.parse(String(config.data)) : null,
    })
    const response: AxiosResponse = { status: server.balasan.status, statusText: 'x', headers: {}, config, data: server.balasan.body }
    if (server.balasan.status >= 400) throw new AxiosError('gagal', 'ERR_BAD_REQUEST', config, null, response)
    return response
  }
  return { ...actual, api: actual.createApiClient({ adapter }) }
})

import type { LogIntegrasiFilter } from '../integrasi.types'
import { logIntegrasiService } from '../services/logIntegrasi.service'

const kosong: LogIntegrasiFilter = { layanan: '', status: '', tanggal_mulai: '', tanggal_selesai: '', page: 1, per_page: 25 }

const daftar = {
  items: [
    {
      id_integrasi_log: 7,
      arah: 1,
      layanan: 'bsre',
      operasi: 'sign_pdf',
      status: 2,
      http_status: 500,
      kode: 'E500',
      pesan: 'Gagal (teredaksi)',
      durasi_ms: 80,
      percobaan: 2,
      ref_id: 'cuti:1',
      id_klien: null,
      created_at: '2026-11-02 03:00:00',
    },
  ],
  total: 1,
  page: 1,
  per_page: 25,
  layanan_tersedia: ['bsre'],
}

beforeEach(() => {
  server.panggilan = []
})

describe('logIntegrasiService — amplop backend nyata', () => {
  it('list() GET /integrasi/log tanpa parameter kosong dan mengembalikan isi data (bukan amplop)', async () => {
    server.balasan = { status: 200, body: { status: 'success', data: daftar } }

    await expect(logIntegrasiService.list(kosong)).resolves.toEqual(daftar)
    expect(server.panggilan).toEqual([{ method: 'get', url: '/integrasi/log', params: { page: 1, per_page: 25 }, body: null }])
  })

  it('list() meneruskan semua filter terisi', async () => {
    server.balasan = { status: 200, body: { status: 'success', data: { ...daftar, items: [] } } }

    await logIntegrasiService.list({ layanan: 'bkn', status: '2', tanggal_mulai: '2026-11-01', tanggal_selesai: '2026-11-09', page: 3, per_page: 25 })
    expect(server.panggilan[0]?.params).toEqual({ page: 3, per_page: 25, layanan: 'bkn', status: '2', tanggal_mulai: '2026-11-01', tanggal_selesai: '2026-11-09' })
  })

  it('tarikEkinerja() POST /integrasi/bkn/tarik, 202 → data hasil antre', async () => {
    const hasil = { jenis: 'skp_periodik', tahun: 2026, bulan: 10, antre: true }
    server.balasan = { status: 202, body: { status: 'success', data: hasil } }

    await expect(logIntegrasiService.tarikEkinerja({ jenis: 'skp_periodik', tahun: 2026, bulan: 10 })).resolves.toEqual(hasil)
    expect(server.panggilan).toEqual([
      { method: 'post', url: '/integrasi/bkn/tarik', params: null, body: { jenis: 'skp_periodik', tahun: 2026, bulan: 10 } },
    ])
  })

  it('422 diteruskan sebagai ApiError dengan errors per parameter', async () => {
    server.balasan = { status: 422, body: { status: 'error', message: 'Parameter daftar tidak valid.', errors: { status: ['Status harus 1 (sukses) atau 2 (gagal).'] } } }

    await expect(logIntegrasiService.list(kosong)).rejects.toMatchObject({ status: 422, errors: { status: ['Status harus 1 (sukses) atau 2 (gagal).'] } })
  })

  it('403 (bukan Super Admin) diteruskan sebagai ApiError', async () => {
    server.balasan = { status: 403, body: { status: 'error', message: 'Akses ditolak.' } }

    await expect(logIntegrasiService.list(kosong)).rejects.toMatchObject({ status: 403, message: 'Akses ditolak.' })
  })
})
