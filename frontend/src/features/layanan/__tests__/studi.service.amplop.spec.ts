/**
 * Paket 5 (MAKE-026) — klien Tugas Belajar (`tb`) dan Izin Belajar (`ib`) dengan KLIEN ASLI (`createApiClient` + interceptor
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

import { createStudiService } from '../services/studi.service'

const sukses = (data: unknown, status = 200): Balasan => ({ status, body: { status: 'success', data } })
const tb = createStudiService('tb')
const ib = createStudiService('ib')

const baris = (jenis: 'tb' | 'ib', extra: Record<string, unknown> = {}) => ({
  [`id_riwayat_${jenis}`]: '41',
  nip: '198001012005011001',
  nama: 'Budi Santoso',
  id_jenjang_pendidikan: '4',
  institusi_pendidikan: 'Universitas Uji',
  jurusan_pendidikan: 'Teknik',
  lokasi_pendidikan: 'Bandung',
  lembaga_yang_mendanai: 'Instansi',
  up: 'Pusat',
  tgl_mulai: '2027-01-01',
  tgl_akhir: '2028-12-31',
  dalam_negeri: '1',
  tembusan: null,
  keterangan: '',
  status: '0',
  label_status: 'Menunggu Verifikasi PIC',
  nada_status: 'warning',
  tahap: [
    { kode: 'diajukan', label: 'Diajukan', state: 'done', nama_petugas: null, waktu: '2026-10-01' },
    { kode: 'pic', label: 'Verifikasi PIC', state: 'current', nama_petugas: null, waktu: null },
  ],
  aksi: { ubah: '1', hapus: '0', proses: '1', unduh_surat: '0' },
  ...extra,
})

beforeEach(() => {
  server.balasan = {}
  server.permintaan = []
})

describe('klien Tugas Belajar (amplop nyata)', () => {
  it('daftar: flag can_create/can_process dan mode dibaca dari server; baris dinormalkan', async () => {
    server.balasan['get /tb'] = sukses({ items: [baris('tb', { nama: null })], total: '1', page: '1', per_page: '20', mode: 'pic', can_create: '0', can_process: '1' })

    const hasil = await tb.list({ mode: 'pic', page: 1, per_page: 20 })

    expect(hasil.total).toBe(1)
    expect(hasil.mode).toBe('pic')
    expect(hasil.can_create).toBe(false)
    expect(hasil.can_process).toBe(true)
    expect(hasil.items[0]).toMatchObject({ id: 41, status: 0, nama: null, tgl_mulai: '2027-01-01', nada_status: 'warning' })
    expect(hasil.items[0].aksi).toEqual({ ubah: true, hapus: false, proses: true, unduh_surat: false })
    expect(hasil.items[0].tahap[1]).toMatchObject({ kode: 'pic', state: 'current' })
  })

  it('detail: aksi.perpanjangan dari server, perpanjangan[] dan lampiran bersarang, SK dari kolom ds_tb', async () => {
    server.balasan['get /tb/41'] = sukses(
      baris('tb', {
        status: '1',
        no_sk: 'SK/1/2026',
        tgl_sk: '2026-10-02',
        ds_tb: 'Dasar A',
        no_ds_tb: 'D-9',
        tgl_ds_tb: '2026-09-30',
        perihal_ds_tb: 'Perihal',
        aksi: { ubah: '0', hapus: '1', proses: '0', unduh_surat: '1', perpanjangan: '1' },
        status_penuh: '1',
        lampiran: [{ id_attachment: '7', tag: 'file_sp', display_name: 'sp.pdf' }],
        surat: [{ id_attachment: '8', jenis: 'tte' }],
        perpanjangan: [{ id_riwayat_tb_perpan: '3', tgl_mulai_perpan: '2028-01-01', tgl_akhir_perpan: '2028-06-30', lampiran: [{ id_attachment: '9', tag: null, display_name: 'p.pdf' }] }],
      }),
    )

    const d = await tb.detail(41)

    expect(d.aksi.perpanjangan).toBe(true)
    expect(d.status_penuh).toBe(true)
    expect(d.sk).toEqual({ no_sk: 'SK/1/2026', tgl_sk: '2026-10-02', ds: 'Dasar A', no_ds: 'D-9', tgl_ds: '2026-09-30', perihal_ds: 'Perihal' })
    expect(d.lampiran[0]).toEqual({ id_attachment: 7, tag: 'file_sp', display_name: 'sp.pdf' })
    expect(d.perpanjangan[0]).toMatchObject({ id: 3, tgl_akhir_perpan: '2028-06-30' })
    expect(d.perpanjangan[0].lampiran[0].id_attachment).toBe(9)
    expect(d.surat).toEqual([{ id_attachment: 8, jenis: 'tte' }])
  })

  it('proses tolak tanpa berkas: JSON dengan reason_note apa adanya', async () => {
    server.balasan['post /tb/41/process'] = sukses({})

    await tb.proses(41, { aksi: 'tolak', reason_note: 'Berkas BAN PT belum ada' })

    expect(JSON.parse(server.permintaan[0].data as string)).toEqual({ aksi: 'tolak', reason_note: 'Berkas BAN PT belum ada' })
  })

  it('proses setujui dengan SK memakai nama kolom ds_tb (bukan ds)', async () => {
    server.balasan['post /tb/41/process'] = sukses({})

    await tb.proses(41, { aksi: 'setujui', no_sk: 'SK/2', tgl_sk: '2026-10-20', ds_tb: 'Dasar', no_ds_tb: '', tgl_ds_tb: '', perihal_ds_tb: '' })

    const kirim = JSON.parse(server.permintaan[0].data as string) as Record<string, string>
    expect(kirim).toMatchObject({ aksi: 'setujui', no_sk: 'SK/2', ds_tb: 'Dasar' })
    expect(kirim).not.toHaveProperty('ds')
  })

  it('perpanjangan: tambah multipart membawa file_sperpan; ubah memakai PUT tanggal saja', async () => {
    server.balasan['post /tb/41/perpanjangan'] = sukses({}, 201)
    server.balasan['put /tb/perpanjangan/3'] = sukses({})

    const berkas = new File(['x'], 'p.pdf', { type: 'application/pdf' })
    await tb.tambahPerpanjangan(41, { tgl_akhir_perpan: '2028-06-30' }, { file_sperpan: berkas })
    await tb.ubahPerpanjangan(3, { tgl_mulai_perpan: '2028-01-01', tgl_akhir_perpan: '2028-07-31' })

    const fd = server.permintaan[0].data as FormData
    expect(fd.get('file_sperpan')).toBeInstanceOf(File)
    expect(fd.get('tgl_akhir_perpan')).toBe('2028-06-30')
    expect(JSON.parse(server.permintaan[1].data as string)).toEqual({ tgl_mulai_perpan: '2028-01-01', tgl_akhir_perpan: '2028-07-31' })
  })
})

describe('klien Izin Belajar (amplop nyata)', () => {
  it('opsi: adalah_pic "0" → false, slot_berkas dari server (tag → label), berkas_wajib boolean', async () => {
    server.balasan['get /ib/opsi'] = sukses({
      jenjang_pendidikan: [{ id_jenjang_pendidikan: '4', jenjang_pendidikan: 'S2', jenjang_pendidikan_singkat: 'S2' }],
      up: 'Pusat',
      pegawai: null,
      slot_berkas: { file_sp: 'Surat Pengantar', file_1: 'Surat Persetujuan', file_2: 'Dokumen Pendukung' },
      berkas_wajib: true,
      can_create: '1',
      adalah_pic: '0',
    })

    const opsi = await ib.opsi()

    expect(opsi.adalah_pic).toBe(false)
    expect(opsi.can_create).toBe(true)
    expect(opsi.berkas_wajib).toBe(true)
    expect(opsi.slot_berkas).toEqual([
      { tag: 'file_sp', label: 'Surat Pengantar' },
      { tag: 'file_1', label: 'Surat Persetujuan' },
      { tag: 'file_2', label: 'Dokumen Pendukung' },
    ])
    expect(opsi.jenjang_pendidikan[0].id_jenjang_pendidikan).toBe(4)
  })

  it('daftar dan detail: endpoint ib, id dari id_riwayat_ib, tanpa perpanjangan', async () => {
    server.balasan['get /ib'] = sukses({ items: [baris('ib')], total: '1', page: '1', per_page: '20', mode: 'saya', can_create: '1', can_process: '0' })
    server.balasan['get /ib/41'] = sukses(baris('ib', { perpanjangan: [], status_penuh: undefined }))

    const daftar = await ib.list({ mode: 'saya', page: 1, per_page: 20 })
    const detail = await ib.detail(41)

    expect(server.permintaan[0].url).toBe('/ib')
    expect(daftar.items[0].id).toBe(41)
    expect(daftar.can_create).toBe(true)
    expect(detail.status_penuh).toBeNull()
    expect(detail.perpanjangan).toEqual([])
  })

  it('tambah multipart mengirim berkas per slot; ubah memakai _method=PUT', async () => {
    server.balasan['post /ib'] = sukses(baris('ib'), 201)
    server.balasan['post /ib/41'] = sukses(baris('ib'))

    const sp = new File(['a'], 'sp.pdf', { type: 'application/pdf' })
    await ib.tambah({ nip: '198001012005011001', institusi_pendidikan: 'Universitas Uji', tgl_mulai: '2027-01-01' }, { file_sp: sp })
    await ib.ubah(41, { institusi_pendidikan: 'Universitas Uji' }, {})

    const tambah = server.permintaan[0].data as FormData
    expect(tambah.get('file_sp')).toBeInstanceOf(File)
    expect(tambah.get('nip')).toBe('198001012005011001')
    const ubah = server.permintaan[1].data as FormData
    expect(ubah.get('_method')).toBe('PUT')
  })

  it('galat 422 per kolom dari server tetap berupa AxiosError dengan errors (dibaca pemanggil)', async () => {
    server.balasan['post /ib'] = { status: 422, body: { status: 'error', message: 'Validasi gagal.', errors: { file_sp: ['Berkas wajib.'] } } }

    await expect(ib.tambah({ institusi_pendidikan: '' }, {})).rejects.toMatchObject({ status: 422 })
  })
})
