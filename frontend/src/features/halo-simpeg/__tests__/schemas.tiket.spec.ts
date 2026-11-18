/**
 * Skema tiket Halo Simpeg vs fixture kontrak MAKE-071 (SALINAN `backend/tests/_support/HaloSimpeg/*.json`, README beku di
 * `main` @64d68377). Fixture hanya menjamin BENTUK; kemungkinan `null` (snapshot, jabatan/satker, ditutup_oleh_label,
 * pesan_terakhir, kategori, lampiran) diuji di sini dengan data sintetis menurut README.
 */
import { describe, expect, it } from 'vitest'
import type { z } from 'zod'

import { TEKS_GALAT_TIKET as TT } from '../constants'
import {
  balasFormSchema,
  halaman,
  hapusSchema,
  hasilBalasSchema,
  ringkasanSchema,
  tiketBaruFormSchema,
  tiketBarisSchema,
  tiketDetailSchema,
} from '../schemas'
import baca from './fixtures/baca.json'
import tiketBalas from './fixtures/tiket-balas.json'
import tiketBuat from './fixtures/tiket-buat.json'
import daftarPemilik from './fixtures/tiket-daftar-pemilik.json'
import daftarPetugas from './fixtures/tiket-daftar-petugas.json'
import daftarRole1 from './fixtures/tiket-daftar-role1.json'
import detailDitutup from './fixtures/tiket-detail-ditutup.json'
import detailPemilik from './fixtures/tiket-detail-pemilik.json'
import detailPetugas from './fixtures/tiket-detail-petugas.json'
import detailRole1 from './fixtures/tiket-detail-role1.json'
import detailTanpaJabatan from './fixtures/tiket-detail-tanpa-jabatan.json'
import detailTanpaKategori from './fixtures/tiket-detail-tanpa-kategori.json'
import tiketHapus from './fixtures/tiket-hapus.json'
import tiketRingkasan from './fixtures/tiket-ringkasan.json'
import tiketTutup from './fixtures/tiket-tutup.json'

const pesan = (r: z.SafeParseReturnType<unknown, unknown>): string[] => (r.success ? [] : r.error.issues.map((i) => i.message))

describe('skema tiket = fixture kontrak MAKE-071', () => {
  it.each([
    ['tiket-daftar-pemilik', halaman(tiketBarisSchema), daftarPemilik],
    ['tiket-daftar-petugas', halaman(tiketBarisSchema), daftarPetugas],
    ['tiket-daftar-role1', halaman(tiketBarisSchema), daftarRole1],
    ['tiket-detail-pemilik', tiketDetailSchema, detailPemilik],
    ['tiket-detail-petugas', tiketDetailSchema, detailPetugas],
    ['tiket-detail-role1', tiketDetailSchema, detailRole1],
    ['tiket-detail-tanpa-kategori', tiketDetailSchema, detailTanpaKategori],
    ['tiket-detail-tanpa-jabatan', tiketDetailSchema, detailTanpaJabatan],
    ['tiket-detail-ditutup', tiketDetailSchema, detailDitutup],
    ['tiket-buat', tiketDetailSchema, tiketBuat],
    ['tiket-balas', hasilBalasSchema, tiketBalas],
    ['tiket-tutup', tiketBarisSchema, tiketTutup],
    ['tiket-hapus', hapusSchema, tiketHapus],
    ['tiket-ringkasan', ringkasanSchema, tiketRingkasan],
    ['baca', ringkasanSchema, baca],
  ] as const)('%s', (_n, schema, fixture) => {
    expect(fixture.status).toBe('success')
    expect(schema.parse(fixture.data)).toEqual(fixture.data)
  })

  it('ringkasan: badge > total (pengingat 24 jam pada tiket sudah dibaca)', () => {
    const r = ringkasanSchema.parse(tiketRingkasan.data)
    expect(r.badge).toBeGreaterThan(r.total)
  })

  it('tiket NULL: kategori null, bisa_balas false; tanpa jabatan: snapshot.jabatan/satker null', () => {
    const t = tiketDetailSchema.parse(detailTanpaKategori.data)
    expect(t.kategori).toBeNull()
    expect(t.bisa_balas).toBe(false)
    const j = tiketDetailSchema.parse(detailTanpaJabatan.data)
    expect(j.snapshot?.jabatan).toBeNull()
    expect(j.snapshot?.satker).toBeNull()
  })
})

describe('nullability menurut README (data sintetis)', () => {
  const baris = daftarPemilik.data.items[0]!
  const detail = detailPetugas.data

  it('pesan_terakhir null (tiket tanpa pesan) dan kategori null diterima', () => {
    expect(tiketBarisSchema.parse({ ...baris, pesan_terakhir: null }).pesan_terakhir).toBeNull()
    expect(tiketBarisSchema.safeParse({ ...baris, kategori: null }).success).toBe(true)
  })

  it('detail: snapshot, pengingat, ditutup_oleh_label boleh null (pemilik / Closed impor)', () => {
    const r = tiketDetailSchema.parse({ ...detail, status: 'closed', snapshot: null, pengingat: null, ditutup_oleh_label: null })
    expect([r.snapshot, r.pengingat, r.ditutup_oleh_label]).toEqual([null, null, null])
  })

  it('lampiran.ukuran harus null (T4); status selain open/closed ditolak', () => {
    const p = detail.pesan[0]!
    const lampiranBerukuran = { ...p, lampiran: { nama: 'x', tipe: 'application/pdf', ukuran: 10 } }
    expect(tiketDetailSchema.safeParse({ ...detail, pesan: [lampiranBerukuran] }).success).toBe(false)
    expect(tiketBarisSchema.safeParse({ ...baris, status: 'pending' }).success).toBe(false)
  })
})

describe('skema form tiket (batas §7.3a, teks BE)', () => {
  const huruf = (n: number) => 'a'.repeat(n)
  const sah = { id_kategori: 1, subjek: 'Cuti', pesan: 'Halo', arsip: null }

  it('tiket baru: kategori wajib; subjek 250 lolos / 251 ditolak; pesan 5.000 lolos / 5.001 ditolak', () => {
    expect(tiketBaruFormSchema.safeParse(sah).success).toBe(true)
    expect(pesan(tiketBaruFormSchema.safeParse({ ...sah, id_kategori: null }))).toContain(TT.kategoriWajib)
    expect(pesan(tiketBaruFormSchema.safeParse({ ...sah, subjek: '  ' }))).toContain(TT.subjekWajib)
    expect(tiketBaruFormSchema.safeParse({ ...sah, subjek: huruf(250) }).success).toBe(true)
    expect(pesan(tiketBaruFormSchema.safeParse({ ...sah, subjek: huruf(251) }))).toContain(TT.subjekMaks)
    expect(tiketBaruFormSchema.safeParse({ ...sah, pesan: huruf(5000) }).success).toBe(true)
    expect(pesan(tiketBaruFormSchema.safeParse({ ...sah, pesan: huruf(5001) }))).toContain(TT.pesanMaks)
  })

  it('balas: pesan wajib, batas 5.000 code point sesudah trim', () => {
    expect(pesan(balasFormSchema.safeParse({ pesan: ' ', arsip: null, ingatkan_24_jam: false }))).toEqual([TT.pesanWajib])
    expect(balasFormSchema.safeParse({ pesan: ` ${'😀'.repeat(5000)} `, arsip: null, ingatkan_24_jam: true }).success).toBe(true)
  })
})
