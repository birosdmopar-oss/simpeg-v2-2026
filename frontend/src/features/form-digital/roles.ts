import { Role, type RoleCode } from '@/features/auth/types'

/** Pengisi form digital (role 2/6/7) [K `core/MY_Controller.php:246`]. */
export const FORM_DIGITAL_PENGISI_ROLES: readonly RoleCode[] = [Role.PEGAWAI, Role.PTT, Role.PPPK]

/** Kelola form digital: role 1. */
export const FORM_DIGITAL_KELOLA_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN]

/**
 * Fase aktif minimum agar host interaksi shell (form digital, SKL) memanggil API — selama `ACTIVE_PHASE` < nilai ini host
 * diam tanpa request (pola lonceng Jalur C, syarat reviewer 11-11).
 */
export const FASE_INTERAKSI = 7
