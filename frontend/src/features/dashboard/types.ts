/** Modul F — Dashboard & News (Fase 7). Tipe domain ditambah di bawah roles. */
import { Role, type RoleCode } from '@/features/auth/types'

/**
 * Dashboard "Admin" (statistik kepegawaian, Gambar 12) — ASUMSI: role 1, 3, 4. Matriks hanya menyatakan
 * "user/dashboard = semua role, tampilan beda per role"; role lain memakai dashboard Pengguna/Pimpinan (Gambar 25).
 */
export const ADMIN_DASHBOARD_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1]

/** Tab "Kehadiran Tim" (Gambar 26) hanya untuk pimpinan. ASUMSI: role 5 (Menteri) dan 8 (Pimpinan). */
export const TEAM_ATTENDANCE_ROLES: readonly RoleCode[] = [Role.MENTERI, Role.PIMPINAN]
