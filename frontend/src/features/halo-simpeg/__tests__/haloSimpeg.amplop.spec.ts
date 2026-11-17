/**
 * Klien API master Halo Simpeg memakai `api` asli (interceptor amplop v2) dengan adapter tiruan — pelajaran amplop CR-059:
 * service tidak boleh membaca `res.data.data`. Fixture di `fixtures/` = SALINAN contoh respons sintetis BE
 * `backend/tests/_support/HaloSimpeg/*.json` (README beku MAKE-070 @cd706a31). Salin ulang bila kontrak berubah lewat CR.
 */
import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const server = vi.hoisted(() => ({
  balasan: { status: 200, body: {} as unknown },
  panggilan: [] as { method: string; url: string; params: unknown; body: unknown }[],
}))

vi.mock('@/lib/axios', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/axios')>()
  const adapter = async (config: InternalAxiosRequestConfig): Promise<AxiosResponse> => {
    server.panggilan.push({
      method: String(config.method),
      url: config.url ?? '',
      params: config.params ?? null,
      body: config.data ? JSON.parse(String(config.data)) : null,
    })
    const response: AxiosResponse = { status: server.balasan.status, statusText: 'x', headers: {}, config, data: server.balasan.body }
    if (server.balasan.status >= 400) throw new AxiosError('gagal', 'ERR_BAD_REQUEST', config, null, response)
    return response
  }
  return { ...actual, api: actual.createApiClient({ adapter }) }
})

import { isApiError } from '@/lib/axios'
import { galatPerKolom } from '@/shared/utils/galatKolom'

import { KontrakHaloError } from '../schemas'
import { kategoriHaloService, kodeGalat, kontakHaloService, petugasHaloService } from '../services/master.service'
import galat404 from './fixtures/galat-404-master.json'
import galat422Duplikat from './fixtures/galat-422-kategori-duplikat.json'
import galat422 from './fixtures/galat-422-master.json'
import galat422Terakhir from './fixtures/galat-422-petugas-terakhir.json'
import kandidatPeringatan from './fixtures/kandidat-peringatan.json'
import kandidat from './fixtures/kandidat.json'
import kategoriDaftar from './fixtures/kategori-daftar.json'
import kategoriOpsi from './fixtures/kategori-opsi.json'
import kontakAktif from './fixtures/kontak-aktif.json'
import kontakDaftar from './fixtures/kontak-daftar.json'
import petugasDaftar from './fixtures/petugas-daftar.json'
import petugasPutPeringatan from './fixtures/petugas-put-peringatan.json'
import petugasSayaAktif from './fixtures/petugas-saya-aktif.json'
import petugasSayaBukan from './fixtures/petugas-saya-bukan.json'
import statusPatchPeringatan from './fixtures/status-patch-peringatan.json'
import statusPatch from './fixtures/status-patch.json'

const jawab = (body: unknown, status = 200): void => {
  server.balasan = { status, body }
}

async function galatDari(p: Promise<unknown>): Promise<unknown> {
  return p.then(
    () => {
      throw new Error('seharusnya gagal')
    },
    (e: unknown) => e,
  )
}

const panggilan = () => server.panggilan.map((p) => `${p.method} ${p.url}`)

beforeEach(() => {
  server.panggilan = []
  jawab({ status: 'success', data: null })
})

describe('kategori (fixture kontrak MAKE-070)', () => {
  it('daftar Halaman<Kategori> dengan filter status/q/page; parameter kosong tidak dikirim', async () => {
    jawab(kategoriDaftar)
    await expect(kategoriHaloService.list({ status: 1, q: '  cuti ', page: 2, per_page: 20 })).resolves.toEqual(kategoriDaftar.data)
    await kategoriHaloService.list({ status: null, q: '  ' })
    expect(server.panggilan[0]).toMatchObject({ method: 'get', url: '/halo-simpeg/kategori', params: { status: 1, q: 'cuti', page: 2, per_page: 20 } })
    expect(server.panggilan[1]?.params).toEqual({})
  })

  it('opsi aktif (form tiket) → array', async () => {
    jawab(kategoriOpsi)
    await expect(kategoriHaloService.opsi()).resolves.toEqual(kategoriOpsi.data)
    expect(panggilan()).toEqual(['get /halo-simpeg/kategori/opsi'])
  })

  it('tambah/ubah memakai nama legacy question_category + status; PATCH status → baris + peringatan null; DELETE → id', async () => {
    const baris = { status: 'success', data: kategoriDaftar.data.items[0] }
    jawab(baris, 201)
    await kategoriHaloService.tambah({ nama: '  Cuti  ', status: 1 })
    jawab(baris)
    await kategoriHaloService.ubah(1, { nama: 'Cuti', status: 0 })
    jawab(statusPatch)
    await expect(kategoriHaloService.setStatus(1, 0)).resolves.toEqual(statusPatch.data)
    jawab({ status: 'success', data: { id: 1 } })
    await expect(kategoriHaloService.hapus(1)).resolves.toBe(1)
    expect(server.panggilan.map((p) => [p.method, p.url, p.body])).toEqual([
      ['post', '/halo-simpeg/kategori', { question_category: 'Cuti', status: 1 }],
      ['put', '/halo-simpeg/kategori/1', { question_category: 'Cuti', status: 0 }],
      ['patch', '/halo-simpeg/kategori/1/status', { status: 0 }],
      ['delete', '/halo-simpeg/kategori/1', null],
    ])
  })

  it('422 duplikat → ApiError + code kategori_duplikat + errors.question_category', async () => {
    jawab(galat422Duplikat, 422)
    const e = await galatDari(kategoriHaloService.tambah({ nama: 'Cuti', status: 1 }))
    expect(isApiError(e) && e.status).toBe(422)
    expect(kodeGalat(e)).toBe('kategori_duplikat')
    expect(galatPerKolom(e)).toEqual({ question_category: 'Kategori sudah terdaftar.' })
  })

  it('422 per kolom tanpa code → kodeGalat null; 404 → pesan BE', async () => {
    jawab(galat422, 422)
    const e = await galatDari(kategoriHaloService.tambah({ nama: '', status: 1 }))
    expect(kodeGalat(e)).toBeNull()
    expect(isApiError(e) && e.message).toBe('Nama kategori wajib diisi.')
    jawab(galat404, 404)
    const e404 = await galatDari(kategoriHaloService.hapus(99))
    expect(isApiError(e404) && [e404.status, e404.message]).toEqual([404, 'Data tidak ditemukan.'])
  })

  it('respons di luar kontrak → KontrakHaloError', async () => {
    jawab({ status: 'success', data: { items: [{ id: 1 }], total: 1, page: 1, per_page: 20 } })
    await expect(kategoriHaloService.list()).rejects.toBeInstanceOf(KontrakHaloError)
  })
})

describe('petugas (fixture kontrak MAKE-070)', () => {
  it('daftar membawa bisa_hapus + alasan', async () => {
    jawab(petugasDaftar)
    const r = await petugasHaloService.list()
    expect(r).toEqual(petugasDaftar.data)
    expect(r.items.find((p) => !p.bisa_hapus)?.alasan_tidak_bisa_hapus).not.toBeNull()
  })

  it('kandidat: GET ?search= → {items, peringatan} (S1)', async () => {
    jawab(kandidat)
    await expect(petugasHaloService.kandidat(' adm ')).resolves.toEqual(kandidat.data)
    expect(server.panggilan[0]).toMatchObject({ url: '/halo-simpeg/petugas/kandidat', params: { search: 'adm' } })
    jawab(kandidatPeringatan)
    await expect(petugasHaloService.kandidat('adm')).resolves.toMatchObject({ peringatan: kandidatPeringatan.data.peringatan })
  })

  it('petugas/saya: aktif dan bukan petugas', async () => {
    jawab(petugasSayaAktif)
    await expect(petugasHaloService.saya()).resolves.toEqual(petugasSayaAktif.data)
    jawab(petugasSayaBukan)
    await expect(petugasHaloService.saya()).resolves.toEqual({ petugas: false, aktif: false, kategori: [], bisa_ekspor: false })
    expect(panggilan()).toEqual(['get /halo-simpeg/petugas/saya', 'get /halo-simpeg/petugas/saya'])
  })

  it('tambah/ubah: body legacy {id_user, category[], email, no_hp, status}; PUT dan PATCH membawa peringatan (S8)', async () => {
    const form = { id_user: 12, kategori: [4], email: ' pegawai@contoh.test ', no_hp: ' ', status: 1 as const }
    jawab({ status: 'success', data: petugasDaftar.data.items[1] }, 201)
    await petugasHaloService.tambah(form)
    jawab(petugasPutPeringatan)
    await expect(petugasHaloService.ubah(2, { ...form, kategori: [] })).resolves.toMatchObject({ peringatan: petugasPutPeringatan.data.peringatan })
    jawab(statusPatchPeringatan)
    await expect(petugasHaloService.setStatus(2, 0)).resolves.toEqual(statusPatchPeringatan.data)
    expect(server.panggilan.map((p) => [p.method, p.url, p.body])).toEqual([
      ['post', '/halo-simpeg/petugas', { id_user: 12, category: [4], email: 'pegawai@contoh.test', no_hp: '', status: 1 }],
      ['put', '/halo-simpeg/petugas/2', { id_user: 12, category: [], email: 'pegawai@contoh.test', no_hp: '', status: 1 }],
      ['patch', '/halo-simpeg/petugas/2/status', { status: 0 }],
    ])
  })

  it('hapus petugas terakhir → 422 code petugas_terakhir dengan pesan BE', async () => {
    jawab(galat422Terakhir, 422)
    const e = await galatDari(petugasHaloService.hapus(2))
    expect(kodeGalat(e)).toBe('petugas_terakhir')
    expect(isApiError(e) && e.message).toBe(galat422Terakhir.message)
  })
})

describe('kontak (fixture kontrak MAKE-070)', () => {
  it('kontak/aktif (array) dan kontak (Halaman)', async () => {
    jawab(kontakAktif)
    await expect(kontakHaloService.aktif()).resolves.toEqual(kontakAktif.data)
    jawab(kontakDaftar)
    await expect(kontakHaloService.list({ status: 0 })).resolves.toEqual(kontakDaftar.data)
    expect(panggilan()).toEqual(['get /halo-simpeg/kontak/aktif', 'get /halo-simpeg/kontak'])
  })

  it('tambah memakai nama legacy {name, contact, email, status}', async () => {
    jawab({ status: 'success', data: kontakDaftar.data.items[0] }, 201)
    await kontakHaloService.tambah({ nama: ' Helpdesk ', kontak: ' 0812 ', email: '', status: 1 })
    expect(server.panggilan[0]?.body).toEqual({ name: 'Helpdesk', contact: '0812', email: '', status: 1 })
  })
})
