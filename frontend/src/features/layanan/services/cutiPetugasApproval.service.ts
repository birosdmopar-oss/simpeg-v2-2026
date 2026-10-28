/**
 * Klien Modul C — Petugas Approval Cuti (aturan `pa_layanan` jenis 1; PRD Fase 4 §6.2, README Layanan). Role 1, 3.
 * `api` sudah membuka amplop, jadi service membaca `res.data` langsung (CR-059); id dan flag dinormalkan (CR-049).
 *   GET    cuti/petugas-approval                 daftar (`items`, `total`) — role 3 hanya unit sendiri
 *   GET    cuti/petugas-approval/opsi            opsi form (jenis pegawai, group jabatan pakai gol, koordinasi, unit terkunci)
 *   POST   cuti/petugas-approval                 tambah
 *   PUT    cuti/petugas-approval/{id}            ubah (hanya baris status 1)
 *   DELETE cuti/petugas-approval/{id}            hapus fisik
 * `petugas` pada body: `struk_<id_jabatan>` | `koord_<id_jabatan_koord>` | `other_<nip>`.
 */
import { api } from '@/lib/axios'

import { num, teksOrNull } from './normalisasi'

export interface PetugasApprovalCuti {
  id: number
  id_jenis_pa: number
  id_jenis_pegawai: number
  jenis_pegawai: string | null
  id_unit_pemohon: number
  unit_pemohon: string | null
  id_group_jabatan_pemohon: number
  group_jabatan_pemohon: string | null
  id_sub_group_jabatan_pemohon: number | null
  sub_group_jabatan_pemohon: string | null
  gol_ruang_pemohon: string | null
  id_unit_petugas: number
  unit_petugas: string | null
  id_satker_petugas: number
  satker_petugas: string | null
  id_group_jabatan_petugas: number
  group_jabatan_petugas: string | null
  id_sub_group_jabatan_petugas: number
  sub_group_jabatan_petugas: string | null
  jabatan_struktural: string | null
  jabatan_koordinasi: string | null
  id_jabatan: number | null
  id_jabatan_koord: number | null
  petugas: { nip: string | null; nama: string | null; jabatan: string | null }
  status: number
}

export interface PetugasListQuery {
  page: number
  per_page: number
}

export interface PetugasListResponse {
  items: PetugasApprovalCuti[]
  total: number
  page: number
  per_page: number
}

export interface PetugasOpsi {
  id_jenis_pa: number
  jenis_pegawai: { id_jenis_pegawai: number; jenis_pegawai: string }[]
  group_jabatan_pakai_gol: number[]
  jabatan_koordinasi: { id_jabatan_koordinasi: number; jabatan: string; jenis: number }[]
  unit_terkunci: { id_unit: number; unit: string } | null
}

/** Isian form (id sebagai angka; `petugas` berbentuk `struk_<id>` / `koord_<id>` / `other_<nip>`). */
export interface PetugasIsian {
  id_jenis_pa: number
  id_jenis_pegawai: number
  id_unit_pemohon: number
  id_group_jabatan_pemohon: number
  id_sub_group_jabatan_pemohon?: number
  gol_ruang_pemohon?: string
  id_unit_petugas: number
  id_satker_petugas: number
  id_group_jabatan_petugas: number
  id_sub_group_jabatan_petugas: number
  petugas: string
}

function daftarRecord(value: unknown): Record<string, unknown>[] {
  return Array.isArray(value) ? (value as Record<string, unknown>[]) : []
}

export function normalisasiPetugas(raw: Record<string, unknown>): PetugasApprovalCuti {
  const p = (raw.petugas ?? {}) as Record<string, unknown>
  return {
    id: num(raw.id_pa_layanan),
    id_jenis_pa: num(raw.id_jenis_pa),
    id_jenis_pegawai: num(raw.id_jenis_pegawai),
    jenis_pegawai: teksOrNull(raw.jenis_pegawai),
    id_unit_pemohon: num(raw.id_unit_pemohon),
    unit_pemohon: teksOrNull(raw.unit_pemohon),
    id_group_jabatan_pemohon: num(raw.id_group_jabatan_pemohon),
    group_jabatan_pemohon: teksOrNull(raw.group_jabatan_pemohon),
    id_sub_group_jabatan_pemohon: raw.id_sub_group_jabatan_pemohon === null ? null : num(raw.id_sub_group_jabatan_pemohon),
    sub_group_jabatan_pemohon: teksOrNull(raw.sub_group_jabatan_pemohon),
    gol_ruang_pemohon: teksOrNull(raw.gol_ruang_pemohon),
    id_unit_petugas: num(raw.id_unit_petugas),
    unit_petugas: teksOrNull(raw.unit_petugas),
    id_satker_petugas: num(raw.id_satker_petugas),
    satker_petugas: teksOrNull(raw.satker_petugas),
    id_group_jabatan_petugas: num(raw.id_group_jabatan_petugas),
    group_jabatan_petugas: teksOrNull(raw.group_jabatan_petugas),
    id_sub_group_jabatan_petugas: num(raw.id_sub_group_jabatan_petugas),
    sub_group_jabatan_petugas: teksOrNull(raw.sub_group_jabatan_petugas),
    jabatan_struktural: teksOrNull(raw.jabatan_struktural),
    jabatan_koordinasi: teksOrNull(raw.jabatan_koordinasi),
    id_jabatan: raw.id_jabatan === null || raw.id_jabatan === undefined ? null : num(raw.id_jabatan),
    id_jabatan_koord: raw.id_jabatan_koord === null || raw.id_jabatan_koord === undefined ? null : num(raw.id_jabatan_koord),
    petugas: { nip: teksOrNull(p.nip), nama: teksOrNull(p.nama), jabatan: teksOrNull(p.jabatan) },
    status: num(raw.status),
  }
}

export const cutiPetugasApprovalService = {
  async list(query: PetugasListQuery): Promise<PetugasListResponse> {
    const { data } = await api.get<Record<string, unknown>>('/cuti/petugas-approval', { params: { page: query.page, per_page: query.per_page } })
    return {
      items: daftarRecord(data.items).map(normalisasiPetugas),
      total: num(data.total),
      page: num(data.page, 1),
      per_page: num(data.per_page, query.per_page),
    }
  },

  async opsi(): Promise<PetugasOpsi> {
    const { data } = await api.get<Record<string, unknown>>('/cuti/petugas-approval/opsi')
    const terkunci = (data.unit_terkunci ?? null) as Record<string, unknown> | null
    return {
      id_jenis_pa: num(data.id_jenis_pa),
      jenis_pegawai: daftarRecord(data.jenis_pegawai).map((j) => ({ id_jenis_pegawai: num(j.id_jenis_pegawai), jenis_pegawai: String(j.jenis_pegawai ?? '') })),
      group_jabatan_pakai_gol: (Array.isArray(data.group_jabatan_pakai_gol) ? data.group_jabatan_pakai_gol : []).map((g) => num(g)),
      jabatan_koordinasi: daftarRecord(data.jabatan_koordinasi).map((k) => ({
        id_jabatan_koordinasi: num(k.id_jabatan_koordinasi),
        jabatan: String(k.jabatan ?? ''),
        jenis: num(k.jenis),
      })),
      unit_terkunci: terkunci ? { id_unit: num(terkunci.id_unit), unit: String(terkunci.unit ?? '') } : null,
    }
  },

  async tambah(payload: PetugasIsian): Promise<void> {
    await api.post('/cuti/petugas-approval', payload)
  },

  async ubah(id: number, payload: PetugasIsian): Promise<void> {
    await api.put(`/cuti/petugas-approval/${id}`, payload)
  },

  async hapus(id: number): Promise<void> {
    await api.delete(`/cuti/petugas-approval/${id}`)
  },
}
