/**
 * Tipe Modul A (snake_case end-to-end, ADR-023). Ditulis manual (ADR-030), mengikuti
 * backend/app/Controllers/Api/Auth/README.md.
 */

/** Kode role — sama persis dengan App\Constants\Role backend (Matriks Role x Endpoint Bagian 3). */
export const Role = {
  SUPER_ADMIN: 1,
  PEGAWAI: 2,
  ADMIN_SATKER: 3,
  ADMIN_VIEW_ESELON1: 4,
  MENTERI: 5,
  PTT: 6,
  PPPK: 7,
  PIMPINAN: 8,
} as const

export type RoleCode = (typeof Role)[keyof typeof Role]

export const ROLE_LABELS: Record<RoleCode, string> = {
  1: 'Super Admin',
  2: 'Pegawai / PNS',
  3: 'Admin Satker',
  4: 'Admin View / Eselon 1',
  5: 'Menteri',
  6: 'PTT',
  7: 'PPPK',
  8: 'Pimpinan',
}

/** Role yang boleh membuka menu Manajemen Akun (user/index,add,edit,delete = 1, 3). */
export const USER_MANAGEMENT_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.ADMIN_SATKER]

export interface User {
  id_pengguna: number
  nip: string
  username: string
  user_level: RoleCode
  id_unit: string | null
  id_satker: string | null
  status: '0' | '1'
  last_login_at: string | null
  created_at: string | null
  updated_at: string | null
}

export interface SessionClaims {
  role: RoleCode
  id_unit: string | null
  id_satker: string | null
  exp: number | null
}

export interface LoginPayload {
  username: string
  password: string
  captcha_token: string
}

export interface LoginResponse {
  user: User
  access_token: string
  access_expires_at: number
  refresh_expires_at: number
}

export interface MeResponse {
  user: User
  claims: SessionClaims
}

export interface UserListQuery {
  search?: string
  user_level?: RoleCode | ''
  status?: '0' | '1' | ''
  id_satker?: string
  sort?: 'username' | 'nip' | 'user_level' | 'status' | 'created_at' | 'last_login_at'
  order?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

export interface UserListResponse {
  items: User[]
  total: number
  page: number
  per_page: number
}

export interface UserCreatePayload {
  nip: string
  username?: string
  password: string
  user_level: RoleCode
  id_unit?: string | null
  id_satker?: string | null
  status?: '0' | '1'
}

export type UserUpdatePayload = Partial<Omit<UserCreatePayload, 'nip'>>
