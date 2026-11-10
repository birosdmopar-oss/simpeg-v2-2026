/**
 * Tipe halaman Admin → Integrasi (MAKE-078, ISSUE-058; PRD Fase 6 §6.2, F6-24). Kontrak backend:
 * `backend/app/Controllers/Api/Integrasi/README.md` — `GET api/v1/integrasi/log` dan `POST api/v1/integrasi/bkn/tarik`.
 */
import { Role, type RoleCode } from '@/features/auth/types'
import { API_DATETIME_FORMAT, formatApiDateTime } from '@/shared/utils/dateTime'

/** Halaman Integrasi: Super Admin saja (kedua endpoint `Role::filter(SUPER_ADMIN)`). */
export const INTEGRASI_ADMIN_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN]

/** `integrasi_log.status`: 1 sukses, 2 gagal. */
export type StatusLogIntegrasi = 1 | 2

/** `integrasi_log.arah`: 1 keluar (v2 memanggil layanan luar), 2 masuk (klien memanggil v2). */
export type ArahLogIntegrasi = 1 | 2

/** Satu baris `integrasi_log` (DBV-027); `created_at` UTC tanpa zona (tampil WIB lewat `formatWaktuWib`). */
export interface LogIntegrasiRow {
  id_integrasi_log: number
  arah: ArahLogIntegrasi
  layanan: string
  operasi: string
  status: StatusLogIntegrasi
  http_status: number | null
  kode: string | null
  pesan: string | null
  durasi_ms: number | null
  percobaan: number
  ref_id: string | null
  id_klien: number | null
  created_at: string
}

/** Filter daftar; string kosong = tidak disaring. Tanggal `YYYY-MM-DD` WIB inklusif. */
export interface LogIntegrasiFilter {
  layanan: string
  status: '' | '1' | '2'
  tanggal_mulai: string
  tanggal_selesai: string
  page: number
  per_page: number
}

export interface DaftarLogIntegrasi {
  items: LogIntegrasiRow[]
  total: number
  page: number
  per_page: number
  /** Kode layanan yang ada di tabel (pilihan filter). */
  layanan_tersedia: string[]
}

export type JenisTarikEkinerja = 'periode' | 'skp_periodik'

export interface TarikEkinerjaPayload {
  jenis: JenisTarikEkinerja
  tahun?: number
  bulan?: number
}

/** Respons 202: job diantre di antrean `integrasi`, bukan dijalankan sinkron. */
export interface TarikEkinerjaHasil {
  jenis: JenisTarikEkinerja
  tahun: number | null
  bulan: number | null
  antre: true
}

export const LABEL_STATUS: Record<StatusLogIntegrasi, string> = { 1: 'Sukses', 2: 'Gagal' }

export const LABEL_ARAH: Record<ArahLogIntegrasi, string> = { 1: 'Keluar', 2: 'Masuk' }

/** Nama tampilan kode layanan yang dikenal; kode lain tampil apa adanya. */
export const LABEL_LAYANAN: Readonly<Record<string, string>> = {
  bkn: 'BKN (token)',
  ekinerja: 'e-Kinerja',
  bsre: 'BSrE (e-sign)',
  siasn: 'SIASN',
  fcm: 'FCM (push)',
  simapi: 'SIMAPI',
  services: 'Services (s_*)',
}

export function labelLayanan(kode: string): string {
  return LABEL_LAYANAN[kode] ?? kode
}

/** Opsi tampilan waktu log: format bawaan API v2, selalu zona WIB (filter tanggal juga tanggal WIB). */
export const WAKTU_WIB: Readonly<Intl.DateTimeFormatOptions> = Object.freeze({ ...API_DATETIME_FORMAT, timeZone: 'Asia/Jakarta' })

/**
 * `created_at` (UTC tanpa zona, CR-086) → "2 Nov 2026, 10.00 WIB", apa pun zona browser — selaras dengan filter tanggal
 * yang juga tanggal WIB. Tidak terbaca → teks apa adanya.
 */
export function formatWaktuWib(createdAt: string): string {
  const teks = formatApiDateTime(createdAt, WAKTU_WIB)
  return teks === null ? createdAt : `${teks} WIB`
}
