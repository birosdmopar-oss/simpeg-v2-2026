/**
 * Klien API Modul B sesuai README kontrak Kepegawaian (MAKE-002):
 * - pegawai: GET pegawai (params), GET pegawai/{nip}.
 * - riwayat: tambah = multipart (field kolom DDL + berkas[<id_riwayat>]) atau JSON bila tanpa berkas; ubah = PUT JSON,
 *   dengan berkas = POST multipart + _method=PUT; hapus; process { aksi, reason_note }.
 * - lampiran: GET ?id_riwayat=&id_entri=, POST multipart (berkas, id_riwayat, id_entri), unduh (blob), DELETE,
 *   ganti sementara = unggah baru lalu hapus lama.
 * - galat: urutan 401/404/403/422/501 → jenis keadaan; 422 dipecah ke kolom, berkas.<id>, dan umum.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/axios', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/axios')>()
  return { ...actual, api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }
})

import { api } from '@/lib/axios'

import pendidikan from '../riwayat/jenis/pendidikan'
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
})

describe('riwayatService', () => {
  it('daftar: GET /pegawai/{nip}/riwayat/{jenis}', async () => {
    get.mockResolvedValue({ data: [] })
    await riwayatService.list(NIP, 'pendidikan')
    expect(get).toHaveBeenCalledWith(`/pegawai/${NIP}/riwayat/pendidikan`)
  })

  it('payload JSON: nama kolom DDL, kosong → null, angka → number, teks dipangkas', () => {
    expect(toJsonPayload(pendidikan.fields, values)).toEqual({
      id_jenjang_pendidikan: '7',
      id_jurusan_pendidikan: null,
      institusi_pendidikan: 'Universitas Contoh',
      tgl_lulus: '2012-08-30',
      no_ijazah: null,
      glr_awal: null,
      glr_akhir: 'S.E.',
      no_sk_penc_glr: null,
      ipk: 3.5,
      nem: null,
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

  it('multipart: kolom kosong dikirim sebagai string kosong (representasi NULL belum ditetapkan — TODO kontrak)', () => {
    const sent = entries(toFormData(pendidikan.fields, values, {}))
    expect(sent).toContainEqual(['no_ijazah', ''])
  })

  it('ubah tanpa berkas: PUT JSON ke /{id}', async () => {
    put.mockResolvedValue({ data: {} })
    await riwayatService.update(NIP, 'pendidikan', 12, pendidikan.fields, values)
    expect(put).toHaveBeenCalledWith(`/pegawai/${NIP}/riwayat/pendidikan/12`, expect.objectContaining({ glr_akhir: 'S.E.' }))
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

  it('ganti (sementara): unggah baru dulu, baru hapus yang lama; unggah gagal → yang lama tidak dihapus', async () => {
    post.mockResolvedValueOnce({ data: { id_attachment: 7 } })
    del.mockResolvedValue({ data: null })
    await lampiranService.replace(NIP, target, 5, pdf('baru.pdf'))
    expect(post.mock.invocationCallOrder[0]).toBeLessThan(del.mock.invocationCallOrder[0])
    expect(del).toHaveBeenCalledWith(`/pegawai/${NIP}/lampiran/5`)

    del.mockClear()
    post.mockRejectedValueOnce(apiError(422, { berkas: ['Berkas harus pdf.'] }))
    await expect(lampiranService.replace(NIP, target, 5, pdf('salah.pdf'))).rejects.toBeTruthy()
    expect(del).not.toHaveBeenCalled()
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

  it('422 riwayat: errors.<kolom> → fields, errors["berkas.<id>"] → berkas, sisanya → general', () => {
    const error = apiError(422, {
      tgl_lulus: ['Tanggal lulus wajib diisi.'],
      'berkas.14': ['Lampiran wajib diunggah.'],
      kolom_asing: ['Tidak dikenal.'],
    })
    expect(splitValidationErrors(error, ['tgl_lulus'])).toEqual({
      fields: { tgl_lulus: 'Tanggal lulus wajib diisi.' },
      berkas: { 14: 'Lampiran wajib diunggah.' },
      general: ['Tidak dikenal.'],
    })
  })

  it('422 lampiran: errors.berkas → general; status selain 422 → kosong', () => {
    expect(splitValidationErrors(apiError(422, { berkas: ['Ukuran melebihi 5 MB.'] })).general).toEqual(['Ukuran melebihi 5 MB.'])
    expect(splitValidationErrors(apiError(403, { x: ['y'] }))).toEqual({ fields: {}, berkas: {}, general: [] })
  })
})
