/**
 * Perbaikan temuan WS-1 (MAKE-083/084): halaman admin Form Digital (kelola, respons) dan Survei Layanan (rekap) dirender di
 * dalam RedesignShell (sidebar + topbar), lewat route yang sebenarnya.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { RouterView } from 'vue-router'

vi.mock('../services/formDigital.service', () => ({
  formDigitalService: {
    daftar: vi.fn().mockResolvedValue({ items: [], total: 0, page: 1, per_page: 20 }),
    respons: vi.fn().mockResolvedValue({ kolom: [], items: [], total: 0, page: 1, per_page: 20 }),
    saya: vi.fn().mockResolvedValue([]),
  },
}))
vi.mock('@/features/survei-layanan/services/surveiLayanan.service', () => ({
  surveiLayananService: {
    rekap: vi.fn().mockResolvedValue({ items: [], total: 0, page: 1, per_page: 20 }),
    ringkasan: vi.fn().mockResolvedValue({ periode: { dari: '2025-11-12', sampai: '2026-11-11' }, per_jenis: [], total: { jumlah: 0, rata_rata: null } }),
    saya: vi.fn().mockResolvedValue([]),
  },
}))
vi.mock('@/features/layanan/services/cuti.service', () => ({ cutiService: { pengingat: vi.fn().mockResolvedValue({ items: [], total: 0 }) } }))
vi.mock('@/features/auth/services/auth.service', () => ({
  authService: { me: vi.fn(), login: vi.fn(), logout: vi.fn().mockResolvedValue(undefined), changePassword: vi.fn() },
}))

import { Role } from '@/features/auth/types'
import { surveiLayananRoutes } from '@/features/survei-layanan/routes'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { formDigitalRoutes } from '../routes'

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

async function buka(path: string, testid: string): Promise<void> {
  loginAs(Role.SUPER_ADMIN)
  const router = createShellRouter()
  for (const r of [...formDigitalRoutes, ...surveiLayananRoutes]) router.addRoute(r)
  await router.push(path)
  await router.isReady()
  mount(RouterView, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  const shell = document.body.querySelector('[data-testid="app-shell"]')
  expect(shell).not.toBeNull()
  expect(shell!.querySelector(`[data-testid="${testid}"]`)).not.toBeNull()
}

describe('halaman F-06/F-11 di dalam RedesignShell', () => {
  it('kelola form digital', async () => {
    await buka('/form-digital', 'form-digital-kelola')
  })

  it('respons form digital', async () => {
    await buka('/form-digital/7/respons', 'form-digital-respons')
  })

  it('rekap survei layanan', async () => {
    await buka('/survei-layanan', 'skl-rekap')
  })

  it('pindah respons form A → B memuat ulang data form B (CR-118)', async () => {
    const { formDigitalService } = await import('../services/formDigital.service')
    loginAs(Role.SUPER_ADMIN)
    const router = createShellRouter()
    for (const r of formDigitalRoutes) router.addRoute(r)
    await router.push('/form-digital/7/respons')
    await router.isReady()
    mount(RouterView, { global: { plugins: [router] }, attachTo: document.body })
    await flushPromises()
    vi.mocked(formDigitalService.respons).mockClear()

    await router.push('/form-digital/8/respons')
    await flushPromises()
    expect(vi.mocked(formDigitalService.respons).mock.calls.map((c) => c[0])).toEqual([8])
  })
})

