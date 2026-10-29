/**
 * MAKE-051 (PRD 5C §7.5, AC-FE5C.1–5): Rekap Kehadiran — akses role 1/3/4, kolom ringkas + TB [V2] dengan catatan,
 * filter Unit/Satker hanya role 1, dan ekspor memanggil service dengan parameter lingkup tanpa paginasi.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/features/master-data/services/master.service', () => ({ masterService: { options: vi.fn().mockResolvedValue([]) } }))
vi.mock('../services/rekapKehadiran.service', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../services/rekapKehadiran.service')>()
  return {
    ...actual,
    rekapKehadiranService: { daftar: vi.fn(), unduh: vi.fn() },
  }
})
vi.mock('@/features/kepegawaian/services/berkasUnduhan', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/features/kepegawaian/services/berkasUnduhan')>()
  return { ...actual, simpanBerkas: vi.fn() }
})

import { Role } from '@/features/auth/types'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'
import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'

import { REKAP_KEHADIRAN_PILIH_UNIT_ROLES, REKAP_KEHADIRAN_ROLES } from '../roles'
import { rekapKehadiranService, type BarisRekapKehadiran } from '../services/rekapKehadiran.service'
import { presensiRoutes } from '../routes.presensi'
import RekapKehadiranPage from '../views/RekapKehadiranPage.vue'

const svc = vi.mocked(rekapKehadiranService)

const baris = (over: Partial<BarisRekapKehadiran> = {}): BarisRekapKehadiran => ({
  nip: '198001012005011001',
  nama: 'Budi Santoso',
  jabatan: 'Analis',
  hari_kerja: 22,
  hadir: 20,
  tl: 1,
  psw: 0,
  tk: 1,
  sakit: 0,
  dinas_luar: 0,
  lain_lain: 0,
  cuti: 0,
  tidak_hadir: 1,
  tb: 0,
  ...over,
})

async function mountPage(role: number) {
  loginAs(role as never)
  svc.daftar.mockResolvedValue({ items: [baris()], total: 1, page: 1, per_page: 25, bulan: '2026-10', lingkup: {} })
  const router = createShellRouter()
  router.addRoute({ path: '/presensi/rekap-kehadiran', name: 'presensi-rekap-kehadiran', component: RekapKehadiranPage })
  await router.push('/presensi/rekap-kehadiran')
  await router.isReady()
  const wrapper = mount(RekapKehadiranPage, { global: { plugins: [router] }, attachTo: document.body })
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
  vi.mocked(simpanBerkas).mockReset()
})

describe('akses role', () => {
  it('route dan peran: role 1, 3, 4 saja (role 2, 6, 7, 8 tidak)', () => {
    expect(REKAP_KEHADIRAN_ROLES).toEqual([Role.SUPER_ADMIN, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1])
    expect(presensiRoutes.find((r) => r.name === 'presensi-rekap-kehadiran')?.meta?.roles).toEqual(REKAP_KEHADIRAN_ROLES)
  })
})

describe('tabel ringkas', () => {
  it('CR-078 T-07: header kolom TB polos; penjelasan v2 ada di catatan bawah tabel (TB tidak mengurangi kolom lain)', async () => {
    const { wrapper } = await mountPage(Role.SUPER_ADMIN)
    expect(wrapper.get('[data-testid="rekap-kehadiran-kolom-tb"]').text()).toBe('TB')
    expect(wrapper.get('[data-testid="rekap-kehadiran-catatan-tb"]').text()).toContain('tambahan v2')
    expect(wrapper.get('[data-testid="rekap-kehadiran-catatan-tb"]').text()).toContain('tidak mengurangi kolom lain')
  })

  it('nama dan NIP memakai kelas kolom sticky kiri; baris memuat nilai dari server', async () => {
    const { wrapper } = await mountPage(Role.SUPER_ADMIN)
    const baris = wrapper.get('[data-testid="rekap-kehadiran-baris-198001012005011001"]')
    expect(baris.findAll('.col-sticky-kiri')).toHaveLength(2)
    expect(baris.text()).toContain('Budi Santoso')
  })

  it('CR-079: wadah tabel punya bingkai sendiri; catatan & paginasi di luar bingkai (tidak tertimpa tabel sticky di layar sempit)', async () => {
    const { wrapper } = await mountPage(Role.SUPER_ADMIN)
    const bingkai = wrapper.get('[data-testid="rekap-kehadiran-bingkai"]')
    expect(bingkai.element.children).toHaveLength(1)
    expect(bingkai.element.firstElementChild?.classList.contains('table-fill')).toBe(true)
    expect(bingkai.find('[data-testid="rekap-kehadiran-catatan-tb"]').exists()).toBe(false)
  })

  it('panggilan daftar memuat bulan berjalan dan paginasi', async () => {
    await mountPage(Role.SUPER_ADMIN)
    const q = svc.daftar.mock.calls[0]?.[0]
    expect(q).toMatchObject({ page: 1, per_page: 25 })
    expect(q?.bulan).toMatch(/^\d{4}-(0[1-9]|1[0-2])$/)
  })
})

describe('filter lingkup', () => {
  it('role 1 dan 4 boleh memilih Unit/Satker; role 3 tidak', () => {
    expect(REKAP_KEHADIRAN_PILIH_UNIT_ROLES).toEqual([Role.SUPER_ADMIN, Role.ADMIN_VIEW_ESELON1])
  })

  it('role 1 melihat filter Unit dan mengirim unit_satker', async () => {
    const { wrapper } = await mountPage(Role.SUPER_ADMIN)
    expect(wrapper.find('[data-testid="filter-laporan"]').text()).toContain('Unit')
    expect(svc.daftar.mock.calls[0]?.[0].unit_satker).toBe('0')
  })

  it('role 4 melihat filter Unit dan mengirim unit_satker (lingkup penuh)', async () => {
    const { wrapper } = await mountPage(Role.ADMIN_VIEW_ESELON1)
    expect(wrapper.find('[data-testid="filter-laporan"]').text()).toContain('Unit')
    expect(svc.daftar.mock.calls[0]?.[0].unit_satker).toBe('0')
  })

  it('role 3 tidak melihat filter Unit/Satker dan tidak mengirim unit_satker', async () => {
    const { wrapper } = await mountPage(Role.ADMIN_SATKER)
    expect(wrapper.find('[data-testid="filter-laporan"]').text()).not.toContain('Satker')
    expect(svc.daftar.mock.calls[0]?.[0].unit_satker).toBeUndefined()
  })
})

describe('ekspor', () => {
  it('Excel harian memanggil unduh(xlsx-harian) dengan lingkup tanpa paginasi, lalu menyimpan berkas', async () => {
    const berkas = { blob: new Blob(['x']), nama: 'rekap_kehadiran_harian_0_2026-10.xlsx' }
    svc.unduh.mockResolvedValue(berkas)
    const { wrapper } = await mountPage(Role.SUPER_ADMIN)

    wrapper.findComponent({ name: 'UiExportMenu' }).vm.$emit('select', 'xlsx')
    await flushPromises()
    const pilihan = wrapper.findAllComponents({ name: 'UiExportMenu' })[1]
    pilihan?.vm.$emit('select', 'xlsx')
    await flushPromises()

    expect(svc.unduh).toHaveBeenCalledWith('xlsx-harian', expect.objectContaining({ bulan: expect.any(String) }))
    expect(svc.unduh.mock.calls.at(-1)?.[1]).not.toHaveProperty('page')
    expect(simpanBerkas).toHaveBeenCalledWith(berkas)
  })
})
