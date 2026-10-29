/**
 * Role per menu/halaman Modul D paket 5A (PRD `docs/fase5/PRD_Fase5A_Skema-SlipGaji-Presensi.md` §6, §7.8.2).
 * Tampilan menu & guard route hanya UX — RoleFilter backend tetap yang menegakkan (ADR-024).
 */
import { Role, type RoleCode } from '@/features/auth/types'

/** Slip Gaji pegawai (`hr/sl_gaji/list|mark_viewed|cetak` = 2, 6, 7; NIP dari token). */
export const SLIP_GAJI_PEGAWAI_ROLES: readonly RoleCode[] = [Role.PEGAWAI, Role.PTT, Role.PPPK]

/** Kelola Slip Gaji (`hr/sl_gaji/admin|import_*|edit_save|reset_access` = 1, 3; SG-2). */
export const SLIP_GAJI_ADMIN_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.ADMIN_SATKER]

/** Presensi Saya (`hr/services/local/po_in|po_out`, `hr/presensi/view_presensi|list_ptt` = 2, 6, 7). */
export const PRESENSI_PEGAWAI_ROLES: readonly RoleCode[] = [Role.PEGAWAI, Role.PTT, Role.PPPK]

/** Data Presensi (`hr/presensi/list_ad|list` = 1, 3, 4, 5; F5-15). */
export const PRESENSI_ADMIN_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI]

/** Penyesuaian Tukin per NIP (5B F5B-14, PRD 5C §6): CRUD hanya Super Admin (`PenyesuaianTukinController` role 1). */
export const PENYESUAIAN_TUKIN_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN]
