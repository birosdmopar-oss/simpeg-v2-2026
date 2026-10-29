/**
 * CR-078 (T-05) — unduhan biner dengan KLIEN ASLI (`createApiClient` + interceptor), body galat JSON datang sebagai
 * Blob karena `responseType: 'blob'` (seperti server sungguhan). Adapter jaringan saja yang palsu (pelajaran CR-059).
 */
import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const server = vi.hoisted(() => ({
  balasan: {} as Record<string, { status: number; body: unknown; tipe?: string; headers?: Record<string, string> }>,
}))

vi.mock('@/lib/axios', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/axios')>()
  const adapter = async (config: InternalAxiosRequestConfig): Promise<AxiosResponse> => {
    const method = (config.method ?? 'get').toLowerCase()
    const balasan = server.balasan[`${method} ${config.url ?? ''}`]
    if (!balasan) throw new Error(`tidak ada balasan palsu untuk ${method} ${config.url}`)
    // Biner (responseType blob): body selalu Blob, tipe dari Content-Type balasan.
    const data = balasan.tipe ? new Blob([JSON.stringify(balasan.body)], { type: balasan.tipe }) : balasan.body
    const response: AxiosResponse = { status: balasan.status, statusText: 'x', headers: balasan.headers ?? {}, config, data }
    if (balasan.status >= 400) throw new AxiosError('gagal', 'ERR_BAD_REQUEST', config, null, response)
    return response
  }
  return { ...actual, api: actual.createApiClient({ adapter }) }
})

import { isApiError } from '@/lib/axios'
import { ambilBerkas, kirimBerkas } from '../services/berkasUnduhan'
import { pesanGalatRekap, rekapTukinService } from '@/features/presensi/services/rekapTukin.service'

const JSON_TIPE = 'application/json'
const XLSX_TIPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
const galatJson = (status: number, body: unknown) => ({ status, body, tipe: JSON_TIPE })

beforeEach(() => {
  server.balasan = {}
})

describe('ambilBerkas — galat JSON ber-tipe blob diurai ulang', () => {
  it('422 errors.unit_satker: message dan errors server terbawa, bukan pesan axios', async () => {
    server.balasan['get /presensi/tukin/rekap/pdf'] = galatJson(422, {
      status: 'error',
      message: 'Lingkup terlalu besar untuk PDF.',
      errors: { unit_satker: ['Persempit lingkup atau unduh XLSX.'] },
    })

    const galat = await ambilBerkas('/presensi/tukin/rekap/pdf', 'laporan', {}).catch((e: unknown) => e)

    expect(isApiError(galat)).toBe(true)
    expect(galat).toMatchObject({ status: 422, message: 'Lingkup terlalu besar untuk PDF.', errors: { unit_satker: ['Persempit lingkup atau unduh XLSX.'] } })
    expect(galat).toHaveProperty('original.response.data.status', 'error')
  })

  it('422 dari rekap Tukin PDF: pesanGalatRekap menampilkan pesan server, bukan "Periksa kembali filter"', async () => {
    server.balasan['get /presensi/tukin/rekap/pdf'] = galatJson(422, { status: 'error', message: 'x', errors: { unit_satker: ['Persempit lingkup atau unduh XLSX.'] } })

    const galat = await rekapTukinService.ekspor('pdf', { bulan: '2026-10', unit_satker: '0', page: 1, per_page: 25 }).catch((e: unknown) => e)

    expect(pesanGalatRekap(galat)).toBe('Persempit lingkup atau unduh XLSX.')
  })

  it('code konfigurasi_tukin_tidak_lengkap tetap terbaca dari body yang sudah diurai', async () => {
    server.balasan['get /presensi/tukin/rekap/pdf'] = galatJson(422, { status: 'error', message: 'x', code: 'konfigurasi_tukin_tidak_lengkap' })

    const galat = await rekapTukinService.ekspor('pdf', { bulan: '2026-10', unit_satker: '0', page: 1, per_page: 25 }).catch((e: unknown) => e)

    expect(pesanGalatRekap(galat)).toBe('Konfigurasi tarif Tukin belum lengkap. Hubungi admin.')
  })

  it('code data_tukin_tidak_sah (403) dari body blob: status dan code tetap terbawa', async () => {
    server.balasan['get /presensi/tukin/rekap/pdf'] = galatJson(403, { status: 'error', message: 'Akses ditolak.', code: 'data_tukin_tidak_sah' })

    const galat = await ambilBerkas('/presensi/tukin/rekap/pdf', 'laporan', {}).catch((e: unknown) => e)

    expect(galat).toMatchObject({ status: 403, message: 'Akses ditolak.' })
    expect(galat).toHaveProperty('original.response.data.code', 'data_tukin_tidak_sah')
  })

  it('body galat bukan JSON (tipe biner) dibiarkan apa adanya tanpa melempar galat baru', async () => {
    server.balasan['get /presensi/tukin/rekap/pdf'] = { status: 500, body: 'rusak', tipe: 'application/pdf' }

    const galat = await ambilBerkas('/presensi/tukin/rekap/pdf', 'laporan', {}).catch((e: unknown) => e)

    expect(isApiError(galat)).toBe(true)
    expect(galat).toMatchObject({ status: 500 })
  })

  it('unduhan sukses tetap mengembalikan blob dan nama dari Content-Disposition', async () => {
    server.balasan['get /presensi/tukin/rekap/pdf'] = {
      status: 200,
      body: 'x',
      tipe: XLSX_TIPE,
      headers: { 'content-disposition': 'attachment; filename="laporan_tukin_0_2026-10.xlsx"' },
    }

    const berkas = await ambilBerkas('/presensi/tukin/rekap/pdf', 'laporan', {})

    expect(berkas.nama).toBe('laporan_tukin_0_2026-10.xlsx')
    expect(berkas.blob).toBeInstanceOf(Blob)
  })
})

describe('kirimBerkas — galat JSON ber-tipe blob diurai ulang', () => {
  it('422 errors dari POST: message dan errors server terbawa', async () => {
    server.balasan['post /pegawai/pencarian/export'] = galatJson(422, {
      status: 'error',
      message: 'Kriteria tidak valid.',
      errors: { kriteria: ['Pilih minimal satu kriteria.'] },
    })

    const galat = await kirimBerkas('/pegawai/pencarian/export', {}, 'pencarian').catch((e: unknown) => e)

    expect(galat).toMatchObject({ status: 422, message: 'Kriteria tidak valid.', errors: { kriteria: ['Pilih minimal satu kriteria.'] } })
  })

  it('403 dari POST: status tetap 403', async () => {
    server.balasan['post /pegawai/pencarian/export'] = galatJson(403, { status: 'error', message: 'Akses ditolak.' })

    const galat = await kirimBerkas('/pegawai/pencarian/export', {}, 'pencarian').catch((e: unknown) => e)

    expect(galat).toMatchObject({ status: 403, message: 'Akses ditolak.' })
  })
})
