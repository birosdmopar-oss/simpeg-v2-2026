/**
 * Klien API helpdesk Halo Simpeg — bagian master (`api/v1/halo-simpeg/{kategori,petugas,kontak}`, README kontrak beku
 * MAKE-070). Interceptor `@/lib/axios` sudah membuka amplop, jadi `data` di sini = isi `data` backend (pelajaran amplop
 * CR-059: jangan `res.data.data`). Setiap respons di-parse skema Zod; di luar kontrak → `KontrakHaloError`.
 * Masukan memakai nama legacy (`question_category`, `id_user`, `category[]`, `email`, `no_hp`, `name`, `contact`, `status`).
 */
import type { AxiosError } from 'axios'
import type { z } from 'zod'

import { api, isApiError } from '@/lib/axios'

import {
  denganPeringatan,
  halaman,
  hapusSchema,
  kandidatSchema,
  kategoriOpsiSchema,
  kategoriSchema,
  KontrakHaloError,
  kontakAktifSchema,
  kontakSchema,
  petugasSayaSchema,
  petugasSchema,
} from '../schemas'
import type {
  DenganPeringatan,
  FilterMaster,
  HasilKandidat,
  Halaman,
  Kategori,
  KategoriForm,
  KategoriOpsi,
  Kontak,
  KontakAktif,
  KontakForm,
  Petugas,
  PetugasForm,
  PetugasSaya,
  StatusMaster,
} from '../types'

export const BASIS_HALO = '/halo-simpeg'

function parse<S extends z.ZodTypeAny>(schema: S, data: unknown, path: string): z.output<S> {
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

/** CRUD master seragam: daftar `Halaman<T>`, POST 201 baris, PUT 200 baris, PATCH status 200 baris + `peringatan`, DELETE `{id}`. */
function crudMaster<Row, Form, Put = Row>(
  jalur: 'kategori' | 'petugas' | 'kontak',
  schema: z.AnyZodObject,
  body: (f: Form) => object,
  putSchema: z.ZodTypeAny = schema,
) {
  return {
    async list(filter: FilterMaster = {}): Promise<Halaman<Row>> {
      return get(jalur, halaman(schema), filter) as Promise<Halaman<Row>>
    },
    async tambah(form: Form): Promise<Row> {
      const { data } = await api.post<unknown>(`${BASIS_HALO}/${jalur}`, body(form))
      return parse(schema, data, jalur) as Row
    },
    async ubah(id: number, form: Form): Promise<Put> {
      const { data } = await api.put<unknown>(`${BASIS_HALO}/${jalur}/${id}`, body(form))
      return parse(putSchema, data, `${jalur}/${id}`) as Put
    },
    async setStatus(id: number, status: StatusMaster): Promise<DenganPeringatan<Row>> {
      const { data } = await api.patch<unknown>(`${BASIS_HALO}/${jalur}/${id}/status`, { status })
      return parse(denganPeringatan(schema), data, `${jalur}/${id}/status`) as DenganPeringatan<Row>
    },
    async hapus(id: number): Promise<number> {
      const { data } = await api.delete<unknown>(`${BASIS_HALO}/${jalur}/${id}`)
      return parse(hapusSchema, data, `${jalur}/${id}`).id
    },
  }
}

export const kategoriHaloService = {
  ...crudMaster<Kategori, KategoriForm>('kategori', kategoriSchema, bodyKategori),
  /** Kategori aktif untuk form tiket (role 1–7). */
  async opsi(): Promise<KategoriOpsi[]> {
    return get('kategori/opsi', kategoriOpsiSchema.array())
  },
}

export const petugasHaloService = {
  /** S8: PUT petugas membawa `peringatan` (melepas kategori aktif terakhir / menonaktifkan). */
  ...crudMaster<Petugas, PetugasForm, DenganPeringatan<Petugas>>('petugas', petugasSchema, bodyPetugas, denganPeringatan(petugasSchema)),
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
  ...crudMaster<Kontak, KontakForm>('kontak', kontakSchema, bodyKontak),
  /** Kontak aktif untuk kartu "Kontak Helpdesk" (role 1–7, array tanpa status/stempel). */
  async aktif(): Promise<KontakAktif[]> {
    return get('kontak/aktif', kontakAktifSchema.array())
  },
}
