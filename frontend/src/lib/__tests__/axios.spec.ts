/**
 * F0-09 — verifikasi 3 skenario DoD:
 *  1. semua request otomatis include cookie kredensial (withCredentials)
 *  2. 401 memicu refresh-lalu-retry SEKALI (bukan infinite loop)
 *  3. refresh gagal → handler auth-failure (redirect login) terpanggil
 * Ditambah: unwrap envelope, normalisasi error, dan satu refresh bersama untuk 401 paralel.
 */
import { AxiosError, type AxiosRequestConfig, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  createApiClient,
  isApiError,
  PASSWORD_CHANGE_REQUIRED_CODE,
  REFRESH_PATH,
  setAuthFailureHandler,
  setPasswordChangeRequiredHandler,
} from '../axios'

type Call = { method: string; url: string; withCredentials: boolean | undefined }

interface FakeServer {
  calls: Call[]
  /** antrian status per URL (FIFO); default 200 */
  queue: Record<string, number[]>
  /** URL yang jawaban 403-nya membawa code `password_change_required` (JwtAuthFilter, MAKE-020) */
  passwordRequired: Set<string>
  adapter: NonNullable<AxiosRequestConfig['adapter']>
}

function makeServer(queue: Record<string, number[]> = {}): FakeServer {
  const server: FakeServer = {
    calls: [],
    queue,
    passwordRequired: new Set<string>(),
    adapter: async (config: InternalAxiosRequestConfig): Promise<AxiosResponse> => {
      const url = config.url ?? ''
      const method = (config.method ?? 'get').toUpperCase()
      server.calls.push({ method, url, withCredentials: config.withCredentials })

      const next = server.queue[url]?.shift() ?? 200

      if (next >= 400) {
        const response: AxiosResponse = {
          status: next,
          statusText: 'ERR',
          headers: {},
          config,
          data:
            next === 422
              ? { status: 'error', message: 'Validasi gagal', errors: { nip: ['NIP wajib diisi'] } }
              : next === 403 && server.passwordRequired.has(url)
                ? { status: 'error', code: PASSWORD_CHANGE_REQUIRED_CODE, message: 'Password wajib diganti sebelum melanjutkan.' }
                : { status: 'error', message: next === 401 ? 'Unauthorized' : 'Forbidden' },
        }
        throw new AxiosError(`Request failed with status code ${next}`, 'ERR_BAD_REQUEST', config, null, response)
      }

      return {
        status: 200,
        statusText: 'OK',
        headers: {},
        config,
        data: { status: 'success', data: { url, ok: true } },
      }
    },
  }
  return server
}

describe('src/lib/axios (F0-09)', () => {
  const onAuthFailure = vi.fn()

  beforeEach(() => {
    onAuthFailure.mockReset()
    setAuthFailureHandler(onAuthFailure)
  })

  afterEach(() => {
    setAuthFailureHandler(null)
  })

  it('mengirim cookie kredensial di setiap request (withCredentials: true)', async () => {
    const server = makeServer()
    const api = createApiClient({ adapter: server.adapter, baseURL: 'http://api.test/api/v1' })

    expect(api.defaults.withCredentials).toBe(true)

    await api.get('/pegawai')
    await api.post('/cuti', { x: 1 })

    expect(server.calls).toHaveLength(2)
    expect(server.calls.every((c) => c.withCredentials === true)).toBe(true)
  })

  it('meng-unwrap envelope {status, data} menjadi response.data', async () => {
    const server = makeServer()
    const api = createApiClient({ adapter: server.adapter })

    const res = await api.get<{ url: string; ok: boolean }>('/health')

    expect(res.data).toEqual({ url: '/health', ok: true })
  })

  it('401 → refresh → retry request sekali, lalu sukses', async () => {
    const server = makeServer({ '/pegawai': [401] })
    const api = createApiClient({ adapter: server.adapter })

    const res = await api.get('/pegawai')

    expect(res.status).toBe(200)
    expect(server.calls.map((c) => `${c.method} ${c.url}`)).toEqual([
      'GET /pegawai',
      `POST ${REFRESH_PATH}`,
      'GET /pegawai',
    ])
    expect(onAuthFailure).not.toHaveBeenCalled()
  })

  it('refresh gagal → request ditolak dan handler auth-failure (redirect login) terpanggil', async () => {
    const server = makeServer({ '/pegawai': [401], [REFRESH_PATH]: [401] })
    const api = createApiClient({ adapter: server.adapter })

    await expect(api.get('/pegawai')).rejects.toMatchObject({ status: 401, isNetworkError: false })

    expect(server.calls.map((c) => `${c.method} ${c.url}`)).toEqual(['GET /pegawai', `POST ${REFRESH_PATH}`])
    expect(onAuthFailure).toHaveBeenCalledTimes(1)
  })

  it('retry setelah refresh tetap 401 → berhenti (tidak infinite loop) dan handler terpanggil', async () => {
    const server = makeServer({ '/pegawai': [401, 401, 401, 401] })
    const api = createApiClient({ adapter: server.adapter })

    await expect(api.get('/pegawai')).rejects.toMatchObject({ status: 401 })

    // Tepat 3 hit: request awal, refresh, retry. Tidak ada refresh/retry kedua.
    expect(server.calls.map((c) => `${c.method} ${c.url}`)).toEqual([
      'GET /pegawai',
      `POST ${REFRESH_PATH}`,
      'GET /pegawai',
    ])
    expect(onAuthFailure).toHaveBeenCalledTimes(1)
  })

  it('beberapa 401 bersamaan hanya memicu satu refresh', async () => {
    const server = makeServer({ '/a': [401], '/b': [401], '/c': [401] })
    const api = createApiClient({ adapter: server.adapter })

    await Promise.all([api.get('/a'), api.get('/b'), api.get('/c')])

    const refreshCalls = server.calls.filter((c) => c.url === REFRESH_PATH)
    expect(refreshCalls).toHaveLength(1)
    expect(server.calls.filter((c) => c.url === '/a')).toHaveLength(2)
    expect(server.calls.filter((c) => c.url === '/b')).toHaveLength(2)
    expect(server.calls.filter((c) => c.url === '/c')).toHaveLength(2)
  })

  it('menormalkan error non-401 menjadi {status, message, errors} tanpa refresh', async () => {
    const server = makeServer({ '/cuti': [422] })
    const api = createApiClient({ adapter: server.adapter })

    try {
      await api.post('/cuti', {})
      expect.unreachable('harus reject')
    } catch (err) {
      expect(isApiError(err)).toBe(true)
      if (isApiError(err)) {
        expect(err.status).toBe(422)
        expect(err.message).toBe('Validasi gagal')
        expect(err.errors).toEqual({ nip: ['NIP wajib diisi'] })
      }
    }

    expect(server.calls).toHaveLength(1)
    expect(onAuthFailure).not.toHaveBeenCalled()
  })

  it('403 tidak memicu refresh maupun redirect login', async () => {
    const server = makeServer({ '/master': [403] })
    const api = createApiClient({ adapter: server.adapter })

    await expect(api.get('/master')).rejects.toMatchObject({ status: 403, message: 'Forbidden' })
    expect(server.calls).toHaveLength(1)
    expect(onAuthFailure).not.toHaveBeenCalled()
  })

  describe('403 password_change_required (MAKE-020/028)', () => {
    const onPasswordChange = vi.fn()

    beforeEach(() => {
      onPasswordChange.mockReset().mockResolvedValue(undefined)
      setPasswordChangeRequiredHandler(onPasswordChange)
    })

    afterEach(() => {
      setPasswordChangeRequiredHandler(null)
    })

    it('menjalankan handler SEKALI lalu tetap menolak request dengan error 403 yang sama', async () => {
      const server = makeServer({ '/master': [403] })
      server.passwordRequired.add('/master')
      const api = createApiClient({ adapter: server.adapter })

      await expect(api.get('/master')).rejects.toMatchObject({ status: 403, message: 'Password wajib diganti sebelum melanjutkan.' })

      expect(onPasswordChange).toHaveBeenCalledTimes(1)
      expect(server.calls).toHaveLength(1) // tanpa retry, tanpa refresh, tanpa permintaan tambahan dari interceptor
      expect(onAuthFailure).not.toHaveBeenCalled()
    })

    it('banyak 403 bersamaan hanya menjalankan handler satu kali', async () => {
      const server = makeServer({ '/a': [403], '/b': [403], '/c': [403] })
      for (const url of ['/a', '/b', '/c']) server.passwordRequired.add(url)
      const api = createApiClient({ adapter: server.adapter })

      const results = await Promise.allSettled([api.get('/a'), api.get('/b'), api.get('/c')])

      expect(results.every((r) => r.status === 'rejected')).toBe(true)
      expect(onPasswordChange).toHaveBeenCalledTimes(1)
      expect(server.calls).toHaveLength(3)
    })

    it('403 berikutnya setelah handler selesai boleh memicu lagi (bukan terkunci selamanya)', async () => {
      const server = makeServer({ '/master': [403, 403] })
      server.passwordRequired.add('/master')
      const api = createApiClient({ adapter: server.adapter })

      await expect(api.get('/master')).rejects.toMatchObject({ status: 403 })
      await expect(api.get('/master')).rejects.toMatchObject({ status: 403 })

      expect(onPasswordChange).toHaveBeenCalledTimes(2)
    })

    it('403 biasa (tanpa code) tetap perilaku lama: tanpa handler, refresh, atau redirect', async () => {
      const server = makeServer({ '/master': [403] })
      const api = createApiClient({ adapter: server.adapter })

      await expect(api.get('/master')).rejects.toMatchObject({ status: 403, message: 'Forbidden' })

      expect(onPasswordChange).not.toHaveBeenCalled()
      expect(server.calls).toHaveLength(1)
      expect(onAuthFailure).not.toHaveBeenCalled()
    })

    it('endpoint yang memang terbuka (auth/me, change-password, logout) tidak memicu handler', async () => {
      const open = ['/auth/me', '/auth/change-password', '/auth/logout', '/auth/me?x=1']
      const server = makeServer(Object.fromEntries(open.map((url) => [url, [403]])))
      for (const url of open) server.passwordRequired.add(url)
      const api = createApiClient({ adapter: server.adapter })

      for (const url of open) {
        await expect(api.get(url)).rejects.toMatchObject({ status: 403 })
      }

      expect(onPasswordChange).not.toHaveBeenCalled()
    })

    it('handler yang gagal tidak menutupi error 403 asli dan tidak membuat loop', async () => {
      onPasswordChange.mockRejectedValue(new Error('me gagal'))
      const server = makeServer({ '/master': [403] })
      server.passwordRequired.add('/master')
      const api = createApiClient({ adapter: server.adapter })

      await expect(api.get('/master')).rejects.toMatchObject({ status: 403 })

      expect(onPasswordChange).toHaveBeenCalledTimes(1)
      expect(server.calls).toHaveLength(1)
    })

    it('setelah auto-refresh, request yang diulang dan kena 403 ini juga memicu handler sekali', async () => {
      // Token baru hasil refresh membawa must_change_password (password kedaluwarsa): retry -> 403 + code.
      const server = makeServer({ '/data': [401, 403], [REFRESH_PATH]: [200] })
      server.passwordRequired.add('/data')
      const api = createApiClient({ adapter: server.adapter })

      await expect(api.get('/data')).rejects.toMatchObject({ status: 403 })

      expect(server.calls.map((c) => c.url)).toEqual(['/data', REFRESH_PATH, '/data'])
      expect(onPasswordChange).toHaveBeenCalledTimes(1)
      expect(onAuthFailure).not.toHaveBeenCalled()
    })

    it('tanpa handler terpasang, 403 password_change_required hanya ditolak biasa', async () => {
      setPasswordChangeRequiredHandler(null)
      const server = makeServer({ '/master': [403] })
      server.passwordRequired.add('/master')
      const api = createApiClient({ adapter: server.adapter })

      await expect(api.get('/master')).rejects.toMatchObject({ status: 403 })
      expect(onPasswordChange).not.toHaveBeenCalled()
    })
  })
})
