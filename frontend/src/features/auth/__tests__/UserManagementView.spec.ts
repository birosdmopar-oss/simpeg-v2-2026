/**
 * A-12 / CR-015 — Manajemen Akun: aksi per baris (Edit, Nonaktifkan|Aktifkan, Hapus) lewat menu ⋮ (RowActionsMenu,
 * testid `user-actions-<id_pengguna>`). Akun yang sedang login tidak bisa menonaktifkan/menghapus dirinya sendiri (item
 * nonaktif); aksi status/hapus selalu lewat ConfirmDialog sebelum memanggil API.
 *
 * DBV-010/CR-013 — akun non-pegawai boleh tanpa NIP: kolom Nama dan NIP ("—" bila kosong), dan "akun sendiri"
 * dibandingkan per id_pengguna (dengan perbandingan NIP, semua akun tanpa NIP ikut dianggap "diri sendiri"). Karena itu
 * testid baris/menu memakai id_pengguna, bukan NIP (NIP NULL akan membuat testid `...-null` ganda).
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/users.service', () => ({
  usersService: {
    list: vi.fn(),
    get: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    setStatus: vi.fn(),
    remove: vi.fn(),
  },
}))

import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'

import { usersService } from '../services/users.service'
import { useAuthStore } from '../stores/auth.store'
import { Role, type User } from '../types'
import UserManagementView from '../views/UserManagementView.vue'

function userOf(id: number, nip: string | null, username: string, status: User['status']): User {
  return {
    id_pengguna: id,
    nip,
    username,
    name: null,
    email: null,
    user_level: Role.PEGAWAI,
    id_unit: null,
    id_satker: null,
    status,
    last_login_at: null,
    created_at: null,
    updated_at: null,
  }
}

const self: User = { ...userOf(1, '198501012010011001', 'admin', '1'), user_level: Role.SUPER_ADMIN }
const active = userOf(2, '199002152015022002', 'budi', '1')
const inactive = userOf(3, '199103032016031003', 'sari', '0')

async function mountView(sessionUser: User = self) {
  useAuthStore().setSession(sessionUser)
  const wrapper = mount(UserManagementView, { attachTo: document.body })
  await flushPromises()
  return wrapper
}

function dialogText(): string | null {
  const dialog = document.body.querySelector('[role="alertdialog"]')
  return dialog ? (dialog.textContent ?? '').replace(/\s+/g, ' ').trim() : null
}

function dialogButton(label: string): HTMLButtonElement {
  const button = Array.from(
    document.body.querySelectorAll<HTMLButtonElement>('[role="alertdialog"] button'),
  ).find((b) => (b.textContent ?? '').trim() === label)
  if (!button) throw new Error(`tombol "${label}" tidak ada di dialog konfirmasi`)
  return button
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(usersService.list).mockResolvedValue({
    items: [self, active, inactive],
    total: 3,
    page: 1,
    per_page: 10,
  })
})

// Hook after berjalan terbalik (stack): pembersihan body didaftarkan dulu agar jalan SETELAH auto-unmount.
afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('UserManagementView — menu aksi baris ⋮', () => {
  it('akun lain: Edit, Nonaktifkan/Aktifkan sesuai status, lalu Hapus (merah); satu tombol ⋮ per baris', async () => {
    const wrapper = await mountView()

    expect(await rowMenuActions(wrapper, `user-actions-${active.id_pengguna}`)).toEqual([
      { key: 'edit', label: 'Edit', disabled: false, danger: false },
      { key: 'toggle', label: 'Nonaktifkan', disabled: false, danger: false },
      { key: 'delete', label: 'Hapus', disabled: false, danger: true },
    ])
    expect(
      (await rowMenuActions(wrapper, `user-actions-${inactive.id_pengguna}`)).map((a) => [a.label, a.disabled]),
    ).toEqual([
      ['Edit', false],
      ['Aktifkan', false],
      ['Hapus', false],
    ])

    const row = wrapper.get(`[data-testid="user-row-${active.id_pengguna}"]`)
    expect(row.findAll('button').map((b) => b.attributes('data-testid'))).toEqual([
      `user-actions-${active.id_pengguna}`,
    ])
    expect(row.get(`[data-testid="user-actions-${active.id_pengguna}"]`).attributes('aria-label')).toBe(
      'Aksi untuk budi',
    )
  })

  it('akun sendiri: Nonaktifkan & Hapus nonaktif (Edit tetap bisa) dan memilihnya tidak membuka konfirmasi', async () => {
    const wrapper = await mountView()
    const testid = `user-actions-${self.id_pengguna}`

    expect((await rowMenuActions(wrapper, testid)).map((a) => [a.key, a.label, a.disabled])).toEqual([
      ['edit', 'Edit', false],
      ['toggle', 'Nonaktifkan', true],
      ['delete', 'Hapus', true],
    ])

    await selectRowAction(wrapper, testid, 'delete')
    await selectRowAction(wrapper, testid, 'toggle')

    expect(dialogText()).toBeNull()
    expect(usersService.remove).not.toHaveBeenCalled()
    expect(usersService.setStatus).not.toHaveBeenCalled()
  })

  it('memilih Hapus membuka ConfirmDialog; konfirmasi memanggil remove lalu memuat ulang daftar', async () => {
    const wrapper = await mountView()
    vi.mocked(usersService.remove).mockResolvedValue()

    await selectRowAction(wrapper, `user-actions-${active.id_pengguna}`, 'delete')

    expect(dialogText()).toContain('Hapus akun budi?')
    expect(dialogText()).toContain('Akun dihapus (soft delete) dan seluruh sesi login akun tersebut dicabut.')
    expect(usersService.remove).not.toHaveBeenCalled()

    const loadsBefore = vi.mocked(usersService.list).mock.calls.length
    dialogButton('Hapus').click()
    await flushPromises()

    expect(usersService.remove).toHaveBeenCalledWith(2)
    expect(usersService.list).toHaveBeenCalledTimes(loadsBefore + 1)
    expect(wrapper.get('p[role="status"]').text()).toBe('Akun budi dihapus.')
    expect(dialogText()).toBeNull()
  })

  it('memilih Nonaktifkan / Aktifkan membuka ConfirmDialog yang sesuai status akun', async () => {
    const wrapper = await mountView()
    vi.mocked(usersService.setStatus).mockResolvedValue({ ...active, status: '0' })

    await selectRowAction(wrapper, `user-actions-${active.id_pengguna}`, 'toggle')
    expect(dialogText()).toContain('Nonaktifkan akun budi?')
    dialogButton('Nonaktifkan').click()
    await flushPromises()
    expect(usersService.setStatus).toHaveBeenCalledWith(2, '0')

    await selectRowAction(wrapper, `user-actions-${inactive.id_pengguna}`, 'toggle')
    expect(dialogText()).toContain('Aktifkan akun sari?')
    expect(usersService.setStatus).toHaveBeenCalledTimes(1)
  })

  it('memilih Edit membuka form edit akun', async () => {
    const wrapper = await mountView()

    await selectRowAction(wrapper, `user-actions-${active.id_pengguna}`, 'edit')

    expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain('Edit Akun')
    expect(dialogText()).toBeNull()
  })
})

describe('UserManagementView — akun tanpa NIP (DBV-010/CR-013)', () => {
  const adminTanpaNip: User = {
    ...userOf(11, null, 'superadmin', '1'),
    name: 'Super Admin',
    user_level: Role.SUPER_ADMIN,
  }
  const pimpinanTanpaNip: User = {
    ...userOf(12, null, 'pimpinan', '1'),
    name: 'Pimpinan',
    user_level: Role.PIMPINAN,
  }
  const pegawai = userOf(13, '199002152015022002', '199002152015022002', '1')

  beforeEach(() => {
    vi.mocked(usersService.list).mockResolvedValue({
      items: [adminTanpaNip, pimpinanTanpaNip, pegawai],
      total: 3,
      page: 1,
      per_page: 10,
    })
  })

  it('hanya baris akun sendiri (per id_pengguna) yang Nonaktifkan/Hapus-nya nonaktif; akun lain tanpa NIP tetap aktif', async () => {
    const wrapper = await mountView(adminTanpaNip)

    const byKey = async (id: number) =>
      Object.fromEntries((await rowMenuActions(wrapper, `user-actions-${id}`)).map((a) => [a.key, a.disabled]))

    expect(await byKey(11)).toEqual({ edit: false, toggle: true, delete: true })
    expect(await byKey(12)).toEqual({ edit: false, toggle: false, delete: false })
    expect(await byKey(13)).toEqual({ edit: false, toggle: false, delete: false })
    expect(wrapper.findAll('[data-testid="user-actions-null"]')).toHaveLength(0)
  })

  it('kolom Nama dan NIP menampilkan "—" bila kosong', async () => {
    const wrapper = await mountView(adminTanpaNip)

    expect(wrapper.findAll('th').map((th) => th.text())).toEqual(expect.arrayContaining(['Username', 'Nama', 'NIP']))
    const cells = (id: number) =>
      wrapper
        .get(`[data-testid="user-row-${id}"]`)
        .findAll('td')
        .map((td) => td.text())
    expect(cells(12).slice(0, 3)).toEqual(['pimpinan', 'Pimpinan', '—'])
    expect(cells(13).slice(0, 3)).toEqual(['199002152015022002', '—', '199002152015022002'])
  })

  it('pencarian mencakup nama; sortir per nama dikirim ke backend', async () => {
    const wrapper = await mountView(adminTanpaNip)
    expect((wrapper.get('[data-testid="user-search"]').element as HTMLInputElement).placeholder).toBe(
      'Cari username / NIP / nama',
    )

    const nameHeader = wrapper.findAll('th button').find((b) => b.text().startsWith('Nama'))
    if (!nameHeader) throw new Error('header Nama tidak ditemukan')
    await nameHeader.trigger('click')
    await flushPromises()

    expect(usersService.list).toHaveBeenLastCalledWith(expect.objectContaining({ sort: 'name', order: 'asc' }))
  })
})
