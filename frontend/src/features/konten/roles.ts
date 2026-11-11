/** Hak akses halaman kelola konten Fase 7 (PRD v2.0 §6): guard FE hanya UX (ADR-024), backend tetap menegakkan. */
import { Role, type RoleCode } from '@/features/auth/types'

/** Kelola berita: role 1 dan 3 (role 3 → menunggu persetujuan, Q7-07). */
export const KONTEN_BERITA_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.ADMIN_SATKER]

/** Kelola popup, banner, kategori berita, DIBO, dan jenis DIBO: role 1 saja. */
export const KONTEN_ADMIN_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN]
