/**
 * Tipe G-09 Web Config (DBV-006/CR-030), snake_case end-to-end (ADR-023). Mengikuti WebConfigController/WebConfigService
 * backend: katalog key bertipe ada di kode backend (Config\WebConfig); admin hanya mengubah nilai, bukan tipe.
 * Semua endpoint role 1 (Matriks Modul G); tidak ada endpoint baca publik.
 */
import type { RoleCode } from '@/features/auth/types'

import { MASTER_DATA_ROLES } from './types'

/** Kelola web config: role 1 (legacy `hr/master/c_web`, `userAuth(['1'])`). */
export const WEB_CONFIG_ROLES: readonly RoleCode[] = MASTER_DATA_ROLES

export type WebConfigType =
  | 'text'
  | 'textarea'
  | 'html'
  | 'integer'
  | 'decimal'
  | 'id_ref'
  | 'url'
  | 'asset_path'
  | 'time'
  | 'email_list'

export interface WebConfigConstraints {
  /** text: batas karakter */
  max_length?: number
  /** integer/decimal */
  min?: number
  max?: number
  /** decimal: digit di belakang titik */
  scale?: number
  /** id_ref: tabel rujukan legacy (unit/satker/jabatan) */
  ref?: string
}

export interface WebConfigItem {
  config_name: string
  /** null bila belum ada baris tersimpan (memakai bawaan). */
  id_web_config: number | null
  /** Nilai tersimpan apa adanya (null = belum diatur). */
  config_value: string | null
  remark: string | null
  updated_at: string | null
  updated_by: number | null
  /** true = belum ada baris, nilai berlaku = bawaan katalog. */
  is_default: boolean
  /** false = key hasil impor yang tidak ada di katalog v2 (read-only, hanya bisa dihapus). */
  known: boolean
  label: string
  group: string
  type: WebConfigType | null
  description: string
  default: string | null
  /** Nilai yang dipakai aplikasi: tersimpan bila sah, selain itu bawaan. */
  effective: string | null
  /** false = nilai tersimpan tidak sesuai tipe (mis. impor) sehingga aplikasi memakai bawaan. */
  valid: boolean
  /** Nilai berisi data pribadi (mis. daftar email). */
  personal: boolean
  constraints: WebConfigConstraints
  /** Hanya pada respons hapus key tak dikenal. */
  deleted?: boolean
}

export interface WebConfigListResponse {
  items: WebConfigItem[]
}

export interface WebConfigPayload {
  config_value: string
  remark?: string
}

export interface WebConfigDeleteResponse {
  deleted: boolean
  item: WebConfigItem
}

export const WEB_CONFIG_TYPE_LABELS: Record<WebConfigType, string> = {
  text: 'Teks',
  textarea: 'Teks panjang',
  html: 'HTML',
  integer: 'Bilangan bulat',
  decimal: 'Desimal',
  id_ref: 'ID rujukan',
  url: 'URL',
  asset_path: 'Path aset',
  time: 'Jam',
  email_list: 'Daftar email',
}
