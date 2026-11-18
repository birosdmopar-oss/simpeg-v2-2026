/**
 * Titik pasang ringkasan helpdesk di RedesignShell (MAKE-073, AC-E08.11/12), gaya `VanyaShell.spec`: klien `api` diganti
 * adapter pencatat yang hanya menjawab `GET /halo-simpeg/tiket/ringkasan` (fixture kontrak) dan menolak request lain;
 * `buildNav` tiruan (satu item `halo`) supaya gerbang fase/role diuji terisolasi; badge pada NAV asli sejak CR-135 ada di
 * `HaloShellNavAsli.spec.ts` (HL2).
 * - role 8, wajib ganti password, `ACTIVE_PHASE` 5 → tanpa request; role 2 → tepat satu request dan badge = `Ringkasan.badge`.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { MessageCircle } from 'lucide-vue-next'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const jaringan = vi.hoisted(() => ({ panggilan: [] as string[], ringkasan: null as unknown }))
const fase = vi.hoisted(() => ({ aktif: 8 }))
vi.mock('@/lib/axios', async (asli) => {
  const a = await asli<typeof import('@/lib/axios')>()
  return {
    ...a,
    api: a.createApiClient({
      adapter: async (config) => {
        const kunci = `${String(config.method)} ${config.url ?? ''}`
        jaringan.panggilan.push(kunci)
        if (kunci === 'get /halo-simpeg/tiket/ringkasan') {
          return { status: 200, statusText: 'OK', headers: {}, config, data: jaringan.ringkasan }
        }
        throw new Error(`request tidak diizinkan di test ini: ${kunci}`)
      },
    }),
  }
})
vi.mock('@/shared/layouts/nav.config', async (asli) => ({
  ...(await asli<typeof import('@/shared/layouts/nav.config')>()),
  get ACTIVE_PHASE() {
    return fase.aktif
  },
  buildNav: () => [
    { key: 'bantuan', title: 'Pusat Bantuan', items: [{ key: 'halo', label: 'Halo Simpeg', icon: MessageCircle, to: '/halo-simpeg', phase: 8 }] },
  ],
}))
vi.mock('@/features/notifikasi', async (asli) => {
  const a = await asli<typeof import('@/features/notifikasi')>()
  return { ...a, FASE_LONCENG: 99 }
})

import { Role, type RoleCode } from '@/features/auth/types'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { resetPolling } from '../composables/useRingkasanHalo'
import { resetHaloSimpeg } from '../stores/haloSimpeg'
import tiketRingkasan from './fixtures/tiket-ringkasan.json'

async function mountShell(role: RoleCode, extra: Parameters<typeof loginAs>[1] = {}) {
  loginAs(role, extra)
  const router = createShellRouter()
  router.addRoute({ path: '/halo-simpeg', name: 'halo-simpeg', component: { render: () => null } })
  await router.push('/')
  await router.isReady()
  const wrapper = mount(RedesignShell, {
    slots: { default: '<p>isi</p>' },
    global: {
      plugins: [router],
      stubs: { InteraksiPopupHost: true, PengingatCutiShell: true, NotifikasiPushShell: true, VanyaWidget: true },
    },
    attachTo: document.body,
  })
  await flushPromises()
  return { wrapper, router }
}

const ringkasanDiminta = () => jaringan.panggilan.filter((p) => p === 'get /halo-simpeg/tiket/ringkasan').length

beforeEach(() => {
  fase.aktif = 8
  jaringan.panggilan = []
  jaringan.ringkasan = tiketRingkasan
  resetPolling()
  resetHaloSimpeg()
})

afterEach(() => {
  const lain = jaringan.panggilan.filter((p) => p !== 'get /halo-simpeg/tiket/ringkasan')
  jaringan.panggilan = []
  expect(lain).toEqual([])
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('RedesignShell — ringkasan & badge Halo Simpeg', () => {
  it('role 2, fase 8 → tepat satu GET ringkasan; badge menu = Ringkasan.badge (bukan total)', async () => {
    const { wrapper } = await mountShell(Role.PEGAWAI)
    expect(ringkasanDiminta()).toBe(1)
    expect(wrapper.get('[data-testid="nav-halo-badge"]').text()).toBe(String(tiketRingkasan.data.badge))
  })

  it('pindah halaman → muat ulang lembut (satu request per perpindahan)', async () => {
    const { router } = await mountShell(Role.PEGAWAI)
    await router.push('/halo-simpeg')
    await flushPromises()
    expect(ringkasanDiminta()).toBe(2)
  })

  it.each([
    ['role 8 (Pimpinan)', Role.PIMPINAN, {}],
    ['wajib ganti password', Role.PEGAWAI, { must_change_password: true }],
  ] as const)('%s → tanpa request, tanpa badge', async (_n, role, extra) => {
    const { wrapper } = await mountShell(role, extra)
    expect(ringkasanDiminta()).toBe(0)
    expect(wrapper.find('[data-testid="nav-halo-badge"]').exists()).toBe(false)
  })

  it('ACTIVE_PHASE 5 → tanpa request, tanpa badge', async () => {
    fase.aktif = 5
    const { wrapper } = await mountShell(Role.PEGAWAI)
    expect(ringkasanDiminta()).toBe(0)
    expect(wrapper.find('[data-testid="nav-halo-badge"]').exists()).toBe(false)
  })
})
