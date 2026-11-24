/**
 * CR-186 (F10-L3): unduhan `responseType: 'blob'` yang dijawab 403 `password_change_required` dengan body Blob JSON
 * memicu handler wajib-ganti-password dan memakai pesan server; 403 bersamaan selama handler berjalan berbagi satu
 * pemanggilan handler. Diuji dengan
 * KLIEN ASLI (`createApiClient`) + adapter palsu yang meniru XHR blob: body galat berupa Blob ber-tipe JSON.
 */
import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { pesanGalatUnduhan } from '@/features/dokumen/dokumen.service'

import { createApiClient, isApiError, PASSWORD_CHANGE_REQUIRED_CODE, REFRESH_PATH, setAuthFailureHandler, setPasswordChangeRequiredHandler } from '../axios'

type Balasan = { status: number; body: unknown; tipe?: string }

const jsonBlob = (body: unknown, tipe = 'application/json; charset=UTF-8') => new Blob([JSON.stringify(body)], { type: tipe })

function klien(balasan: Record<string, Balasan[]>) {
  const panggilan: string[] = []
  const adapter = async (config: InternalAxiosRequestConfig): Promise<AxiosResponse> => {
    const url = config.url ?? ''
    panggilan.push(url)
    const b = balasan[url]?.shift() ?? { status: 200, body: { status: 'success', data: { ok: true } } }
    // Seperti XHR asli: `responseType: 'blob'` → body (sukses maupun galat) berupa Blob.
    const data = config.responseType === 'blob' && !(b.body instanceof Blob) ? jsonBlob(b.body, b.tipe) : b.body
    const response: AxiosResponse = { status: b.status, statusText: 'x', headers: {}, config, data }
    if (b.status >= 400) throw new AxiosError(`Request failed with status code ${b.status}`, 'ERR_BAD_REQUEST', config, null, response)
    return response
  }
  return { api: createApiClient({ adapter }), panggilan }
}

const PASSWORD_403: Balasan = { status: 403, body: { status: 'error', code: PASSWORD_CHANGE_REQUIRED_CODE, message: 'Password wajib diganti sebelum melanjutkan.' } }

describe('interceptor: body galat Blob (CR-186)', () => {
  const handler = vi.fn()
  const onAuthFailure = vi.fn()

  beforeEach(() => {
    handler.mockReset()
    onAuthFailure.mockReset()
    setPasswordChangeRequiredHandler(handler)
    setAuthFailureHandler(onAuthFailure)
  })
  afterEach(() => {
    setPasswordChangeRequiredHandler(null)
    setAuthFailureHandler(null)
  })

  it('GET blob 403 Blob JSON password_change_required → handler sekali, pesan server, body terurai', async () => {
    const { api } = klien({ '/berkas': [{ ...PASSWORD_403 }] })
    const galat = await api.get('/berkas', { responseType: 'blob' }).catch((e: unknown) => e)

    expect(handler).toHaveBeenCalledTimes(1)
    expect(isApiError(galat) && galat.status).toBe(403)
    expect(isApiError(galat) && galat.message).toBe('Password wajib diganti sebelum melanjutkan.')
    expect(isApiError(galat) && galat.original.response?.data).toMatchObject({ code: PASSWORD_CHANGE_REQUIRED_CODE })
  })

  it('blob 403 + JSON 403 bersamaan → handler sekali (bersama selama handler berjalan)', async () => {
    const { api } = klien({ '/berkas': [{ ...PASSWORD_403 }], '/master': [{ ...PASSWORD_403 }] })
    await Promise.allSettled([api.get('/berkas', { responseType: 'blob' }), api.get('/master')])
    expect(handler).toHaveBeenCalledTimes(1)
  })

  it('dua blob 403 bersamaan + handler async (deferred) → handler SEKALI selama berjalan; kedua request menunggu handler', async () => {
    let selesai!: () => void
    handler.mockImplementation(() => new Promise<void>((r) => (selesai = r)))
    const { api } = klien({ '/berkas-a': [{ ...PASSWORD_403 }], '/berkas-b': [{ ...PASSWORD_403 }] })
    const status = { a: 'pending', b: 'pending' }
    const a = api.get('/berkas-a', { responseType: 'blob' }).catch(() => (status.a = 'ditolak'))
    const b = api.get('/berkas-b', { responseType: 'blob' }).catch(() => (status.b = 'ditolak'))

    await vi.waitFor(() => expect(handler).toHaveBeenCalledTimes(1))
    await new Promise((r) => setTimeout(r, 10))
    expect(handler).toHaveBeenCalledTimes(1)
    expect(status).toEqual({ a: 'pending', b: 'pending' })

    selesai()
    await Promise.all([a, b])
    expect(status).toEqual({ a: 'ditolak', b: 'ditolak' })
    expect(handler).toHaveBeenCalledTimes(1)
  })

  it('403 blob tanpa code → handler tidak terpanggil, pesan server tetap dipakai', async () => {
    const { api } = klien({ '/berkas': [{ status: 403, body: { status: 'error', message: 'Anda tidak berhak mengakses data ini.' } }] })
    const galat = await api.get('/berkas', { responseType: 'blob' }).catch((e: unknown) => e)
    expect(handler).not.toHaveBeenCalled()
    expect(isApiError(galat) && galat.message).toBe('Anda tidak berhak mengakses data ini.')
  })

  it('403 Blob non-JSON tidak dibaca: tetap Blob, tanpa handler', async () => {
    const html = new Blob(['<html>403</html>'], { type: 'text/html' })
    const teks = vi.spyOn(html, 'text')
    const { api } = klien({ '/berkas': [{ status: 403, body: html }] })
    const galat = await api.get('/berkas', { responseType: 'blob' }).catch((e: unknown) => e)
    expect(handler).not.toHaveBeenCalled()
    expect(teks).not.toHaveBeenCalled()
    expect(isApiError(galat) && galat.original.response?.data).toBeInstanceOf(Blob)
  })

  it('403 Blob ber-tipe JSON tapi rusak → tetap ditolak biasa, tanpa handler', async () => {
    const { api } = klien({ '/berkas': [{ status: 403, body: new Blob(['{rusak'], { type: 'application/json' }) }] })
    const galat = await api.get('/berkas', { responseType: 'blob' }).catch((e: unknown) => e)
    expect(handler).not.toHaveBeenCalled()
    expect(isApiError(galat) && galat.status).toBe(403)
  })

  it('401 blob → alur refresh lalu retry tetap berjalan (berkas sukses)', async () => {
    const berkas = new Blob(['%PDF'], { type: 'application/pdf' })
    const { api, panggilan } = klien({ '/berkas': [{ status: 401, body: { status: 'error', message: 'Unauthorized' } }, { status: 200, body: berkas }] })
    const res = await api.get<Blob>('/berkas', { responseType: 'blob' })
    expect(panggilan).toEqual(['/berkas', REFRESH_PATH, '/berkas'])
    expect(res.data).toBe(berkas)
    expect(handler).not.toHaveBeenCalled()
    expect(onAuthFailure).not.toHaveBeenCalled()
  })

  it('unduhan sukses tidak berubah: Blob 2xx (bahkan ber-tipe JSON) tidak dibaca', async () => {
    const berkas = jsonBlob({ status: 'error', code: PASSWORD_CHANGE_REQUIRED_CODE })
    const teks = vi.spyOn(berkas, 'text')
    const { api } = klien({ '/berkas': [{ status: 200, body: berkas }] })
    const res = await api.get<Blob>('/berkas', { responseType: 'blob' })
    expect(res.data).toBe(berkas)
    expect(teks).not.toHaveBeenCalled()
    expect(handler).not.toHaveBeenCalled()
  })

  it('422 blob (dokumen.service) tetap memberi pesan per kolom `errors`', async () => {
    const { api } = klien({ '/dokumen': [{ status: 422, body: { status: 'error', message: 'Validasi gagal', errors: { lingkup: ['PDF hanya tersedia per unit/satker.'] } } }] })
    const galat = await api.get('/dokumen', { responseType: 'blob' }).catch((e: unknown) => e)
    expect(handler).not.toHaveBeenCalled()
    expect(await pesanGalatUnduhan(galat)).toBe('PDF hanya tersedia per unit/satker.')
  })
})
