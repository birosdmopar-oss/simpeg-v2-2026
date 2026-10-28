/**
 * Pelajaran CR-059: klien Presensi diuji dengan KLIEN ASLI (`createApiClient` + interceptor `unwrapEnvelope`) dan
 * respons berbentuk backend nyata `{status:'success', data:{...}}`; hanya adapter jaringan yang palsu, sehingga service
 * yang membaca `res.data.data` akan gagal di sini. Angka & boolean dikirim sebagai string (MySQLi). Koordinat wajib di
 * body, tidak pernah di query string (PRD 5A §7.5.4).
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

import { formatJam, formatTanggal, labelSumber, presensiService } from '../services/presensi.service'

const sukses = (data: unknown, status = 200) => ({ status, body: { status: 'success', data } })
const body = (i: number) => JSON.parse(String(server.panggilan[i]?.data ?? '{}'))

beforeEach(() => {
  server.balasan = {}
  server.panggilan = []
})

describe('presensiService — amplop backend nyata lewat klien asli', () => {
  it('hariIni(): boolean/angka string dinormalkan, lokasi angka', async () => {
    server.balasan['GET /presensi/saya/hari-ini'] = sukses({
      tanggal_lokal: '2026-10-28', jam_lokal: '07:10:05', zonasi_menit: '60',
      jendela: { masuk: ['04:00:00', '12:30:59'], pulang: ['12:31:00', '23:59:59'] },
      boleh_masuk: '1', boleh_pulang: false, presensi: null, wajib_geofence: '1',
      lokasi: [{ id_lokasi_presensi: '3', nama_lokasi: 'Kantor Pusat', latitude: '-6.2', longitude: '106.8', radius: '56' }],
    })
    const h = await presensiService.hariIni()
    expect(h).toMatchObject({ zonasi_menit: 60, boleh_masuk: true, boleh_pulang: false, wajib_geofence: true, presensi: null })
    expect(h.lokasi[0]).toEqual({ id_lokasi_presensi: 3, nama_lokasi: 'Kantor Pusat', latitude: -6.2, longitude: 106.8, radius: 56 })
  })

  it('cekLokasi() & rekam(): koordinat di body (bukan query), akurasi dibulatkan; hasil dinormalkan', async () => {
    server.balasan['POST /presensi/cek-lokasi'] = sukses({ wajib_geofence: true, dalam_radius: '0', lokasi_terdekat: { id: '3', nama: 'Kantor Pusat', radius: '56', jarak_m: '1112' } })
    server.balasan['POST /presensi/masuk'] = sukses({ tgl: '2026-10-28', jam_masuk: '07:15:00', jam_pulang: null, id_lokasi_masuk: '3', jarak_m: '12' }, 201)

    const c = await presensiService.cekLokasi({ latitude: -6.2005, longitude: 106.8166667, akurasi: 12.3456 })
    expect(c).toEqual({ wajib_geofence: true, dalam_radius: false, lokasi_terdekat: { id: 3, nama: 'Kantor Pusat', radius: 56, jarak_m: 1112 } })
    expect(server.panggilan[0]?.params).toBeUndefined()
    expect(body(0)).toEqual({ latitude: -6.2005, longitude: 106.8166667, akurasi: 12.35 })

    const r = await presensiService.rekam('masuk', { latitude: -6.2, longitude: 106.8, akurasi: null })
    expect(server.panggilan[1]).toMatchObject({ method: 'POST', url: '/presensi/masuk' })
    expect(body(1)).toEqual({ latitude: -6.2, longitude: 106.8 })
    expect(r).toEqual({ tgl: '2026-10-28', jam_masuk: '07:15:00', jam_pulang: null, jarak_m: 12 })
  })

  it('rekam() tanpa koordinat mengirim body kosong; 422 diteruskan dengan pesan server', async () => {
    server.balasan['POST /presensi/pulang'] = {
      status: 422,
      body: { status: 'error', code: 'di_luar_jendela', message: 'Presensi pulang hanya dapat direkam pukul 12:31–23:59 waktu setempat.', errors: { jam: ['x'] } },
    }
    await expect(presensiService.rekam('pulang', null)).rejects.toMatchObject({ status: 422, message: 'Presensi pulang hanya dapat direkam pukul 12:31–23:59 waktu setempat.' })
    expect(body(0)).toEqual({})
  })

  it('riwayatSaya(): bulan & paginasi di params, sumber dinormalkan (1/2/null)', async () => {
    server.balasan['GET /presensi/saya'] = sukses({
      bulan: '2026-10', total: '2', page: '1', per_page: '20',
      items: [
        { tgl: '2026-10-28', jam_masuk: '07:15:00', jam_pulang: null, sumber: '1' },
        { tgl: '2026-10-27', jam_masuk: '07:00:00', jam_pulang: '16:00:00', sumber: null },
      ],
    })
    const r = await presensiService.riwayatSaya('2026-10', 1, 20)
    expect(server.panggilan[0]?.params).toEqual({ bulan: '2026-10', page: 1, per_page: 20 })
    expect(r.items.map((i) => i.sumber)).toEqual([1, null])
    expect(r.total).toBe(2)
  })

  it('daftarPegawai() & detailPegawai(): filter hanya yang terisi, aksi/can_* boolean', async () => {
    server.balasan['GET /presensi/pegawai'] = sukses({ items: [{ nip: '1990', nama: 'Budi', unit: 'Biro', satker: null }], total: '1', page: '1', per_page: '20', can_kelola_manual: '0' })
    server.balasan['GET /presensi/pegawai/1990'] = sukses({
      pegawai: { nip: '1990', nama: 'Budi' },
      items: [{ nip: '1990', tgl: '2026-10-28', jam_masuk: '07:15:00', jam_pulang: null, sumber: '2', id_lokasi_masuk: null, lokasi_masuk: null, id_lokasi_pulang: null, lokasi_pulang: null, aksi: { ubah: '1', hapus: '1' } }],
      total: '1', page: '1', per_page: '100', can_create: true,
    })
    const d = await presensiService.daftarPegawai({ search: ' bud ', id_unit: '', id_satker: '' })
    expect(server.panggilan[0]?.params).toEqual({ search: 'bud' })
    expect(d.can_kelola_manual).toBe(false)

    const det = await presensiService.detailPegawai('1990', { tgl_mulai: '2026-10-01' })
    expect(server.panggilan[1]?.params).toEqual({ tgl_mulai: '2026-10-01' })
    expect(det.items[0]).toMatchObject({ sumber: 2, aksi: { ubah: true, hapus: true } })
    expect(det.can_create).toBe(true)
  })

  it('manual: POST/PUT body whitelist (jam kosong = null), DELETE path', async () => {
    server.balasan['POST /presensi/manual'] = sukses({}, 201)
    server.balasan['PUT /presensi/manual/1990/2026-10-27'] = sukses({})
    server.balasan['DELETE /presensi/manual/1990/2026-10-27'] = sukses({})

    await presensiService.tambahManual({ nip: ' 1990 ', tgl: '2026-10-27', jam_masuk: '07:00', jam_pulang: '' })
    expect(body(0)).toEqual({ nip: '1990', tgl: '2026-10-27', jam_masuk: '07:00', jam_pulang: null })

    await presensiService.ubahManual('1990', '2026-10-27', { jam_masuk: '', jam_pulang: '16:00' })
    expect(body(1)).toEqual({ jam_masuk: null, jam_pulang: '16:00' })

    await presensiService.hapusManual('1990', '2026-10-27')
    expect(server.panggilan[2]).toMatchObject({ method: 'DELETE', url: '/presensi/manual/1990/2026-10-27' })
  })

  it('format tampilan tanpa toISOString', () => {
    expect(formatTanggal('2026-10-28')).toEqual({ hari: 'Rabu', tanggal: '28 Oktober 2026' })
    expect(formatJam('07:15:30')).toBe('07:15')
    expect(formatJam(null)).toBe('-')
    expect([labelSumber(1), labelSumber(2), labelSumber(null)]).toEqual(['Online', 'Manual', 'Impor'])
  })
})
