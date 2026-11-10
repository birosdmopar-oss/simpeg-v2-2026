/**
 * Lonceng topbar F-08 saat dibuka (ACTIVE_PHASE ≥ FASE_LONCENG, penutup Fase 7): titik merah + label jumlah dari ringkasan,
 * klik membuka Pusat Notifikasi untuk role yang punya halamannya. ACTIVE_PHASE dimock 7.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@/lib/axios', () => ({
  api: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
  isApiError: (v: unknown) => typeof v === 'object' && v !== null && 'isNetworkError' in v && 'original' in v,
}))
vi.mock('@/features/auth/services/auth.service', () => ({
  authService: { me: vi.fn(), login: vi.fn(), logout: vi.fn().mockResolvedValue(undefined), changePassword: vi.fn() },
}))
vi.mock('@/shared/layouts/nav.config', async (asli) => ({ ...(await asli<typeof import('@/shared/layouts/nav.config')>()), ACTIVE_PHASE: 7 }))

import { Role, type RoleCode } from '@/features/auth/types'
import { api } from '@/lib/axios'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { resetRingkasanNotifikasi } from '../composables/useRingkasanNotifikasi'

const get = vi.mocked(api.get)
const stub = { render: () => h('div') }
const RINGKASAN = { notifikasi_belum_dibaca: 2, butuh_approval: 1, berita_belum_dibaca: null, tiket_belum_dibaca: null, diperbarui_at: '2026-11-10T08:00:00Z' }

beforeEach(() => {
  resetRingkasanNotifikasi()
  get.mockReset().mockResolvedValue({ data: RINGKASAN } as never)
})
afterEach(() => {
  resetRingkasanNotifikasi()
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('Lonceng topbar (RedesignShell) — ACTIVE_PHASE ≥ FASE_LONCENG', () => {
  async function mountShell(role: RoleCode) {
    loginAs(role)
    const router = createShellRouter()
    router.addRoute({ path: '/notifikasi', name: 'notifikasi', component: stub })
    await router.push('/')
    await router.isReady()
    const wrapper = mount(RedesignShell, { global: { plugins: [router] }, attachTo: document.body })
    await flushPromises()
    return { wrapper, router }
  }

  it('titik merah + label jumlah dari ringkasan; klik membuka Pusat Notifikasi', async () => {
    const { wrapper, router } = await mountShell(Role.PEGAWAI)
    const bell = wrapper.get('[data-testid="topbar-bell"]')
    expect(bell.attributes('aria-label')).toBe('Notifikasi, 3 belum dibaca')
    expect(bell.find('span').exists()).toBe(true)

    await bell.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.path).toBe('/notifikasi')
  })

  it('role tanpa halaman notifikasi: klik lonceng tidak berpindah', async () => {
    const { wrapper, router } = await mountShell(Role.MENTERI)
    await wrapper.get('[data-testid="topbar-bell"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.path).toBe('/')
  })
})
