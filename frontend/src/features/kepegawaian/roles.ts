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

/**
 * Usulan Konket (`hr/rwy/konket/*` = 1, 2, 3, 6, 7) + [K] role 4, 5 lihat saja (legacy list_ad/detail; keputusan user
 * 13-10-2026, MAKE-013): antrean + detail tanpa Tambah/aksi (server `can_create`/`aksi`).
 */
export const KONKET_ROLES: readonly RoleCode[] = [
  Role.SUPER_ADMIN,
  Role.PEGAWAI,
  Role.ADMIN_SATKER,
  Role.ADMIN_VIEW_ESELON1,
  Role.MENTERI,
  Role.PTT,
  Role.PPPK,
]

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

/**
 * "Kelola Data" = profil/riwayat pegawai milik akun (CR-049 ISSUE-034). [K] legacy sidebar pegawai/ptt/pppk
 * (`views/template/sidebar_menu/{pegawai,ptt,pppk}.php` → `hr/employee/edit`, data sendiri); role 4/5/8 membuka profil
 * lewat Daftar Pegawai (keputusan user 15-10-2026). Tampil hanya bila akun punya NIP.
 */
export const DATA_SAYA_ROLES: readonly RoleCode[] = [Role.PEGAWAI, Role.PTT, Role.PPPK]

/** Antrean pengajuan perubahan Data Umum (B-04 `biodata/pengajuan` = role 1, 3; legacy process userAuth ['1', '3']). */
export const BIODATA_PROSES_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.ADMIN_SATKER]

/**
 * Penugasan Plt/Plh (`hr/rwy/jabatan/list_off_plt|plh`, B-07 MAKE-012): lihat 1, 3 (lingkup satker/unit), 4, 5;
 * tambah/ubah/hapus hanya role 1 (legacy userAuth ['1']). Server tetap yang menegakkan.
 */
export const PENUGASAN_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI]
export const PENUGASAN_TULIS_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN]
/** Master Angka Kredit JF `dm_ak_jf` (MAKE-021; legacy `C_jabatan::akjf` userAuth ['1']). */
export const AK_JF_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN]
