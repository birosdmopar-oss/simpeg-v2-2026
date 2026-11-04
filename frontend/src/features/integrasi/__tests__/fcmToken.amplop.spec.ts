/**
 * MAKE-066 (F6-13) — fcmTokenService dengan KLIEN ASLI (`createApiClient` + interceptor amplop) dan respons berbentuk
 * backend nyata `{status:'success', data:{...}}` (pelajaran amplop CR-059: hanya adapter jaringan yang palsu).
 */
import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const server = vi.hoisted(() => ({
  balasan: { status: 200, body: {} as unknown },
  panggilan: [] as { method: string; url: string; body: unknown }[],
}))

vi.mock('@/lib/axios', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/axios')>()
  const adapter = async (config: InternalAxiosRequestConfig): Promise<AxiosResponse> => {
    server.panggilan.push({ method: String(config.method), url: config.url ?? '', body: config.data ? JSON.parse(String(config.data)) : null })
    const response: AxiosResponse = { status: server.balasan.status, statusText: 'x', headers: {}, config, data: server.balasan.body }
    if (server.balasan.status >= 400) throw new AxiosError('gagal', 'ERR_BAD_REQUEST', config, null, response)
    return response
  }
  return { ...actual, api: actual.createApiClient({ adapter }) }
})

import { fcmTokenService } from '../services/fcmToken.service'

beforeEach(() => {
  server.panggilan = []
})

describe('fcmTokenService — amplop backend nyata', () => {
  it('daftar() mengirim {fcm_token} dan mengembalikan data.status', async () => {
    server.balasan = { status: 200, body: { status: 'success', data: { status: 'baru' } } }

    await expect(fcmTokenService.daftar('tok-uji')).resolves.toBe('baru')
    expect(server.panggilan).toEqual([{ method: 'post', url: '/integrasi/fcm-token', body: { fcm_token: 'tok-uji' } }])
  })

  it('lepas() memakai DELETE dengan body dan mengembalikan data.dihapus', async () => {
    server.balasan = { status: 200, body: { status: 'success', data: { dihapus: 1 } } }

    await expect(fcmTokenService.lepas('tok-uji')).resolves.toBe(1)
    expect(server.panggilan).toEqual([{ method: 'delete', url: '/integrasi/fcm-token', body: { fcm_token: 'tok-uji' } }])
  })

  it('422 diteruskan sebagai ApiError dengan errors.fcm_token', async () => {
    server.balasan = { status: 422, body: { status: 'error', message: 'Validasi gagal.', errors: { fcm_token: ['Token notifikasi tidak valid.'] } } }

    await expect(fcmTokenService.daftar(' ')).rejects.toMatchObject({ status: 422, errors: { fcm_token: ['Token notifikasi tidak valid.'] } })
  })
})
