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

/**
 * Tipe field tambahan (App\Libraries\MasterData\MasterField). `html` = konten HTML (mis. isi artikel FAQ):
 * textarea besar + pratinjau lewat sanitizeHtml(); backend menyanitasi ulang (HTMLPurifier) saat simpan.
 * CR-009: `boolean` = flag 1/0 (checkbox), `ref` = rujukan ke master lain (dropdown `{entity}/options`, berjenjang
 * lewat `depends_on`).
 */
export type MasterFieldType = 'text' | 'textarea' | 'int' | 'decimal' | 'date' | 'select' | 'html' | 'boolean' | 'ref'

export interface MasterFieldMeta {
  name: string
  label: string
  type: MasterFieldType
  required: boolean
  options: Array<{ value: string; label: string }> | null
  hint: string | null
  /** Batas panjang dalam byte UTF-8 (mis. TINYTEXT = 255), bila diekspos backend; ikut divalidasi di form. */
  max_bytes?: number | null
  /** Batas nilai field angka (int: rentang tipe kolom, mis. TINYINT 0–127; CR-009); null = tanpa batas. */
  min?: number | null
  max?: number | null
  /** Tipe ref: key master rujukan (pilihan dari `{entity}/options`). */
  entity?: string | null
  /** Tipe ref: field ref lain di form yang menjadi induk entri rujukan (pilihan disaring `?parent=` nilainya). */
  depends_on?: string | null
}

/**
 * Mode urutan (CR-009): `shift` = posisi tampil 1..n (entri lain bergeser); `manual` = nilai bisnis (mis. level
 * pangkat) yang disimpan apa adanya, entri lain tidak pernah digeser.
 */
export type MasterOrderMode = 'shift' | 'manual'

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
  /** CR-009 — bawaan `shift`. */
  order_mode?: MasterOrderMode
  /** Field pembentuk lingkup urutan selain induk (mis. `jenis_diklat`): urutan berlaku per nilainya. */
  order_scope?: string[]
  /** Batas nilai urutan mode manual (tipe kolom `order`, mis. TINYINT = 127); null untuk mode shift. */
  order_max?: number | null
  /** Field yang boleh dipakai filter `?kolom=nilai` di daftar & dropdown (allowlist backend). */
  filters?: string[]
  /** Dropdown hanya memuat entri yang seluruh rantai induknya aktif (pola FAQ). */
  status_chain?: boolean
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
  /** Filter field allowlist master (meta `filters`), mis. { jenis_diklat: '2' }; nilai kosong diabaikan. */
  filters?: Record<string, string>
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

// ------------------------------------------------------------------
// G-10 FAQ — halaman baca pegawai & rating (DBV-002/CR-003 §4). Kelola konten memakai master generik
// faq-topic / faq-sub-topic / faq-article (role 1). Id dinormalkan ke string oleh faqService.
// ------------------------------------------------------------------

/** Id dari API: kolom INT, bisa terkirim sebagai angka atau string. */
export type FaqApiId = number | string

export interface FaqRef {
  id: string
  nama: string
}

export interface FaqArticleRef {
  id: string
  title: string
}

export interface FaqSubTopic {
  id: string
  nama: string
  articles: FaqArticleRef[]
}

export interface FaqTopic {
  id: string
  nama: string
  sub_topics: FaqSubTopic[]
}

export interface FaqSearchResult {
  id: string
  title: string
  topic: FaqRef
  sub_topic: FaqRef
  /** Kalimat pertama isi artikel (teks polos, maks 200 karakter). */
  snippet: string
}

/** 1 = Membantu, 2 = Kurang Membantu (faq_rate.rate legacy). */
export type FaqRate = 1 | 2

export interface FaqRating {
  /** Role 2/6/7 (UL_PEGAWAI) yang belum menilai artikel ini. */
  can_rate: boolean
  rated: boolean
  rate: FaqRate | null
}

export interface FaqArticleDetail {
  id: string
  title: string
  /** HTML yang sudah disanitasi backend; tetap dirender lewat sanitizeHtml(). */
  content: string
  topic: FaqRef
  sub_topic: FaqRef
  updated_at: string | null
  /** Maks 5 artikel aktif lain di sub topik yang sama (dihitung otomatis seperti legacy). */
  related: FaqArticleRef[]
  rating: FaqRating
}

export interface FaqRatePayload {
  rate: FaqRate
  /** Wajib bila rate = 2 (maks 255 byte); tidak dikirim bila rate = 1. */
  reason?: string
}

export interface FaqRateResponse {
  rated: boolean
  rate: FaqRate
}

/** Bentuk mentah respons API (sebelum normalisasi id). */
export interface FaqApiRef {
  id: FaqApiId
  nama: string
}

export interface FaqApiArticleRef {
  id: FaqApiId
  title: string
}

export interface FaqApiTreeResponse {
  topics: Array<FaqApiRef & { sub_topics?: Array<FaqApiRef & { articles?: FaqApiArticleRef[] }> }>
}

export interface FaqApiSearchResponse {
  results: Array<FaqApiArticleRef & { topic: FaqApiRef; sub_topic: FaqApiRef; snippet: string | null }>
  search: string
}

export interface FaqApiArticleResponse extends FaqApiArticleRef {
  content: string | null
  topic: FaqApiRef
  sub_topic: FaqApiRef
  updated_at: string | null
  related?: FaqApiArticleRef[]
  rating?: { can_rate: boolean; rated: boolean; rate: FaqApiId | null }
}

export interface FaqApiRateResponse {
  rated: boolean
  rate: FaqApiId
}
