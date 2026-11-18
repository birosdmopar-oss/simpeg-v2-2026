/**
 * Kunci tipe kompilasi `types.ts` ↔ `schemas.ts` ↔ service (review B1 V1): bila kontrak berubah (mis. MAKE-071) dan salah
 * satu sisi tertinggal, vue-tsc gagal di berkas ini. Test runtime hanya memastikan berkas ikut dijalankan.
 */
import { describe, expect, it } from 'vitest'
import type { z } from 'zod'

import type {
  hasilBalasSchema,
  kandidatSchema,
  kategoriOpsiSchema,
  kategoriSchema,
  kategoriStatusSchema,
  kontakAktifSchema,
  kontakSchema,
  kontakStatusSchema,
  petugasPeringatanSchema,
  pesanSchema,
  petugasSayaSchema,
  petugasSchema,
  ringkasanSchema,
  tiketBarisSchema,
  tiketDetailSchema,
} from '../schemas'
import type { kategoriHaloService, kontakHaloService, petugasHaloService } from '../services/master.service'
import type {
  DenganPeringatan,
  Halaman,
  HasilBalas,
  HasilKandidat,
  Kategori,
  KategoriOpsi,
  Kontak,
  KontakAktif,
  Pesan,
  Petugas,
  PetugasSaya,
  Ringkasan,
  TiketBaris,
  TiketDetail,
} from '../types'

/** Ratakan irisan (`T & {peringatan}`) menjadi satu objek agar dua tipe yang setara secara struktur terbanding identik. */
type Rata<T> = T extends readonly (infer U)[] ? Rata<U>[] : T extends object ? { [K in keyof T]: T[K] } : T
/** Sama persis dua arah (bukan sekadar assignable), sesudah diratakan. */
export type Equal<A, B> = (<T>() => T extends Rata<A> ? 1 : 2) extends <T>() => T extends Rata<B> ? 1 : 2 ? true : false
export type Expect<T extends true> = T

type Hasil<F> = F extends (...a: never[]) => Promise<infer R> ? R : never

export type KunciSkema = [
  Expect<Equal<z.output<typeof kategoriSchema>, Kategori>>,
  Expect<Equal<z.output<typeof kategoriOpsiSchema>, KategoriOpsi>>,
  Expect<Equal<z.output<typeof petugasSchema>, Petugas>>,
  Expect<Equal<z.output<typeof kandidatSchema>, HasilKandidat>>,
  Expect<Equal<z.output<typeof petugasSayaSchema>, PetugasSaya>>,
  Expect<Equal<z.output<typeof kontakSchema>, Kontak>>,
  Expect<Equal<z.output<typeof kontakAktifSchema>, KontakAktif>>,
  Expect<Equal<z.output<typeof petugasPeringatanSchema>, DenganPeringatan<Petugas>>>,
  Expect<Equal<z.output<typeof kategoriStatusSchema>, DenganPeringatan<Kategori>>>,
  Expect<Equal<z.output<typeof kontakStatusSchema>, DenganPeringatan<Kontak>>>,
]

export type KunciService = [
  Expect<Equal<Hasil<typeof kategoriHaloService.list>, Halaman<Kategori>>>,
  Expect<Equal<Hasil<typeof kategoriHaloService.tambah>, Kategori>>,
  Expect<Equal<Hasil<typeof kategoriHaloService.setStatus>, DenganPeringatan<Kategori>>>,
  Expect<Equal<Hasil<typeof kategoriHaloService.opsi>, KategoriOpsi[]>>,
  Expect<Equal<Hasil<typeof petugasHaloService.list>, Halaman<Petugas>>>,
  Expect<Equal<Hasil<typeof petugasHaloService.ubah>, DenganPeringatan<Petugas>>>,
  Expect<Equal<Hasil<typeof petugasHaloService.setStatus>, DenganPeringatan<Petugas>>>,
  Expect<Equal<Hasil<typeof petugasHaloService.kandidat>, HasilKandidat>>,
  Expect<Equal<Hasil<typeof petugasHaloService.saya>, PetugasSaya>>,
  Expect<Equal<Hasil<typeof kontakHaloService.list>, Halaman<Kontak>>>,
  Expect<Equal<Hasil<typeof kontakHaloService.ubah>, Kontak>>,
  Expect<Equal<Hasil<typeof kontakHaloService.setStatus>, DenganPeringatan<Kontak>>>,
  Expect<Equal<Hasil<typeof kontakHaloService.aktif>, KontakAktif[]>>,
]

export type KunciSkemaTiket = [
  Expect<Equal<z.output<typeof tiketBarisSchema>, TiketBaris>>,
  Expect<Equal<z.output<typeof tiketDetailSchema>, TiketDetail>>,
  Expect<Equal<z.output<typeof pesanSchema>, Pesan>>,
  Expect<Equal<z.output<typeof ringkasanSchema>, Ringkasan>>,
  Expect<Equal<z.output<typeof hasilBalasSchema>, HasilBalas>>,
]

describe('kunci tipe kontrak master', () => {
  it('diperiksa vue-tsc (berkas ini gagal kompilasi bila tipe dan skema menyimpang)', () => {
    expect(true).toBe(true)
  })
})
