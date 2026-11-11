import { Role, type RoleCode } from '@/features/auth/types'

/** Pengisi SKL: role 2, 6 (Q7-23) [K `core/MY_Controller.php:466`]. */
export const SKL_PENGISI_ROLES: readonly RoleCode[] = [Role.PEGAWAI, Role.PTT]

/** Rekap & ringkasan SKL: role 1. */
export const SKL_REKAP_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN]
