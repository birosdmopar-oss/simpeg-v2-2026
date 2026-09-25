/**
 * Tipe G-08 Hari Libur (DBV-003/CR-010), snake_case end-to-end (ADR-023). Mengikuti bagian "Hari libur" di
 * backend/app/Controllers/Api/MasterData/README.md. Endpoint khusus `/hari-libur` (bukan master generik): unik per
 * tanggal mulai, tanpa urutan, overlap dicek terhadap semua status. Master jenis libur memakai engine generik
 * (`master/jenis-libur`).
 */
import { Role, type RoleCode } from '@/features/auth/types'

import type { MasterSettableStatus, MasterStatus } from './types'

/** Baca daftar/detail: role 1/4/5/8 seperti legacy (Presensi.php:1015). Role 4/5/8 hanya melihat status Aktif. */
export const HARI_LIBUR_READ_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PIMPINAN]

/** Tambah/ubah/hapus: role 1. */
export const HARI_LIBUR_WRITE_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN]

/** HTTP 409: penulisan lain sedang berjalan (named lock backend) — coba lagi. */
export const HARI_LIBUR_BUSY_STATUS = 409

export interface HariLiburRow {
  id_libur: number | string
  /** NULL untuk data impor legacy tanpa jenis (wajib diisi saat diubah lewat form). */
  id_jenis_libur: number | string | null
  /** Nama jenis libur (LEFT JOIN), null bila tanpa jenis. */
  jenis_libur: string | null
  /** YYYY-MM-DD */
  tgl_mulai: string
  /** YYYY-MM-DD, ≥ tgl_mulai */
  tgl_akhir: string
  nama_libur: string
  keterangan: string | null
  /** 1 Aktif (dihitung sebagai libur), 2 Tidak Aktif, 10 Dihapus. */
  status: MasterStatus | number
  created_at: string | null
  updated_at: string | null
  updated_by: number | string | null
}

export interface HariLiburListQuery {
  /** Rentang yang beririsan dengan tahun ini (1900-2100). */
  tahun?: string
  search?: string
  /** Role 1 saja; role 4/5/8 selalu status 1. */
  status?: MasterStatus | ''
  page?: number
  per_page?: number
}

export interface HariLiburListResponse {
  items: HariLiburRow[]
  total: number
  page: number
  per_page: number
}

export interface HariLiburPayload {
  tgl_mulai: string
  tgl_akhir: string
  id_jenis_libur: string
  nama_libur: string
  keterangan?: string
  /** Hanya saat tambah (bawaan 1); ubah status lewat setStatus. */
  status?: MasterSettableStatus
}

export interface HariLiburDeleteResponse {
  deleted: boolean
  soft_delete: boolean
  item: HariLiburRow
}

/** Nilai form (semua string). */
export interface HariLiburFormValues {
  tgl_mulai: string
  tgl_akhir: string
  id_jenis_libur: string
  nama_libur: string
  keterangan: string
  status: string
}
