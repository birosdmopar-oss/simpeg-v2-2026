/**
 * Role per menu/halaman Modul B (Fase 3) mengikuti Matriks v2 (`docs/fase3/MATRIKS_ROLE_MODUL_B.md`).
 * Tampilan menu & guard route hanya UX — RoleFilter backend tetap yang menegakkan (ADR-024).
 */
import { Role, type RoleCode } from '@/features/auth/types'

/** Daftar pegawai (`hr/employee/index` = 1, 3, 4, 5, 8). */
export const PEGAWAI_LIST_ROLES: readonly RoleCode[] = [
  Role.SUPER_ADMIN,
  Role.ADMIN_SATKER,
  Role.ADMIN_VIEW_ESELON1,
  Role.MENTERI,
  Role.PIMPINAN,
]

/** Usulan Konket (`hr/rwy/konket/*` = 1, 2, 3, 6, 7). */
export const KONKET_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.PEGAWAI, Role.ADMIN_SATKER, Role.PTT, Role.PPPK]

/**
 * Verifikasi LKH (`hr/rwy/lkh/*` = 1, 2, 3, 6, 7). Approver = atasan langsung (ikut legacy) — penentuan atasan
 * dilakukan backend; menu hanya dibatasi ke role yang punya akses LKH.
 */
export const LKH_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.PEGAWAI, Role.ADMIN_SATKER, Role.PTT, Role.PPPK]

/** Usulan Karpeg/Karis/Karsu (`hr/rwy/karpeg/*`, `hr/rwy/kariskarsu/*` = 1, 2, 4, 5, 7). */
export const KARPEG_KARIS_ROLES: readonly RoleCode[] = [
  Role.SUPER_ADMIN,
  Role.PEGAWAI,
  Role.ADMIN_VIEW_ESELON1,
  Role.MENTERI,
  Role.PPPK,
]
