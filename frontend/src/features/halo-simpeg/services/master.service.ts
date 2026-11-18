/**
 * Klien API helpdesk Halo Simpeg — bagian master (`api/v1/halo-simpeg/{kategori,petugas,kontak}`, README kontrak beku
 * MAKE-070). Interceptor `@/lib/axios` sudah membuka amplop, jadi `data` di sini = isi `data` backend (pelajaran amplop
 * CR-059: jangan `res.data.data`). Setiap respons di-parse skema Zod; di luar kontrak → `KontrakHaloError`.
 * Masukan memakai nama legacy (`question_category`, `id_user`, `category[]`, `email`, `no_hp`, `name`, `contact`, `status`).
 */
import type { AxiosError } from 'axios'
import type { z } from 'zod'

import { api, isApiError } from '@/lib/axios'

import { PAGE_MAKS, PER_PAGE_MAKS } from '../constants'
import {
  halaman,
  hapusSchema,
  kandidatSchema,
  kategoriOpsiSchema,
  kategoriSchema,
  kategoriStatusSchema,
  KontrakHaloError,
  kontakAktifSchema,
  kontakSchema,
  kontakStatusSchema,
  petugasPeringatanSchema,
  petugasSayaSchema,
  petugasSchema,
} from '../schemas'
import type {
  FilterMaster,
  HasilKandidat,
  Halaman,
  KategoriForm,
  KategoriOpsi,
  KontakAktif,
  KontakForm,
  PetugasForm,
  PetugasSaya,
  StatusMaster,
} from '../types'

export const BASIS_HALO = '/halo-simpeg'

export function parse<S extends z.ZodTypeAny>(schema: S, data: unknown, path: string): z.output<S> {
  const hasil = schema.safeParse(data)
  if (!hasil.success) throw new KontrakHaloError(path, hasil.error)
  return hasil.data as z.output<S>
}

/** Parameter kosong tidak dikirim; teks di-trim. */
function params(filter: object): Record<string, string | number> {
  const hasil: Record<string, string | number> = {}
  for (const [k, v] of Object.entries(filter)) {
    if (v === undefined || v === null) continue
    if (typeof v === 'string' && v.trim() === '') continue
    hasil[k] = typeof v === 'string' ? v.trim() : (v as number)
  }
  return hasil
}

const bulatDalam = (n: number, min: number, maks: number): number => Math.min(maks, Math.max(min, Math.trunc(n)))

/**
 * Filter daftar → query (README final S3; daftar tiket memakai konvensi paginasi yang sama): `page` dibulatkan ke
 * 1..1.000.000 dan `per_page` ke 1..100 agar UI tidak memicu 422 `errors.page`; nilai bukan angka hingga tidak dikirim
 * (bawaan BE). Parameter kosong tidak dikirim.
 */
export function queryDaftar<F extends { page?: number; per_page?: number }>(filter: F): Record<string, string | number> {
  const { page, per_page, ...lain } = filter
  return params({
    ...lain,
    page: typeof page === 'number' && Number.isFinite(page) ? bulatDalam(page, 1, PAGE_MAKS) : undefined,
    per_page: typeof per_page === 'number' && Number.isFinite(per_page) ? bulatDalam(per_page, 1, PER_PAGE_MAKS) : undefined,
  })
}

async function get<S extends z.ZodTypeAny>(path: string, schema: S, query?: object): Promise<z.output<S>> {
  const { data } = await api.get<unknown>(`${BASIS_HALO}/${path}`, query ? { params: params(query) } : undefined)
  return parse(schema, data, path)
}

/**
 * `code` galat bisnis bernama (amplop v2, snake_case) dari `ApiError`; `null` bila tidak ada. `ApiError` tidak membawa `code`,
 * jadi dibaca dari respons asli.
 */
export function kodeGalat(e: unknown): string | null {
  if (!isApiError(e)) return null
  const body = (e.original as AxiosError<{ code?: unknown }>).response?.data
  return typeof body?.code === 'string' ? body.code : null
}

const bodyKategori = (f: KategoriForm) => ({ question_category: f.nama.trim(), status: f.status })
const bodyPetugas = (f: PetugasForm) => ({
  id_user: f.id_user,
  category: f.kategori,
  email: f.email.trim(),
  no_hp: f.no_hp.trim(),
  status: f.status,
})
const bodyKontak = (f: KontakForm) => ({ name: f.nama.trim(), contact: f.kontak.trim(), email: f.email.trim(), status: f.status })

/**
 * CRUD master seragam: daftar `Halaman<T>`, POST 201 baris, PUT 200 baris (petugas: + `peringatan`, S8), PATCH status 200
 * baris + `peringatan`, DELETE `{id}`. Tipe baris = `z.output` skema (bukan cast), sehingga `types.ts` ↔ `schemas.ts` dijaga
 * vue-tsc lewat `__tests__/kontrakTipe.spec.ts`.
 */
function crudMaster<S extends z.AnyZodObject, T extends z.AnyZodObject, Form, P extends z.ZodTypeAny = S>(
  jalur: 'kategori' | 'petugas' | 'kontak',
  schema: S,
  skemaStatus: T,
  body: (f: Form) => object,
  putSchema?: P,
) {
  const skemaPut = (putSchema ?? schema) as P
  return {
    async list(filter: FilterMaster = {}): Promise<Halaman<z.output<S>>> {
      const { data } = await api.get<unknown>(`${BASIS_HALO}/${jalur}`, { params: queryDaftar(filter) })
      return parse(halaman(schema), data, jalur)
    },
    async tambah(form: Form): Promise<z.output<S>> {
      const { data } = await api.post<unknown>(`${BASIS_HALO}/${jalur}`, body(form))
      return parse(schema, data, jalur)
    },
    async ubah(id: number, form: Form): Promise<z.output<P>> {
      const { data } = await api.put<unknown>(`${BASIS_HALO}/${jalur}/${id}`, body(form))
      return parse(skemaPut, data, `${jalur}/${id}`)
    },
    async setStatus(id: number, status: StatusMaster): Promise<z.output<T>> {
      const { data } = await api.patch<unknown>(`${BASIS_HALO}/${jalur}/${id}/status`, { status })
      return parse(skemaStatus, data, `${jalur}/${id}/status`)
    },
    async hapus(id: number): Promise<number> {
      const { data } = await api.delete<unknown>(`${BASIS_HALO}/${jalur}/${id}`)
      return parse(hapusSchema, data, `${jalur}/${id}`).id
    },
  }
}

export const kategoriHaloService = {
  ...crudMaster('kategori', kategoriSchema, kategoriStatusSchema, bodyKategori),
  /** Kategori aktif untuk form tiket (role 1–7). */
  async opsi(): Promise<KategoriOpsi[]> {
    return get('kategori/opsi', kategoriOpsiSchema.array())
  },
}

export const petugasHaloService = {
  /** S8: PUT petugas membawa `peringatan` (melepas kategori aktif terakhir / menonaktifkan). */
  ...crudMaster('petugas', petugasSchema, petugasPeringatanSchema, bodyPetugas, petugasPeringatanSchema),
  /** S1: `{items, peringatan}`; `search` 2..50 code point (divalidasi pemanggil). */
  async kandidat(search: string): Promise<HasilKandidat> {
    return get('petugas/kandidat', kandidatSchema, { search })
  },
  /** Sumber tab antrean / panel "Perlu ditindak" / ekspor (role 1–7, selalu 200). */
  async saya(): Promise<PetugasSaya> {
    return get('petugas/saya', petugasSayaSchema)
  },
}

export const kontakHaloService = {
  ...crudMaster('kontak', kontakSchema, kontakStatusSchema, bodyKontak),
  /** Kontak aktif untuk kartu "Kontak Helpdesk" (role 1–7, array tanpa status/stempel). */
  async aktif(): Promise<KontakAktif[]> {
    return get('kontak/aktif', kontakAktifSchema.array())
  },
}
