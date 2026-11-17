/**
 * Skema Zod helpdesk Halo Simpeg — bagian master (README kontrak beku MAKE-070; PRD Fase 8 v1.3 §6.1b, §7.3a).
 * - Skema respons: service mem-parse setiap respons di sini; respons di luar kontrak → `KontrakHaloError`. Angka/boolean
 *   dari PHP bisa datang sebagai string ("1") → dikoersi.
 * - Skema form: validasi klien memakai batas dan teks galat yang sama dengan BE (`constants.ts`), panjang = code point
 *   sesudah trim.
 */
import { z } from 'zod'

import { BATAS_MASTER, TEKS_GALAT_MASTER as T } from './constants'
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
