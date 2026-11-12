/**
 * Router NYATA — halaman Arsip F-10 (MAKE-091; PRD §9.1, AC1-03, AC1-04): role 2/6/7 selalu mendarat di arsip NIP sendiri
 * tanpa pesan galat (`/arsip`, `/arsip/<nip lain>`, `/arsip/<nip lain>/<folder>`), role 8 → /403, role 1/3/4/5 tidak
 * dialihkan, tamu → login. Guard FE hanya UX; backend yang menegakkan (ADR-024/005).
 */
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/features/auth/services/auth.service', () => ({
  authService: { me: vi.fn(), login: vi.fn(), logout: vi.fn(), changePassword: vi.fn(), forgotPassword: vi.fn(), resetPassword: vi.fn() },
}))

import { authService } from '@/features/auth/services/auth.service'
import { Role, type RoleCode, type User } from '@/features/auth/types'
import { ARSIP_ROLES } from '@/features/kepegawaian/roles'

import router from '../index'

const NIP_SENDIRI = '199001012015011001'
const NIP_LAIN = '198505052010012002'

function loginAs(role: RoleCode, nip: string | null = null): void {
  vi.mocked(authService.me).mockResolvedValue({
    user: { username: 'u', user_level: role, nip } as User,
    claims: { role, id_unit: null, id_satker: null, exp: null },
  })
}

beforeEach(async () => {
  setActivePinia(createPinia())
  vi.mocked(authService.me).mockReset()
  await router.replace('/login')
})

describe('router — /arsip (meta)', () => {
  it('tiga route dengan nama, judul, dan roles = ARSIP_ROLES (1–7)', () => {
    expect(router.resolve('/arsip').name).toBe('arsip')
    expect(router.resolve(`/arsip/${NIP_SENDIRI}`).name).toBe('arsip-pegawai')
    expect(router.resolve(`/arsip/${NIP_SENDIRI}/9`).name).toBe('arsip-folder')
    for (const path of ['/arsip', `/arsip/${NIP_SENDIRI}`, `/arsip/${NIP_SENDIRI}/9`]) {
      expect(router.resolve(path).meta.roles).toEqual(ARSIP_ROLES)
      expect(router.resolve(path).meta.title).toBe('Arsip')
    }
    expect(ARSIP_ROLES).toEqual([1, 2, 3, 4, 5, 6, 7])
  })
})

describe('router — role 2/6/7 dialihkan ke arsip sendiri (AC1-03)', () => {
  it.each([Role.PEGAWAI, Role.PTT, Role.PPPK])('role %i: /arsip → /arsip/<nip sendiri>', async (role) => {
    loginAs(role, NIP_SENDIRI)
    await router.push('/arsip')
    expect(router.currentRoute.value.name).toBe('arsip-pegawai')
    expect(router.currentRoute.value.params.nip).toBe(NIP_SENDIRI)
    expect(router.currentRoute.value.fullPath).toBe(`/arsip/${NIP_SENDIRI}`)
  })

  it.each([Role.PEGAWAI, Role.PTT, Role.PPPK])('role %i: /arsip/<nip lain> → /arsip/<nip sendiri>', async (role) => {
    loginAs(role, NIP_SENDIRI)
    await router.push(`/arsip/${NIP_LAIN}`)
    expect(router.currentRoute.value.name).toBe('arsip-pegawai')
    expect(router.currentRoute.value.params.nip).toBe(NIP_SENDIRI)
  })

  it.each([Role.PEGAWAI, Role.PTT, Role.PPPK])('role %i: /arsip/<nip lain>/9 → /arsip/<nip sendiri>/9', async (role) => {
    loginAs(role, NIP_SENDIRI)
    await router.push(`/arsip/${NIP_LAIN}/9`)
    expect(router.currentRoute.value.name).toBe('arsip-folder')
    expect(router.currentRoute.value.params).toMatchObject({ nip: NIP_SENDIRI, folder: '9' })
  })

  it('NIP sendiri dibiarkan (tidak berputar)', async () => {
    loginAs(Role.PEGAWAI, NIP_SENDIRI)
    await router.push(`/arsip/${NIP_SENDIRI}/semua`)
    expect(router.currentRoute.value.fullPath).toBe(`/arsip/${NIP_SENDIRI}/semua`)
  })
})

describe('router — admin tidak dialihkan; role 8 dan tamu', () => {
  it.each([Role.SUPER_ADMIN, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI])('role %i: /arsip tetap pemilih, /arsip/<nip lain> tetap', async (role) => {
    loginAs(role)
    await router.push('/arsip')
    expect(router.currentRoute.value.name).toBe('arsip')
    await router.push(`/arsip/${NIP_LAIN}`)
    expect(router.currentRoute.value.params.nip).toBe(NIP_LAIN)
    await router.push(`/arsip/${NIP_LAIN}/tanpa_jenis`)
    expect(router.currentRoute.value.name).toBe('arsip-folder')
    expect(router.currentRoute.value.params).toMatchObject({ nip: NIP_LAIN, folder: 'tanpa_jenis' })
  })

  it.each(['/arsip', `/arsip/${NIP_LAIN}`, `/arsip/${NIP_LAIN}/9`])('role 8 (Pimpinan): %s → /403', async (path) => {
    loginAs(Role.PIMPINAN)
    await router.push(path)
    expect(router.currentRoute.value.name).toBe('forbidden')
  })

  it('tamu diarahkan ke login dengan redirect', async () => {
    vi.mocked(authService.me).mockRejectedValue({ status: 401, message: 'x', errors: null, isNetworkError: false, original: new Error('x') })
    await router.push('/arsip')
    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query).toEqual({ redirect: '/arsip' })
  })
})
