/**
 * Tipe Modul G — Master Data (snake_case end-to-end, ADR-023). Mengikuti
 * backend/app/Controllers/Api/MasterData/README.md. Metadata tiap master datang dari GET /master/meta
 * (Config\MasterData backend = satu sumber kebenaran), sehingga halaman master bersifat generik.
 */
import { Role, type RoleCode } from '@/features/auth/types'

/** Seluruh CRUD master = role 1 (Matriks Role x Endpoint Modul G). */
export const MASTER_DATA_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN]

export type MasterStatus = '0' | '1'

export interface MasterMeta {
  key: string
  label: string
  primary_key: string
  id_max_length: number
  name_field: string
  name_label: string
  name_max_length: number
  parent: { field: string; entity: string } | null
}

/** Baris master: kolom dinamis per tabel + kolom standar order/status (+ parent_nama untuk master berinduk). */
export type MasterRow = Record<string, string | number | null> & {
  order: number | string
  status: MasterStatus
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
