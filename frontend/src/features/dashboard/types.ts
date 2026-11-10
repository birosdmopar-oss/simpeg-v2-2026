/**
 * Beranda per role (F-13, MAKE-087; PRD Fase 7 v2.0 §5.10). Kontrak: `backend/app/Controllers/Api/Dashboard/README.md`
 * — `GET api/v1/dashboard/ringkasan` (jwt, semua role; bentuk per keluarga role).
 */
import { Role, type RoleCode } from '@/features/auth/types'

/** Keluarga dashboard admin [K] (`User.php:526-567`: role 1/4/5/8 → dashboard_sa, 3 → satker/unit). */
export const ADMIN_DASHBOARD_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PIMPINAN]

export type TampilanKomposisi = 'donut' | 'angka'

export type KelompokTile = 'komposisi' | 'hari_ini'

/** Satu tile `views/user/dashboard_ad.php` (urutan & label dari backend = view legacy). */
export interface TileBeranda {
  kode: string
  label: string
  kelompok: KelompokTile
  jumlah: number
  /** round(jumlah / total_aktif × 100), seperti view legacy. */
  persen: number
}

/** Kartu pengingat `dashboard_ad.php:8-37`: Pensiun, Naik Pangkat, Tanda Jasa, KGB. */
export interface KartuPengingat {
  kode: 'pensiun' | 'kp' | 'tj' | 'kgb'
  label: string
  jumlah: number
}

export interface RingkasanAdmin {
  keluarga: 'admin'
  lingkup: { jenis: 'kementerian' | 'satker' | 'unit'; id: number | null }
  /** Saklar Q7-36 (`Config\Dashboard::$tampilanKomposisi`): `donut` [V2] atau `angka` (persis legacy). */
  tampilan_komposisi: TampilanKomposisi
  /** Tanggal bisnis WIB `YYYY-MM-DD` yang dipakai angka "hari ini". */
  tanggal: string
  total_aktif: number
  pengingat: KartuPengingat[]
  tile: TileBeranda[]
}

/** Baris tabel "Masa Kerja Direktur Politeknik" (role 1/4, `GET /dashboard/direktur`). */
export interface DirekturPoliteknik {
  nama: string
  politeknik: string
  masa_kerja: string
}

/** Tabel direktur hanya role 1 & 4 [K `dashboard_ad.php:201`]. */
export const DIREKTUR_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.ADMIN_VIEW_ESELON1]

/** Kerangka M1: kartu prediksi/kelengkapan/bawahan menyusul (M2). */
export interface RingkasanPegawai {
  keluarga: 'pegawai'
}

export type RingkasanBeranda = RingkasanAdmin | RingkasanPegawai

/** Palet donut komposisi (biru → toska, selaras palet laporan F-03). */
export const PALET_KOMPOSISI = ['#1e3a8a', '#2563eb', '#0ea5e9', '#38bdf8', '#14b8a6', '#6366f1'] as const
