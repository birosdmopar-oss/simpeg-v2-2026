/**
 * G-08 Hari Libur (DBV-003/CR-010) — halaman daftar: role 1 (tambah/ubah/status/hapus/pulihkan + filter status) vs
 * role 4/5/8 (baca saja, hanya status Aktif dari backend), filter tahun (bawaan tahun berjalan), pencarian (debounce),
 * paginasi, format tanggal Indonesia + jumlah hari, pesan 409 (penulisan lain sedang berjalan), dan route /hari-libur
 * untuk role 1/4/5/8. Aksi baris lewat menu titik tiga ⋮ (AGENTS.md bagian 1), kolom Status hanya badge.
 */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/hariLibur.service', () => ({
  hariLiburService: { list: vi.fn(), get: vi.fn(), create: vi.fn(), update: vi.fn(), setStatus: vi.fn(), remove: vi.fn(), jenisOptions: vi.fn() },
}))

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role, type RoleCode, type User } from '@/features/auth/types'
import router from '@/router'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'

import HariLiburFormDialog from '../components/HariLiburFormDialog.vue'
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

async function actionKeys(wrapper: VueWrapper, id: string): Promise<string[]> {
  return (await rowMenuActions(wrapper, `hari-libur-actions-${id}`)).map((a) => a.key)
}

function confirmButton(): HTMLButtonElement {
  const button = Array.from(document.body.querySelectorAll<HTMLButtonElement>('[role="alertdialog"] button')).find((b) => b.textContent?.trim() === 'Hapus')
  if (!button) throw new Error('tombol konfirmasi Hapus tidak ditemukan')
  return button
}

const year = String(new Date().getFullYear())
const query = (override: Record<string, unknown> = {}) => ({ tahun: year, search: '', status: '', page: 1, per_page: 20, ...override })

beforeEach(() => {
  document.body.innerHTML = ''
  vi.mocked(hariLiburService.list).mockReset().mockResolvedValue({ items: rows, total: rows.length, page: 1, per_page: 20 })
  vi.mocked(hariLiburService.setStatus).mockReset().mockResolvedValue({ ...rows[0]!, status: '2' })
  vi.mocked(hariLiburService.remove).mockReset().mockResolvedValue({ deleted: true, soft_delete: true, item: { ...rows[0]!, status: '10' } })
  vi.mocked(hariLiburService.jenisOptions).mockReset().mockResolvedValue([])
})

describe('HariLiburView', () => {
  it('Super Admin: daftar tahun berjalan, filter status & tahun, tombol tambah', async () => {
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    expect(hariLiburService.list).toHaveBeenCalledWith(query())
    expect(wrapper.find('[data-testid="hari-libur-add"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="hari-libur-status"]').exists()).toBe(true)

    const cuti = wrapper.get('[data-testid="hari-libur-row-2"]').text()
    expect(cuti).toContain('19 Maret 2026 – 20 Maret 2026')
    expect(cuti).toContain('Cuti Bersama Idul Fitri')
    expect(cuti).toContain('SKB 3 Menteri')
    expect(wrapper.get('[data-testid="hari-libur-row-2"]').findAll('td')[1]?.text()).toBe('2')
    expect(wrapper.get('[data-testid="hari-libur-row-5"]').findAll('td')[3]?.text()).toBe('—')
    expect(wrapper.get('[data-testid="hari-libur-row-5"]').text()).toContain('25 Desember 2025')

    await wrapper.get('[data-testid="hari-libur-status"]').setValue('10')
    await flushPromises()
    expect(hariLiburService.list).toHaveBeenLastCalledWith(query({ status: '10' }))

    await wrapper.get('[data-testid="hari-libur-tahun"]').setValue('')
    await flushPromises()
    expect(hariLiburService.list).toHaveBeenLastCalledWith(query({ tahun: '', status: '10' }))
    wrapper.unmount()
  })

  it('menu ⋮ per status: Aktif → Nonaktifkan, Tidak Aktif → Aktifkan, Dihapus → Pulihkan tanpa Hapus; Status hanya badge', async () => {
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    expect(await rowMenuActions(wrapper, 'hari-libur-actions-2')).toEqual([
      { key: 'edit', label: 'Edit', disabled: false, danger: false },
      { key: 'deactivate', label: 'Nonaktifkan', disabled: false, danger: false },
      { key: 'delete', label: 'Hapus', disabled: false, danger: true },
    ])
    expect(await actionKeys(wrapper, '5')).toEqual(['edit', 'activate', 'delete'])
    expect(await actionKeys(wrapper, '4')).toEqual(['edit', 'restore'])

    for (const [id, status] of [['2', '1'], ['4', '10'], ['5', '2']]) {
      const row = wrapper.get(`[data-testid="hari-libur-row-${id}"]`)
      expect(row.find('[role="switch"]').exists()).toBe(false)
      expect(row.get('[data-status]').attributes('data-status')).toBe(status)
      expect(row.findAll('button').map((b) => b.attributes('data-testid'))).toEqual([`hari-libur-actions-${id}`])
    }
    expect(wrapper.get('[data-testid="hari-libur-actions-4"]').attributes('aria-label')).toBe('Aksi untuk Hari Lahir Pancasila')
    wrapper.unmount()
  })

  it('aksi status lewat menu: Nonaktifkan, Aktifkan, Pulihkan memanggil setStatus lalu memuat ulang', async () => {
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    await selectRowAction(wrapper, 'hari-libur-actions-2', 'deactivate')
    expect(hariLiburService.setStatus).toHaveBeenLastCalledWith('2', '2')
    expect(wrapper.text()).toContain('"Cuti Bersama Idul Fitri" dinonaktifkan dan tidak lagi dihitung sebagai hari libur.')

    await selectRowAction(wrapper, 'hari-libur-actions-5', 'activate')
    expect(hariLiburService.setStatus).toHaveBeenLastCalledWith('5', '1')
    expect(wrapper.text()).toContain('"Hari Raya Natal" diaktifkan dan dihitung sebagai hari libur.')

    await selectRowAction(wrapper, 'hari-libur-actions-4', 'restore')
    expect(hariLiburService.setStatus).toHaveBeenLastCalledWith('4', '1')
    expect(wrapper.text()).toContain('"Hari Lahir Pancasila" dipulihkan dan kembali aktif.')
    expect(hariLiburService.list).toHaveBeenCalledTimes(4)
    wrapper.unmount()
  })

  it('hapus lewat menu → konfirmasi → remove(id), pesan, dan daftar dimuat ulang; gagal → pesan error, dialog tertutup', async () => {
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    await selectRowAction(wrapper, 'hari-libur-actions-2', 'delete')
    expect(document.body.querySelector('[role="alertdialog"]')?.textContent).toContain('Hapus hari libur "Cuti Bersama Idul Fitri"?')
    expect(hariLiburService.remove).not.toHaveBeenCalled()

    confirmButton().click()
    await flushPromises()
    expect(hariLiburService.remove).toHaveBeenCalledWith('2')
    expect(wrapper.get('[role="status"]').text()).toBe(
      '"Cuti Bersama Idul Fitri" dihapus dan tidak lagi dihitung sebagai hari libur; bisa dipulihkan lewat filter status Dihapus.',
    )
    expect(hariLiburService.list).toHaveBeenCalledTimes(2)
    await vi.waitFor(() => expect(document.body.querySelector('[role="alertdialog"]')).toBeNull())

    vi.mocked(hariLiburService.remove).mockRejectedValueOnce(busy)
    await selectRowAction(wrapper, 'hari-libur-actions-5', 'delete')
    confirmButton().click()
    await flushPromises()
    expect(hariLiburService.remove).toHaveBeenLastCalledWith('5')
    expect(wrapper.get('[role="alert"]').text()).toBe('Data hari libur sedang diubah pengguna lain. Coba lagi.')
    await vi.waitFor(() => expect(document.body.querySelector('[role="alertdialog"]')).toBeNull())
    expect(hariLiburService.list).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('pencarian menunggu 300 ms lalu memuat halaman 1; paginasi Berikutnya/Sebelumnya', async () => {
    vi.mocked(hariLiburService.list).mockResolvedValue({ items: rows, total: 45, page: 1, per_page: 20 })
    const wrapper = await mountAs(Role.SUPER_ADMIN)
    expect(wrapper.text()).toContain('45 data · halaman 1 dari 3')

    await wrapper.get('[data-testid="hari-libur-next"]').trigger('click')
    await flushPromises()
    expect(hariLiburService.list).toHaveBeenLastCalledWith(query({ page: 2 }))
    await wrapper.get('[data-testid="hari-libur-next"]').trigger('click')
    await flushPromises()
    expect(hariLiburService.list).toHaveBeenLastCalledWith(query({ page: 3 }))
    expect(wrapper.get('[data-testid="hari-libur-next"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid="hari-libur-prev"]').trigger('click')
    await flushPromises()
    expect(hariLiburService.list).toHaveBeenLastCalledWith(query({ page: 2 }))

    const calls = vi.mocked(hariLiburService.list).mock.calls.length
    await wrapper.get('[data-testid="hari-libur-search"]').setValue('  idul ')
    await flushPromises()
    expect(hariLiburService.list).toHaveBeenCalledTimes(calls)
    await new Promise((resolve) => setTimeout(resolve, 350))
    await flushPromises()
    expect(hariLiburService.list).toHaveBeenCalledTimes(calls + 1)
    expect(hariLiburService.list).toHaveBeenLastCalledWith(query({ search: 'idul', page: 1 }))
    wrapper.unmount()
  })

  it('Edit lewat menu membuka form berisi baris; simpan → pesan diperbarui; Tambah → pesan ditambahkan', async () => {
    const wrapper = await mountAs(Role.SUPER_ADMIN)
    const dialog = wrapper.getComponent(HariLiburFormDialog)

    await selectRowAction(wrapper, 'hari-libur-actions-2', 'edit')
    expect(dialog.props('open')).toBe(true)
    expect(dialog.props('row')).toEqual(rows[0])
    dialog.vm.$emit('saved', { ...rows[0]!, nama_libur: 'Cuti Bersama Lebaran' })
    await flushPromises()
    expect(wrapper.get('[role="status"]').text()).toBe('Hari libur "Cuti Bersama Lebaran" diperbarui.')
    expect(hariLiburService.list).toHaveBeenCalledTimes(2)

    await wrapper.get('[data-testid="hari-libur-add"]').trigger('click')
    expect(dialog.props('row')).toBeNull()
    dialog.vm.$emit('saved', { ...rows[0]!, id_libur: 9, nama_libur: 'Hari Kemerdekaan' })
    await flushPromises()
    expect(wrapper.get('[role="status"]').text()).toBe('Hari libur "Hari Kemerdekaan" ditambahkan.')
    wrapper.unmount()
  })

  it.each([Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PIMPINAN])('role %i: baca saja (tanpa tombol, filter status, dan kolom aksi)', async (role) => {
    const wrapper = await mountAs(role)

    expect(hariLiburService.list).toHaveBeenCalledWith(query())
    expect(wrapper.find('[data-testid="hari-libur-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="hari-libur-status"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="hari-libur-actions-2"]').exists()).toBe(false)
    expect(wrapper.find('[role="switch"]').exists()).toBe(false)
    expect(wrapper.findAll('th').map((th) => th.text())).not.toContain('Aksi')
    wrapper.unmount()
  })

  it('409 saat ubah status → pesan coba lagi tampil, daftar tidak berubah', async () => {
    vi.mocked(hariLiburService.setStatus).mockRejectedValue(busy)
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    await selectRowAction(wrapper, 'hari-libur-actions-2', 'deactivate')

    expect(hariLiburService.setStatus).toHaveBeenCalledWith('2', '2')
    expect(wrapper.get('[role="alert"]').text()).toBe('Data hari libur sedang diubah pengguna lain. Coba lagi.')
    expect(hariLiburService.list).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('route /hari-libur hanya untuk role 1/4/5/8', () => {
    expect(router.resolve('/hari-libur').name).toBe('hari-libur')
    expect(router.resolve('/hari-libur').meta.roles).toEqual(HARI_LIBUR_READ_ROLES)
    expect(HARI_LIBUR_READ_ROLES).toEqual([Role.SUPER_ADMIN, Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PIMPINAN])
  })
})
