/**
 * HL2 (checklist QA Fase 8 §HL, CR-135): badge Halo Simpeg pada `NAV` **asli** (tanpa `buildNav` tiruan) dengan `ACTIVE_PHASE`
 * asli (8). Klien `api` = adapter yang hanya menjawab `GET /halo-simpeg/tiket/ringkasan` (fixture kontrak); lonceng F-08 dimatikan
 * (`FASE_LONCENG` 99) dan shell host lain di-stub supaya hanya request ringkasan helpdesk yang terjadi.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const jaringan = vi.hoisted(() => ({ panggilan: [] as string[] }))
vi.mock('@/lib/axios', async (asli) => {
  const a = await asli<typeof import('@/lib/axios')>()
  const { default: ringkasan } = await import('./fixtures/tiket-ringkasan.json')
  return {
    ...a,
    api: a.createApiClient({
      adapter: async (config) => {
        const kunci = `${String(config.method)} ${config.url ?? ''}`
        jaringan.panggilan.push(kunci)
        if (kunci === 'get /halo-simpeg/tiket/ringkasan') return { status: 200, statusText: 'OK', headers: {}, config, data: ringkasan }
        throw new Error(`request tidak diizinkan di test ini: ${kunci}`)
      },
    }),
  }
})
vi.mock('@/features/notifikasi', async (asli) => ({ ...(await asli<typeof import('@/features/notifikasi')>()), FASE_LONCENG: 99 }))

import { Role, type RoleCode } from '@/features/auth/types'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { ACTIVE_PHASE } from '@/shared/layouts/nav.config'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { resetPolling } from '../composables/useRingkasanHalo'
import { resetHaloSimpeg } from '../stores/haloSimpeg'
import tiketRingkasan from './fixtures/tiket-ringkasan.json'

async function mountShell(role: RoleCode) {
  loginAs(role)
  const router = createShellRouter()
  router.addRoute({ path: '/halo-simpeg', name: 'halo-simpeg', component: { render: () => null } })
  await router.push('/')
  await router.isReady()
  const wrapper = mount(RedesignShell, {
    slots: { default: '<p>isi</p>' },
    global: { plugins: [router], stubs: { InteraksiPopupHost: true, PengingatCutiShell: true, NotifikasiPushShell: true, VanyaWidget: true } },
    attachTo: document.body,
  })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  jaringan.panggilan = []
  resetPolling()
  resetHaloSimpeg()
})
afterEach(() => {
  expect(jaringan.panggilan.filter((p) => p !== 'get /halo-simpeg/tiket/ringkasan')).toEqual([])
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('HL2: badge Halo Simpeg pada NAV asli (ACTIVE_PHASE 8)', () => {
  it('ACTIVE_PHASE asli = 8', () => {
    expect(ACTIVE_PHASE).toBe(8)
  })

  it.each([Role.PEGAWAI, Role.ADMIN_SATKER, Role.PTT])('role %i: menu Halo Simpeg menuju /halo-simpeg dengan badge = Ringkasan.badge', async (role) => {
    const wrapper = await mountShell(role)
    expect(wrapper.get('[data-testid="nav-halo"]').attributes('href')).toBe('/halo-simpeg')
    expect(wrapper.get('[data-testid="nav-halo-badge"]').text()).toBe(String(tiketRingkasan.data.badge))
    expect(jaringan.panggilan.filter((p) => p === 'get /halo-simpeg/tiket/ringkasan')).toHaveLength(1)
  })

  it('role 8 (Pimpinan): tanpa menu Halo Simpeg, tanpa badge, tanpa request', async () => {
    const wrapper = await mountShell(Role.PIMPINAN)
    expect(wrapper.find('[data-testid="nav-halo"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="nav-halo-badge"]').exists()).toBe(false)
    expect(jaringan.panggilan).toEqual([])
  })
})
