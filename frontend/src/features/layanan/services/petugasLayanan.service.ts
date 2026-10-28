/**
 * Klien Modul C — Petugas Layanan (PIC) (PRD Fase 4 §5.5, §6.10; README "Dashboard Status Layanan & PIC — C-10"). Hanya
 * role 1 yang menulis; daftar bisa dibaca semua role layanan, tetapi halaman ini khusus role 1.
 *   GET    layanan/jenis                                   jenis layanan aktif (`id_jenis_layanan`, `jenis_layanan`)
 *   GET    layanan/petugas?id_jenis_layanan=&status=&search=&page=&per_page=   daftar (`items`, `total`, `can_create`)
 *   GET    layanan/petugas/kandidat?search=                kandidat PIC (`items`, `peringatan`), role 1
 *   POST   layanan/petugas                                 `{id_jenis_layanan, nip, status?}`
 *   PUT    layanan/petugas/{id}                            kolom yang dikirim saja
 *   DELETE layanan/petugas/{id}                            hapus fisik
 * `api` sudah membuka amplop, jadi service membaca `res.data` langsung (CR-059); id dan flag dinormalkan (CR-049).
 */
import { api } from '@/lib/axios'

import { num, teksOrNull } from './normalisasi'

export interface JenisLayanan {
  id_jenis_layanan: number
  jenis_layanan: string
  module_name: string | null
}

export interface PetugasLayanan {
  id: number
  id_jenis_layanan: number
  jenis_layanan: string | null
  nip: string
  nama: string | null
  jabatan: string | null
  satker: string | null
  unit: string | null
  status: number
  label_status: string
  aksi: { ubah: boolean; aktifkan: boolean; nonaktifkan: boolean; hapus: boolean }
}

export interface PetugasListQuery {
  id_jenis_layanan?: string
  status?: string
  search?: string
  page: number
  per_page: number
}

export interface PetugasListResponse {
  items: PetugasLayanan[]
  total: number
  page: number
  per_page: number
  can_create: boolean
}

export interface KandidatPetugas {
  nip: string
  nama: string
}

export interface KandidatResponse {
  items: KandidatPetugas[]
  peringatan: string | null
}

export interface PetugasIsian {
  id_jenis_layanan: number
  nip: string
  status?: number
}

function daftarRecord(value: unknown): Record<string, unknown>[] {
  return Array.isArray(value) ? (value as Record<string, unknown>[]) : []
}

export function normalisasiPetugasLayanan(raw: Record<string, unknown>): PetugasLayanan {
  const aksi = (raw.aksi ?? {}) as Record<string, unknown>
  const flag = (v: unknown): boolean => v === true || v === 1 || v === '1'
  return {
    id: num(raw.id_petugas_layanan),
    id_jenis_layanan: num(raw.id_jenis_layanan),
    jenis_layanan: teksOrNull(raw.jenis_layanan),
    nip: String(raw.nip ?? ''),
    nama: teksOrNull(raw.nama),
    jabatan: teksOrNull(raw.jabatan),
    satker: teksOrNull(raw.satker),
    unit: teksOrNull(raw.unit),
    status: num(raw.status),
    label_status: String(raw.label_status ?? ''),
    aksi: { ubah: flag(aksi.ubah), aktifkan: flag(aksi.aktifkan), nonaktifkan: flag(aksi.nonaktifkan), hapus: flag(aksi.hapus) },
  }
}

export const petugasLayananService = {
  async jenis(): Promise<JenisLayanan[]> {
    const { data } = await api.get<unknown>('/layanan/jenis')
    return daftarRecord(data).map((j) => ({
      id_jenis_layanan: num(j.id_jenis_layanan),
      jenis_layanan: String(j.jenis_layanan ?? ''),
      module_name: teksOrNull(j.module_name),
    }))
  },

  async list(query: PetugasListQuery): Promise<PetugasListResponse> {
    const params: Record<string, string | number> = { page: query.page, per_page: query.per_page }
    if (query.id_jenis_layanan) params.id_jenis_layanan = query.id_jenis_layanan
    if (query.status) params.status = query.status
    if (query.search) params.search = query.search
    const { data } = await api.get<Record<string, unknown>>('/layanan/petugas', { params })
    return {
      items: daftarRecord(data.items).map(normalisasiPetugasLayanan),
      total: num(data.total),
      page: num(data.page, 1),
      per_page: num(data.per_page, query.per_page),
      can_create: data.can_create === true || data.can_create === 1 || data.can_create === '1',
    }
  },

  async kandidat(search: string): Promise<KandidatResponse> {
    const { data } = await api.get<Record<string, unknown>>('/layanan/petugas/kandidat', { params: search ? { search } : undefined })
    return {
      items: daftarRecord(data.items).map((k) => ({ nip: String(k.nip ?? ''), nama: String(k.nama ?? '') })),
      peringatan: teksOrNull(data.peringatan),
    }
  },

  async tambah(isian: PetugasIsian): Promise<void> {
    await api.post('/layanan/petugas', isian)
  },

  async ubah(id: number, isian: Partial<PetugasIsian>): Promise<void> {
    await api.put(`/layanan/petugas/${id}`, isian)
  },

  async hapus(id: number): Promise<void> {
    await api.delete(`/layanan/petugas/${id}`)
  },
}
