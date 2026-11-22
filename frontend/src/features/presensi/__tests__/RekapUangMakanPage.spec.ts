/**
 * MAKE-051 (PRD 5C §7.5, F5B-6, F5B-20): Rekap Uang Makan — akses role 1/3, tab Instansi Lain hanya role 1 dan hanya
 * memakai Bulan, tabel NIP | Tanggal Absen tanpa nominal, dan catatan perbedaan dengan rekap kehadiran.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { RouterView } from 'vue-router'

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

async function mountPage(role: number, url = '/presensi/uang-makan', total = 1) {
  loginAs(role as never)
  svc.daftar.mockResolvedValue({ items: [{ nip: '198001012005011001', tgl_absen: '2026-10-05' }], total, page: 1, per_page: 25, bulan: '2026-10', lingkup: {} })
  const router = createShellRouter()
  router.addRoute({ path: '/presensi/uang-makan', name: 'presensi-uang-makan', component: RekapUangMakanPage })
  await router.push(url)
  await router.isReady()
  // Dipasang di bawah RouterView seperti di aplikasi, supaya onBeforeRouteUpdate (penerapan query URL) aktif.
  const wrapper = mount(defineComponent({ render: () => h(RouterView) }), { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

async function pilihTab(wrapper: Awaited<ReturnType<typeof mountPage>>['wrapper'], label: string): Promise<void> {
  await wrapper
    .findAll('[role="tab"]')
    .find((t) => t.text().includes(label))
    ?.trigger('click')
  await flushPromises()
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

  it('CR-179: dari halaman 2 tab Pegawai, ganti ke Instansi Lain (dan kembali) meminta halaman 1', async () => {
    const { wrapper, router } = await mountPage(Role.SUPER_ADMIN, '/presensi/uang-makan?page=2', 60)
    expect(svc.daftar.mock.calls.at(-1)?.[0].page).toBe(2)

    svc.daftar.mockClear()
    await pilihTab(wrapper, 'Instansi Lain')
    expect(svc.daftar).toHaveBeenCalledTimes(1)
    expect(svc.daftar.mock.calls[0]?.[0].page).toBe(1)
    expect(svc.daftar.mock.calls[0]?.[1]).toBe(true)
    expect(router.currentRoute.value.query.page).toBeUndefined()

    await router.push('/presensi/uang-makan?page=2')
    await flushPromises()
    expect(svc.daftar.mock.calls.at(-1)?.[0].page).toBe(2)

    svc.daftar.mockClear()
    await pilihTab(wrapper, 'Pegawai')
    expect(svc.daftar).toHaveBeenCalledTimes(1)
    expect(svc.daftar.mock.calls[0]?.[0].page).toBe(1)
    expect(svc.daftar.mock.calls[0]?.[1]).toBe(false)
  })

  it('CR-179: ganti tab ke halaman 1, lalu query URL berikutnya tetap dipakai (penjaga regresi dariUrl)', async () => {
    const { wrapper, router } = await mountPage(Role.SUPER_ADMIN, '/presensi/uang-makan?page=2', 60)
    expect(svc.daftar.mock.calls[0]?.[0].page).toBe(2)
    await pilihTab(wrapper, 'Instansi Lain')
    expect(svc.daftar.mock.calls.at(-1)?.[0].page).toBe(1)

    // Back/forward ke rute yang sama dengan ?page=3 saat tab IL aktif: halaman dari URL yang dipakai.
    await router.push('/presensi/uang-makan?page=3')
    await flushPromises()
    expect(svc.daftar.mock.calls.at(-1)?.[0].page).toBe(3)
    expect(svc.daftar.mock.calls.at(-1)?.[1]).toBe(true)
    expect(wrapper.find('[data-testid="rekap-uang-makan-il-catatan"]').exists()).toBe(true)
  })

  it('CR-179 R3: respons tab lama yang datang belakangan tidak menimpa tab baru', async () => {
    const { wrapper, router } = await mountPage(Role.SUPER_ADMIN, '/presensi/uang-makan', 60)
    const lingkup = {}
    let selesaiPegawai!: (v: Awaited<ReturnType<typeof svc.daftar>>) => void
    let selesaiIl!: (v: Awaited<ReturnType<typeof svc.daftar>>) => void
    svc.daftar
      .mockImplementationOnce(() => new Promise((r) => (selesaiPegawai = r)))
      .mockImplementationOnce(() => new Promise((r) => (selesaiIl = r)))

    // Permintaan Pegawai (halaman 2 dari URL) masih berjalan, lalu pindah ke tab IL.
    await router.push('/presensi/uang-makan?page=2')
    await flushPromises()
    await pilihTab(wrapper, 'Instansi Lain')

    selesaiIl({ items: [{ nip: '111', tgl_absen: '2026-09-02' }], total: 1, page: 1, per_page: 25, bulan: '2026-09', lingkup })
    await flushPromises()
    selesaiPegawai({ items: [{ nip: '222', tgl_absen: '2026-09-03' }], total: 1, page: 1, per_page: 25, bulan: '2026-09', lingkup })
    await flushPromises()

    expect(wrapper.find('[data-testid="rekap-uang-makan-baris-111"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="rekap-uang-makan-baris-222"]').exists()).toBe(false)
  })

  it('CR-179 R3: memuat tetap tampil sampai permintaan terbaru selesai', async () => {
    const { wrapper, router } = await mountPage(Role.SUPER_ADMIN, '/presensi/uang-makan', 60)
    let selesaiPegawai!: (v: Awaited<ReturnType<typeof svc.daftar>>) => void
    svc.daftar.mockImplementationOnce(() => new Promise((r) => (selesaiPegawai = r))).mockImplementationOnce(() => new Promise(() => {}))

    await router.push('/presensi/uang-makan?page=2')
    await flushPromises()
    await pilihTab(wrapper, 'Instansi Lain')
    selesaiPegawai({ items: [], total: 0, page: 1, per_page: 25, bulan: '2026-09', lingkup: {} })
    await flushPromises()

    expect(wrapper.text()).toContain('Memuat...')
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
