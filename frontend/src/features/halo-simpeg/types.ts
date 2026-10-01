/** Modul E — Halo Simpeg (Fase 8). Tipe domain ditambah di bawah roles. */
import { Role, type RoleCode } from '@/features/auth/types'

/** Admin yang membalas percakapan (inbox admin). ASUMSI: role 1 & 3 — Matriks hanya menyebut "semua role sebagai pengguna". */
export const HALO_ADMIN_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.ADMIN_SATKER]

/** Pengguna yang bertanya lewat drawer chat (hr/support/halo_simpeg = role 1–7; admin memakai inbox). */
export const HALO_USER_ROLES: readonly RoleCode[] = [Role.PEGAWAI, Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PTT, Role.PPPK]
