/**
 * A-11 — store sesi: fetchCurrentUser, login, logout, canManageUsers (menu role 1 & 3, A-12).
 */
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { AxiosError } from 'axios'

vi.mock('../services/auth.service', () => ({
  authService: { me: vi.fn(), login: vi.fn(), logout: vi.fn() },
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
})
