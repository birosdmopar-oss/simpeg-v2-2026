/**
 * MAKE-087 — berandaService dengan KLIEN ASLI (`createApiClient` + interceptor amplop) dan respons berbentuk backend nyata
 * `{status:'success', data:{...}}` (pelajaran amplop CR-059: hanya adapter jaringan yang palsu).
 */
import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const server = vi.hoisted(() => ({
  balasan: { status: 200, body: {} as unknown },
  panggilan: [] as { method: string; url: string }[],
}))

vi.mock('@/lib/axios', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/axios')>()
  const adapter = async (config: InternalAxiosRequestConfig): Promise<AxiosResponse> => {
    server.panggilan.push({ method: String(config.method), url: config.url ?? '' })
    const response: AxiosResponse = { status: server.balasan.status, statusText: 'x', headers: {}, config, data: server.balasan.body }
    if (server.balasan.status >= 400) throw new AxiosError('gagal', 'ERR_BAD_REQUEST', config, null, response)
    return response
  }
  return { ...actual, api: actual.createApiClient({ adapter }) }
})

import { berandaService } from '../services/beranda.service'

beforeEach(() => {
  server.panggilan = []
})

describe('berandaService — amplop backend nyata', () => {
  it('ringkasan() GET /dashboard/ringkasan → isi data (bukan amplop)', async () => {
    const data = { keluarga: 'pegawai' }
    server.balasan = { status: 200, body: { status: 'success', data } }

    await expect(berandaService.ringkasan()).resolves.toEqual(data)
    expect(server.panggilan).toEqual([{ method: 'get', url: '/dashboard/ringkasan' }])
  })

  it('galat diteruskan sebagai ApiError', async () => {
    server.balasan = { status: 500, body: { status: 'error', message: 'Terjadi kesalahan.' } }

    await expect(berandaService.ringkasan()).rejects.toMatchObject({ status: 500, message: 'Terjadi kesalahan.' })
  })
})
