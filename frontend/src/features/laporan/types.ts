/** Laporan & statistik kepegawaian (Fase 7). */
import { Role, type RoleCode } from '@/features/auth/types'

/** Grafik/statistik kepegawaian (hr/chart/*, Matriks Role x Endpoint): role 1, 3, 4, 5, 8. */
export const LAPORAN_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PIMPINAN]

/** Tiga laporan pada submenu (Gambar 15): hanya "Unit Kerja" yang digambarkan mockup; dua lainnya mengikuti pola yang sama. */
export const LAPORAN_TIPE = ['unit-kerja', 'jenis-kelamin', 'struktural'] as const
export type LaporanTipe = (typeof LAPORAN_TIPE)[number]

export interface LaporanSeries {
  label: string
  /** Warna batang/donat — monoton kebiruan sesuai anotasi "Pewarnaan Grafik" (Gambar 16). */
  color: string
  /** Nada bilah progres pada tabel. */
  tone: 'navy' | 'sky'
}

export interface LaporanBaris {
  label: string
  /** Satu angka per series (urutan sama dengan `series`). */
  values: number[]
}

export interface LaporanData {
  tipe: LaporanTipe
  title: string
  /** Judul kolom pertama tabel, mis. "Unit Kerja Eselon 1". */
  kolomLabel: string
  series: LaporanSeries[]
  baris: LaporanBaris[]
  /** Donat kiri: distribusi per baris. */
  donatBaris: { title: string; subtitle: string }
  /** Donat kanan: total per series. */
  donatSeries: { title: string; subtitle: string }
}
