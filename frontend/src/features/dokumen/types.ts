/**
 * Generator dokumen F-09 (Fase 7, MAKE-037) — kontrak `api/v1/dokumen/*` (`backend/app/Controllers/Api/Dashboard/README.md`
 * bagian F-09; PRD Fase 7 §6.2, §7.2).
 */
import { LAPORAN_ROLES, type LingkupStatistik } from '@/features/laporan/types'
import type { RoleCode } from '@/features/auth/types'

/** `hr/document/*` Matriks v2 = role 1, 3, 4, 5, 8 (sama dengan laporan statistik); role 3 dibatasi satker/unit akun. */
export const DOKUMEN_ROLES: readonly RoleCode[] = LAPORAN_ROLES

/** Empat jenis dokumen (PRD §6.2), sama dengan segmen `{jenis}` endpoint. */
export const DOKUMEN_JENIS = ['duk', 'nominatif', 'pensiun', 'baperjakat'] as const
export type DokumenJenis = (typeof DOKUMEN_JENIS)[number]

/** Label submenu per jenis. */
export const DOKUMEN_LABEL: Record<DokumenJenis, string> = {
  duk: 'DUK',
  nominatif: 'Nominatif',
  pensiun: 'Proyeksi Pensiun',
  baperjakat: 'Baperjakat',
}

/**
 * Kolom inti tabel halaman (key `items`); label diambil dari `kolom` respons. Kolom lengkap (37 / 11) ada di XLSX.
 */
export const KOLOM_INTI: Record<DokumenJenis, readonly string[]> = {
  duk: ['nama', 'nip', 'gol_ruang', 'tmtsk_pangkat', 'jabatan', 'satker', 'tmtsk_cpns', 'tgl_lahir'],
  nominatif: ['nama', 'nip', 'gol_ruang', 'jabatan', 'satker', 'strata', 'tgl_lahir', 'jenis_kelamin'],
  pensiun: ['nama', 'nip', 'tgl_lahir', 'jabatan', 'satker', 'eselon', 'tgl_pensiun', 'usia_th'],
  baperjakat: ['nama', 'nip', 'gol_ruang', 'jabatan', 'satker', 'eselon', 'strata'],
}

/** Batas PDF backend (`DokumenService::PDF_MAKS_BARIS`, fallback F7-15 / ADR-034): PDF per unit/satker, maks 1.700 baris. */
export const PDF_MAKS_BARIS = 1700

/** Batas NIP Baperjakat (`BaperjakatInput::NIP_MAKS`) dan panjang teks. */
export const BAPERJAKAT_NIP_MAKS = 2000
export const BAPERJAKAT_JUDUL_MAKS = 150
export const BAPERJAKAT_TEKS_MAKS = 1000

export type DokumenFormat = 'pdf' | 'xlsx'

export type DokumenItem = { no: number } & Record<string, string | number>

/** `GET dokumen/{jenis}` → `data`. */
export interface DokumenData {
  jenis: DokumenJenis
  judul: string
  lingkup: LingkupStatistik
  /** Pensiun: `{tahun, sampai}`; jenis lain kosong. */
  parameter: { tahun?: number; sampai?: number } | unknown[]
  /** key → label kolom lengkap (37 atau 11). */
  kolom: Record<string, string>
  items: DokumenItem[]
  total: number
  page: number
  per_page: number
  periode: string
}

export interface DokumenQuery {
  search?: string
  page?: number
  per_page?: number
  tahun?: string
  sampai?: string
}

/** Body `POST dokumen/baperjakat/{pdf|xlsx}`. */
export interface BaperjakatBody {
  nip: string[]
  judul: string
  subjudul?: string
  teks?: string
}
