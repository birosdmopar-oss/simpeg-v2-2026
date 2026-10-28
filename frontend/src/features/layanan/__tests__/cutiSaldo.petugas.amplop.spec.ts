/**
 * Paket 4 (MAKE-026) — cutiSaldoService dan cutiPetugasApprovalService dengan KLIEN ASLI (`createApiClient` + interceptor
 * amplop) dan respons berbentuk backend `{status:'success', data:{...}}` dengan angka/flag berupa STRING (MySQLi). Spec
 * halaman me-mock service, jadi amplop salah (`res.data.data`) hanya tertangkap di sini (pelajaran CR-059).
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

import { cutiPetugasApprovalService } from '../services/cutiPetugasApproval.service'
import { cutiSaldoService } from '../services/cutiSaldo.service'

const sukses = (data: unknown, status = 200): Balasan => ({ status, body: { status: 'success', data } })

beforeEach(() => {
  server.balasan = {}
  server.permintaan = []
})

describe('cutiSaldoService (amplop nyata)', () => {
  it('daftar: baris dinormalkan dari string MySQLi ke angka, total/tahun dibaca dari data', async () => {
    server.balasan['get /cuti/saldo'] = sukses({
      items: [{ id_pegawai_sisa_cuti: '12', nip: '198001012005011001', nama: 'Budi', tahun: '2026', sisa_cuti: '12', sisa_cuti_n1: '3', sisa_cuti_n2: '0', sisa_cuti_ditangguhkan: '0', utang_cuti: '1', jabatan: 'Staf', satker: null }],
      total: '1',
      page: '1',
      per_page: '20',
      tahun: '2026',
    })

    const hasil = await cutiSaldoService.list({ tahun: '2026', page: 1, per_page: 20 })

    expect(hasil.total).toBe(1)
    expect(hasil.tahun).toBe(2026)
    expect(hasil.items[0]).toMatchObject({ id: 12, tahun: 2026, sisa_cuti: 12, sisa_cuti_n1: 3, utang_cuti: 1, satker: null })
    expect(server.permintaan[0].params).toMatchObject({ tahun: '2026', page: 1, per_page: 20 })
  })

  it('tambah target semua pegawai: respons 201 (ringkasan + dilewati) diteruskan utuh, bukan diringkas', async () => {
    server.balasan['post /cuti/saldo'] = sukses(
      {
        message: 'Saldo cuti dibuat untuk 1 pegawai.',
        ringkasan: { tahun: '2026', target: '1', jumlah_populasi: '2', jumlah_dibuat: '1', jumlah_dilewati: '1' },
        dibuat: [{ nip: '198001012005011001', nama: 'Budi' }],
        dilewati: [{ nip: '199001012010011002', nama: 'Sari', alasan_kode: 'sudah_punya_saldo', alasan: 'Pegawai sudah memiliki saldo cuti tahun 2026.' }],
      },
      201,
    )

    const hasil = await cutiSaldoService.tambah({ target: 1, tahun: 2026, sisa_cuti: 12, sisa_cuti_n1: 0, sisa_cuti_n2: 0, utang_cuti: 0 })

    expect(hasil.ringkasan).toEqual({ tahun: 2026, target: 1, jumlah_populasi: 2, jumlah_dibuat: 1, jumlah_dilewati: 1 })
    expect(hasil.dilewati).toEqual([{ nip: '199001012010011002', nama: 'Sari', alasan_kode: 'sudah_punya_saldo', alasan: 'Pegawai sudah memiliki saldo cuti tahun 2026.' }])
    expect(JSON.parse(server.permintaan[0].data as string)).toMatchObject({ target: 1, tahun: 2026 })
  })
})

describe('cutiPetugasApprovalService (amplop nyata)', () => {
  it('daftar: id_pa_layanan jadi id, petugas bersarang dan id_jabatan null tetap null', async () => {
    server.balasan['get /cuti/petugas-approval'] = sukses({
      items: [
        {
          id_pa_layanan: '7',
          id_jenis_pa: '1',
          id_jenis_pegawai: '1',
          jenis_pegawai: 'PNS',
          id_unit_pemohon: '10',
          unit_pemohon: 'Biro SDM',
          id_group_jabatan_pemohon: '2',
          group_jabatan_pemohon: 'Pelaksana',
          id_sub_group_jabatan_pemohon: null,
          sub_group_jabatan_pemohon: null,
          gol_ruang_pemohon: 'III/a',
          id_unit_petugas: '11',
          unit_petugas: 'Bagian Umum',
          id_satker_petugas: '4',
          satker_petugas: 'Satker A',
          id_group_jabatan_petugas: '3',
          group_jabatan_petugas: 'Struktural',
          id_sub_group_jabatan_petugas: '5',
          sub_group_jabatan_petugas: 'Kepala',
          jabatan_struktural: 'Kepala Bagian',
          jabatan_koordinasi: null,
          id_jabatan: '21',
          id_jabatan_koord: null,
          status: '1',
          petugas: { nip: null, nama: null, jabatan: 'Kepala Bagian' },
        },
      ],
      total: '1',
      page: '1',
      per_page: '25',
    })

    const hasil = await cutiPetugasApprovalService.list({ page: 1, per_page: 25 })
    const r = hasil.items[0]

    expect(hasil.total).toBe(1)
    expect(r).toMatchObject({ id: 7, id_unit_pemohon: 10, id_sub_group_jabatan_pemohon: null, gol_ruang_pemohon: 'III/a', id_jabatan: 21, id_jabatan_koord: null, status: 1 })
    expect(r.petugas).toEqual({ nip: null, nama: null, jabatan: 'Kepala Bagian' })
  })

  it('opsi: unit_terkunci null untuk role 1; kode group jabatan pakai gol jadi angka', async () => {
    server.balasan['get /cuti/petugas-approval/opsi'] = sukses({
      id_jenis_pa: '1',
      jenis_pegawai: [{ id_jenis_pegawai: '1', jenis_pegawai: 'PNS' }],
      group_jabatan_pakai_gol: ['2', '3'],
      jabatan_koordinasi: [{ id_jabatan_koordinasi: '9', jabatan: 'Koordinator', jenis: '1' }],
      unit_terkunci: null,
    })

    const opsi = await cutiPetugasApprovalService.opsi()

    expect(opsi.id_jenis_pa).toBe(1)
    expect(opsi.group_jabatan_pakai_gol).toEqual([2, 3])
    expect(opsi.jabatan_koordinasi).toEqual([{ id_jabatan_koordinasi: 9, jabatan: 'Koordinator', jenis: 1 }])
    expect(opsi.unit_terkunci).toBeNull()
  })

  it('tambah mengirim petugas dalam bentuk struk_<id> apa adanya', async () => {
    server.balasan['post /cuti/petugas-approval'] = sukses({}, 201)

    await cutiPetugasApprovalService.tambah({
      id_jenis_pa: 1,
      id_jenis_pegawai: 1,
      id_unit_pemohon: 10,
      id_group_jabatan_pemohon: 2,
      id_sub_group_jabatan_pemohon: 5,
      id_unit_petugas: 11,
      id_satker_petugas: 4,
      id_group_jabatan_petugas: 3,
      id_sub_group_jabatan_petugas: 5,
      petugas: 'struk_21',
    })

    expect(JSON.parse(server.permintaan[0].data as string)).toMatchObject({ petugas: 'struk_21', id_sub_group_jabatan_pemohon: 5 })
  })
})
