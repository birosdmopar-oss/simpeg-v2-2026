/**
 * MAKE-049 — service Rekap Tukin dengan KLIEN ASLI (`createApiClient` + interceptor `unwrapEnvelope`, amplop backend
 * nyata, string MySQLi, baris galat, 422 `unit_satker`); hanya adapter jaringan yang palsu (pelajaran CR-059).
 */
import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const server = vi.hoisted(() => ({
  balasan: {} as Record<string, { status: number; body: unknown; headers?: Record<string, string> }>,
  permintaan: [] as { method: string; url: string; params: unknown }[],
}))

vi.mock('@/lib/axios', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/axios')>()
  const adapter = async (config: InternalAxiosRequestConfig): Promise<AxiosResponse> => {
    const url = config.url ?? ''
    const method = (config.method ?? 'get').toLowerCase()
    server.permintaan.push({ method, url, params: config.params })
    const balasan = server.balasan[`${method} ${url}`]
    if (!balasan) throw new Error(`tidak ada balasan palsu untuk ${method} ${url}`)
    const response: AxiosResponse = { status: balasan.status, statusText: 'x', headers: balasan.headers ?? {}, config, data: balasan.body }
    if (balasan.status >= 400) throw new AxiosError('gagal', 'ERR_BAD_REQUEST', config, null, response)
    return response
  }
  return { ...actual, api: actual.createApiClient({ adapter }) }
})

import { pesanGalatRekap, rekapTukinService } from '../services/rekapTukin.service'

const sukses = (data: unknown) => ({ status: 200, body: { status: 'success', data } })
const galat = (status: number, code?: string, errors?: Record<string, string[]>) => ({
  status,
  body: { status: 'error', message: 'x', ...(code ? { code } : {}), ...(errors ? { errors } : {}) },
})

const baris = (nip: string, ekstra: Record<string, unknown> = {}) => ({
  nip, nama: 'Budi Santoso', unit_es1: 'Biro Uji', unit_es2: 'Satker Uji',
  pengurangan: { TL: '0.2', PSW: '0', TK: '1', TA: '0', CUTI: '0', TB: '0' },
  total_presensi: '1.2', total_skp: '0', total_potongan: '1.2', keterangan: 'Cuti tahunan (1 Oktober 2026)', penyesuaian: null, galat: null,
  ...ekstra,
})
const rekap = (items: unknown[] = [baris('199001012020011001')]) => ({
  items, total: String(items.length), page: '1', per_page: '25', bulan: '2026-10',
  periode: { awal: '2026-09-16', akhir: '2026-10-15' }, lingkup: { tipe: 'unit', id: '5', kode: '5', nama: 'Biro Uji' }, jumlah_galat: '0',
})
const param = { bulan: '2026-10', unit_satker: '5', jenis_pegawai: ['1', '6'], page: 2, per_page: 25 }

beforeEach(() => {
  server.balasan = {}
  server.permintaan = []
})

describe('rekapTukinService — amplop backend nyata lewat klien asli', () => {
  it('daftar(): parameter sesuai kontrak, angka string dinormalkan, urutan kolom pengurangan tetap', async () => {
    server.balasan['get /presensi/tukin/rekap'] = sukses(rekap())
    const hasil = await rekapTukinService.daftar(param)

    expect(server.permintaan[0]!.params).toEqual({ bulan: '2026-10', page: 2, per_page: 25, unit_satker: '5', jenis_pegawai: ['1', '6'] })
    expect(hasil).toMatchObject({ total: 1, page: 1, per_page: 25, bulan: '2026-10', jumlah_galat: 0, periode: { awal: '2026-09-16' } })
    expect(hasil.lingkup).toEqual({ tipe: 'unit', id: 5, kode: '5', nama: 'Biro Uji' })
    expect(Object.keys(hasil.items[0]!.pengurangan!)).toEqual(['TL', 'PSW', 'TK', 'TA', 'CUTI', 'TB'])
    expect(hasil.items[0]).toMatchObject({ total_potongan: '1.2', galat: null })
  })

  it('daftar() mempertahankan baris galat dengan pengurangan/total null dan pesan', async () => {
    server.balasan['get /presensi/tukin/rekap'] = sukses(
      rekap([
        baris('199001012020011001'),
        baris('199201012021011002', { pengurangan: null, total_presensi: null, total_skp: null, total_potongan: null, keterangan: '', galat: { code: 'data_tukin_belum_tersedia', message: 'Data Tukin belum tersedia.' } }),
      ]),
    )
    const hasil = await rekapTukinService.daftar(param)
    expect(hasil.items).toHaveLength(2)
    expect(hasil.items[1]).toMatchObject({ pengurangan: null, total_potongan: null, galat: { code: 'data_tukin_belum_tersedia', message: 'Data Tukin belum tersedia.' } })
  })

  it('daftar() IL memakai /rekap-il dan hanya bulan + paginasi', async () => {
    server.balasan['get /presensi/tukin/rekap-il'] = sukses({ ...rekap(), lingkup: null })
    const hasil = await rekapTukinService.daftar(param, true)
    expect(server.permintaan[0]).toMatchObject({ url: '/presensi/tukin/rekap-il', params: { bulan: '2026-10', page: 2, per_page: 25 } })
    expect(hasil.lingkup).toBeNull()
  })

  it('ekspor xlsx: tanpa paginasi, jenis_pegawai tetap array, nama dari server', async () => {
    server.balasan['get /presensi/tukin/rekap/xlsx'] = {
      status: 200, body: new Blob(['x']), headers: { 'content-disposition': 'attachment; filename="laporan_tukin_5_2026-10.xlsx"' },
    }
    const f = await rekapTukinService.ekspor('xlsx', param)
    expect(f.nama).toBe('laporan_tukin_5_2026-10.xlsx')
    expect(server.permintaan[0]!.params).toEqual({ bulan: '2026-10', unit_satker: '5', jenis_pegawai: ['1', '6'] })
  })

  it('ekspor pdf memakai /rekap/pdf; ekspor IL selalu /rekap-il/xlsx tanpa unit/jenis', async () => {
    server.balasan['get /presensi/tukin/rekap/pdf'] = { status: 200, body: new Blob(['x']) }
    server.balasan['get /presensi/tukin/rekap-il/xlsx'] = { status: 200, body: new Blob(['x']) }
    await rekapTukinService.ekspor('pdf', param)
    await rekapTukinService.ekspor('xlsx', param, true)
    expect(server.permintaan.map((p) => p.url)).toEqual(['/presensi/tukin/rekap/pdf', '/presensi/tukin/rekap-il/xlsx'])
    expect(server.permintaan[1]!.params).toEqual({ bulan: '2026-10' })
  })

  it('galat: 422 unit_satker (batas PDF) → pesan server; code periode_tidak_didukung; 403; non-API', async () => {
    server.balasan['get /presensi/tukin/rekap/pdf'] = galat(422, undefined, { unit_satker: ['Persempit lingkup atau unduh XLSX.'] })
    expect(pesanGalatRekap(await rekapTukinService.ekspor('pdf', param).catch((e: unknown) => e))).toBe('Persempit lingkup atau unduh XLSX.')

    server.balasan['get /presensi/tukin/rekap'] = galat(422, 'periode_tidak_didukung', { bulan: ['x'] })
    expect(pesanGalatRekap(await rekapTukinService.daftar(param).catch((e: unknown) => e))).toContain('arsip SIMPEG lama')

    server.balasan['get /presensi/tukin/rekap'] = galat(403)
    expect(pesanGalatRekap(await rekapTukinService.daftar(param).catch((e: unknown) => e))).toContain('tidak berhak')
    expect(pesanGalatRekap(new Error('x'))).toBe('Terjadi kesalahan. Coba lagi.')
  })
})
