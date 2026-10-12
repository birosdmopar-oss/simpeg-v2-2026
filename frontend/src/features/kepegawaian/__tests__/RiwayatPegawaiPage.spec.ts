/**
 * RiwayatPegawaiPage — halaman riwayat SEMENTARA sampai MAKE-010 (WS-1 M3 MAKE-006): tab dari
 * `GET pegawai/{nip}/riwayat` (descriptor sama dengan `data.tabs` Detail Pegawai), urutan backend, hanya jenis yang punya
 * berkas registry; ?tab= tersimpan; galat 403/404 tanpa data contoh; route di routes.riwayat.ts.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../riwayat/riwayat.service', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../riwayat/riwayat.service')>()
  return { ...actual, riwayatService: { tabs: vi.fn(), list: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn(), process: vi.fn() } }
})

import { Role } from '@/features/auth/types'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { riwayatService } from '../riwayat/riwayat.service'
import { riwayatRoutes } from '../routes.riwayat'
import RiwayatPegawaiPage from '../views/RiwayatPegawaiPage.vue'

import { apiError, descriptor, NIP, pendidikanRow } from './fixtures'

const svc = vi.mocked(riwayatService)

async function mountPage(query = '') {
  loginAs(Role.SUPER_ADMIN)
  const router = createShellRouter()
  router.addRoute({ path: '/pegawai/:nip/riwayat', name: 'pegawai-riwayat', component: RiwayatPegawaiPage })
  await router.push(`/pegawai/${NIP}/riwayat${query}`)
  await router.isReady()
  const wrapper = mount(RiwayatPegawaiPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

const tabLabels = (w: ReturnType<typeof mount>) => w.findAll('[role="tab"]').map((t) => t.text())

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  for (const fn of Object.values(svc)) fn.mockReset()
  svc.tabs.mockResolvedValue([
    descriptor('kp', 'Riwayat Kenaikan Pangkat'),
    descriptor('kgb', 'Riwayat KGB', { can_create: false }),
    descriptor('pendidikan', 'Riwayat Pendidikan'),
    descriptor('hukdis', 'Riwayat Hukuman Disiplin'),
  ])
  svc.list.mockResolvedValue([pendidikanRow(1)])
})

describe('RiwayatPegawaiPage', () => {
  it('route sementara terdaftar di routes.riwayat.ts tanpa batas role (lingkup ditegakkan backend)', () => {
    const route = riwayatRoutes.find((r) => r.name === 'pegawai-riwayat')
    expect(route?.path).toBe('/pegawai/:nip/riwayat')
    expect(route?.meta?.roles).toBeUndefined()
  })

  it('GET pegawai/{nip}/riwayat → tab urutan backend; tab pertama aktif dan memuat datanya', async () => {
    const { wrapper } = await mountPage()
    expect(svc.tabs).toHaveBeenCalledWith(NIP)
    expect(tabLabels(wrapper)).toEqual(['Riwayat Kenaikan Pangkat', 'Riwayat KGB', 'Riwayat Pendidikan', 'Riwayat Hukuman Disiplin'])
    expect(svc.list).toHaveBeenCalledWith(NIP, 'kp')
    expect(wrapper.find('[data-testid="riwayat-section-kp"]').exists()).toBe(true)
  })

  it('?tab= memilih tab; hak dari descriptor (KGB tanpa Tambah)', async () => {
    const { wrapper } = await mountPage('?tab=kgb')
    expect(svc.list).toHaveBeenCalledWith(NIP, 'kgb')
    expect(wrapper.find('[data-testid="riwayat-section-kgb"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="riwayat-add"]').exists()).toBe(false)
  })

  it.each([
    [403, 'Akses ditolak'],
    [404, 'Pegawai tidak ditemukan'],
  ] as const)('galat %i → keadaan halaman, tanpa tab', async (status, title) => {
    svc.tabs.mockRejectedValue(apiError(status))
    const { wrapper } = await mountPage()
    expect(wrapper.get('[data-testid="riwayat-pegawai-failure"]').text()).toContain(title)
    expect(tabLabels(wrapper)).toEqual([])
    expect(svc.list).not.toHaveBeenCalled()
  })

  it('tanpa tab yang boleh dilihat → keterangan kosong', async () => {
    svc.tabs.mockResolvedValue([])
    const { wrapper } = await mountPage()
    expect(wrapper.get('[data-testid="riwayat-pegawai-kosong"]').text()).toContain('Tidak ada riwayat')
  })
})
