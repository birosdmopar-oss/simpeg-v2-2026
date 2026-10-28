/**
 * Paket 9 (MAKE-026) — petugasLayananService dengan KLIEN ASLI (`createApiClient` + interceptor amplop) dan respons berbentuk
 * backend `{status:'success', data:{...}}` dengan angka/flag berupa STRING (MySQLi). Spec halaman me-mock service, jadi amplop
 * salah (`res.data.data`) hanya tertangkap di sini (pelajaran CR-059).
 */
import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

type Balasan = { status: number; body: unknown }

const server = vi.hoisted(() => ({
  balasan: {} as Record<string, Balasan>,
  permintaan: [] as { method: string; url: string; params: unknown; data: unknown }[],
}))

vi.mock('@/lib/axios', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/axios')>()
  const adapter = async (config: InternalAxiosRequestConfig): Promise<AxiosResponse> => {
    const url = config.url ?? ''
    const method = (config.method ?? 'get').toLowerCase()
    server.permintaan.push({ method, url, params: config.params, data: config.data })
    const balasan = server.balasan[`${method} ${url}`]
    if (!balasan) throw new Error(`tidak ada balasan palsu untuk ${method} ${url}`)
    const response: AxiosResponse = { status: balasan.status, statusText: 'x', headers: {}, config, data: balasan.body }
    if (balasan.status >= 400) throw new AxiosError('gagal', 'ERR_BAD_REQUEST', config, null, response)
    return response
  }
  return { ...actual, api: actual.createApiClient({ adapter }) }
})

import { petugasLayananService } from '../services/petugasLayanan.service'

const sukses = (data: unknown, status = 200): Balasan => ({ status, body: { status: 'success', data } })

beforeEach(() => {
  server.balasan = {}
  server.permintaan = []
})

describe('petugasLayananService (amplop nyata)', () => {
  it('jenis: array langsung dari data, id dinormalkan', async () => {
    server.balasan['get /layanan/jenis'] = sukses([
      { id_jenis_layanan: '3', jenis_layanan: 'Tugas Belajar', module_name: 'tb' },
      { id_jenis_layanan: '4', jenis_layanan: 'Izin Belajar', module_name: null },
    ])

    const hasil = await petugasLayananService.jenis()

    expect(hasil).toEqual([
      { id_jenis_layanan: 3, jenis_layanan: 'Tugas Belajar', module_name: 'tb' },
      { id_jenis_layanan: 4, jenis_layanan: 'Izin Belajar', module_name: null },
    ])
  })

  it('daftar: status dan aksi dari server (string "1"/"0") dinormalkan; can_create dibaca dari data', async () => {
    server.balasan['get /layanan/petugas'] = sukses({
      items: [
        {
          id_petugas_layanan: '5',
          id_jenis_layanan: '3',
          jenis_layanan: 'Tugas Belajar',
          nip: '198001012005011001',
          nama: 'Budi Santoso',
          jabatan: 'Kepala Bagian',
          satker: 'Satker A',
          unit: 'Biro SDM',
          status: '1',
          label_status: 'Aktif',
          aksi: { ubah: '1', aktifkan: '0', nonaktifkan: '1', hapus: '1' },
        },
      ],
      total: '1',
      page: '1',
      per_page: '20',
      can_create: '1',
    })

    const hasil = await petugasLayananService.list({ id_jenis_layanan: '3', status: '1', page: 1, per_page: 20 })

    expect(hasil.can_create).toBe(true)
    expect(hasil.items[0]).toMatchObject({ id: 5, id_jenis_layanan: 3, status: 1, label_status: 'Aktif', nama: 'Budi Santoso' })
    expect(hasil.items[0].aksi).toEqual({ ubah: true, aktifkan: false, nonaktifkan: true, hapus: true })
    expect(server.permintaan[0].params).toMatchObject({ id_jenis_layanan: '3', status: '1', page: 1 })
  })

  it('kandidat: peringatan satker kosong dibawa apa adanya dengan items kosong', async () => {
    server.balasan['get /layanan/petugas/kandidat'] = sukses({ items: [], peringatan: 'Satker kepegawaian belum diatur pada pengaturan web.' })

    const hasil = await petugasLayananService.kandidat('budi')

    expect(hasil.items).toEqual([])
    expect(hasil.peringatan).toBe('Satker kepegawaian belum diatur pada pengaturan web.')
    expect(server.permintaan[0].params).toEqual({ search: 'budi' })
  })

  it('kandidat: daftar kandidat dan peringatan null', async () => {
    server.balasan['get /layanan/petugas/kandidat'] = sukses({ items: [{ nip: '198001012005011001', nama: 'Budi Santoso' }], peringatan: null })

    const hasil = await petugasLayananService.kandidat('198')

    expect(hasil).toEqual({ items: [{ nip: '198001012005011001', nama: 'Budi Santoso' }], peringatan: null })
  })

  it('tambah mengirim id_jenis_layanan sebagai angka dan nip; ubah status dengan PUT', async () => {
    server.balasan['post /layanan/petugas'] = sukses({}, 201)
    server.balasan['put /layanan/petugas/5'] = sukses({})

    await petugasLayananService.tambah({ id_jenis_layanan: 3, nip: '198001012005011001' })
    await petugasLayananService.ubah(5, { status: 0 })

    expect(JSON.parse(server.permintaan[0].data as string)).toEqual({ id_jenis_layanan: 3, nip: '198001012005011001' })
    expect(JSON.parse(server.permintaan[1].data as string)).toEqual({ status: 0 })
  })

  it('galat 422 dari server tetap menjadi ApiError dengan status 422 (dibaca pemanggil)', async () => {
    server.balasan['post /layanan/petugas'] = { status: 422, body: { status: 'error', message: 'Validasi gagal.', errors: { nip: ['Pegawai sudah menjadi PIC.'] } } }

    await expect(petugasLayananService.tambah({ id_jenis_layanan: 3, nip: '198001012005011001' })).rejects.toMatchObject({ status: 422 })
  })
})
