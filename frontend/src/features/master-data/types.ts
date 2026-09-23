/**
 * Tipe Modul G — Master Data (snake_case end-to-end, ADR-023). Mengikuti
 * backend/app/Controllers/Api/MasterData/README.md. Metadata tiap master datang dari GET /master/meta
 * (Config\MasterData backend = satu sumber kebenaran), sehingga halaman master bersifat generik.
 */
import { Role, type RoleCode } from '@/features/auth/types'

/** Seluruh CRUD master = role 1 (Matriks Role x Endpoint Modul G). */
export const MASTER_DATA_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN]

export type MasterStatus = '0' | '1'

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
  /** PK diberikan database (kode tidak diinput admin). */
  auto_increment: boolean
  name_field: string
  name_label: string
  name_max_length: number
  has_order: boolean
  has_status: boolean
  parent: { field: string; entity: string } | null
  fields: MasterFieldMeta[]
}

/** Baris master: kolom dinamis per tabel + kolom standar order/status (+ parent_nama untuk master berinduk). */
export type MasterRow = Record<string, string | number | null> & {
  order?: number | string
  status?: MasterStatus
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

/** Nilai form generik: kode, induk, nama (key = nama kolom backend), field tambahan, order opsional. */
export type MasterFormValues = Record<string, string | undefined>

// ------------------------------------------------------------------
// G-08 Hari Libur
// ------------------------------------------------------------------

export interface HariLibur {
  id_hari_libur: number
  id_jenis_libur: string
  nama: string
  tgl_mulai: string
  tgl_akhir: string
  keterangan: string | null
  status: MasterStatus
}

export interface HariLiburQuery {
  search?: string
  status?: MasterStatus | ''
  id_jenis_libur?: string
  tahun?: string
  page?: number
  per_page?: number
}

export interface HariLiburListResponse {
  items: HariLibur[]
  total: number
  page: number
  per_page: number
}

export type HariLiburPayload = Omit<HariLibur, 'id_hari_libur' | 'status'> & { status?: MasterStatus }

// ------------------------------------------------------------------
// G-09 Web Config
// ------------------------------------------------------------------

export const WEB_CONFIG_TYPES = ['string', 'text', 'integer', 'decimal', 'time', 'boolean'] as const
export type WebConfigType = (typeof WEB_CONFIG_TYPES)[number]

export interface WebConfig {
  id_web_config: number
  config_name: string
  config_value: string | null
  tipe_data: WebConfigType
  keterangan: string | null
  value_casted: string | number | boolean | null
}

export interface WebConfigPayload {
  config_name?: string
  config_value: string
  tipe_data?: WebConfigType
  keterangan?: string
}

// ------------------------------------------------------------------
// G-10 FAQ (view pegawai)
// ------------------------------------------------------------------

export interface FaqArticleRef {
  id_article: string
  judul: string
}

export interface FaqSubTopic {
  id_sub_topic: string
  nama_sub_topic: string
  articles: FaqArticleRef[]
}

export interface FaqTopic {
  id_topic: string
  nama_topic: string
  sub_topics: FaqSubTopic[]
}

export interface FaqArticle {
  id_article: string
  judul: string
  isi: string | null
  id_sub_topic: string
  nama_sub_topic: string
  id_topic: string
  nama_topic: string
}
