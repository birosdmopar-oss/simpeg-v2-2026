/**
 * Lonceng topbar F-08 sebelum dibuka (ACTIVE_PHASE < FASE_LONCENG): tanpa angka, tanpa polling ringkasan, klik tidak berpindah.
 * Dipindah dari `notifikasi.spec.ts` di CR-135: ACTIVE_PHASE asli kini 8, jadi gerbang ini diuji dengan ACTIVE_PHASE dimock 6.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/axios', () => ({
  api: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
  isApiError: (v: unknown) => typeof v === 'object' && v !== null && 'isNetworkError' in v && 'original' in v,
}))
vi.mock('@/features/auth/services/auth.service', () => ({
  authService: { me: vi.fn(), login: vi.fn(), logout: vi.fn().mockResolvedValue(undefined), changePassword: vi.fn() },
}))
vi.mock('@/shared/layouts/nav.config', async (asli) => ({ ...(await asli<typeof import('@/shared/layouts/nav.config')>()), ACTIVE_PHASE: 6 }))

import { Role } from '@/features/auth/types'
import { api } from '@/lib/axios'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { resetRingkasanNotifikasi } from '../composables/useRingkasanNotifikasi'

const get = vi.mocked(api.get)

beforeEach(() => {
  resetRingkasanNotifikasi()
  get.mockReset().mockResolvedValue({ data: { notifikasi_belum_dibaca: 3, butuh_approval: 0, berita_belum_dibaca: null, tiket_belum_dibaca: null } } as never)
})
afterEach(() => {
  resetRingkasanNotifikasi()
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('Lonceng topbar (RedesignShell) — ACTIVE_PHASE < FASE_LONCENG', () => {
  it('lonceng tanpa angka, tanpa polling ringkasan, dan klik tidak berpindah sampai penutup Fase 7', async () => {
    loginAs(Role.PEGAWAI)
    const router = createShellRouter()
    await router.push('/')
    await router.isReady()
    const wrapper = mount(RedesignShell, { global: { plugins: [router] }, attachTo: document.body })
    await flushPromises()

    const bell = wrapper.get('[data-testid="topbar-bell"]')
    expect(bell.attributes('aria-label')).toBe('Notifikasi')
    expect(bell.find('span').exists()).toBe(false)
    expect(get.mock.calls.filter(([p]) => p === '/notifikasi/ringkasan')).toHaveLength(0)

    await bell.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.path).toBe('/')
  })
})
