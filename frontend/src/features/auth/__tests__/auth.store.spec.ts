/**
 * A-11 — store sesi: fetchCurrentUser, login, logout, canManageUsers (menu role 1 & 3, A-12).
 */
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { AxiosError } from 'axios'

vi.mock('../services/auth.service', () => ({
  authService: { me: vi.fn(), login: vi.fn(), logout: vi.fn(), changePassword: vi.fn() },
}))

import { authService } from '../services/auth.service'
import { useAuthStore } from '../stores/auth.store'
import { Role, type User } from '../types'

const user = (level: User['user_level']): User => ({
  id_pengguna: 1,
  nip: '198501012010011001',
  username: '198501012010011001',
  user_level: level,
  id_unit: 'U01',
  id_satker: 'S01',
  status: '1',
  last_login_at: null,
  created_at: null,
  updated_at: null,
})

function apiError(status: number) {
  const err = new AxiosError('x')
  return { status, message: 'Unauthorized', errors: null, isNetworkError: false, original: err }
}

describe('useAuthStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(authService.me).mockReset()
    vi.mocked(authService.login).mockReset()
    vi.mocked(authService.logout).mockReset()
    vi.mocked(authService.changePassword).mockReset()
  })

  it('fetchCurrentUser: 401 → guest, tidak melempar', async () => {
    vi.mocked(authService.me).mockRejectedValue(apiError(401))
    const store = useAuthStore()

    await expect(store.fetchCurrentUser()).resolves.toBe(false)
    expect(store.status).toBe('guest')
    expect(store.isAuthenticated).toBe(false)
    // Panggilan berikutnya tidak hit API lagi (status sudah diketahui).
    await store.fetchCurrentUser()
    expect(authService.me).toHaveBeenCalledTimes(1)
  })

  it('fetchCurrentUser: sukses → authenticated dan hanya sekali hit API', async () => {
    vi.mocked(authService.me).mockResolvedValue({ user: user(Role.ADMIN_SATKER), claims: { role: 3, id_unit: 'U01', id_satker: 'S01', exp: 1 } })
    const store = useAuthStore()

    await expect(store.fetchCurrentUser()).resolves.toBe(true)
    await store.fetchCurrentUser()
    expect(authService.me).toHaveBeenCalledTimes(1)
    expect(store.role).toBe(3)
    expect(store.canManageUsers).toBe(true)
  })

  it.each([
    [Role.SUPER_ADMIN, true],
    [Role.PEGAWAI, false],
    [Role.ADMIN_SATKER, true],
    [Role.ADMIN_VIEW_ESELON1, false],
    [Role.MENTERI, false],
    [Role.PTT, false],
    [Role.PPPK, false],
    [Role.PIMPINAN, false],
  ])('canManageUsers role %i → %s (menu Manajemen Akun hanya role 1 & 3)', (role, expected) => {
    const store = useAuthStore()
    store.setSession(user(role))
    expect(store.canManageUsers).toBe(expected)
  })

  it.each(Object.values(Role).map((role) => [role, role === Role.SUPER_ADMIN] as const))(
    'canManageMasterData role %i → %s (menu Master Data hanya role 1, Modul G)',
    (role, expected) => {
      const store = useAuthStore()
      store.setSession(user(role))
      expect(store.canManageMasterData).toBe(expected)
    },
  )

  it('login menyimpan user & claims; logout mengosongkan sesi walau API gagal', async () => {
    vi.mocked(authService.login).mockResolvedValue({ user: user(Role.PEGAWAI), access_token: 't', access_expires_at: 99, refresh_expires_at: 100 })
    vi.mocked(authService.logout).mockRejectedValue(apiError(500))
    const store = useAuthStore()

    await store.login({ username: 'u', password: 'p', captcha_token: 'c' })
    expect(store.isAuthenticated).toBe(true)
    expect(store.claims?.role).toBe(2)

    await expect(store.logout()).rejects.toBeTruthy()
    expect(store.status).toBe('guest')
    expect(store.user).toBeNull()
  })

  it('changePassword sukses: sesi lokal dikosongkan TANPA memanggil /auth/logout (ISSUE-006)', async () => {
    vi.mocked(authService.changePassword).mockResolvedValue(undefined)
    const store = useAuthStore()
    store.setSession(user(Role.PEGAWAI))
    const payload = { old_password: 'Lama1234', new_password: 'Baru12345', new_password_confirmation: 'Baru12345' }

    await store.changePassword(payload)

    expect(authService.changePassword).toHaveBeenCalledWith(payload)
    expect(authService.logout).not.toHaveBeenCalled()
    expect(store.status).toBe('guest')
    expect(store.user).toBeNull()
    expect(store.isAuthenticated).toBe(false)
  })

  it('changePassword gagal (422): sesi tetap utuh dan error diteruskan', async () => {
    vi.mocked(authService.changePassword).mockRejectedValue(apiError(422))
    const store = useAuthStore()
    store.setSession(user(Role.PEGAWAI))

    await expect(store.changePassword({ old_password: 'x', new_password: 'Baru12345', new_password_confirmation: 'Baru12345' })).rejects.toBeTruthy()
    expect(store.isAuthenticated).toBe(true)
  })
})
