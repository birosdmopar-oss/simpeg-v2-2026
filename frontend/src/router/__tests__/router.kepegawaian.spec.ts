/**
 * Router — route Modul B Kepegawaian (Fase 3): /pegawai (hr/employee/index = role 1,3,4,5,8), /pegawai/:nip
 * (hr/employee/detail = semua role login), /struktur-organisasi (hr/so/full = semua role login), serta placeholder
 * Usulan Konket, Verifikasi LKH (routes.pegawai.ts, WS-2) dan Usulan Karpeg/Karis (routes.riwayat.ts, WS-1).
 * Guard FE hanya UX; RoleFilter backend yang menegakkan (ADR-024/005).
 */
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/features/auth/services/auth.service', () => ({
  authService: { me: vi.fn(), login: vi.fn(), logout: vi.fn(), changePassword: vi.fn(), forgotPassword: vi.fn(), resetPassword: vi.fn() },
}))

import { authService } from '@/features/auth/services/auth.service'
import { Role, type RoleCode, type User } from '@/features/auth/types'
import { KARPEG_KARIS_ROLES, KONKET_ROLES, LKH_ROLES, PEGAWAI_LIST_ROLES } from '@/features/kepegawaian/roles'

import router from '../index'

function apiError(status: number) {
  return { status, message: 'x', errors: null, isNetworkError: false, original: new Error('x') }
}

function loginAs(role: RoleCode): void {
  vi.mocked(authService.me).mockResolvedValue({
    user: { username: 'u', user_level: role } as User,
    claims: { role, id_unit: null, id_satker: null, exp: null },
  })
}

beforeEach(async () => {
  setActivePinia(createPinia())
  vi.mocked(authService.me).mockReset()
  await router.replace('/login')
})

describe('router — /pegawai (daftar)', () => {
  it('meta: roles = PEGAWAI_LIST_ROLES dan judul', () => {
    const route = router.resolve('/pegawai')
    expect(route.name).toBe('pegawai-list')
    expect(route.meta.roles).toEqual(PEGAWAI_LIST_ROLES)
    expect(route.meta.title).toBe('Daftar Pegawai')
  })

  it.each([Role.SUPER_ADMIN, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PIMPINAN])('role %i boleh membuka', async (role) => {
    loginAs(role)
    await router.push('/pegawai')
    expect(router.currentRoute.value.name).toBe('pegawai-list')
  })

  it.each([Role.PEGAWAI, Role.PTT, Role.PPPK])('role %i diarahkan ke /403', async (role) => {
    loginAs(role)
    await router.push('/pegawai')
    expect(router.currentRoute.value.name).toBe('forbidden')
  })

  it('tamu diarahkan ke login dengan redirect', async () => {
    vi.mocked(authService.me).mockRejectedValue(apiError(401))
    await router.push('/pegawai')
    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query).toEqual({ redirect: '/pegawai' })
  })
})

describe('router — /pegawai/:nip dan /struktur-organisasi', () => {
  it.each(Object.values(Role))('role %i boleh membuka detail pegawai (UL_ALL)', async (role) => {
    loginAs(role)
    await router.push('/pegawai/197001011995011001')
    expect(router.currentRoute.value.name).toBe('pegawai-detail')
    expect(router.currentRoute.value.params.nip).toBe('197001011995011001')
    expect(router.currentRoute.value.meta.title).toBe('Data Pegawai')
  })

  it.each(Object.values(Role))('role %i boleh membuka struktur organisasi', async (role) => {
    loginAs(role)
    await router.push('/struktur-organisasi')
    expect(router.currentRoute.value.name).toBe('org-structure')
  })

  it('judul dokumen mengikuti route', async () => {
    loginAs(Role.SUPER_ADMIN)
    await router.push('/struktur-organisasi')
    expect(document.title).toBe('Struktur Organisasi · SIMPEG v2')
  })
})

describe('router — placeholder halaman Fase 3 (Matriks v2)', () => {
  const cases = [
    { path: '/konket', name: 'konket-usulan', roles: KONKET_ROLES, title: 'Usulan Konket' },
    { path: '/lkh/verifikasi', name: 'lkh-verifikasi', roles: LKH_ROLES, title: 'Verifikasi LKH' },
    { path: '/karpeg-karis', name: 'karpeg-karis-usulan', roles: KARPEG_KARIS_ROLES, title: 'Usulan Karpeg/Karis' },
  ]

  it.each(cases)('$path: meta roles & judul sesuai Matriks', ({ path, name, roles, title }) => {
    const route = router.resolve(path)
    expect(route.name).toBe(name)
    expect(route.meta.roles).toEqual(roles)
    expect(route.meta.title).toBe(title)
  })

  it('Konket & LKH = role 1, 2, 3, 6, 7; Karpeg/Karis = role 1, 2, 4, 5, 7', () => {
    expect(KONKET_ROLES).toEqual([1, 2, 3, 6, 7])
    expect(LKH_ROLES).toEqual([1, 2, 3, 6, 7])
    expect(KARPEG_KARIS_ROLES).toEqual([1, 2, 4, 5, 7])
  })

  it.each(cases)('$path: role di luar daftar diarahkan ke /403', async ({ path, roles }) => {
    const outsider = Object.values(Role).find((r) => !roles.includes(r))
    loginAs(outsider!)
    await router.push(path)
    expect(router.currentRoute.value.name).toBe('forbidden')
  })

  it.each(cases)('$path: role yang berhak membuka placeholder', async ({ path, name }) => {
    loginAs(Role.SUPER_ADMIN)
    await router.push(path)
    expect(router.currentRoute.value.name).toBe(name)
  })
})
