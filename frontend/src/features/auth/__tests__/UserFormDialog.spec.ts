/**
 * DBV-010/CR-013 — form akun: akun tanpa NIP untuk role 1/3/4/5/8 (isian nama), NIP wajib untuk role 2/6/7, NIP
 * read-only saat edit akun ber-NIP, "Tautkan NIP" untuk akun tanpa NIP, dan error 422 server dipetakan ke field.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/users.service', () => ({
  usersService: { create: vi.fn(), update: vi.fn() },
}))

import UserFormDialog from '../components/UserFormDialog.vue'
import { NIP_REQUIRED_MESSAGE } from '../schemas/user.schema'
import { usersService } from '../services/users.service'
import { useAuthStore } from '../stores/auth.store'
import { Role, type User } from '../types'

function account(overrides: Partial<User> = {}): User {
  return {
    id_pengguna: 10,
    nip: null,
    username: 'admin.pusat',
    name: 'Admin Pusat',
    email: null,
    user_level: Role.SUPER_ADMIN,
    id_unit: 'U01',
    id_satker: 'S01',
    status: '1',
    last_login_at: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  }
}

function mountDialog(user: User | null) {
  return mount(UserFormDialog, { props: { open: true, user }, attachTo: document.body })
}

function field<T extends HTMLElement = HTMLInputElement>(name: string): T {
  const el = document.body.querySelector<T>(`[name="${name}"]`)
  if (!el) throw new Error(`field ${name} tidak ditemukan`)
  return el
}

function typeInto(name: string, value: string): void {
  const el = field(name)
  el.value = value
  el.dispatchEvent(new Event('input'))
}

async function chooseRole(value: string): Promise<void> {
  const el = field<HTMLSelectElement>('user_level')
  el.value = value
  el.dispatchEvent(new Event('change'))
  await flushPromises()
}

async function submitForm(): Promise<void> {
  const form = document.body.querySelector<HTMLFormElement>('form[data-testid="user-form"]')
  if (!form) throw new Error('form tidak ditemukan')
  form.dispatchEvent(new Event('submit', { cancelable: true }))
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  useAuthStore().setSession(account({ id_pengguna: 1, username: 'superadmin' }))
  vi.mocked(usersService.create).mockReset()
  vi.mocked(usersService.update).mockReset()
  vi.mocked(usersService.create).mockImplementation(async (payload) => account({ id_pengguna: 11, ...payload, nip: payload.nip ?? null }))
  vi.mocked(usersService.update).mockImplementation(async (id, payload) => account({ id_pengguna: id, ...payload, nip: payload.nip ?? null }))
})

afterEach(() => {
  document.body.innerHTML = ''
})

describe('UserFormDialog — tambah akun', () => {
  it('role 1 tanpa NIP + nama → payload nip null dan name', async () => {
    const wrapper = mountDialog(null)
    await flushPromises()

    await chooseRole('1')
    typeInto('name', 'Admin Pusat')
    typeInto('email', 'admin@example.go.id')
    typeInto('username', 'admin.pusat')
    typeInto('password', 'AkunBaru2026')
    await flushPromises()
    await submitForm()
    await vi.waitFor(() => expect(usersService.create).toHaveBeenCalledTimes(1))

    expect(usersService.create).toHaveBeenCalledWith(
      expect.objectContaining({ nip: null, name: 'Admin Pusat', email: 'admin@example.go.id', username: 'admin.pusat', user_level: 1 }),
    )
    wrapper.unmount()
  })

  it('role 2 tanpa NIP → error di field NIP, tidak ada request', async () => {
    const wrapper = mountDialog(null)
    await flushPromises()

    await chooseRole('2')
    typeInto('name', 'Pegawai')
    typeInto('username', 'pegawai')
    typeInto('password', 'AkunBaru2026')
    await flushPromises()
    await submitForm()

    await vi.waitFor(() => expect(document.body.textContent).toContain(NIP_REQUIRED_MESSAGE))
    expect(field('nip').getAttribute('aria-invalid')).toBe('true')
    expect(usersService.create).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('akun ber-NIP: nama dan username boleh kosong (username default NIP)', async () => {
    const wrapper = mountDialog(null)
    await flushPromises()

    await chooseRole('6')
    typeInto('nip', '3171012345678901')
    typeInto('password', 'AkunBaru2026')
    await flushPromises()
    await submitForm()
    await vi.waitFor(() => expect(usersService.create).toHaveBeenCalledTimes(1))

    expect(usersService.create).toHaveBeenCalledWith(expect.objectContaining({ nip: '3171012345678901', name: null, username: undefined, user_level: 6 }))
    wrapper.unmount()
  })

  it('error 422 server dipetakan ke field name/email', async () => {
    vi.mocked(usersService.create).mockRejectedValue({
      status: 422,
      message: 'Validasi gagal.',
      errors: { name: ['Nama ditolak server.'], email: ['Email ditolak server.'] },
      isNetworkError: false,
      original: new AxiosError('x'),
    })
    const wrapper = mountDialog(null)
    await flushPromises()

    await chooseRole('5')
    typeInto('name', 'Menteri')
    typeInto('username', 'menteri')
    typeInto('password', 'AkunBaru2026')
    await flushPromises()
    await submitForm()

    await vi.waitFor(() => expect(document.body.textContent).toContain('Nama ditolak server.'))
    expect(document.body.textContent).toContain('Email ditolak server.')
    expect(field('email').getAttribute('aria-invalid')).toBe('true')
    wrapper.unmount()
  })
})

describe('UserFormDialog — edit akun', () => {
  it('akun ber-NIP: NIP tampil read-only dan tidak ikut payload', async () => {
    const wrapper = mountDialog(account({ id_pengguna: 20, nip: '199002152015022002', username: '199002152015022002', name: null, user_level: Role.PEGAWAI }))
    await flushPromises()

    const nip = field('nip_readonly')
    expect(nip.value).toBe('199002152015022002')
    expect(nip.disabled).toBe(true)
    expect(document.body.querySelector('[name="nip"]')).toBeNull()

    await submitForm()
    await vi.waitFor(() => expect(usersService.update).toHaveBeenCalledTimes(1))
    expect(vi.mocked(usersService.update).mock.calls[0]?.[1]).not.toHaveProperty('nip')
    wrapper.unmount()
  })

  it('akun tanpa NIP: isian "Tautkan NIP" mengirim nip', async () => {
    const wrapper = mountDialog(account({ id_pengguna: 21 }))
    await flushPromises()

    expect(document.body.textContent).toContain('Tautkan NIP')
    expect(document.body.textContent).toContain('Akun tanpa NIP')
    typeInto('nip', '199002152015022099')
    await flushPromises()
    await submitForm()
    await vi.waitFor(() => expect(usersService.update).toHaveBeenCalledTimes(1))

    expect(usersService.update).toHaveBeenCalledWith(21, expect.objectContaining({ nip: '199002152015022099', name: 'Admin Pusat' }))
    wrapper.unmount()
  })

  it('akun tanpa NIP: tanpa isian NIP, nip tidak dikirim', async () => {
    const wrapper = mountDialog(account({ id_pengguna: 22 }))
    await flushPromises()

    await submitForm()
    await vi.waitFor(() => expect(usersService.update).toHaveBeenCalledTimes(1))
    expect(vi.mocked(usersService.update).mock.calls[0]?.[1]).not.toHaveProperty('nip')
    wrapper.unmount()
  })
})
