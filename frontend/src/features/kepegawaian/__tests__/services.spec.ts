/**
 * Klien API Modul B sesuai README kontrak Kepegawaian (MAKE-002):
 * - pegawai: GET pegawai (params), GET pegawai/{nip}.
 * - riwayat: tambah = multipart (field kolom DDL + berkas[<id_riwayat>]) atau JSON bila tanpa berkas; ubah = PUT JSON,
 *   dengan berkas = POST multipart + _method=PUT; hapus; process { aksi, reason_note }.
 * - lampiran: GET ?id_riwayat=&id_entri=, POST multipart (berkas, id_riwayat, id_entri), unduh (blob), DELETE.
 * - galat: urutan 401/404/403/422/501 → jenis keadaan; 422 dipecah ke kolom, berkas.<id>, dan umum.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/axios', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/axios')>()
  return { ...actual, api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }
})

import { api } from '@/lib/axios'

import pendidikan from '../riwayat/jenis/pendidikan'
import { M, T } from '../riwayat/riwayat.config'
import { riwayatService, toFormData, toJsonPayload } from '../riwayat/riwayat.service'
import { describeApiError, splitValidationErrors } from '../services/apiErrors'
import { lampiranService } from '../services/lampiran.service'
import { pegawaiService } from '../services/pegawai.service'

import { apiError, NIP, pegawaiDetail } from './fixtures'

const get = vi.mocked(api.get)
const post = vi.mocked(api.post)
const put = vi.mocked(api.put)
const del = vi.mocked(api.delete)

beforeEach(() => {
  for (const fn of [get, post, put, del]) fn.mockReset()
})

const values = {
  id_jenjang_pendidikan: '7',
  id_jurusan_pendidikan: '',
  institusi_pendidikan: ' Universitas Contoh ',
  tgl_lulus: '2012-08-30',
  no_ijazah: '',
  glr_awal: '',
  glr_akhir: 'S.E.',
  no_sk_penc_glr: '',
  ipk: '3.5',
  nem: '',
  keterangan: '',
}

const pdf = (name: string) => new File(['%PDF-1.4'], name, { type: 'application/pdf' })

function entries(form: FormData): Array<[string, string]> {
  return [...form.entries()].map(([k, v]) => [k, typeof v === 'string' ? v : `file:${(v as File).name}`])
}

describe('pegawaiService', () => {
  it('list: GET /pegawai dengan page, per_page, search (kosong tidak dikirim)', async () => {
    get.mockResolvedValue({ data: { items: [], total: 0, page: 2, per_page: 25 } })
    await pegawaiService.list({ page: 2, per_page: 25, search: '' })
    expect(get).toHaveBeenCalledWith('/pegawai', { params: { page: 2, per_page: 25, search: undefined } })

    await pegawaiService.list({ page: 1, per_page: 10, search: 'budi' })
    expect(get).toHaveBeenLastCalledWith('/pegawai', { params: { page: 1, per_page: 10, search: 'budi' } })
  })

  it('detail: GET /pegawai/{nip} → kolom pegawai + tabs', async () => {
    const detail = pegawaiDetail([])
    get.mockResolvedValue({ data: detail })
    await expect(pegawaiService.detail(NIP)).resolves.toEqual(detail)
    expect(get).toHaveBeenCalledWith(`/pegawai/${NIP}`)
  })

  it('list: filter status/id_unit/id_satker/sort ikut dikirim bila diisi (MAKE-010)', async () => {
    get.mockResolvedValue({ data: { items: [], total: 0, page: 1, per_page: 10 } })
    await pegawaiService.list({ page: 1, per_page: 10, status: '1', id_satker: '12', sort: 'jabatan', order: 'desc' })
    expect(get).toHaveBeenCalledWith('/pegawai', { params: { page: 1, per_page: 10, status: '1', id_satker: '12', sort: 'jabatan', order: 'desc' } })
  })

  it('ubah biodata: PUT JSON tanpa foto; dengan foto POST multipart + _method=PUT + berkas foto', async () => {
    put.mockResolvedValue({ data: { jalur: 'pengajuan' } })
    await pegawaiService.updateBiodata(NIP, { no_hp: '0812' })
    expect(put).toHaveBeenCalledWith(`/pegawai/${NIP}/biodata`, { no_hp: '0812' })

    post.mockResolvedValue({ data: { jalur: 'langsung' } })
    const foto = new File(['x'], 'foto.png', { type: 'image/png' })
    await pegawaiService.updateBiodata(NIP, { no_hp: '0812' }, foto)
    const [url, body] = post.mock.calls[0] as [string, FormData]
    expect(url).toBe(`/pegawai/${NIP}/biodata`)
    expect(body.get('_method')).toBe('PUT')
    expect(body.get('no_hp')).toBe('0812')
    expect((body.get('foto') as File).name).toBe('foto.png')
  })

  it('foto: GET /foto sebagai blob; koreksi NIP: POST /koreksi-nip { nip_baru }', async () => {
    get.mockResolvedValue({ data: new Blob(['x']) })
    await pegawaiService.foto(NIP)
    expect(get).toHaveBeenCalledWith(`/pegawai/${NIP}/foto`, { responseType: 'blob' })

    post.mockResolvedValue({ data: { nip_baru: '1' } })
    await pegawaiService.koreksiNip(NIP, '199001012015011999')
    expect(post).toHaveBeenCalledWith(`/pegawai/${NIP}/koreksi-nip`, { nip_baru: '199001012015011999' })
  })
})

describe('riwayatService', () => {
  it('tabs: GET /pegawai/{nip}/riwayat → data.tabs (halaman riwayat sementara, MAKE-006)', async () => {
    const tabs = [{ jenis: 'kgb', label: 'Riwayat KGB', can_view: true, can_create: false, can_edit: false, can_delete: false, can_process: false }]
    get.mockResolvedValue({ data: { tabs } })
    await expect(riwayatService.tabs(NIP)).resolves.toEqual(tabs)
    expect(get).toHaveBeenCalledWith(`/pegawai/${NIP}/riwayat`)
  })

  it('pratinjau: GET /pegawai/{nip}/riwayat/{jenis}/pratinjau, query kosong tidak dikirim (MAKE-017)', async () => {
    get.mockResolvedValue({ data: { jabatan: 'Analis' } })
    await expect(riwayatService.pratinjau(NIP, 'ak', { tgl_pak: '2024-07-15' })).resolves.toEqual({ jabatan: 'Analis' })
    expect(get).toHaveBeenCalledWith(`/pegawai/${NIP}/riwayat/ak/pratinjau`, { params: { tgl_pak: '2024-07-15' } })
    await riwayatService.pratinjau(NIP, 'ak', { tgl_pak: '' })
    expect(get).toHaveBeenLastCalledWith(`/pegawai/${NIP}/riwayat/ak/pratinjau`, { params: {} })
  })

  it('daftar: GET /pegawai/{nip}/riwayat/{jenis}', async () => {
    get.mockResolvedValue({ data: [] })
    await riwayatService.list(NIP, 'pendidikan')
    expect(get).toHaveBeenCalledWith(`/pegawai/${NIP}/riwayat/pendidikan`)
  })

  it('payload JSON: nama kolom DDL, kosong → null, angka → number, teks dipangkas', () => {
    // Hanya isian konfigurasi (= field Definisi); kolom lain di nilai form (mis. glr_akhir) tidak ikut.
    expect(toJsonPayload(pendidikan.fields, values)).toEqual({
      id_jenjang_pendidikan: '7',
      id_bidang_pendidikan: null,
      bidang_pendidikan_lain: null,
      id_jurusan_pendidikan: null,
      jurusan_pendidikan_lain: null,
      institusi_pendidikan: 'Universitas Contoh',
      no_ijazah: null,
      tgl_lulus: '2012-08-30',
      no_sk_penc_glr: null,
      nem: null,
      ipk: 3.5,
      keterangan: null,
    })
  })

  it('tambah dengan berkas: POST multipart berisi kolom DDL + berkas[14], berkas[40]', async () => {
    post.mockResolvedValue({ data: { id_riwayat_pendidikan: 9 } })
    await riwayatService.create(NIP, 'pendidikan', pendidikan.fields, values, { 14: pdf('ijazah.pdf'), 40: pdf('transkrip.pdf') })

    const [url, body] = post.mock.calls[0]
    expect(url).toBe(`/pegawai/${NIP}/riwayat/pendidikan`)
    expect(body).toBeInstanceOf(FormData)
    const sent = entries(body as FormData)
    expect(sent).toContainEqual(['institusi_pendidikan', 'Universitas Contoh'])
    expect(sent).toContainEqual(['berkas[14]', 'file:ijazah.pdf'])
    expect(sent).toContainEqual(['berkas[40]', 'file:transkrip.pdf'])
    expect(sent.map(([k]) => k)).not.toContain('_method')
  })

  it('tambah tanpa berkas: POST JSON', async () => {
    post.mockResolvedValue({ data: {} })
    await riwayatService.create(NIP, 'pendidikan', pendidikan.fields, values)
    expect(post.mock.calls[0][1]).not.toBeInstanceOf(FormData)
    expect(post.mock.calls[0][1]).toMatchObject({ tgl_lulus: '2012-08-30', ipk: 3.5 })
  })

  it('multipart: kolom kosong dikirim sebagai string kosong (= NULL, kontrak engine MAKE-004)', () => {
    const sent = entries(toFormData(pendidikan.fields, values, {}))
    expect(sent).toContainEqual(['no_ijazah', ''])
  })

  it('multiselect (MAKE-018): JSON = array ID; multipart = kolom[] berulang, tanpa pilihan = kolom kosong', () => {
    const fields = [M('id_subrumpun_jabatan', 'Subrumpun', 'subrumpun-jabatan'), T('keterangan', 'Keterangan')]
    expect(toJsonPayload(fields, { id_subrumpun_jabatan: ['11', '12'], keterangan: '' })).toEqual({ id_subrumpun_jabatan: ['11', '12'], keterangan: null })
    expect(toJsonPayload(fields, { keterangan: 'x' })).toEqual({ id_subrumpun_jabatan: [], keterangan: 'x' })
    expect(entries(toFormData(fields, { id_subrumpun_jabatan: ['11', '12'] }, {}))).toEqual([
      ['id_subrumpun_jabatan[]', '11'],
      ['id_subrumpun_jabatan[]', '12'],
      ['keterangan', ''],
    ])
    expect(entries(toFormData(fields, { id_subrumpun_jabatan: [] }, {}))).toContainEqual(['id_subrumpun_jabatan', ''])
  })

  it('ubah tanpa berkas: PUT JSON ke /{id}', async () => {
    put.mockResolvedValue({ data: {} })
    await riwayatService.update(NIP, 'pendidikan', 12, pendidikan.fields, values)
    expect(put).toHaveBeenCalledWith(`/pegawai/${NIP}/riwayat/pendidikan/12`, expect.objectContaining({ institusi_pendidikan: 'Universitas Contoh' }))
    expect(post).not.toHaveBeenCalled()
  })

  it('ubah dengan berkas: POST multipart + _method=PUT (huruf besar) ke /{id}', async () => {
    post.mockResolvedValue({ data: {} })
    await riwayatService.update(NIP, 'pendidikan', 12, pendidikan.fields, values, { 39: pdf('gelar.pdf') })
    expect(put).not.toHaveBeenCalled()
    const [url, body] = post.mock.calls[0]
    expect(url).toBe(`/pegawai/${NIP}/riwayat/pendidikan/12`)
    const sent = entries(body as FormData)
    expect(sent[0]).toEqual(['_method', 'PUT'])
    expect(sent).toContainEqual(['berkas[39]', 'file:gelar.pdf'])
  })

  it('hapus: DELETE /{id}; process: POST /{id}/process { aksi, reason_note }', async () => {
    del.mockResolvedValue({ data: null })
    post.mockResolvedValue({ data: {} })
    await riwayatService.remove(NIP, 'kgb', 3)
    expect(del).toHaveBeenCalledWith(`/pegawai/${NIP}/riwayat/kgb/3`)

    await riwayatService.process(NIP, 'kgb', 3, { aksi: 'tolak', reason_note: 'SK tidak terbaca' })
    expect(post).toHaveBeenCalledWith(`/pegawai/${NIP}/riwayat/kgb/3/process`, { aksi: 'tolak', reason_note: 'SK tidak terbaca' })
  })
})

describe('lampiranService', () => {
  const target = { id_riwayat: 14, id_entri: 12 }

  it('daftar: GET /pegawai/{nip}/lampiran?id_riwayat=&id_entri= → baris document_attachment (kunci NIP)', async () => {
    const row = { id_attachment: 5, NIP, id_riwayat: 14, id_entri: '12', display_name: 'ijazah.pdf' }
    get.mockResolvedValue({ data: [row] })
    await expect(lampiranService.list(NIP, target)).resolves.toEqual([row])
    expect(get).toHaveBeenCalledWith(`/pegawai/${NIP}/lampiran`, { params: { id_riwayat: 14, id_entri: '12' } })
  })

  it('unggah: POST multipart berkas, id_riwayat, id_entri', async () => {
    post.mockResolvedValue({ data: { id_attachment: 6 } })
    await lampiranService.upload(NIP, target, pdf('ijazah.pdf'))
    const [url, body] = post.mock.calls[0]
    expect(url).toBe(`/pegawai/${NIP}/lampiran`)
    expect(entries(body as FormData)).toEqual([
      ['berkas', 'file:ijazah.pdf'],
      ['id_riwayat', '14'],
      ['id_entri', '12'],
    ])
  })

  it('unduh: GET /{id}/unduh sebagai blob; hapus: DELETE /{id}', async () => {
    const blob = new Blob(['x'])
    get.mockResolvedValue({ data: blob })
    del.mockResolvedValue({ data: null })
    await expect(lampiranService.download(NIP, 5)).resolves.toBe(blob)
    expect(get).toHaveBeenCalledWith(`/pegawai/${NIP}/lampiran/5/unduh`, { responseType: 'blob' })
    await lampiranService.remove(NIP, 5)
    expect(del).toHaveBeenCalledWith(`/pegawai/${NIP}/lampiran/5`)
  })

  it('belum ada fungsi ganti lampiran (semantik belum ditetapkan kontrak)', () => {
    expect('replace' in lampiranService).toBe(false)
  })
})

describe('apiErrors', () => {
  it.each([
    [401, 'unauthorized'],
    [403, 'forbidden'],
    [404, 'not-found'],
    [422, 'validation'],
    [501, 'unavailable'],
    [500, 'other'],
    [null, 'network'],
  ] as const)('status %s → %s', (status, kind) => {
    expect(describeApiError(apiError(status)).kind).toBe(kind)
  })

  it('pesan 403 tidak menyiratkan keberadaan NIP (NIP tak ada bagi role ber-lingkup juga 403)', () => {
    expect(describeApiError(apiError(403)).message).toBe('Anda tidak berhak mengakses data ini.')
  })

  it('422 riwayat: errors.<kolom> → fields, errors["berkas.<id>"] kode dikenal → berkas, sisanya → general', () => {
    const error = apiError(422, {
      tgl_lulus: ['Tanggal lulus wajib diisi.'],
      'berkas.14': ['Lampiran wajib diunggah.'],
      kolom_asing: ['Tidak dikenal.'],
    })
    expect(splitValidationErrors(error, ['tgl_lulus'], [14])).toEqual({
      fields: { tgl_lulus: 'Tanggal lulus wajib diisi.' },
      berkas: { 14: 'Lampiran wajib diunggah.' },
      general: ['Tidak dikenal.'],
    })
  })

  it('422 berkas.<id> untuk kode yang tidak ada di form → general (tidak hilang tanpa pesan)', () => {
    const error = apiError(422, { 'berkas.14': ['Lampiran wajib diunggah.'], 'berkas.39': ['Berkas terlalu besar.'] })
    expect(splitValidationErrors(error, [], [39])).toEqual({
      fields: {},
      berkas: { 39: 'Berkas terlalu besar.' },
      general: ['Lampiran wajib diunggah.'],
    })
    expect(splitValidationErrors(error).general).toEqual(['Lampiran wajib diunggah.', 'Berkas terlalu besar.'])
  })

  it('422 lampiran: errors.berkas → general; status selain 422 → kosong', () => {
    expect(splitValidationErrors(apiError(422, { berkas: ['Ukuran melebihi 5 MB.'] })).general).toEqual(['Ukuran melebihi 5 MB.'])
    expect(splitValidationErrors(apiError(403, { x: ['y'] }))).toEqual({ fields: {}, berkas: {}, general: [] })
  })
})
