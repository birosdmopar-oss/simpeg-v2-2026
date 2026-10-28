/**
 * Pelajaran CR-059: klien Slip Gaji diuji dengan KLIEN ASLI (`createApiClient` + interceptor `unwrapEnvelope`) dan
 * respons berbentuk backend nyata `{status:'success', data:{...}}`. Hanya adapter jaringan yang palsu, sehingga service
 * yang membaca `res.data.data` (amplop dobel) akan gagal di sini. Angka dikirim sebagai string (MySQLi).
 */
import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const server = vi.hoisted(() => ({
  balasan: {} as Record<string, { status: number; body: unknown }>,
  panggilan: [] as { method: string; url: string }[],
}))

vi.mock('@/lib/axios', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/axios')>()
  const adapter = async (config: InternalAxiosRequestConfig): Promise<AxiosResponse> => {
    const url = config.url ?? ''
    server.panggilan.push({ method: String(config.method).toUpperCase(), url })
    const balasan = server.balasan[`${String(config.method).toUpperCase()} ${url}`]
    if (!balasan) throw new Error(`tidak ada balasan palsu untuk ${config.method} ${url}`)
    const response: AxiosResponse = { status: balasan.status, statusText: 'x', headers: {}, config, data: balasan.body }
    if (balasan.status >= 400) throw new AxiosError('gagal', 'ERR_BAD_REQUEST', config, null, response)
    return response
  }
  return { ...actual, api: actual.createApiClient({ adapter }) }
})

import { formatRupiah, slipGajiService } from '../services/slipGaji.service'

const sukses = (data: unknown) => ({ status: 200, body: { status: 'success', data } })

beforeEach(() => {
  server.balasan = {}
  server.panggilan = []
})

describe('slipGajiService — amplop backend nyata lewat klien asli', () => {
  it('daftar() mengembalikan isi data dan menormalkan boolean, bukan undefined', async () => {
    server.balasan['GET /slip-gaji/saya'] = sukses({
      items: [
        { periode: '2026-09', label: 'September 2026', ada_data: true, dibuka: '1', viewed_at: '2026-10-04T03:00:00Z', viewed_at_wib: '4 Oktober 2026, 10:00 WIB' },
        { periode: '2026-08', label: 'Agustus 2026', ada_data: '0', dibuka: false, viewed_at: null, viewed_at_wib: null },
      ],
      periode_terbaru: '2026-09',
    })

    const daftar = await slipGajiService.daftar()

    expect(daftar.periode_terbaru).toBe('2026-09')
    expect(daftar.items.map((p) => [p.periode, p.ada_data, p.dibuka])).toEqual([
      ['2026-09', true, true],
      ['2026-08', false, false],
    ])
  })

  it('buka() memakai POST, nominal tetap string desimal, hari uang makan & identitas dinormalkan', async () => {
    server.balasan['POST /slip-gaji/saya/2026-09/buka'] = sukses({
      periode: '2026-09',
      label: 'September 2026',
      viewed_at: '2026-10-27T02:00:00Z',
      viewed_at_wib: '27 Oktober 2026, 09:00 WIB',
      rincian: { gjpokok: '3000000.00', tjistri: '300000.00' },
      ringkasan: { jumlah_penghasilan_gpp: '3300000.00', jumlah_potongan_gpp: '0.00', bersih_gpp: '2700000.00', bersih_tk: '1900000.00', bersih_2: '0.00', uang_makan: '0.00', total_penghasilan: '4600000.00' },
      uang_makan: { nominal: '0.00', hari: '21', periode_kehadiran: '2026-08', tersedia: true },
      pegawai: { nip: '199001012020011001', nama: 'Dr. Budi Santoso, M.Si.', pangkat: 'Penata / III/c', jabatan: 'Analis', unit: 'Biro Uji', satker: 'Satker Uji' },
    })

    const slip = await slipGajiService.buka('2026-09')

    expect(server.panggilan).toEqual([{ method: 'POST', url: '/slip-gaji/saya/2026-09/buka' }])
    expect(slip.rincian.gjpokok).toBe('3000000.00')
    expect(slip.ringkasan.total_penghasilan).toBe('4600000.00')
    expect(slip.uang_makan).toEqual({ nominal: '0.00', hari: 21, periode_kehadiran: '2026-08', tersedia: true })
    expect(slip.pegawai.nama).toBe('Dr. Budi Santoso, M.Si.')
  })

  it('buka() 422 diteruskan sebagai ApiError dengan pesan server', async () => {
    server.balasan['POST /slip-gaji/saya/2026-09/buka'] = {
      status: 422,
      body: { status: 'error', code: 'slip_sudah_dibuka', message: 'Slip gaji sudah dibuka.', errors: { periode: ['Slip gaji sudah dibuka.'] } },
    }

    await expect(slipGajiService.buka('2026-09')).rejects.toMatchObject({ status: 422, message: 'Slip gaji sudah dibuka.' })
  })

  it('verifikasi() memanggil rute publik dan mengembalikan snapshot', async () => {
    const token = 'a'.repeat(64)
    server.balasan[`GET /publik/verifikasi-slip/${token}`] = sukses({
      status_keaslian: 'asli',
      periode: '2026-09',
      label_periode: 'September 2026',
      satker_penerbit: 'Sekretariat Uji',
      waktu_terbit: '27 Oktober 2026, 09:00 WIB',
      nama: 'B*** S***',
    })

    const hasil = await slipGajiService.verifikasi(token)

    expect(hasil).toEqual({
      status_keaslian: 'asli',
      periode: '2026-09',
      label_periode: 'September 2026',
      satker_penerbit: 'Sekretariat Uji',
      waktu_terbit: '27 Oktober 2026, 09:00 WIB',
      nama: 'B*** S***',
    })
  })

  it('verifikasi() 404 dan 429 diteruskan dengan status', async () => {
    server.balasan['GET /publik/verifikasi-slip/x'] = { status: 404, body: { status: 'error', message: 'Dokumen tidak ditemukan atau tidak valid.' } }
    server.balasan['GET /publik/verifikasi-slip/y'] = { status: 429, body: { status: 'error', message: 'Terlalu banyak permintaan.' } }

    await expect(slipGajiService.verifikasi('x')).rejects.toMatchObject({ status: 404 })
    await expect(slipGajiService.verifikasi('y')).rejects.toMatchObject({ status: 429, message: 'Terlalu banyak permintaan.' })
  })
})

describe('formatRupiah — tanpa aritmetika float, pembulatan seperti number_format legacy', () => {
  it.each([
    ['4500000.00', '4.500.000'],
    ['0.00', '0'],
    ['999.49', '999'],
    ['999.50', '1.000'],
    ['12345678901234.99', '12.345.678.901.235'],
    ['-100000.00', '-100.000'],
    ['5', '5'],
    ['bukan angka', '0'],
  ])('%s → %s', (masuk, keluar) => {
    expect(formatRupiah(masuk)).toBe(keluar)
  })
})
