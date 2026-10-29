/**
 * MAKE-048 — service Laporan Tukin dengan KLIEN ASLI (`createApiClient` + interceptor `unwrapEnvelope`, amplop backend
 * nyata, string MySQLi, `code` galat terkendali); hanya adapter jaringan yang palsu (pelajaran CR-059).
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


import { kodeGalat, laporanTukinService, pesanGalatLaporan } from '../services/laporanTukin.service'

const NIP = '199001012020011001'
const sukses = (data: unknown) => ({ status: 200, body: { status: 'success', data } })
const galat = (status: number, code?: string, errors?: Record<string, string[]>) => ({
  status,
  body: { status: 'error', message: 'x', ...(code ? { code } : {}), ...(errors ? { errors } : {}) },
})

const pegawai = (nip = NIP, nama = 'Budi Santoso') => ({
  nip, nama, gol_ruang: 'III/c', pangkat: 'Penata', jabatan: 'Analis', eselon: '', unit: 'Biro Uji', satker: 'Satker Uji',
  pendidikan: { strata: 'S1', jurusan: 'HUKUM', institusi: 'Univ Uji' }, jenis_pegawai: 'PNS', aksi: { lihat_laporan: true },
})
const daftar = (items = [pegawai()]) => ({
  items, total: String(items.length), page: '1', per_page: '25', lingkup: null,
  bulan_tersedia: [{ bulan: '2026-10', label: 'Oktober 2026' }],
})
const harian = (tanggal: string, status: string, kode: string[] = [], ekstra: Record<string, unknown> = {}) => ({
  tanggal, status, kode, jam_masuk: null, jam_pulang: null, potongan: '0', keterangan: '', kategori_konket: null, ...ekstra,
})
const laporan = (ekstra: Record<string, unknown> = {}) => ({
  identitas: { nip: NIP, nama: 'Budi Santoso', gol_ruang: 'III/c', satker: 'Satker Uji', jabatan: 'Analis', kelas_jabatan: '9', id_unit: '1', id_satker: '2', predikat_skp: 'Baik' },
  periode: { awal: '2026-09-16', akhir: '2026-10-15', dihitung_sampai: '2026-10-15', sumber: 'bawaan' },
  harian: [
    harian('2026-10-01', 'hadir', ['TL2', 'PSW1'], { jam_masuk: '07:45:00', jam_pulang: '16:00:00', potongan: '0.40', keterangan: 'TL2 - PSW1' }),
    harian('2026-10-02', 'TK', ['TK'], { potongan: '1.00', keterangan: 'TK' }),
    harian('2026-10-03', 'cuti', [], { keterangan: 'Cuti tahunan' }),
    harian('2026-10-04', 'tugas_belajar', [], { keterangan: 'Tugas Belajar' }),
    harian('2026-10-05', 'konket', [], { keterangan: 'Konket', kategori_konket: '13' }),
    harian('2026-10-10', 'akhir_pekan', [], { keterangan: 'Sabtu' }),
    harian('2026-10-14', 'libur', [], { keterangan: '(Libur) Hari Uji' }),
    harian('2026-09-16', 'di_luar_masa_kerja'),
  ],
  jumlah: { TL1: '0', TL2: '1', PSW1: '1', TK: '1', CS: '0' },
  rincian: { TL: '0.2', PSW: '0.2', TA: '0', TK: '1', CUTI: '0' },
  total_presensi: '1.4', total_skp: '0', total_potongan: '1.4', alasan: null, penyesuaian: null, keterangan: '',
  ...ekstra,
})

beforeEach(() => {
  server.balasan = {}
  server.permintaan = []
})

describe('laporanTukinService — amplop backend nyata lewat klien asli', () => {
  it('daftar(): bulan tidak dikirim; unit_satker & jenis_pegawai[] hanya bila terisi; angka string dinormalkan', async () => {
    server.balasan['get /presensi/tukin/pegawai'] = sukses(daftar())
    const hasil = await laporanTukinService.daftar({ unit_satker: 'sat_9', jenis_pegawai: ['1', '6'], search: 'bud', page: 2, per_page: 25 })

    expect(server.permintaan[0]!.params).toEqual({ page: 2, per_page: 25, search: 'bud', unit_satker: 'sat_9', jenis_pegawai: ['1', '6'] })
    expect(hasil).toMatchObject({ total: 1, page: 1, per_page: 25, bulan_tersedia: [{ bulan: '2026-10', label: 'Oktober 2026' }] })
    expect(hasil.items[0]).toMatchObject({ nip: NIP, gol_ruang: 'III/c', pendidikan: { strata: 'S1' } })
  })

  it('daftar() IL memakai /pegawai-il dan hanya search + paginasi', async () => {
    server.balasan['get /presensi/tukin/pegawai-il'] = sukses(daftar([]))
    await laporanTukinService.daftar({ unit_satker: '5', jenis_pegawai: ['1'], page: 1, per_page: 10 }, true)
    expect(server.permintaan[0]).toMatchObject({ url: '/presensi/tukin/pegawai-il', params: { page: 1, per_page: 10 } })
  })

  it('laporan(): menormalkan kode[], jumlah, kelas_jabatan, harian non-kerja; bulan sebagai parameter', async () => {
    server.balasan[`get /presensi/tukin/laporan/${NIP}`] = sukses(laporan())
    const l = await laporanTukinService.laporan(NIP, '2026-10')

    expect(server.permintaan[0]!.params).toEqual({ bulan: '2026-10' })
    expect(l.identitas.kelas_jabatan).toBe(9)
    expect(l.jumlah).toEqual({ TL1: 0, TL2: 1, PSW1: 1, TK: 1, CS: 0 })
    expect(l.harian[0]).toMatchObject({ status: 'hadir', kode: ['TL2', 'PSW1'], jam_masuk: '07:45:00', potongan: '0.40' })
    expect(l.harian[4]!.kategori_konket).toBe(13)
    expect(l.harian.map((h) => h.status)).toContain('akhir_pekan')
    expect(l.penyesuaian).toBeNull()
  })

  it('pdf(): nama berkas dari Content-Disposition server', async () => {
    server.balasan[`get /presensi/tukin/laporan/${NIP}/pdf`] = {
      status: 200, body: new Blob(['%PDF']), headers: { 'content-disposition': `attachment; filename="tukin_${NIP}_2026-10.pdf"` },
    }
    const f = await laporanTukinService.pdf(NIP, '2026-10')
    expect(f.nama).toBe(`tukin_${NIP}_2026-10.pdf`)
    expect(server.permintaan[0]!.params).toEqual({ bulan: '2026-10' })
  })

  it('galat terkendali: code → pesan, 403/404/422 → pesan status', async () => {
    server.balasan[`get /presensi/tukin/laporan/${NIP}`] = galat(422, 'konfigurasi_tukin_tidak_lengkap')
    const e1 = await laporanTukinService.laporan(NIP, '2026-10').catch((e: unknown) => e)
    expect(kodeGalat(e1)).toBe('konfigurasi_tukin_tidak_lengkap')
    expect(pesanGalatLaporan(e1)).toContain('Konfigurasi tarif Tukin')

    server.balasan[`get /presensi/tukin/laporan/${NIP}`] = galat(403)
    expect(pesanGalatLaporan(await laporanTukinService.laporan(NIP, '2026-10').catch((e: unknown) => e))).toContain('tidak berhak')
    server.balasan[`get /presensi/tukin/laporan/${NIP}`] = galat(404)
    expect(pesanGalatLaporan(await laporanTukinService.laporan(NIP, '2026-10').catch((e: unknown) => e))).toContain('tidak ditemukan')
    server.balasan[`get /presensi/tukin/laporan/${NIP}`] = galat(422, undefined, { bulan: ['Bulan melewati batas.'] })
    expect(pesanGalatLaporan(await laporanTukinService.laporan(NIP, '2099-01').catch((e: unknown) => e))).toBe('Bulan melewati batas.')
    expect(kodeGalat(new Error('x'))).toBeNull()
    expect(pesanGalatLaporan(new Error('x'))).toBe('Terjadi kesalahan. Coba lagi.')
  })
})

