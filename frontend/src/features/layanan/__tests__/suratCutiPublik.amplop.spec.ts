/**
 * MAKE-065 (review B4a P6) — suratCutiPublikService dengan KLIEN ASLI (`createApiClient` + interceptor `unwrapEnvelope`)
 * dan respons berbentuk backend nyata `{status:'success', data:{...}}`. Spec halaman me-mock `api.get` langsung, jadi
 * amplop salah (`res.data.data`) hanya tertangkap di sini (pelajaran CR-059). Juga 404 seragam dan 429 throttle.
 */
import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const server = vi.hoisted(() => ({
  balasan: {} as Record<string, { status: number; body: unknown; headers?: Record<string, string> }>,
  permintaan: [] as { method: string; url: string; headers: unknown }[],
}))

vi.mock('@/lib/axios', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/axios')>()
  const adapter = async (config: InternalAxiosRequestConfig): Promise<AxiosResponse> => {
    const url = config.url ?? ''
    const method = (config.method ?? 'get').toLowerCase()
    server.permintaan.push({ method, url, headers: config.headers })
    const balasan = server.balasan[`${method} ${url}`]
    if (!balasan) throw new Error(`tidak ada balasan palsu untuk ${method} ${url}`)
    const response: AxiosResponse = { status: balasan.status, statusText: 'x', headers: balasan.headers ?? {}, config, data: balasan.body }
    if (balasan.status >= 400) throw new AxiosError('gagal', 'ERR_BAD_REQUEST', config, null, response)
    return response
  }
  return { ...actual, api: actual.createApiClient({ adapter }) }
})

import { suratCutiPublikService } from '../services/suratCutiPublik.service'

const TOKEN = 'ab'.repeat(32)
const URL = `/publik/surat-cuti/${TOKEN}`

beforeEach(() => {
  server.balasan = {}
  server.permintaan = []
})

describe('suratCutiPublikService — amplop backend nyata lewat klien asli', () => {
  it('sukses: amplop dibuka, data minimal dinormalkan', async () => {
    server.balasan[`get ${URL}`] = {
      status: 200,
      body: {
        status: 'success',
        data: {
          status_keaslian: 'asli',
          jenis_surat: 'Surat Cuti',
          jenis_cuti: 'Cuti Tahunan',
          penanda_tangan: 'Dr. Pejabat Uji',
          tanggal_tanda_tangan: '2026-11-04 10:00 WIB',
          status_tte: 'ditandatangani',
        },
      },
    }

    await expect(suratCutiPublikService.verifikasi(TOKEN)).resolves.toEqual({
      status_keaslian: 'asli',
      jenis_surat: 'Surat Cuti',
      jenis_cuti: 'Cuti Tahunan',
      penanda_tangan: 'Dr. Pejabat Uji',
      tanggal_tanda_tangan: '2026-11-04 10:00 WIB',
      status_tte: 'ditandatangani',
    })
    expect(server.permintaan).toHaveLength(1)
    expect(server.permintaan[0]).toMatchObject({ method: 'get', url: URL })
  })

  it('belum ber-TTE: nilai kosong → null, status "belum"', async () => {
    server.balasan[`get ${URL}`] = {
      status: 200,
      body: { status: 'success', data: { jenis_surat: 'Surat Cuti', jenis_cuti: '', penanda_tangan: 'Pejabat Uji', tanggal_tanda_tangan: null, status_tte: 'belum' } },
    }

    const hasil = await suratCutiPublikService.verifikasi(TOKEN)

    expect(hasil.status_tte).toBe('belum')
    expect(hasil.jenis_cuti).toBeNull()
    expect(hasil.tanggal_tanda_tangan).toBeNull()
  })

  it('404 seragam dan 429 throttle diteruskan sebagai ApiError dengan pesan server', async () => {
    server.balasan[`get ${URL}`] = { status: 404, body: { status: 'error', message: 'Dokumen tidak ditemukan atau tidak valid.' } }
    await expect(suratCutiPublikService.verifikasi(TOKEN)).rejects.toMatchObject({ status: 404, message: 'Dokumen tidak ditemukan atau tidak valid.' })

    server.balasan[`get ${URL}`] = { status: 429, body: { status: 'error', message: 'Terlalu banyak permintaan.' }, headers: { 'retry-after': '60' } }
    await expect(suratCutiPublikService.verifikasi(TOKEN)).rejects.toMatchObject({ status: 429, message: 'Terlalu banyak permintaan.' })
  })

  it('token di-encode di path (karakter khusus tidak memecah URL)', async () => {
    server.balasan['get /publik/surat-cuti/a%2Fb%3Fc'] = { status: 404, body: { status: 'error', message: 'Dokumen tidak ditemukan atau tidak valid.' } }
    await expect(suratCutiPublikService.verifikasi('a/b?c')).rejects.toMatchObject({ status: 404 })
  })
})
