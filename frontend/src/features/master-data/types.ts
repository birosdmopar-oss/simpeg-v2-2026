/**
 * Tipe Modul G — Master Data (snake_case end-to-end, ADR-023). Mengikuti
 * backend/app/Controllers/Api/MasterData/README.md. Metadata tiap master datang dari GET /master/meta
 * (Config\MasterData backend = satu sumber kebenaran), sehingga halaman master bersifat generik.
 */
import { Role, type RoleCode } from '@/features/auth/types'

/** Seluruh CRUD master = role 1 (Matriks Role x Endpoint Modul G). */
export const MASTER_DATA_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN]

/** Status legacy (DBV-001): 1 Aktif, 2 Tidak Aktif, 10 Dihapus (soft delete). */
export type MasterStatus = '1' | '2' | '10'

/** Status yang boleh diset lewat PATCH /status (10 hanya lewat DELETE; set 1/2 juga memulihkan entri terhapus). */
export type MasterSettableStatus = '1' | '2'

export const MASTER_STATUS_LABELS: Record<MasterStatus, string> = {
  '1': 'Aktif',
  '2': 'Tidak Aktif',
  '10': 'Dihapus',
}

/** Tipe field tambahan (App\Libraries\MasterData\MasterField). */
export type MasterFieldType = 'text' | 'textarea' | 'int' | 'decimal' | 'date' | 'select'

export interface MasterFieldMeta {
  name: string
  label: string
  type: MasterFieldType
  required: boolean
  options: Array<{ value: string; label: string }> | null
  hint: string | null
}

export interface MasterMeta {
  key: string
  label: string
  primary_key: string
  id_max_length: number
  /** Kode wajib tepat N digit angka (kode wilayah legacy 2/4/7/10); null = kode bebas. */
  id_digits: number | null
  /** PK diberikan database (kode tidak diinput admin). */
  auto_increment: boolean
  name_field: string
  name_label: string
  name_max_length: number
  has_order: boolean
  has_status: boolean
  parent: { field: string; entity: string } | null
  /** Kolom tambahan legacy (mis. kd_area, kd_pos, status_pegawai). */
  fields: MasterFieldMeta[]
}

/** Baris master: kolom dinamis per tabel + kolom standar order/status (+ parent_nama untuk master berinduk). */
export type MasterRow = Record<string, string | number | null> & {
  order?: number | string
  status?: MasterStatus | number
  parent_nama?: string | null
}

export interface MasterListQuery {
  search?: string
  status?: MasterStatus | ''
  parent?: string
  page?: number
  per_page?: number
}

export interface MasterListResponse {
  items: MasterRow[]
  total: number
  page: number
  per_page: number
}

export interface MasterOption {
  id: string
  nama: string
  parent: string | null
}

export interface MasterDeleteResponse {
  deleted: boolean
  soft_delete: boolean
  item: MasterRow
}

/** Nilai form generik: kode, induk, nama (key = nama kolom backend), order opsional. */
export type MasterFormValues = Record<string, string | undefined>
