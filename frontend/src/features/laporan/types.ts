/**
 * Laporan statistik kepegawaian F-03 (Fase 7, MAKE-036) — kontrak `GET api/v1/statistik/*`
 * (`backend/app/Controllers/Api/Dashboard/README.md`; PRD Fase 7 §6.1, §7.1.2).
 */
import { Role, type RoleCode } from '@/features/auth/types'

/** `hr/chart/*` Matriks v2 = role 1, 3, 4, 5, 8; role 3 dibatasi satker/unit akun oleh backend (PegawaiScope). */
export const LAPORAN_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PIMPINAN]

/** Tujuh jenis statistik (PRD F7-1), sama dengan segmen `{jenis}` endpoint. */
export const LAPORAN_TIPE = ['unit-kerja', 'jenis-kelamin', 'jabatan', 'eselon', 'pendidikan', 'usia', 'golongan'] as const
export type LaporanTipe = (typeof LAPORAN_TIPE)[number]

/** Label submenu/judul pendek per tipe (nav mengikuti mockup Gambar 15 + 4 tipe tambahan). */
export const LAPORAN_LABEL: Record<LaporanTipe, string> = {
  'unit-kerja': 'Unit Kerja',
  'jenis-kelamin': 'Jenis Kelamin',
  jabatan: 'Jabatan',
  eselon: 'Struktural',
  pendidikan: 'Pendidikan',
  usia: 'Usia',
  golongan: 'Golongan',
}

export type LingkupTipe = 'kementerian' | 'unit' | 'satker'

export interface LingkupStatistik {
  tipe: LingkupTipe
  id: number | null
  nama: string
}

/** `GET statistik/lingkup`: lingkup yang boleh dipilih akun (kementerian null untuk role 3). */
export interface LingkupTersedia {
  kementerian: { nama: string } | null
  unit: Array<{ id: number; nama: string; satker: Array<{ id: number; nama: string }> }>
}

export interface StatistikSeri {
  key: string
  label: string
}

export interface StatistikBaris {
  kategori_id: string
  label: string
  /** Satu angka per seri (urutan sama dengan `seri`). */
  nilai: number[]
  total: number
}

/** `GET statistik/{jenis}` → `data`. */
export interface StatistikData {
  jenis: LaporanTipe
  judul: string
  lingkup: LingkupStatistik
  /** Judul kolom pertama tabel ("Unit Kerja Eselon I" / "Satuan Kerja"). */
  label_kategori: string
  kategori: Array<{ id: string; label: string }>
  seri: StatistikSeri[]
  baris: StatistikBaris[]
  subtotal: number[]
  total: number
  /** Tanggal data (Y-m-d). */
  periode: string
  cache: { hit: boolean; ttl: number }
}

/** Nilai pemilih lingkup di halaman: '' = bawaan server, `kementerian`, `unit:<id>`, `satker:<id>`. */
export type LingkupPilihan = '' | 'kementerian' | `unit:${number}` | `satker:${number}`

export interface LingkupQuery {
  lingkup?: LingkupTipe
  id?: string
}

/** Palet seri (batang/donat): monoton kebiruan lalu aksen, sesuai anotasi "Pewarnaan Grafik" (Gambar 16). */
export const SERI_PALET = ['#1e3a8a', '#0ea5e9', '#6366f1', '#14b8a6', '#f59e0b', '#ef4444', '#8b5cf6', '#64748b'] as const
export const DONAT_PALET = ['#1e3a8a', '#2563eb', '#0ea5e9', '#38bdf8', '#7dd3fc', '#6366f1', '#a5b4fc', '#14b8a6', '#f59e0b', '#64748b'] as const
export const PROGRES_TONE = ['primary', 'info', 'violet', 'success', 'warning', 'danger'] as const
