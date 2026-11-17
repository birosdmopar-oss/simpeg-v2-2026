/**
 * Router NYATA — route helpdesk Halo Simpeg (MAKE-073 B1): `/halo-simpeg` dan `/halo-simpeg/tiket/:id` role 1–7 (F8-04, role 8 tanpa akses),
 * admin master role 1; guard router aplikasi mengarahkan role di luar daftar ke `/403`.
 */
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/features/auth/services/auth.service', () => ({
  authService: { me: vi.fn(), login: vi.fn(), logout: vi.fn(), changePassword: vi.fn(), forgotPassword: vi.fn(), resetPassword: vi.fn() },
}))

import { authService } from '@/features/auth/services/auth.service'
import { Role, type RoleCode, type User } from '@/features/auth/types'
import { HALO_ADMIN_ROLES, HALO_ROLES } from '@/features/halo-simpeg/constants'
import { haloRoutes, idTiketDariParam, type PropsHaloSimpeg } from '@/features/halo-simpeg/routes.halo'

import router from '../index'

function login(role: RoleCode): void {
  vi.mocked(authService.me).mockResolvedValue({
    user: { username: 'uji', user_level: role, nip: null } as User,
    claims: { role, id_unit: null, id_satker: null, exp: null },
  })
}

beforeEach(async () => {
  setActivePinia(createPinia())
  vi.mocked(authService.me).mockReset()
  await router.replace('/login')
})

describe('route Halo Simpeg', () => {
  it('terdaftar dengan role: pemilik/petugas 1–7, admin 1; /halo-simpeg-admin → kategori', () => {
    expect(HALO_ROLES).toEqual([1, 2, 3, 4, 5, 6, 7])
    for (const nama of ['halo-simpeg', 'halo-tiket']) expect(router.getRoutes().find((r) => r.name === nama)?.meta.roles).toEqual(HALO_ROLES)
    for (const b of ['kategori', 'petugas', 'kontak']) {
      expect(router.getRoutes().find((r) => r.name === `halo-admin-${b}`)?.meta.roles).toEqual(HALO_ADMIN_ROLES)
    }
    expect(router.resolve('/halo-simpeg-admin').matched.at(-1)?.redirect).toEqual({ name: 'halo-admin-kategori' })
    expect(haloRoutes).toHaveLength(6)
  })

  it('admin di prefiks sendiri: /halo-simpeg-admin/* tidak cocok prefiks /halo-simpeg/ (aria-current item halo, CR-135)', () => {
    for (const b of ['kategori', 'petugas', 'kontak']) {
      const path = router.resolve({ name: `halo-admin-${b}` }).path
      expect(path).toBe(`/halo-simpeg-admin/${b}`)
      expect(path.startsWith('/halo-simpeg/')).toBe(false)
      expect(path === '/halo-simpeg').toBe(false)
    }
  })

  it('/halo-simpeg/tiket/:id hanya angka; props idTiket angka', () => {
    const r = router.resolve('/halo-simpeg/tiket/42')
    expect(r.name).toBe('halo-tiket')
    const props = r.matched.at(-1)?.props.default as (rt: typeof r) => PropsHaloSimpeg
    expect(props(r)).toEqual({ idTiket: 42, idTidakSah: false })
    const nol = router.resolve('/halo-simpeg/tiket/0')
    expect(nol.name).toBe('halo-tiket')
    expect(props(nol)).toEqual({ idTiket: null, idTidakSah: true })
    expect(router.resolve('/halo-simpeg').matched.at(-1)?.props.default).toEqual({ idTiket: null, idTidakSah: false })
    expect(router.resolve('/halo-simpeg/tiket/abc').name).not.toBe('halo-tiket')
    expect(idTiketDariParam('0')).toBeNull()
    expect(idTiketDariParam(['7'])).toBe(7)
    expect(idTiketDariParam('99999999999999999999')).toBeNull()
  })

  it.each([Role.SUPER_ADMIN, Role.PEGAWAI, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PTT, Role.PPPK])(
    'role %s membuka /halo-simpeg',
    async (role) => {
      login(role)
      await router.push('/halo-simpeg')
      expect(router.currentRoute.value.name).toBe('halo-simpeg')
    },
  )

  it('role 8 (Pimpinan) → 403 untuk halaman dan tiket', async () => {
    login(Role.PIMPINAN)
    await router.push('/halo-simpeg')
    expect(router.currentRoute.value.name).toBe('forbidden')
    await router.push('/halo-simpeg/tiket/3')
    expect(router.currentRoute.value.name).toBe('forbidden')
  })

  it('admin master: role 1 boleh', async () => {
    login(Role.SUPER_ADMIN)
    await router.push('/halo-simpeg-admin/petugas')
    expect(router.currentRoute.value.name).toBe('halo-admin-petugas')
  })

  it('admin master: role 2 → 403', async () => {
    login(Role.PEGAWAI)
    await router.push('/halo-simpeg-admin/kontak')
    expect(router.currentRoute.value.name).toBe('forbidden')
  })
})
