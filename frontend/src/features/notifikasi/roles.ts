/**
 * Role F-08 ikut legacy (Q7-29, PRD Fase 7 v2.0 §6) — sama dengan filter route backend `Config/RoutesNotifikasi.php`.
 */
import { Role, type RoleCode } from '@/features/auth/types'

/** Daftar notifikasi `hr/notification/index` (`NC:12`). */
export const NOTIFIKASI_DAFTAR_ROLES: readonly RoleCode[] = [Role.PEGAWAI, Role.ADMIN_SATKER, Role.PTT, Role.PPPK]

/** Hapus notifikasi (`NC:120`). */
export const NOTIFIKASI_HAPUS_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.PEGAWAI, Role.PTT, Role.PPPK]

/** Butuh approval & histori approval (`NC:39,68`). */
export const NOTIFIKASI_APPROVAL_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.PEGAWAI, Role.ADMIN_SATKER]

/** Role yang punya halaman Pusat Notifikasi (gabungan daftar + approval). */
export const NOTIFIKASI_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.PEGAWAI, Role.ADMIN_SATKER, Role.PTT, Role.PPPK]

/**
 * Alias untuk konsumen lain (kartu "Butuh Approval" dashboard Jalur D): role yang angka `butuh_approval`-nya berlaku dan
 * punya halaman Butuh Approval. UL 7 tidak termasuk: legacy hanya mengisi penanda petugas untuk UL 2 (`MY_Controller:46-68`).
 */
export const BUTUH_APPROVAL_ROLES: readonly RoleCode[] = NOTIFIKASI_APPROVAL_ROLES
