/**
 * Skema Zod helpdesk Halo Simpeg — bagian master (README kontrak beku MAKE-070; PRD Fase 8 v1.3 §6.1b, §7.3a).
 * - Skema respons: service mem-parse setiap respons di sini; respons di luar kontrak → `KontrakHaloError`. Angka/boolean
 *   dari PHP bisa datang sebagai string ("1") → dikoersi.
 * - Skema form: validasi klien memakai batas dan teks galat yang sama dengan BE (`constants.ts`), panjang = code point
 *   sesudah trim.
 */
import { z } from 'zod'

import { BATAS_MASTER, BATAS_TIKET, TEKS_GALAT_MASTER as T, TEKS_GALAT_TIKET as TT } from './constants'
import type { StatusMaster } from './types'

const id = z.coerce.number().int()
const angka = z.coerce.number()
const teks = z.string()
const teksNull = z.string().nullable()
const waktu = z.string().nullable()

/** 0/1 dari angka atau string; selain 1 dianggap Tidak Aktif. */
export const statusMaster = z.union([z.number(), z.string()]).transform((v): StatusMaster => (Number(v) === 1 ? 1 : 0))

const bendera = z.union([z.boolean(), z.number(), z.string()]).transform((v) => v === true || Number(v) === 1)

export function halaman<S extends z.ZodTypeAny>(item: S) {
  return z.object({ items: z.array(item), total: angka, page: angka, per_page: angka })
}

/** Respons PATCH status / PUT petugas (S8): baris + `peringatan`. Generik pada shape agar tipe keluaran tetap utuh. */
export function denganPeringatan<T extends z.ZodRawShape>(baris: z.ZodObject<T>) {
  return baris.extend({ peringatan: teksNull })
}

export const kategoriSchema = z.object({
  id,
  nama: teks,
  status: statusMaster,
  jumlah_petugas: angka,
  bisa_hapus: bendera,
  dibuat_pada: waktu,
  diubah_pada: waktu,
})

export const kategoriOpsiSchema = z.object({ id, nama: teks })

export const kategoriPetugasSchema = z.object({ id, nama: teks, status: statusMaster })

export const petugasSchema = z.object({
  id,
  id_user: id,
  label: teks,
  email: teks,
  no_hp: teksNull,
  status: statusMaster,
  kategori: z.array(kategoriPetugasSchema),
  bisa_hapus: bendera,
  alasan_tidak_bisa_hapus: teksNull,
})

export const kandidatSchema = z.object({
  items: z.array(z.object({ id_user: id, label: teks })),
  peringatan: teksNull,
})

export const petugasSayaSchema = z.object({
  petugas: bendera,
  aktif: bendera,
  kategori: z.array(kategoriPetugasSchema),
  bisa_ekspor: bendera,
})

export const kontakSchema = z.object({
  id,
  nama: teks,
  kontak: teks,
  email: teksNull,
  status: statusMaster,
  dibuat_pada: waktu,
  diubah_pada: waktu,
})

export const kontakAktifSchema = z.object({ id, nama: teks, kontak: teks, email: teksNull })

export const kategoriStatusSchema = denganPeringatan(kategoriSchema)
export const petugasPeringatanSchema = denganPeringatan(petugasSchema)
export const kontakStatusSchema = denganPeringatan(kontakSchema)

export const hapusSchema = z.object({ id })

// --- tiket (README MAKE-071; nullability dari README, bukan dari fixture) --------------------------------------------------

const statusTiket = z.enum(['open', 'closed'])

export const kategoriTiketSchema = z.object({ id, nama: teks })

export const lampiranRingkasSchema = z.object({ nama: teks, tipe: teks })

/** T4: `ukuran` selalu `null`. */
export const lampiranSchema = lampiranRingkasSchema.extend({ ukuran: z.null() })

export const pesanTerakhirSchema = z.object({
  id_inbox_msg: id,
  waktu: teks,
  potongan: teks,
  pengirim_label: teks,
  lampiran: lampiranRingkasSchema.nullable(),
})

export const tiketBarisSchema = z.object({
  id,
  subjek: teks,
  kategori: kategoriTiketSchema.nullable(),
  status: statusTiket,
  dibuat_pada: teks,
  pemilik_nama: teks,
  sebagai_petugas: bendera,
  belum_dibaca: bendera,
  perlu_tindak: bendera,
  pesan_terakhir: pesanTerakhirSchema.nullable(),
  bisa_balas: bendera,
  bisa_tutup: bendera,
  bisa_hapus: bendera,
})

export const pesanSchema = z.object({
  id_inbox_msg: id,
  waktu: teks,
  isi: teks,
  pengirim_label: teks,
  milik_saya: bendera,
  dari_pemilik: bendera,
  urutan_pengirim: angka,
  lampiran: lampiranSchema.nullable(),
})

export const tiketDetailSchema = tiketBarisSchema.extend({
  allow_reply: bendera,
  snapshot: z.object({ nip: teks, jabatan: teksNull, satker: teksNull }).nullable(),
  ditutup_oleh_label: teksNull,
  pengingat: z.object({ aktif: bendera, jatuh_tempo: teksNull }).nullable(),
  pesan: z.array(pesanSchema),
})

export const ringkasanSchema = z.object({
  belum_dibaca_saya: angka,
  belum_dibaca_petugas: angka,
  perlu_tindak: angka,
  badge: angka,
  total: angka,
})

export const hasilBalasSchema = z.object({ pesan: pesanSchema, tiket: tiketBarisSchema })

/** Galat parse respons dianggap galat muat data (pesan galat umum, bukan crash). */
export class KontrakHaloError extends Error {
  readonly path: string

  constructor(path: string, cause: unknown) {
    super(`Respons ${path} tidak sesuai kontrak.`)
    this.path = path
    this.cause = cause
  }
}

// --- form ---------------------------------------------------------------------------------------------------------------

/** Panjang dalam code point (sama dengan `mb_strlen` BE), sesudah trim. */
export const panjang = (s: string): number => [...s.trim()].length

/**
 * Panjang isi pesan (`msg`) seperti yang diterima BE: multipart mengirim baris baru sebagai CRLF, dan `mb_strlen` menghitungnya
 * 2 code point (review B2 P5 (a)). Dipakai validasi klien pesan tiket/balasan (dan penghitung karakter bila ada).
 */
export const panjangPesan = (s: string): number => [...s.trim().replace(/\r?\n/g, '\r\n')].length

const pesanWajib = z
  .string()
  .refine((s) => s.trim() !== '', TT.pesanWajib)
  .refine((s) => panjangPesan(s) <= BATAS_TIKET.pesan, TT.pesanMaks)

const wajib = (pesanWajib: string, maks: number, pesanMaks: string) =>
  z
    .string()
    .refine((s) => s.trim() !== '', pesanWajib)
    .refine((s) => panjang(s) <= maks, pesanMaks)

const EMAIL = z.string().email()
const emailValid = (s: string): boolean => EMAIL.safeParse(s.trim()).success

const status01 = z.union([z.literal(0), z.literal(1)])

export const kategoriFormSchema = z.object({
  nama: wajib(T.namaKategoriWajib, BATAS_MASTER.namaKategori, T.namaKategoriMaks),
  status: status01,
})

export const petugasFormSchema = z.object({
  id_user: z.number({ invalid_type_error: T.akunWajib, required_error: T.akunWajib }).int().nullable().refine((v) => v !== null, T.akunWajib),
  kategori: z.array(z.number().int()).min(1, T.kategoriMin),
  email: z
    .string()
    .refine((s) => s.trim() !== '', T.emailWajib)
    .refine((s) => s.trim() === '' || emailValid(s), T.emailFormat)
    .refine((s) => panjang(s) <= BATAS_MASTER.emailPetugas, T.emailMaks),
  no_hp: z.string().refine((s) => panjang(s) <= BATAS_MASTER.noHpPetugas, T.noHpMaks),
  status: status01,
})

export const kontakFormSchema = z.object({
  nama: wajib(T.namaWajib, BATAS_MASTER.namaKontak, T.namaMaks),
  kontak: wajib(T.kontakWajib, BATAS_MASTER.nomorKontak, T.kontakMaks),
  email: z
    .string()
    .refine((s) => s.trim() === '' || emailValid(s), T.emailFormat)
    .refine((s) => panjang(s) <= BATAS_MASTER.emailKontak, T.emailMaks),
  status: status01,
})

/** Kata kunci kandidat petugas: 2..50 code point sesudah trim (README beku). */
export const cariKandidatSchema = z
  .string()
  .refine((s) => panjang(s) >= BATAS_MASTER.cariKandidatMin, T.cariMin)
  .refine((s) => panjang(s) <= BATAS_MASTER.cariKandidatMaks, T.cariMaks)

/** Form tiket baru (E-03): kategori aktif wajib, subjek ≤ 250, pesan ≤ 5.000 (code point sesudah trim); lampiran opsional. */
export const tiketBaruFormSchema = z.object({
  id_kategori: z.number().int().nullable().refine((v) => v !== null, TT.kategoriWajib),
  subjek: wajib(TT.subjekWajib, BATAS_TIKET.subjek, TT.subjekMaks),
  pesan: pesanWajib,
  arsip: z.instanceof(File).nullable(),
})

/** Form balas (E-04). */
export const balasFormSchema = z.object({
  pesan: pesanWajib,
  arsip: z.instanceof(File).nullable(),
  ingatkan_24_jam: z.boolean(),
})

