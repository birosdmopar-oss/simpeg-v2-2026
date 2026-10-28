/**
 * Role per menu/halaman Modul C (Fase 4) mengikuti PRD Fase 4 §6.11.2 dan kontrak route backend
 * (`Config/RoutesLayanan.php`, `RoutesLayananStudi.php`). Tampilan menu & guard route hanya UX — filter role backend
 * tetap yang menegakkan (ADR-024). Hak per baris/aksi diambil dari server (`aksi {}`, `can_create`, `bisa_memproses`).
 */
import { Role, type RoleCode } from '@/features/auth/types'

/** Cuti (`cuti*` = 1, 2, 3, 6, 7). Role 3/6 tidak bisa mengajukan (server: `can_create`). */
export const CUTI_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.PEGAWAI, Role.ADMIN_SATKER, Role.PTT, Role.PPPK]

/** Saldo Cuti & Petugas Approval Cuti (1, 3). */
export const CUTI_ADMIN_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.ADMIN_SATKER]

/** Tab "Cuti Saya" / tombol "Ajukan Cuti" ditampilkan untuk role pemilik; hak sebenarnya dari server (`can_create`). */
export const CUTI_SAYA_ROLES: readonly RoleCode[] = [Role.PEGAWAI, Role.PTT, Role.PPPK]

/** Tugas Belajar (1, 2, 3, 6) — role 7 (PPPK) tidak punya TB (DoD 070-4). */
export const TB_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.PEGAWAI, Role.ADMIN_SATKER, Role.PTT]

/** Izin Belajar (1, 2, 3, 7) — role 6 (PTT) tidak punya IB (DoD 071-3). */
export const IB_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.PEGAWAI, Role.ADMIN_SATKER, Role.PPPK]

/** PMK (1, 2, 3). */
export const PMK_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.PEGAWAI, Role.ADMIN_SATKER]

/** Status Layanan (1–7; role 8 tidak). Lingkup data ditegakkan backend. */
export const STATUS_LAYANAN_ROLES: readonly RoleCode[] = [
  Role.SUPER_ADMIN,
  Role.PEGAWAI,
  Role.ADMIN_SATKER,
  Role.ADMIN_VIEW_ESELON1,
  Role.MENTERI,
  Role.PTT,
  Role.PPPK,
]

/** Filter Unit/Satker di Status Layanan hanya untuk role ber-lingkup luas (1, 3, 4, 5). */
export const STATUS_LAYANAN_FILTER_UNIT_ROLES: readonly RoleCode[] = [
  Role.SUPER_ADMIN,
  Role.ADMIN_SATKER,
  Role.ADMIN_VIEW_ESELON1,
  Role.MENTERI,
]

/** Petugas Layanan (PIC) — hanya Super Admin. */
export const PETUGAS_LAYANAN_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN]

/** Terbitkan TTE surat cuti (1, 3; `aksi.esign` dari server). */
export const ESIGN_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.ADMIN_SATKER]
