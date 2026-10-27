/**
 * CR-059 (ISSUE-050) — laporanService.lingkup/statistik dan dokumenService.daftar dengan KLIEN ASLI (`createApiClient`
 * + interceptor `unwrapEnvelope`) dan respons berbentuk backend nyata `{status:'success', data:{...}}`.
 *
 * Spec halaman (LaporanPage/DokumenPage) me-mock seluruh `@/lib/axios`, sehingga bentuk amplop yang salah di service
 * (`res.data.data`, padahal interceptor sudah membuka amplop) lolos di CI. Di sini hanya adapter jaringan yang palsu;
 * kode lama membaca `undefined` (lingkup tak pernah muncul, `normalisasi()` melempar TypeError).
 */
import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

type Balasan = { status: number; body: unknown }

const server = vi.hoisted(() => ({
  balasan: {} as Record<string, { status: number; body: unknown }>,
  panggilan: [] as string[],
}))

vi.mock('@/lib/axios', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/axios')>()
  const adapter = async (config: InternalAxiosRequestConfig): Promise<AxiosResponse> => {
    const url = config.url ?? ''
    server.panggilan.push(url)
    const balasan = server.balasan[url]
    if (!balasan) throw new Error(`tidak ada balasan palsu untuk ${url}`)
    const response: AxiosResponse = { status: balasan.status, statusText: 'x', headers: {}, config, data: balasan.body }
    if (balasan.status >= 400) throw new AxiosError('gagal', 'ERR_BAD_REQUEST', config, null, response)
    return response
  }
  return { ...actual, api: actual.createApiClient({ adapter }) }
})

import { dokumenService } from '@/features/dokumen/dokumen.service'

import { laporanService } from '../laporan.service'

const sukses = (data: unknown): Balasan => ({ status: 200, body: { status: 'success', data } })

beforeEach(() => {
  server.balasan = {}
  server.panggilan = []
})

describe('laporanService — amplop backend nyata lewat klien asli', () => {
  it('lingkup() mengembalikan isi data (kementerian + unit), bukan undefined', async () => {
    server.balasan['/statistik/lingkup'] = sukses({
      kementerian: { nama: 'Kementerian Uji' },
      unit: [{ id: 3, nama: 'Unit A', satker: [{ id: 31, nama: 'Satker A1' }] }],
    })

    const lingkup = await laporanService.lingkup()

    expect(lingkup.kementerian).toEqual({ nama: 'Kementerian Uji' })
    expect(lingkup.unit).toHaveLength(1)
    expect(lingkup.unit[0]?.satker[0]?.nama).toBe('Satker A1')
  })

  it('statistik() menormalkan angka string MySQLi dan tidak melempar TypeError', async () => {
    server.balasan['/statistik/golongan'] = sukses({
      jenis: 'golongan',
      judul: 'Golongan',
      lingkup: { tipe: 'kementerian', id: null, nama: 'Kementerian Uji' },
      label_kategori: 'Unit Kerja Eselon I',
      kategori: [{ id: '3', label: 'Unit A' }],
      seri: [{ key: 'pns', label: 'PNS' }, { key: 'pppk', label: 'PPPK' }],
      baris: [{ kategori_id: '3', label: 'Unit A', nilai: ['1200', 300], total: '1500' }],
      subtotal: ['1200', 300],
      total: '1500',
      periode: '2026-10-21',
      cache: { hit: false, ttl: 900 },
    })

    const data = await laporanService.statistik('golongan')

    expect(data.baris[0]?.nilai).toEqual([1200, 300])
    expect(data.baris[0]?.total).toBe(1500)
    expect(data.subtotal).toEqual([1200, 300])
    expect(data.total).toBe(1500)
    expect(data.judul).toBe('Golongan')
  })
})

describe('dokumenService — amplop backend nyata lewat klien asli', () => {
  it('daftar() mengembalikan isi data dengan total/page/per_page berupa angka', async () => {
    server.balasan['/dokumen/duk'] = sukses({
      jenis: 'duk',
      judul: 'Daftar Urut Kepangkatan',
      kolom: { nama: 'Nama', nip: 'NIP' },
      items: [{ no: 1, nama: 'Budi', nip: '198501012010011001' }],
      total: '1',
      page: '1',
      per_page: '25',
    })

    const data = await dokumenService.daftar('duk', '')

    expect(data.judul).toBe('Daftar Urut Kepangkatan')
    expect(data.items).toHaveLength(1)
    expect(data.total).toBe(1)
    expect(data.page).toBe(1)
    expect(data.per_page).toBe(25)
  })
})
