/**
 * MAKE-051 (PRD 5C §7.5, F5B-6, F5B-20): Rekap Uang Makan — akses role 1/3, tab Instansi Lain hanya role 1 dan hanya
 * memakai Bulan, tabel NIP | Tanggal Absen tanpa nominal, dan catatan perbedaan dengan rekap kehadiran.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/features/master-data/services/master.service', () => ({ masterService: { options: vi.fn().mockResolvedValue([]) } }))
vi.mock('../services/rekapKehadiran.service', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../services/rekapKehadiran.service')>()
  return { ...actual, rekapUangMakanService: { daftar: vi.fn(), unduh: vi.fn() } }
})

import { Role } from '@/features/auth/types'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { REKAP_UANG_MAKAN_ROLES } from '../roles'
import { rekapUangMakanService } from '../services/rekapKehadiran.service'
import { presensiRoutes } from '../routes.presensi'
import RekapUangMakanPage from '../views/RekapUangMakanPage.vue'

const svc = vi.mocked(rekapUangMakanService)

async function mountPage(role: number) {
  loginAs(role as never)
  svc.daftar.mockResolvedValue({ items: [{ nip: '198001012005011001', tgl_absen: '2026-10-05' }], total: 1, page: 1, per_page: 25, bulan: '2026-10', lingkup: {} })
  const router = createShellRouter()
  router.addRoute({ path: '/presensi/uang-makan', name: 'presensi-uang-makan', component: RekapUangMakanPage })
  await router.push('/presensi/uang-makan')
  await router.isReady()
  const wrapper = mount(RekapUangMakanPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper }
}

afterEach(() => {
  document.body.innerHTML = ''
  window.sessionStorage.clear()
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  svc.daftar.mockReset()
  svc.unduh.mockReset()
})

describe('akses role', () => {
  it('route dan peran: role 1 dan 3 saja', () => {
    expect(REKAP_UANG_MAKAN_ROLES).toEqual([Role.SUPER_ADMIN, Role.ADMIN_SATKER])
    expect(presensiRoutes.find((r) => r.name === 'presensi-uang-makan')?.meta?.roles).toEqual(REKAP_UANG_MAKAN_ROLES)
  })
})

describe('tab Instansi Lain', () => {
  it('role 1 melihat tab Pegawai dan Instansi Lain; role 3 hanya Pegawai', async () => {
    const { wrapper: admin } = await mountPage(Role.SUPER_ADMIN)
    expect(admin.get('[data-testid="rekap-uang-makan-tab"]').text()).toContain('Instansi Lain')
    admin.unmount()

    const { wrapper: satker } = await mountPage(Role.ADMIN_SATKER)
    expect(satker.find('[data-testid="rekap-uang-makan-tab"]').exists()).toBe(false)
  })

  it('CR-079: kartu filter tanpa fill; wadah tabel punya bingkai sendiri, catatan di luar bingkai', async () => {
    const { wrapper } = await mountPage(Role.SUPER_ADMIN)
    const bingkai = wrapper.get('[data-testid="rekap-uang-makan-bingkai"]')
    expect(bingkai.element.children).toHaveLength(1)
    expect(bingkai.element.firstElementChild?.getAttribute('data-testid')).toBe('rekap-uang-makan-wadah')
    expect(bingkai.text()).not.toContain('aturan slip')
  })

  it('memilih Instansi Lain memanggil daftar dengan il = true dan memakai bulan saja', async () => {
    const { wrapper } = await mountPage(Role.SUPER_ADMIN)
    const il = wrapper.findAll('[role="tab"]').find((t) => t.text().includes('Instansi Lain'))
    await il?.trigger('click')
    await flushPromises()

    const terakhir = svc.daftar.mock.calls.at(-1)
    expect(terakhir?.[1]).toBe(true)
    expect(wrapper.find('[data-testid="rekap-uang-makan-il-catatan"]').exists()).toBe(true)
  })
})

describe('tabel uang makan', () => {
  it('kolom NIP | Tanggal Absen, tanpa kolom nominal; baris menampilkan tanggal', async () => {
    const { wrapper } = await mountPage(Role.ADMIN_SATKER)
    const kepala = wrapper.findAll('[data-testid="rekap-uang-makan-tabel"] thead th').map((th) => th.text())
    expect(kepala).toEqual(['NIP', 'Tanggal Absen'])
    expect(wrapper.get('[data-testid="rekap-uang-makan-baris-198001012005011001"]').text()).toContain('5 Oktober 2026')
  })

  it('catatan selisih dengan rekap kehadiran ditampilkan', async () => {
    const { wrapper } = await mountPage(Role.ADMIN_SATKER)
    expect(wrapper.text()).toContain('Rekap kehadiran mengikuti aturan lama')
  })
})
