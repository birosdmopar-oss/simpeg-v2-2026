/**
 * Router aplikasi — route ISSUE-006: /ganti-password wajib login (semua role), /lupa-password & /reset-password
 * khusus tamu dan hanya aktif kalau VITE_PASSWORD_RESET_ENABLED=true (selain itu dialihkan ke login).
 */
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/features/auth/services/auth.service', () => ({
  authService: { me: vi.fn(), login: vi.fn(), logout: vi.fn(), changePassword: vi.fn(), forgotPassword: vi.fn(), resetPassword: vi.fn() },
}))

import { authService } from '@/features/auth/services/auth.service'
import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role, type User } from '@/features/auth/types'

import router from '../index'

function apiError(status: number) {
  return { status, message: 'x', errors: null, isNetworkError: false, original: new Error('x') }
}

beforeEach(async () => {
  setActivePinia(createPinia())
  vi.mocked(authService.me).mockReset()
  // Mulai dari halaman tamu (tanpa /auth/me) agar setiap test benar-benar bernavigasi.
  await router.replace('/login')
})

afterEach(() => {
  vi.unstubAllEnvs()
})

describe('router — lupa/reset password di balik VITE_PASSWORD_RESET_ENABLED', () => {
  it.each(['/lupa-password', '/reset-password?token=abc', '/reset-password#token=abc'])('flag false → %s dialihkan ke login', async (path) => {
    vi.stubEnv('VITE_PASSWORD_RESET_ENABLED', 'false')
    await router.push(path)

    expect(router.currentRoute.value.name).toBe('login')
  })

  it('flag true → halaman tamu terbuka tanpa memanggil /auth/me', async () => {
    vi.stubEnv('VITE_PASSWORD_RESET_ENABLED', 'true')

    await router.push('/lupa-password')
    expect(router.currentRoute.value.name).toBe('forgot-password')

    await router.push('/reset-password?token=abc')
    expect(router.currentRoute.value.name).toBe('reset-password')
    expect(authService.me).not.toHaveBeenCalled()
  })

  it('flag true tapi sudah login → guestOnly mengarahkan ke beranda', async () => {
    vi.stubEnv('VITE_PASSWORD_RESET_ENABLED', 'true')
    useAuthStore().setSession({ username: 'u', user_level: Role.PEGAWAI } as User)

    await router.push('/lupa-password')
    expect(router.currentRoute.value.name).toBe('home')
  })
})

describe('router — /ganti-password', () => {
  it('butuh login: tamu diarahkan ke login dengan redirect', async () => {
    vi.mocked(authService.me).mockRejectedValue(apiError(401))
    await router.push('/ganti-password')

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query).toEqual({ redirect: '/ganti-password' })
  })

  it.each(Object.values(Role))('role %i boleh membuka halaman ganti password', async (role) => {
    vi.mocked(authService.me).mockResolvedValue({
      user: { username: 'u', user_level: role } as User,
      claims: { role, id_unit: null, id_satker: null, exp: null },
    })
    await router.push('/ganti-password')

    expect(router.currentRoute.value.name).toBe('change-password')
    expect(router.currentRoute.value.meta.title).toBe('Ganti Password')
  })
})
