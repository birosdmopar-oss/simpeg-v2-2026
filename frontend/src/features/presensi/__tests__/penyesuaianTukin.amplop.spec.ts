/**
 * Pelajaran CR-059: klien Penyesuaian Tukin diuji dengan KLIEN ASLI (`createApiClient` + interceptor `unwrapEnvelope`)
 * dan respons berbentuk backend nyata `{status:'success', data:{...}}`; hanya adapter jaringan yang palsu. Service yang
 * membaca `res.data.data` akan gagal di sini.
 */
import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const server = vi.hoisted(() => ({
  balasan: {} as Record<string, { status: number; body: unknown }>,
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
    const response: AxiosResponse = { status: balasan.status, statusText: 'x', headers: {}, config, data: balasan.body }
    if (balasan.status >= 400) throw new AxiosError('gagal', 'ERR_BAD_REQUEST', config, null, response)
    return response
  }
  return { ...actual, api: actual.createApiClient({ adapter }) }
})

import { formatPersen, labelBulan, penyesuaianTukinService } from '../services/penyesuaianTukin.service'

const sukses = (data: unknown, status = 200) => ({ status, body: { status: 'success', data } })
const sukses201 = (data: unknown) => sukses(data, 201)

const barisBalasan = {
  id: '4',
  nip: '198001012005011001',
  nama: 'Budi Santoso',
  bulan_mulai: '2025-03',
  bulan_akhir: '2025-06',
  persen: '-20.00',
  alasan: 'Predikat Kinerja Sangat Kurang',
  status: '1',
  created_at: '2026-10-29 03:00:00',
  updated_at: null,
}

beforeEach(() => {
  server.balasan = {}
  server.panggilan = []
})

describe('penyesuaianTukinService — amplop backend nyata lewat klien asli', () => {
  it('daftar(): envelope items/total/page/per_page, angka string dinormalkan, persen tetap string', async () => {
    server.balasan['GET /presensi/tukin/penyesuaian'] = sukses({ items: [barisBalasan], total: '1', page: '1', per_page: '25' })

    const hasil = await penyesuaianTukinService.daftar({ nip: '198001012005011001', bulan: '2025-04', status: 1, page: 1, per_page: 25 })

    expect(hasil.total).toBe(1)
    expect(hasil.per_page).toBe(25)
    expect(hasil.items[0]).toMatchObject({ id: 4, nip: '198001012005011001', status: 1, persen: '-20.00', updated_at: null })
    expect(server.panggilan[0]?.params).toEqual({ page: 1, per_page: 25, nip: '198001012005011001', bulan: '2025-04', status: 1 })
  })

  it('daftar(): parameter kosong tidak dikirim', async () => {
    server.balasan['GET /presensi/tukin/penyesuaian'] = sukses({ items: [], total: 0, page: 1, per_page: 10 })
    await penyesuaianTukinService.daftar({ page: 1, per_page: 10 })
    expect(server.panggilan[0]?.params).toEqual({ page: 1, per_page: 10 })
  })

  it('tambah(): POST JSON, respons 201 dibaca dari data', async () => {
    server.balasan['POST /presensi/tukin/penyesuaian'] = sukses201(barisBalasan)
    const hasil = await penyesuaianTukinService.tambah({
      nip: '198001012005011001',
      bulan_mulai: '2025-03',
      bulan_akhir: '2025-06',
      persen: '-20',
      alasan: 'Predikat Kinerja Sangat Kurang',
    })
    expect(hasil.id).toBe(4)
    expect(JSON.parse(String(server.panggilan[0]?.data))).toMatchObject({ persen: '-20', bulan_mulai: '2025-03' })
  })

  it('ubah(): PUT ke /{id} dengan status', async () => {
    server.balasan['PUT /presensi/tukin/penyesuaian/4'] = sukses({ ...barisBalasan, status: '2' })
    const hasil = await penyesuaianTukinService.ubah(4, {
      nip: '198001012005011001',
      bulan_mulai: '2025-03',
      bulan_akhir: '2025-06',
      persen: '-10',
      alasan: 'Ubah',
      status: 2,
    })
    expect(hasil.status).toBe(2)
    expect(JSON.parse(String(server.panggilan[0]?.data))).toMatchObject({ status: 2 })
  })

  it('hapus(): DELETE ke /{id}, tanpa body yang dibaca', async () => {
    server.balasan['DELETE /presensi/tukin/penyesuaian/4'] = sukses(null)
    await expect(penyesuaianTukinService.hapus(4)).resolves.toBeUndefined()
    expect(server.panggilan[0]?.method).toBe('DELETE')
  })

  it('galat 422 per field tetap membawa errors dari server', async () => {
    server.balasan['POST /presensi/tukin/penyesuaian'] = {
      status: 422,
      body: { status: 'error', message: 'Data tidak valid.', errors: { bulan_mulai: ['Bulan mulai minimal 2024-04.'] } },
    }
    await expect(
      penyesuaianTukinService.tambah({ nip: '1', bulan_mulai: '2024-01', bulan_akhir: '2024-02', persen: '-20', alasan: 'x' }),
    ).rejects.toMatchObject({ status: 422, errors: { bulan_mulai: ['Bulan mulai minimal 2024-04.'] } })
  })
})

describe('format tampilan penyesuaian', () => {
  it('labelBulan: YYYY-MM → nama bulan Indonesia', () => {
    expect(labelBulan('2024-04')).toBe('April 2024')
    expect(labelBulan('2026-12')).toBe('Desember 2026')
  })

  it('formatPersen: titik → koma, dua desimal, tanda minus dipertahankan', () => {
    expect(formatPersen('-20.00')).toBe('-20,00%')
    expect(formatPersen('10')).toBe('10,00%')
  })
})
