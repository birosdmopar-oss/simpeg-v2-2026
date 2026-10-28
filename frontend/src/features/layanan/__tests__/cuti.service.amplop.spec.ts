/**
 * Paket 1 M7 (MAKE-026) — cutiService dengan KLIEN ASLI (`createApiClient` + interceptor `unwrapEnvelope`) dan respons
 * berbentuk backend nyata `{status:'success', data:{...}}` dengan angka/flag berupa STRING (MySQLi). Spec halaman me-mock
 * seluruh service, jadi amplop yang salah (`res.data.data`) hanya tertangkap di sini (pelajaran CR-059).
 */
import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

type Balasan = { status: number; body: unknown }

const server = vi.hoisted(() => ({
  balasan: {} as Record<string, { status: number; body: unknown }>,
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

import { cutiService } from '../services/cuti.service'

const sukses = (data: unknown): Balasan => ({ status: 200, body: { status: 'success', data } })

const barisCuti = (extra: Record<string, unknown> = {}) => ({
  id_riwayat_cuti: '12',
  nip: '198001012005011001',
  nama: 'Budi Santoso',
  id_jenis_cuti: '1',
  nama_jenis_cuti: 'Cuti Tahunan',
  tgl_mulai: '2026-11-04',
  tgl_akhir: '2026-11-06',
  lama_cuti: '3',
  status: '0',
  keterangan: 'Keperluan keluarga besar',
  no_telp_saat_cuti: '08123456789',
  alamat_saat_cuti: 'Jl. Uji No. 1',
  nip_atasan_langsung: '197001012000011001',
  nama_atasan_langsung: 'Atasan Uji',
  nip_yang_menyetujui: null,
  nama_yang_menyetujui: null,
  label_status: 'Menunggu Atasan',
  nada_status: 'warning',
  tahap: [{ kode: 'diajukan', label: 'Diajukan', state: 'done', nama_petugas: 'Budi Santoso' }],
  aksi: { ubah: '0', hapus: '1', proses: '1', tangguhkan: '1', tunda: '0', esign: '0', unduh_surat: '0', proses_atas_nama: '0' },
  ada_lampiran: '0',
  ada_surat: '0',
  ...extra,
})

beforeEach(() => {
  server.balasan = {}
  server.permintaan = []
})

describe('cutiService — amplop backend nyata lewat klien asli', () => {
  it('list() membuka amplop, menormalkan string, dan mengirim parameter', async () => {
    server.balasan['get /cuti'] = sukses({ items: [barisCuti()], total: '1', page: '1', per_page: '20', mode: 'persetujuan', can_create: '0' })

    const hasil = await cutiService.list({ mode: 'persetujuan', page: 1, per_page: 20, status: '0', search: 'budi' })

    expect(hasil.total).toBe(1)
    expect(hasil.mode).toBe('persetujuan')
    expect(hasil.can_create).toBe(false)
    const item = hasil.items[0]!
    expect(item.id).toBe(12)
    expect(item.lama_cuti).toBe(3)
    expect(item.status).toBe(0)
    expect(item.id_jenis_cuti).toBe(1)
    expect(item.aksi).toEqual({ ubah: false, hapus: true, proses: true, tangguhkan: true, tunda: false, esign: false, unduh_surat: false, proses_atas_nama: false })
    expect(item.nada_status).toBe('warning')
    expect(item.tahap[0]?.label).toBe('Diajukan')
    expect(item.nama_yang_menyetujui).toBeNull()
    expect(server.permintaan[0]?.params).toEqual({ mode: 'persetujuan', page: 1, per_page: 20, status: '0', search: 'budi' })
  })

  it('detail() memuat lampiran, surat, ketua tim, dan catatan approver', async () => {
    server.balasan['get /cuti/12'] = sukses(
      barisCuti({
        status: '1',
        ketua_tim_detail: [{ nip: '1', nama: 'Ketua Satu' }],
        lampiran: [{ id_attachment: '5', display_name: 'bukti.pdf' }],
        surat: [{ id_attachment: '6', jenis: 'tte', display_name: 'surat.pdf', created_at: '2026-11-03 02:00:00' }],
        riwayat_approval: { keterangan_petugas_approval: null, reason_note_al: 'Disetujui', reason_note_pyb: null },
      }),
    )

    const d = await cutiService.detail(12)

    expect(d.status).toBe(1)
    expect(d.ketua_tim_detail).toEqual([{ nip: '1', nama: 'Ketua Satu' }])
    expect(d.lampiran).toEqual([{ id_attachment: 5, display_name: 'bukti.pdf' }])
    expect(d.surat[0]).toMatchObject({ id_attachment: 6, jenis: 'tte' })
    expect(d.riwayat_approval.reason_note_al).toBe('Disetujui')
    expect(d.riwayat_approval.reason_note_pyb).toBeNull()
  })

  it('process() mengirim body aksi + alasan + atas_nama dan mengembalikan item terbaru', async () => {
    server.balasan['post /cuti/12/process'] = sukses(barisCuti({ status: '2', label_status: 'Ditolak', nada_status: 'danger' }))

    const item = await cutiService.process(12, { aksi: 'tolak', reason_note: 'Kuota habis', atas_nama: true })

    expect(item.status).toBe(2)
    expect(item.label_status).toBe('Ditolak')
    expect(JSON.parse(String(server.permintaan[0]?.data))).toEqual({ aksi: 'tolak', reason_note: 'Kuota habis', atas_nama: true })
  })

  it('pengingat() dan tunda() membaca isi data, bukan data.data', async () => {
    server.balasan['get /cuti/pengingat'] = sukses({ items: [barisCuti({ aksi: { tunda: '1' } })], total: '1' })
    server.balasan['post /cuti/12/tunda'] = sukses(barisCuti())

    const p = await cutiService.pengingat()
    expect(p.total).toBe(1)
    expect(p.items[0]?.aksi.tunda).toBe(true)
    expect((await cutiService.tunda(12)).id).toBe(12)
  })

  it('galat 422 diteruskan sebagai ApiError dengan errors per kolom', async () => {
    server.balasan['post /cuti/12/process'] = { status: 422, body: { status: 'error', message: 'Validasi gagal.', errors: { reason_note: ['Alasan ditolak tidak boleh kosong.'] } } }

    await expect(cutiService.process(12, { aksi: 'tolak' })).rejects.toMatchObject({ status: 422, errors: { reason_note: ['Alasan ditolak tidak boleh kosong.'] } })
  })

  it('opsi() menormalkan jenis, pejabat, saldo, dan ketua tim (string MySQLi)', async () => {
    server.balasan['get /cuti/opsi'] = sukses({
      jenis_cuti: [
        { id_jenis_cuti: '1', nama_jenis_cuti: 'Cuti Tahunan', pakai_durasi: '0', lampiran_wajib: '0' },
        { id_jenis_cuti: '3', nama_jenis_cuti: 'Cuti Sakit', pakai_durasi: false, lampiran_wajib: true },
      ],
      atasan_langsung: { nip: '197', nama: 'Atasan', jabatan: 'Kabag', bisa_memproses: '1' },
      pyb: [{ nip: '196', nama: 'Pejabat', jabatan: null, bisa_memproses: '0' }],
      langkah_tunggal: '0',
      eselon_i: '0',
      saldo: { tahun: '2026', sisa_cuti: '12', sisa_cuti_n1: '6', sisa_cuti_n2: '4', saldo_tahun_ini: '22', saldo_tahun_depan: '0' },
      tgl_akhir_maks: '2027-12-31',
      ketua_tim: [{ nip: '111', nama: 'Ketua' }],
      can_create: '1',
    })

    const o = await cutiService.opsi('198001012005011001')

    expect(server.permintaan[0]?.params).toEqual({ nip: '198001012005011001' })
    expect(o.jenis_cuti).toEqual([
      { id_jenis_cuti: 1, nama_jenis_cuti: 'Cuti Tahunan', pakai_durasi: false, lampiran_wajib: false },
      { id_jenis_cuti: 3, nama_jenis_cuti: 'Cuti Sakit', pakai_durasi: false, lampiran_wajib: true },
    ])
    expect(o.atasan_langsung).toMatchObject({ nip: '197', bisa_memproses: true })
    expect(o.pyb[0]).toMatchObject({ jabatan: null, bisa_memproses: false })
    expect(o.saldo).toMatchObject({ tahun: 2026, sisa_cuti_n2: 4, saldo_tahun_ini: 22 })
    expect(o.can_create).toBe(true)
    expect(o.eselon_i).toBe(false)
  })

  it('hitungLama() membuka amplop dan menormalkan kuota, hari libur, dan tgl_akhir', async () => {
    server.balasan['post /cuti/hitung-lama'] = sukses({
      lama_cuti: '3',
      satuan_lama_cuti: 'Hari',
      tgl_akhir: null,
      hari_libur: [{ tanggal: '2026-11-05', keterangan: 'Libur' }],
      kuota: { tahun: '2026', sisa_cuti: '12', sisa_cuti_n1: '6', sisa_cuti_n2: '4', saldo_tahun_ini: '19', menunggu_tahun_ini: '1', diajukan_tahun_ini: '3', saldo_tahun_depan: '0', menunggu_tahun_depan: '0', diajukan_tahun_depan: '0', sisa_setelah_tahun_ini: '15', sisa_setelah_tahun_depan: '-2', cukup: '0', pesan: 'Tidak cukup' },
    })

    const h = await cutiService.hitungLama({ id_jenis_cuti: 1, tgl_mulai: '2026-11-04', tgl_akhir: '2026-11-06' })

    expect(h.lama_cuti).toBe(3)
    expect(h.tgl_akhir).toBeNull()
    expect(h.hari_libur).toEqual([{ tanggal: '2026-11-05', keterangan: 'Libur' }])
    expect(h.kuota).toMatchObject({ tahun: 2026, sisa_cuti_n1: 6, saldo_tahun_ini: 19, sisa_setelah_tahun_ini: 15, sisa_setelah_tahun_depan: -2, cukup: false, pesan: 'Tidak cukup' })
    expect(JSON.parse(String(server.permintaan[0]?.data))).toEqual({ id_jenis_cuti: 1, tgl_mulai: '2026-11-04', tgl_akhir: '2026-11-06' })
  })

  it('hitungLama() jenis non-Tahunan: kuota null tetap null', async () => {
    server.balasan['post /cuti/hitung-lama'] = sukses({ lama_cuti: '31', satuan_lama_cuti: 'Hari', tgl_akhir: '2026-12-03', hari_libur: [], kuota: null })

    const h = await cutiService.hitungLama({ id_jenis_cuti: 2, tgl_mulai: '2026-11-04', durasi: 1 })

    expect(h.kuota).toBeNull()
    expect(h.tgl_akhir).toBe('2026-12-03')
  })

  it('create()/update() mengirim multipart: ketua_tim[] berulang, file_bukti, dan _method=PUT', async () => {
    server.balasan['post /cuti'] = sukses(barisCuti())
    server.balasan['post /cuti/12'] = sukses(barisCuti())
    const isian = {
      id_jenis_cuti: '1',
      tgl_mulai: '2026-11-04',
      tgl_akhir: '2026-11-06',
      no_telp_saat_cuti: '0812',
      alamat_saat_cuti: 'Jl. Uji',
      keterangan: 'Keperluan keluarga besar',
      ketua_tim: ['111', '222'],
    }
    const berkas = new File(['%PDF'], 'bukti.pdf', { type: 'application/pdf' })

    const dibuat = await cutiService.create(isian, berkas)
    const fdBuat = server.permintaan[0]?.data as FormData
    expect(dibuat.id).toBe(12)
    expect(fdBuat.getAll('ketua_tim[]')).toEqual(['111', '222'])
    expect(fdBuat.get('id_jenis_cuti')).toBe('1')
    expect((fdBuat.get('file_bukti') as File).name).toBe('bukti.pdf')
    expect(fdBuat.has('lama_cuti')).toBe(false)

    await cutiService.update(12, isian, null)
    const fdUbah = server.permintaan[1]?.data as FormData
    expect(fdUbah.get('_method')).toBe('PUT')
    expect(fdUbah.has('file_bukti')).toBe(false)
  })

  it('esign() mengirim passphrase hanya di body JSON (bukan URL/query) dan mengembalikan item terbaru', async () => {
    server.balasan['post /cuti/12/esign'] = sukses(barisCuti({ status: '1', label_status: 'Disetujui', nada_status: 'success', aksi: { esign: '0', unduh_surat: '1' } }))

    const item = await cutiService.esign(12, 'rahasia-Passphrase-123')

    const req = server.permintaan[0]!
    expect(req.url).toBe('/cuti/12/esign')
    expect(req.params).toBeUndefined()
    expect(JSON.parse(String(req.data))).toEqual({ passphrase: 'rahasia-Passphrase-123' })
    expect(item.id).toBe(12)
    expect(item.aksi.esign).toBe(false)
    expect(item.aksi.unduh_surat).toBe(true)
  })

  it('esign() 422: galat per kolom passphrase/nip/status diteruskan tanpa membuka passphrase', async () => {
    server.balasan['post /cuti/12/esign'] = {
      status: 422,
      body: { status: 'error', message: 'Validasi gagal.', errors: { passphrase: ['Passphrase tidak sesuai.'], nip: ['Data pejabat tidak terdaftar.'] } },
    }

    await expect(cutiService.esign(12, 'x')).rejects.toMatchObject({ status: 422, errors: { passphrase: ['Passphrase tidak sesuai.'], nip: ['Data pejabat tidak terdaftar.'] } })
  })

  it('data kosong/rusak tidak melempar TypeError', async () => {
    server.balasan['get /cuti'] = sukses({})

    const hasil = await cutiService.list({ mode: 'saya', page: 1, per_page: 20 })

    expect(hasil.items).toEqual([])
    expect(hasil.mode).toBe('saya')
    expect(hasil.total).toBe(0)
  })
})
