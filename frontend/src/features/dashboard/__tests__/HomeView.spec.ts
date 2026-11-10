/**
 * MAKE-087 (F-13 M1) — beranda per keluarga role: admin 1/3/4/5/8 → AdminDashboard dengan komposisi pegawai mode `donut`
 * (donut + tile status hari ini) atau `angka` (semua tile berurutan legacy + bar persen, tanpa chart; Q7-36); pegawai
 * 2/6/7 → PegawaiDashboard; galat memuat tampil tanpa merusak sapaan (`home-role` MTC-001 tetap ada).
 */
import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/beranda.service', () => ({ berandaService: { ringkasan: vi.fn() } }))

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role, type RoleCode, type User } from '@/features/auth/types'

import { berandaService } from '../services/beranda.service'
import type { RingkasanAdmin, TileBeranda } from '../types'
import HomeView from '../views/HomeView.vue'

const tile = (kode: string, label: string, kelompok: TileBeranda['kelompok'], jumlah: number, persen: number): TileBeranda => ({ kode, label, kelompok, jumlah, persen })

const admin = (tampilan: RingkasanAdmin['tampilan_komposisi']): RingkasanAdmin => ({
  keluarga: 'admin',
  lingkup: { jenis: 'kementerian', id: null },
  tampilan_komposisi: tampilan,
  tanggal: '2026-11-10',
  total_aktif: 1200,
  tile: [
    tile('pns', 'Total PNS', 'komposisi', 1000, 83),
    tile('ptt', 'Total PTT', 'komposisi', 100, 8),
    tile('non_pns', 'Total Non PNS', 'komposisi', 20, 2),
    tile('staf_khusus', 'Total Staff Khusus', 'komposisi', 5, 0),
    tile('tenaga_ahli', 'Total Tenaga Ahli', 'komposisi', 15, 1),
    tile('tb', 'Tugas Belajar', 'hari_ini', 12, 1),
    tile('ib', 'Izin Belajar', 'hari_ini', 8, 1),
    tile('cltn', 'CLTN', 'hari_ini', 2, 0),
    tile('cuti', 'Cuti Online', 'hari_ini', 30, 3),
    tile('presensi_online', 'Presensi Online', 'hari_ini', 640, 53),
    tile('pppk', 'Total PPPK', 'komposisi', 60, 5),
  ],
})

async function mountAs(role: RoleCode) {
  setActivePinia(createPinia())
  useAuthStore().user = { username: 'uji', user_level: role } as User
  const wrapper = mount(HomeView, { global: { stubs: { RedesignShell: { template: '<div><slot /></div>' } } } })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  vi.mocked(berandaService.ringkasan).mockReset()
})

describe('HomeView — keluarga admin', () => {
  it.each([Role.SUPER_ADMIN, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PIMPINAN])('role %i → AdminDashboard', async (role) => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(admin('donut'))
    const wrapper = await mountAs(role)

    expect(wrapper.find('[data-testid="beranda-admin"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="beranda-pegawai"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="home-role"]').text()).toContain(`${role} — `)
  })

  it('mode donut: donut komposisi (jenis pegawai) + tile status hari ini saja', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(admin('donut'))
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    expect(wrapper.find('[data-testid="beranda-komposisi-donut"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="beranda-komposisi-angka"]').exists()).toBe(false)
    expect(wrapper.find('svg').exists()).toBe(true)
    expect(wrapper.get('[data-testid="beranda-komposisi"]').text()).toContain('Total pegawai aktif 1.200')

    const tiles = wrapper.findAll('[data-testid^="beranda-tile-"]').map((t) => t.attributes('data-testid'))
    expect(tiles).toEqual(['tb', 'ib', 'cltn', 'cuti', 'presensi_online'].map((k) => `beranda-tile-${k}`))
    expect(wrapper.get('[data-testid="beranda-tile-presensi_online"]').text()).toContain('640')
  })

  it('mode angka (rollback legacy): 11 tile urutan legacy + bar persen, tanpa chart', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(admin('angka'))
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    expect(wrapper.find('[data-testid="beranda-komposisi-donut"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="beranda-komposisi"] svg').exists()).toBe(false)

    const labels = wrapper.findAll('[data-testid^="beranda-tile-"]').map((t) => t.find('p').text())
    expect(labels).toEqual([
      'Total PNS', 'Total PTT', 'Total Non PNS', 'Total Staff Khusus', 'Total Tenaga Ahli', 'Tugas Belajar', 'Izin Belajar', 'CLTN',
      'Cuti Online', 'Presensi Online', 'Total PPPK',
    ])
    expect(wrapper.get('[data-testid="beranda-tile-pns"]').text()).toContain('1.000')
    expect(wrapper.get('[data-testid="beranda-tile-pns"] [role="progressbar"]').attributes('aria-valuenow')).toBe('83')
  })

  it('lingkup satker ditampilkan', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue({ ...admin('donut'), lingkup: { jenis: 'satker', id: 7 } })
    const wrapper = await mountAs(Role.ADMIN_SATKER)

    expect(wrapper.get('[data-testid="beranda-lingkup"]').text()).toBe('Lingkup data: Satker')
  })
})

describe('HomeView — keluarga pegawai & galat', () => {
  it.each([Role.PEGAWAI, Role.PTT, Role.PPPK])('role %i → PegawaiDashboard', async (role) => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue({ keluarga: 'pegawai' })
    const wrapper = await mountAs(role)

    expect(wrapper.find('[data-testid="beranda-pegawai"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="beranda-admin"]').exists()).toBe(false)
  })

  it('galat memuat → pesan, sapaan & home-role tetap', async () => {
    vi.mocked(berandaService.ringkasan).mockRejectedValue({ status: 500, message: 'Server sedang bermasalah.', errors: null, isNetworkError: false, original: new AxiosError('x') })
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    expect(wrapper.get('[role="alert"]').text()).toBe('Server sedang bermasalah.')
    expect(wrapper.get('[data-testid="home-role"]').text()).toContain('1 — ')
    expect(wrapper.find('[data-testid="beranda-admin"]').exists()).toBe(false)
  })
})
