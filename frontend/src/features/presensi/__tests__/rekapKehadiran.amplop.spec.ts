/**
 * Pelajaran CR-059: klien Rekap Kehadiran & Uang Makan diuji dengan KLIEN ASLI (`createApiClient` + interceptor
 * `unwrapEnvelope`) dan respons berbentuk backend nyata. Unduhan XLSX memakai `responseType: 'blob'` dan nama berkas
 * dari `Content-Disposition`; uji ini memastikan parameter & nama berkas sampai ke pemanggil.
 */
import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const server = vi.hoisted(() => ({
  balasan: {} as Record<string, { status: number; body: unknown; headers?: Record<string, string> }>,
  panggilan: [] as { method: string; url: string; params: unknown; data: unknown }[],
}))

vi.mock('@/lib/axios', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/axios')>()
  const adapter = async (config: InternalAxiosRequestConfig): Promise<AxiosResponse> => {
    const url = config.url ?? ''
    const method = String(config.method).toUpperCase()
    server.panggilan.push({ method, url, params: config.params, data: config.data })
    const balasan = server.balasan[`${method} ${url}`]
    if (!balasan) throw new Error(`tidak ada balasan palsu untuk ${method} ${url}`)
    const response: AxiosResponse = { status: balasan.status, statusText: 'x', headers: balasan.headers ?? {}, config, data: balasan.body }
    if (balasan.status >= 400) throw new AxiosError('gagal', 'ERR_BAD_REQUEST', config, null, response)
    return response
  }
  return { ...actual, api: actual.createApiClient({ adapter }) }
})

import { rekapKehadiranService, rekapUangMakanService } from '../services/rekapKehadiran.service'

const sukses = (data: unknown, status = 200) => ({ status, body: { status: 'success', data } })

beforeEach(() => {
  server.balasan = {}
  server.panggilan = []
})

describe('rekapKehadiranService — amplop backend nyata lewat klien asli', () => {
  it('daftar(): items ringkas, angka string dinormalkan, paginasi dibaca dari envelope', async () => {
    server.balasan['GET /presensi/rekap-kehadiran'] = sukses({
      items: [{ nip: '198001012005011001', nama: 'Budi Santoso', jabatan: 'Analis', hari_kerja: '22', hadir: '20', tl: '1', psw: '0', tk: '1', sakit: '0', dinas_luar: '0', lain_lain: '0', cuti: '0', tidak_hadir: '1', tb: '0' }],
      total: '1', page: '1', per_page: '25', bulan: '2026-10', lingkup: { kode: '0' },
    })

    const hasil = await rekapKehadiranService.daftar({ bulan: '2026-10', unit_satker: '0', jenis_pegawai: ['1', '2'], page: 1, per_page: 25 })

    expect(hasil.total).toBe(1)
    expect(hasil.items[0]).toMatchObject({ nip: '198001012005011001', hari_kerja: 22, hadir: 20, tb: 0, jabatan: 'Analis' })
    expect(server.panggilan[0]?.params).toEqual({ bulan: '2026-10', unit_satker: '0', jenis_pegawai: ['1', '2'], page: 1, per_page: 25 })
  })

  it('unduh(xlsx-harian): nama berkas dari Content-Disposition, parameter tanpa paginasi', async () => {
    server.balasan['GET /presensi/rekap-kehadiran/xlsx-harian'] = {
      status: 200,
      body: new Blob(['x'], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }),
      headers: { 'content-disposition': 'attachment; filename="rekap_kehadiran_harian_0_2026-10.xlsx"' },
    }

    const berkas = await rekapKehadiranService.unduh('xlsx-harian', { bulan: '2026-10', unit_satker: 'sat_4' })

    expect(berkas.nama).toBe('rekap_kehadiran_harian_0_2026-10.xlsx')
    expect(server.panggilan[0]?.params).toEqual({ bulan: '2026-10', unit_satker: 'sat_4' })
  })
})

describe('rekapUangMakanService — amplop backend nyata lewat klien asli', () => {
  it('daftar(): baris NIP + tgl_absen tanpa nominal', async () => {
    server.balasan['GET /presensi/uang-makan/rekap'] = sukses({
      items: [{ nip: '198001012005011001', tgl_absen: '2026-10-05' }], total: '1', page: '1', per_page: '25', bulan: '2026-10', lingkup: { kode: '0' },
    })

    const hasil = await rekapUangMakanService.daftar({ bulan: '2026-10', page: 1, per_page: 25 }, false)

    expect(hasil.items).toEqual([{ nip: '198001012005011001', tgl_absen: '2026-10-05' }])
    expect(server.panggilan[0]?.params).toEqual({ bulan: '2026-10', page: 1, per_page: 25 })
  })

  it('daftar(il): endpoint Instansi Lain hanya membawa bulan dan paginasi', async () => {
    server.balasan['GET /presensi/uang-makan/rekap-il'] = sukses({ items: [], total: 0, page: 1, per_page: 25, bulan: '2026-10', lingkup: {} })

    await rekapUangMakanService.daftar({ bulan: '2026-10', unit_satker: '0', jenis_pegawai: ['1'], page: 1, per_page: 25 }, true)

    expect(server.panggilan[0]?.url).toBe('/presensi/uang-makan/rekap-il')
    expect(server.panggilan[0]?.params).toEqual({ bulan: '2026-10', page: 1, per_page: 25 })
  })

  it('unduh(il): berkas XLSX IL hanya dengan bulan', async () => {
    server.balasan['GET /presensi/uang-makan/rekap-il/xlsx'] = {
      status: 200,
      body: new Blob(['x']),
      headers: { 'content-disposition': 'attachment; filename="rekap_uang_makan_il_2026-10.xlsx"' },
    }

    const berkas = await rekapUangMakanService.unduh({ bulan: '2026-10' }, true)

    expect(berkas.nama).toBe('rekap_uang_makan_il_2026-10.xlsx')
    expect(server.panggilan[0]?.params).toEqual({ bulan: '2026-10' })
  })
})
