/**
 * MAKE-028 — aksi 403 `password_change_required`: ambil ulang /auth/me (store mutakhir), lalu ke /ganti-password
 * SATU kali; tidak redirect bila sudah di sana atau penanda tidak aktif.
 */
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/auth.service', () => ({
  authService: { me: vi.fn(), login: vi.fn(), logout: vi.fn(), changePassword: vi.fn() },
}))

import { handlePasswordChangeRequired } from '../passwordChangeRequired'
import { authService } from '../services/auth.service'
import { useAuthStore } from '../stores/auth.store'
import { Role, type User } from '../types'

const user = (mustChange: boolean): User =>
  ({
    id_pengguna: 1,
    nip: '198501012010011001',
    username: 'admin',
    name: 'Admin',
    email: null,
    user_level: Role.SUPER_ADMIN,
    id_unit: null,
    id_satker: null,
    status: '1',
    last_login_at: null,
    created_at: null,
    updated_at: null,
    must_change_password: mustChange,
  }) as User

function fakeRouter(routeName: string) {
  const push = vi.fn().mockResolvedValue(undefined)
  return { router: { currentRoute: { value: { name: routeName } }, push } as never, push }
}

describe('handlePasswordChangeRequired', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(authService.me).mockReset()
  })

  it('memperbarui store dari /auth/me lalu mengarahkan ke /ganti-password sekali', async () => {
    vi.mocked(authService.me).mockResolvedValue({ user: user(true), claims: null } as never)
    const auth = useAuthStore()
    auth.setSession(user(false)) // store lama: sesi terlihat sehat
    const { router, push } = fakeRouter('home')

    await handlePasswordChangeRequired(auth, router)

    expect(authService.me).toHaveBeenCalledTimes(1)
    expect(auth.mustChangePassword).toBe(true)
    expect(push).toHaveBeenCalledTimes(1)
    expect(push).toHaveBeenCalledWith({ name: 'change-password' })
  })

  it('sudah di halaman ganti password: tidak redirect lagi (tanpa loop)', async () => {
    vi.mocked(authService.me).mockResolvedValue({ user: user(true), claims: null } as never)
    const auth = useAuthStore()
    const { router, push } = fakeRouter('change-password')

    await handlePasswordChangeRequired(auth, router)

    expect(auth.mustChangePassword).toBe(true)
    expect(push).not.toHaveBeenCalled()
  })

  it('/auth/me menyatakan tidak wajib ganti: tidak redirect', async () => {
    vi.mocked(authService.me).mockResolvedValue({ user: user(false), claims: null } as never)
    const auth = useAuthStore()
    const { router, push } = fakeRouter('home')

    await handlePasswordChangeRequired(auth, router)

    expect(auth.mustChangePassword).toBe(false)
    expect(push).not.toHaveBeenCalled()
  })
})
