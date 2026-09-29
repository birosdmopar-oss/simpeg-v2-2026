/** Modul C — Layanan & Approval (Fase 4). */
import { Role, type RoleCode } from '@/features/auth/types'

/** Dashboard status layanan (hr/rwy/layanan/index, Matriks Role x Endpoint): role 1–7. */
export const LAYANAN_ROLES: readonly RoleCode[] = [
  Role.SUPER_ADMIN,
  Role.PEGAWAI,
  Role.ADMIN_SATKER,
  Role.ADMIN_VIEW_ESELON1,
  Role.MENTERI,
  Role.PTT,
  Role.PPPK,
]

export const JENIS_LAYANAN = ['Izin Belajar', 'Tugas Belajar', 'Cuti'] as const
export type JenisLayanan = (typeof JENIS_LAYANAN)[number]
export type LangkahState = 'done' | 'current' | 'todo'
export type StatusLayanan = 'Berjalan' | 'Selesai'

/**
 * Alur persetujuan per jenis layanan (Gambar 17). ASUMSI: urutan langkah mengikuti mockup; alur sebenarnya ditentukan
 * task C-xx (inbox & approval) dan harus punya satu titik akhir tunggal (aturan proyek, 00-INDEX.md).
 */
export const ALUR_LAYANAN: Record<JenisLayanan, string[]> = {
  'Izin Belajar': ['Verifikasi Dokumen', 'Review Ketua Tim', 'Proses TTE', 'Penerbitan SK'],
  'Tugas Belajar': ['Verifikasi Dokumen', 'Validasi Kabid', 'Penerbitan SK'],
  Cuti: ['Review Ketua Tim', 'Penerbitan Izin Cuti'],
}

export interface LayananRow {
  id: number
  nip: string
  nama: string
  /** Seed avatar placeholder — HANYA data contoh. */
  foto_seed: string
  jenis: JenisLayanan
  satuan_kerja: string
  langkah: Array<{ label: string; state: LangkahState }>
  status: StatusLayanan
}

export interface LayananListQuery {
  page: number
  per_page: number
  search?: string
  unit?: string
  status?: string
}

export interface LayananListResult {
  items: LayananRow[]
  total: number
}
