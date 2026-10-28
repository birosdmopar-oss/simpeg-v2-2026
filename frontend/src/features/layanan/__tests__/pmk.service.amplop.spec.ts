/**
 * Paket 7 M7 (MAKE-026) — pmkService dengan KLIEN ASLI (`createApiClient` + interceptor `unwrapEnvelope`) dan respons
 * berbentuk backend nyata `{status:'success', data:{...}}` dengan angka/flag berupa STRING (MySQLi). Spec halaman me-mock
 * service, jadi amplop salah (`res.data.data`) hanya tertangkap di sini (pelajaran CR-059).
 */
import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

type Balasan = { status: number; body: unknown; headers?: Record<string, string> }

const server = vi.hoisted(() => ({
  balasan: {} as Record<string, { status: number; body: unknown; headers?: Record<string, string> }>,
  permintaan: [] as { method: string; url: string; params: unknown; data: unknown; responseType: unknown }[],
}))

vi.mock('@/lib/axios', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/axios')>()
  const adapter = async (config: InternalAxiosRequestConfig): Promise<AxiosResponse> => {
    const url = config.url ?? ''
    const method = (config.method ?? 'get').toLowerCase()
    server.permintaan.push({ method, url, params: config.params, data: config.data, responseType: config.responseType })
    const balasan = server.balasan[`${method} ${url}`]
    if (!balasan) throw new Error(`tidak ada balasan palsu untuk ${method} ${url}`)
    const response: AxiosResponse = { status: balasan.status, statusText: 'x', headers: balasan.headers ?? {}, config, data: balasan.body }
    if (balasan.status >= 400) throw new AxiosError('gagal', 'ERR_BAD_REQUEST', config, null, response)
    return response
  }
  return { ...actual, api: actual.createApiClient({ adapter }) }
})

import { pmkService } from '../services/pmk.service'

const sukses = (data: unknown): Balasan => ({ status: 200, body: { status: 'success', data } })

const barisPmk = (extra: Record<string, unknown> = {}) => ({
  id_riwayat_pmk_req: '31',
  nip: '198001012005011001',
  jenis_pmk: 'Swasta',
  instansi: 'PT Contoh',
  tgl_awal: '2001-01-01',
  tgl_akhir: '2003-06-30',
  mker_th: '2',
  mker_bl: '5',
  status: '0',
  reason_note: null,
  file_kontrak: true,
  file_paklaring: false,
  file_surat_phk: true,
  file_slip_gaji: false,
  label_status: 'Menunggu PIC',
  nada_status: 'warning',
  tahap: [{ kode: 'diajukan', label: 'Diajukan', state: 'done', nama_petugas: null, waktu: '2026-10-01 02:00:00' }],
  aksi: { ubah: '1', hapus: '1', proses: '1' },
  aksi_tersedia: ['proses', 'setujui', 'tolak'],
  created_at: '2026-10-01 02:00:00',
  approved_at: null,
  ...extra,
})

beforeEach(() => {
  server.balasan = {}
  server.permintaan = []
})

describe('pmkService — amplop backend nyata lewat klien asli', () => {
  it('list() membuka amplop, menormalkan string, dan mengirim parameter', async () => {
    server.balasan['get /pmk/pengajuan'] = sukses({ items: [barisPmk()], total: '1', page: '1', per_page: '20', mode: 'pic', can_create: '0', can_process: '1' })

    const hasil = await pmkService.list({ mode: 'pic', page: 1, per_page: 20, status: '0', search: 'budi' })

    expect(hasil.total).toBe(1)
    expect(hasil.mode).toBe('pic')
    expect(hasil.can_process).toBe(true)
    expect(hasil.can_create).toBe(false)
    const item = hasil.items[0]!
    expect(item.id).toBe(31)
    expect(item.mker_th).toBe(2)
    expect(item.status).toBe(0)
    expect(item.berkas).toEqual({ file_kontrak: true, file_paklaring: false, file_surat_phk: true, file_slip_gaji: false })
    expect(item.aksi).toEqual({ ubah: true, hapus: true, proses: true })
    expect(item.aksi_tersedia).toEqual(['proses', 'setujui', 'tolak'])
    expect(item.tahap[0]?.label).toBe('Diajukan')
    expect(server.permintaan[0]?.params).toEqual({ mode: 'pic', page: 1, per_page: 20, status: '0', search: 'budi' })
  })

  it('aksi_tersedia membuang nilai tak dikenal; data kosong tidak melempar', async () => {
    server.balasan['get /pmk/pengajuan'] = sukses({})
    expect((await pmkService.list({ mode: 'saya', page: 1, per_page: 20 })).items).toEqual([])

    server.balasan['get /pmk/pengajuan/31'] = sukses(barisPmk({ aksi_tersedia: ['setujui', 'hapus-semua'] }))
    expect((await pmkService.detail(31)).aksi_tersedia).toEqual(['setujui'])
  })

  it('opsi() menormalkan jenis, pegawai, tanggal, label berkas, can_create', async () => {
    server.balasan['get /pmk/opsi'] = sukses({
      jenis_pmk: ['Swasta', 'Negeri'],
      pegawai: { nip: '198001012005011001', nama: 'Budi' },
      tmt_cpns: '2005-01-01',
      tgl_awal_maks: '2004-12-31',
      berkas: { file_kontrak: 'Surat kontrak kerja' },
      can_create: '1',
    })

    const o = await pmkService.opsi('198001012005011001')

    expect(server.permintaan[0]?.params).toEqual({ nip: '198001012005011001' })
    expect(o.jenis_pmk).toEqual(['Swasta', 'Negeri'])
    expect(o.tgl_awal_maks).toBe('2004-12-31')
    expect(o.berkas.file_kontrak).toBe('Surat kontrak kerja')
    expect(o.can_create).toBe(true)
  })

  it('hitungMker() mengirim tanggal dan menormalkan hasil server', async () => {
    server.balasan['post /pmk/hitung-mker'] = sukses({ mker_th: '2', mker_bl: '5', peringatan: null })

    const h = await pmkService.hitungMker('2001-01-01', '2003-06-30')

    expect(h).toEqual({ mker_th: 2, mker_bl: 5, peringatan: null })
    expect(JSON.parse(String(server.permintaan[0]?.data))).toEqual({ tgl_awal: '2001-01-01', tgl_akhir: '2003-06-30' })
  })

  it('create()/update() mengirim multipart: isian, 4 kolom berkas, dan _method=PUT', async () => {
    server.balasan['post /pmk/pengajuan'] = sukses(barisPmk())
    server.balasan['post /pmk/pengajuan/31'] = sukses(barisPmk())
    const isian = { jenis_pmk: 'Swasta', instansi: 'PT Contoh', tgl_awal: '2001-01-01', tgl_akhir: '2003-06-30', mker_th: '2', mker_bl: '5' }
    const pdf = (n: string) => new File(['%PDF'], n, { type: 'application/pdf' })

    const baru = await pmkService.create(isian, { file_kontrak: pdf('k.pdf'), file_slip_gaji: pdf('s.pdf') })
    const fd = server.permintaan[0]?.data as FormData
    expect(baru.id).toBe(31)
    expect(fd.get('instansi')).toBe('PT Contoh')
    expect(fd.get('mker_th')).toBe('2')
    expect((fd.get('file_kontrak') as File).name).toBe('k.pdf')
    expect(fd.has('file_paklaring')).toBe(false)

    await pmkService.update(31, isian, {})
    expect((server.permintaan[1]?.data as FormData).get('_method')).toBe('PUT')
  })

  it('process() mengirim aksi (+ alasan bila ada) dan tidak mengharapkan isi data', async () => {
    server.balasan['post /pmk/pengajuan/31/process'] = sukses({ processed: true })

    await pmkService.process(31, 'proses')
    await pmkService.process(31, 'tolak', 'Berkas tidak lengkap')

    expect(JSON.parse(String(server.permintaan[0]?.data))).toEqual({ aksi: 'proses' })
    expect(JSON.parse(String(server.permintaan[1]?.data))).toEqual({ aksi: 'tolak', reason_note: 'Berkas tidak lengkap' })
  })

  it('process() 422 status final diteruskan sebagai ApiError', async () => {
    server.balasan['post /pmk/pengajuan/31/process'] = { status: 422, body: { status: 'error', message: 'Validasi gagal.', errors: { status: ['Pengajuan sudah final.'] } } }

    await expect(pmkService.process(31, 'setujui')).rejects.toMatchObject({ status: 422, errors: { status: ['Pengajuan sudah final.'] } })
  })

  it('berkas() meminta blob ke pmk/pengajuan/{id}/berkas/{kolom} dan membaca nama dari Content-Disposition', async () => {
    server.balasan['get /pmk/pengajuan/31/berkas/file_kontrak'] = {
      status: 200,
      body: new Blob(['%PDF'], { type: 'application/pdf' }),
      headers: { 'content-disposition': 'attachment; filename="pmk_31_file_kontrak.pdf"' },
    }

    const b = await pmkService.berkas(31, 'file_kontrak')

    expect(server.permintaan[0]?.responseType).toBe('blob')
    expect(b.nama).toBe('pmk_31_file_kontrak.pdf')
  })

  it('riwayat() menormalkan item final + arsip; tambahArsip/hapus memakai rute yang benar', async () => {
    server.balasan['get /pmk'] = sukses({
      items: [{ id_riwayat_pmk: '5', nip: '1', no_sk: 'SK-1', tgl_sk: '2020-01-01', instansi: null, tgl_awal: null, tgl_akhir: null, mker_th: '1', mker_bl: '0', arsip: [{ id_attachment: '9', display_name: 'sk.pdf' }] }],
      total: '1',
      page: '1',
      per_page: '20',
      can_edit: '1',
    })
    server.balasan['post /pmk/5'] = sukses([{ id_attachment: '9', display_name: 'sk.pdf' }, { id_attachment: '10', display_name: 'sk2.pdf' }])
    server.balasan['delete /pmk/5/arsip/9'] = sukses({ deleted: true })
    server.balasan['delete /pmk/5'] = sukses({ deleted: true })

    const r = await pmkService.riwayat({ nip: '1', page: 1, per_page: 20 })
    expect(r.can_edit).toBe(true)
    expect(r.items[0]).toMatchObject({ id: 5, mker_th: 1, instansi: null, arsip: [{ id_attachment: 9, display_name: 'sk.pdf' }] })
    expect(server.permintaan[0]?.params).toEqual({ page: 1, per_page: 20, nip: '1' })

    const arsip = await pmkService.tambahArsip(5, new File(['%PDF'], 'sk2.pdf', { type: 'application/pdf' }))
    const fd = server.permintaan[1]?.data as FormData
    expect(arsip.map((a) => a.id_attachment)).toEqual([9, 10])
    expect(fd.get('_method')).toBe('PUT')
    expect((fd.get('arsip') as File).name).toBe('sk2.pdf')

    await pmkService.hapusArsip(5, 9)
    await pmkService.hapusRiwayat(5)
    expect(server.permintaan.map((p) => `${p.method} ${p.url}`).slice(2)).toEqual(['delete /pmk/5/arsip/9', 'delete /pmk/5'])
  })
})
