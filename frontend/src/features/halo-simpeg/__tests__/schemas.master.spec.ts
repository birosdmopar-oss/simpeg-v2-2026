/**
 * Skema master Halo Simpeg vs fixture kontrak MAKE-070. Fixture di `fixtures/` = SALINAN contoh respons sintetis BE
 * `backend/tests/_support/HaloSimpeg/*.json` (README beku di `main` @56a700b1 (MAKE-070); tanpa `*.php`). Salin
 * ulang bila kontrak berubah lewat CR. Form: nilai batas PRD §7.3a (= batas lolos, batas+1 ditolak), pesan = teks BE.
 */
import { describe, expect, it } from 'vitest'
import type { z } from 'zod'

import { TEKS_GALAT_MASTER as T } from '../constants'
import {
  cariKandidatSchema,
  denganPeringatan,
  halaman,
  kandidatSchema,
  kategoriFormSchema,
  kategoriOpsiSchema,
  kategoriSchema,
  kontakAktifSchema,
  kontakFormSchema,
  kontakSchema,
  petugasFormSchema,
  petugasSayaSchema,
  petugasSchema,
} from '../schemas'
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
import petugasSayaNonaktif from './fixtures/petugas-saya-nonaktif.json'
import statusPatchPeringatan from './fixtures/status-patch-peringatan.json'
import statusPatch from './fixtures/status-patch.json'

const pesan = (r: z.SafeParseReturnType<unknown, unknown>): string[] => (r.success ? [] : r.error.issues.map((i) => i.message))

describe('skema respons master = fixture kontrak MAKE-070 (parse tanpa kehilangan field)', () => {
  it.each([
    ['kategori-daftar', halaman(kategoriSchema), kategoriDaftar],
    ['kategori-opsi', kategoriOpsiSchema.array(), kategoriOpsi],
    ['petugas-daftar', halaman(petugasSchema), petugasDaftar],
    ['kandidat', kandidatSchema, kandidat],
    ['kandidat-peringatan', kandidatSchema, kandidatPeringatan],
    ['petugas-saya-aktif', petugasSayaSchema, petugasSayaAktif],
    ['petugas-saya-nonaktif', petugasSayaSchema, petugasSayaNonaktif],
    ['petugas-saya-bukan', petugasSayaSchema, petugasSayaBukan],
    ['kontak-aktif', kontakAktifSchema.array(), kontakAktif],
    ['kontak-daftar', halaman(kontakSchema), kontakDaftar],
    ['status-patch', denganPeringatan(kategoriSchema), statusPatch],
    ['status-patch-peringatan', denganPeringatan(petugasSchema), statusPatchPeringatan],
    ['petugas-put-peringatan', denganPeringatan(petugasSchema), petugasPutPeringatan],
  ] as const)('%s', (_n, schema, fixture) => {
    expect(fixture.status).toBe('success')
    expect(schema.parse(fixture.data)).toEqual(fixture.data)
  })

  it('koersi PHP: angka/boolean sebagai string; status selain 1 → 0', () => {
    const baris = { ...kategoriDaftar.data.items[0], id: '5', status: '1', jumlah_petugas: '2', bisa_hapus: '0' }
    expect(kategoriSchema.parse(baris)).toMatchObject({ id: 5, status: 1, jumlah_petugas: 2, bisa_hapus: false })
    expect(kategoriSchema.parse({ ...baris, status: 2 }).status).toBe(0)
  })

  it('petugas bisa_hapus=false membawa alasan; peringatan ada pada PATCH dan PUT (S8)', () => {
    const p = halaman(petugasSchema).parse(petugasDaftar.data).items.find((x) => !x.bisa_hapus)
    expect(p?.alasan_tidak_bisa_hapus).toContain('petugas terakhir')
    expect(denganPeringatan(petugasSchema).parse(statusPatchPeringatan.data).peringatan).toContain('petugas aktif terakhir')
    expect(denganPeringatan(petugasSchema).parse(petugasPutPeringatan.data).peringatan).toContain('petugas aktif terakhir')
  })

  it('bukan petugas → semua false, kategori kosong', () => {
    expect(petugasSayaSchema.parse(petugasSayaBukan.data)).toEqual({ petugas: false, aktif: false, kategori: [], bisa_ekspor: false })
  })

  it('field wajib hilang → gagal parse', () => {
    const tanpaLabel: Record<string, unknown> = { ...petugasDaftar.data.items[0] }
    delete tanpaLabel.label
    expect(petugasSchema.safeParse(tanpaLabel).success).toBe(false)
  })
})

describe('skema form master (batas §7.3a, teks BE)', () => {
  const huruf = (n: number) => 'a'.repeat(n)

  it('kategori: wajib, 250 lolos, 251 ditolak; dihitung per code point sesudah trim', () => {
    expect(pesan(kategoriFormSchema.safeParse({ nama: '   ', status: 1 }))).toContain(T.namaKategoriWajib)
    expect(kategoriFormSchema.safeParse({ nama: huruf(250), status: 1 }).success).toBe(true)
    expect(pesan(kategoriFormSchema.safeParse({ nama: huruf(251), status: 1 }))).toContain(T.namaKategoriMaks)
    expect(kategoriFormSchema.safeParse({ nama: `  ${'😀'.repeat(250)}  `, status: 0 }).success).toBe(true)
  })

  it('petugas: akun & kategori wajib; email wajib/format/150; no_hp 20', () => {
    const sah = { id_user: 11, kategori: [1], email: 'a@contoh.test', no_hp: '', status: 1 as const }
    expect(petugasFormSchema.safeParse(sah).success).toBe(true)
    expect(pesan(petugasFormSchema.safeParse({ ...sah, id_user: null }))).toContain(T.akunWajib)
    expect(pesan(petugasFormSchema.safeParse({ ...sah, kategori: [] }))).toContain(T.kategoriMin)
    expect(pesan(petugasFormSchema.safeParse({ ...sah, email: '' }))).toEqual([T.emailWajib])
    expect(pesan(petugasFormSchema.safeParse({ ...sah, email: 'bukan-email' }))).toContain(T.emailFormat)
    const email150 = `${huruf(150 - '@contoh.test'.length)}@contoh.test`
    expect(petugasFormSchema.safeParse({ ...sah, email: email150 }).success).toBe(true)
    expect(pesan(petugasFormSchema.safeParse({ ...sah, email: `a${email150}` }))).toContain(T.emailMaks)
    expect(petugasFormSchema.safeParse({ ...sah, no_hp: '1'.repeat(20) }).success).toBe(true)
    expect(pesan(petugasFormSchema.safeParse({ ...sah, no_hp: '1'.repeat(21) }))).toContain(T.noHpMaks)
  })

  it('kontak: nama 150, nomor 20 wajib; email opsional berformat', () => {
    const sah = { nama: 'Helpdesk', kontak: '0812', email: '', status: 1 as const }
    expect(kontakFormSchema.safeParse(sah).success).toBe(true)
    expect(pesan(kontakFormSchema.safeParse({ ...sah, nama: '' }))).toContain(T.namaWajib)
    expect(kontakFormSchema.safeParse({ ...sah, nama: huruf(150) }).success).toBe(true)
    expect(pesan(kontakFormSchema.safeParse({ ...sah, nama: huruf(151) }))).toContain(T.namaMaks)
    expect(pesan(kontakFormSchema.safeParse({ ...sah, kontak: '' }))).toContain(T.kontakWajib)
    expect(kontakFormSchema.safeParse({ ...sah, kontak: '1'.repeat(20) }).success).toBe(true)
    expect(pesan(kontakFormSchema.safeParse({ ...sah, kontak: '1'.repeat(21) }))).toContain(T.kontakMaks)
    expect(pesan(kontakFormSchema.safeParse({ ...sah, email: 'x' }))).toContain(T.emailFormat)
  })

  it('kata kunci kandidat 2..50', () => {
    expect(pesan(cariKandidatSchema.safeParse(' a '))).toEqual([T.cariMin])
    expect(cariKandidatSchema.safeParse('ab').success).toBe(true)
    expect(cariKandidatSchema.safeParse(huruf(50)).success).toBe(true)
    expect(pesan(cariKandidatSchema.safeParse(huruf(51)))).toEqual([T.cariMaks])
  })
})
