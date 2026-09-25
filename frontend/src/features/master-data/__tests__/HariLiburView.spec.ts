/**
 * G-08 Hari Libur (DBV-003/CR-010) — halaman daftar: role 1 (tambah/ubah/status/hapus/pulihkan + filter status) vs
 * role 4/5/8 (baca saja, hanya status Aktif dari backend), filter tahun (bawaan tahun berjalan), format tanggal
 * Indonesia + jumlah hari, pesan 409 (penulisan lain sedang berjalan), dan route /hari-libur untuk role 1/4/5/8.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/hariLibur.service', () => ({
  hariLiburService: { list: vi.fn(), get: vi.fn(), create: vi.fn(), update: vi.fn(), setStatus: vi.fn(), remove: vi.fn(), jenisOptions: vi.fn() },
}))

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role, type RoleCode, type User } from '@/features/auth/types'
import router from '@/router'

import { HARI_LIBUR_READ_ROLES, type HariLiburRow } from '../hariLibur.types'
import { hariLiburService } from '../services/hariLibur.service'
import HariLiburView from '../views/HariLiburView.vue'

const rows: HariLiburRow[] = [
  {
    id_libur: 2,
    id_jenis_libur: 2,
    jenis_libur: 'Cuti Bersama',
    tgl_mulai: '2026-03-19',
    tgl_akhir: '2026-03-20',
    nama_libur: 'Cuti Bersama Idul Fitri',
    keterangan: 'SKB 3 Menteri',
    status: '1',
    created_at: null,
    updated_at: null,
    updated_by: null,
  },
  {
    id_libur: 4,
    id_jenis_libur: 1,
    jenis_libur: 'Libur Nasional',
    tgl_mulai: '2026-06-01',
    tgl_akhir: '2026-06-01',
    nama_libur: 'Hari Lahir Pancasila',
    keterangan: null,
    status: '10',
    created_at: null,
    updated_at: null,
    updated_by: null,
  },
  {
    id_libur: 5,
    id_jenis_libur: null,
    jenis_libur: null,
    tgl_mulai: '2025-12-25',
    tgl_akhir: '2025-12-25',
    nama_libur: 'Hari Raya Natal',
    keterangan: null,
    status: '2',
    created_at: null,
    updated_at: null,
    updated_by: null,
  },
]

const busy = {
  status: 409,
  message: 'Data hari libur sedang diubah pengguna lain. Coba lagi.',
  errors: null,
  isNetworkError: false,
  original: new AxiosError('x'),
}

async function mountAs(role: RoleCode) {
  setActivePinia(createPinia())
  useAuthStore().user = { username: 'uji', user_level: role } as User
  const wrapper = mount(HariLiburView, { attachTo: document.body })
  await flushPromises()
  return wrapper
}

const year = String(new Date().getFullYear())

beforeEach(() => {
  document.body.innerHTML = ''
  vi.mocked(hariLiburService.list).mockReset().mockResolvedValue({ items: rows, total: rows.length, page: 1, per_page: 20 })
  vi.mocked(hariLiburService.setStatus).mockReset().mockResolvedValue({ ...rows[0]!, status: '2' })
  vi.mocked(hariLiburService.remove).mockReset()
  vi.mocked(hariLiburService.jenisOptions).mockReset().mockResolvedValue([])
})

describe('HariLiburView', () => {
  it('Super Admin: daftar tahun berjalan, filter status, tombol tambah/ubah/hapus/pulihkan', async () => {
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    expect(hariLiburService.list).toHaveBeenCalledWith({ tahun: year, search: '', status: '', page: 1, per_page: 20 })
    expect(wrapper.find('[data-testid="hari-libur-add"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="hari-libur-status"]').exists()).toBe(true)

    const cuti = wrapper.get('[data-testid="hari-libur-row-2"]').text()
    expect(cuti).toContain('19 Maret 2026 – 20 Maret 2026')
    expect(cuti).toContain('Cuti Bersama Idul Fitri')
    expect(cuti).toContain('SKB 3 Menteri')
    expect(wrapper.get('[data-testid="hari-libur-row-2"]').findAll('td')[1]?.text()).toBe('2')
    expect(wrapper.get('[data-testid="hari-libur-row-5"]').findAll('td')[3]?.text()).toBe('—')
    expect(wrapper.get('[data-testid="hari-libur-row-5"]').text()).toContain('25 Desember 2025')
    expect(wrapper.find('[data-testid="hari-libur-edit-2"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="hari-libur-restore-4"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="hari-libur-restore-2"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="hari-libur-delete-4"]').attributes('disabled')).toBeDefined()

    await wrapper.get('[data-testid="hari-libur-status"]').setValue('10')
    await flushPromises()
    expect(hariLiburService.list).toHaveBeenLastCalledWith({ tahun: year, search: '', status: '10', page: 1, per_page: 20 })

    await wrapper.get('[data-testid="hari-libur-tahun"]').setValue('')
    await flushPromises()
    expect(hariLiburService.list).toHaveBeenLastCalledWith({ tahun: '', search: '', status: '10', page: 1, per_page: 20 })

    await wrapper.get('[data-testid="hari-libur-restore-4"]').trigger('click')
    await flushPromises()
    expect(hariLiburService.setStatus).toHaveBeenCalledWith('4', '1')
    expect(wrapper.text()).toContain('"Hari Lahir Pancasila" dipulihkan dan kembali aktif.')
    wrapper.unmount()
  })

  it.each([Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PIMPINAN])('role %i: baca saja (tanpa tombol, filter status, dan kolom aksi)', async (role) => {
    const wrapper = await mountAs(role)

    expect(hariLiburService.list).toHaveBeenCalledWith({ tahun: year, search: '', status: '', page: 1, per_page: 20 })
    expect(wrapper.find('[data-testid="hari-libur-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="hari-libur-status"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="hari-libur-edit-2"]').exists()).toBe(false)
    expect(wrapper.find('[role="switch"]').exists()).toBe(false)
    expect(wrapper.findAll('th').map((th) => th.text())).not.toContain('Aksi')
    wrapper.unmount()
  })

  it('409 saat ubah status → pesan coba lagi tampil, daftar tidak berubah', async () => {
    vi.mocked(hariLiburService.setStatus).mockRejectedValue(busy)
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    await wrapper.get('[data-testid="hari-libur-row-2"] [role="switch"]').trigger('click')
    await flushPromises()

    expect(hariLiburService.setStatus).toHaveBeenCalledWith('2', '2')
    expect(wrapper.get('[role="alert"]').text()).toBe('Data hari libur sedang diubah pengguna lain. Coba lagi.')
    wrapper.unmount()
  })

  it('route /hari-libur hanya untuk role 1/4/5/8', () => {
    expect(router.resolve('/hari-libur').name).toBe('hari-libur')
    expect(router.resolve('/hari-libur').meta.roles).toEqual(HARI_LIBUR_READ_ROLES)
    expect(HARI_LIBUR_READ_ROLES).toEqual([Role.SUPER_ADMIN, Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PIMPINAN])
  })
})
