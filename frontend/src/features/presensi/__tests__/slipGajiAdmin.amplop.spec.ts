/**
 * Pelajaran CR-059: klien Kelola Slip Gaji diuji dengan KLIEN ASLI (`createApiClient` + interceptor `unwrapEnvelope`)
 * dan respons berbentuk backend nyata `{status:'success', data:{...}}`; hanya adapter jaringan yang palsu, sehingga
 * service yang membaca `res.data.data` akan gagal di sini. Angka & boolean dikirim sebagai string (MySQLi).
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

import { labelPeriode, slipGajiAdminService } from '../services/slipGajiAdmin.service'

const sukses = (data: unknown) => ({ status: 200, body: { status: 'success', data } })

beforeEach(() => {
  server.balasan = {}
  server.panggilan = []
})

describe('slipGajiAdminService — amplop backend nyata lewat klien asli', () => {
  it('daftar(): parameter hanya yang terisi, angka/boolean string dinormalkan', async () => {
    server.balasan['GET /slip-gaji'] = sukses({
      items: [
        {
          id: '12', nip: '199001012020011001', nama: 'Budi', unit_satker: 'Biro Uji - Satker Uji', periode: '2026-09', dibuka: '1',
          viewed_at: '2026-10-04T03:00:00Z', viewed_at_wib: '4 Oktober 2026, 10:00 WIB', viewed_ip: '10.0.0.1',
          aksi: { lihat: true, cetak: true, koreksi: true, buka_ulang: '1' },
        },
      ],
      total: '41', page: '2', per_page: '20',
      ringkasan: { jumlah: '41', dibuka: '30', belum_dibuka: '11' },
      periode_tersedia: ['2026-09', '2026-08'],
    })

    const d = await slipGajiAdminService.daftar({ periode: '2026-09', unit_satker: '', status_view: 'semua', search: '  budi ', page: 2, per_page: 20 })

    expect(server.panggilan[0]?.params).toEqual({ periode: '2026-09', search: 'budi', page: 2, per_page: 20 })
    expect(d.total).toBe(41)
    expect(d.ringkasan).toEqual({ jumlah: 41, dibuka: 30, belum_dibuka: 11 })
    expect(d.items[0]).toMatchObject({ id: 12, dibuka: true, aksi: { buka_ulang: true } })
    expect(d.periode_tersedia).toEqual(['2026-09', '2026-08'])
  })

  it('detail() & koreksi(): PUT 28 kolom apa adanya, nominal tetap string, identitas dinormalkan', async () => {
    const slip = {
      id: '12', nip: '199001012020011001', nama: 'Budi', periode: '2026-09', label: 'September 2026', dibuka: false,
      viewed_at: null, viewed_at_wib: null, viewed_ip: null,
      rincian: { gjpokok: '3000000.00', pajak: '70000.00' },
      ringkasan: { jumlah_penghasilan_gpp: '3000000.00', jumlah_potongan_gpp: '0.00', bersih_gpp: '3000000.00', bersih_tk: '0.00', bersih_2: '0.00', uang_makan: '0.00', total_penghasilan: '3000000.00' },
      uang_makan: { nominal: '0.00', hari: null, periode_kehadiran: null, tersedia: '0' },
      aksi: { lihat: true, cetak: true, koreksi: true, buka_ulang: false },
      pegawai: { nip: '199001012020011001', nama: 'Budi', pangkat: null, jabatan: 'Analis', unit: 'Biro Uji', satker: 'Satker Uji' },
    }
    server.balasan['GET /slip-gaji/12'] = sukses(slip)
    server.balasan['PUT /slip-gaji/12'] = sukses({ ...slip, rincian: { gjpokok: '3100000.00', pajak: '70000.00' } })

    const d = await slipGajiAdminService.detail(12)
    expect(d).toMatchObject({ id: 12, rincian: { pajak: '70000.00' }, uang_makan: { tersedia: false }, pegawai: { pangkat: '-', jabatan: 'Analis' } })

    const k = await slipGajiAdminService.koreksi(12, { gjpokok: '3100000.00' })
    expect(server.panggilan[1]).toMatchObject({ method: 'PUT', url: '/slip-gaji/12' })
    expect(JSON.parse(String(server.panggilan[1]?.data))).toEqual({ gjpokok: '3100000.00' })
    expect(k.rincian.gjpokok).toBe('3100000.00')
  })

  it('bukaUlang() memakai POST; galat 422 server diteruskan sebagai ApiError', async () => {
    server.balasan['POST /slip-gaji/12/buka-ulang'] = {
      status: 422,
      body: { status: 'error', message: 'Slip gaji ini belum pernah dibuka, tidak perlu direset.', errors: { status: ['x'] } },
    }
    await expect(slipGajiAdminService.bukaUlang(12)).rejects.toMatchObject({ status: 422, message: 'Slip gaji ini belum pernah dibuka, tidak perlu direset.' })
  })

  it('pratinjau(): multipart berkas + filter bulan/tahun; ringkasan & baris dinormalkan', async () => {
    server.balasan['POST /slip-gaji/impor/pratinjau'] = sukses({
      token_pratinjau: 'tok123', kedaluwarsa_at: '2026-10-28T05:00:00Z', timpa_bawaan: true, filter: '2026-09',
      ringkasan: { valid: '1', duplikat: '1', galat: '1' },
      info: {},
      baris: [
        { nomor: '2', sheet: 'GPP', nip: '1990', nama: 'Budi', periode: '2026-09', status: 'valid', rencana_bila_timpa: 'sisip', rencana_bila_tidak_timpa: 'sisip', galat: [], info: [], nilai: {} },
        { nomor: '3', sheet: 'GPP', nip: null, nama: null, periode: null, status: 'galat', rencana_bila_timpa: 'gagal', rencana_bila_tidak_timpa: 'gagal', galat: [{ kode: 'nip_kosong', pesan: 'NIP wajib diisi.', sheet: 'GPP', baris: '3' }], info: [], nilai: {} },
      ],
    })

    const p = await slipGajiAdminService.pratinjau(new File(['x'], 'gaji.xlsx'), '2026-09')

    const form = server.panggilan[0]?.data as FormData
    expect(form).toBeInstanceOf(FormData)
    expect(form.get('filter_bulan')).toBe('9')
    expect(form.get('filter_tahun')).toBe('2026')
    expect((form.get('berkas') as File).name).toBe('gaji.xlsx')
    expect(p.ringkasan).toEqual({ valid: 1, duplikat: 1, galat: 1 })
    expect(p.baris[1]).toMatchObject({ nomor: 3, status: 'galat', galat: [{ baris: 3, pesan: 'NIP wajib diisi.' }] })
  })

  it('pratinjau() tanpa filter tidak mengirim filter_bulan/filter_tahun', async () => {
    server.balasan['POST /slip-gaji/impor/pratinjau'] = sukses({ token_pratinjau: 't', kedaluwarsa_at: null, timpa_bawaan: true, filter: null, ringkasan: { valid: 0, duplikat: 0, galat: 0 }, baris: [] })
    await slipGajiAdminService.pratinjau(new File(['x'], 'gaji.xls'), null)
    const form = server.panggilan[0]?.data as FormData
    expect(form.has('filter_bulan')).toBe(false)
    expect(form.has('filter_tahun')).toBe(false)
  })

  it('commit(): body {timpa}, hitungan string dinormalkan', async () => {
    server.balasan['POST /slip-gaji/impor/pratinjau/tok123/commit'] = sukses({ disisip: '3', diperbarui: '1', dilewati: '0', gagal: '1', baris: [{ nip: '1990', periode: '2026-09', hasil: 'sisip', galat: [] }] })
    const h = await slipGajiAdminService.commit('tok123', false)
    expect(JSON.parse(String(server.panggilan[0]?.data))).toEqual({ timpa: false })
    expect([h.disisip, h.diperbarui, h.dilewati, h.gagal]).toEqual([3, 1, 0, 1])
  })

  it('opsiSatker() memetakan opsi master satker (UL_ALL)', async () => {
    server.balasan['GET /master/satker/options'] = sukses([{ id: 7, nama: 'Biro Uji', parent: '1' }])
    expect(await slipGajiAdminService.opsiSatker()).toEqual([{ value: '7', label: 'Biro Uji' }])
  })

  it('labelPeriode() memakai nama bulan Indonesia tanpa Date', () => {
    expect(labelPeriode('2026-09')).toBe('September 2026')
    expect(labelPeriode(null)).toBe('-')
    expect(labelPeriode('bukan')).toBe('bukan')
  })
})
