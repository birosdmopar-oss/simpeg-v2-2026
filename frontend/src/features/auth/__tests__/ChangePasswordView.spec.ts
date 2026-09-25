/**
 * A-06 / ISSUE-006 — halaman ganti password: checklist berubah saat diketik (sebelum submit), 422 dipetakan ke field,
 * sukses mengosongkan sesi lokal lalu pindah ke /login?reason=password-changed TANPA memanggil /auth/logout.
 */
import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'

vi.mock('../services/auth.service', () => ({
  authService: { me: vi.fn(), login: vi.fn(), logout: vi.fn(), changePassword: vi.fn() },
}))

import { authService } from '../services/auth.service'
import { useAuthStore } from '../stores/auth.store'
import { Role, type User } from '../types'
import ChangePasswordView from '../views/ChangePasswordView.vue'

const stub = { render: () => h('div') }
const user = { id_pengguna: 1, nip: '199002152015022002', username: '199002152015022002', user_level: Role.PEGAWAI } as User

function apiError(status: number | null, message: string, errors: Record<string, string[]> | null = null) {
  return { status, message, errors, isNetworkError: status === null, original: new AxiosError(message) }
}

let router: Router

async function mountView() {
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: stub },
      { path: '/login', name: 'login', component: stub },
      { path: '/ganti-password', name: 'change-password', component: ChangePasswordView },
    ],
  })
  await router.push('/ganti-password')
  await router.isReady()
  useAuthStore().setSession(user)
  return mount(ChangePasswordView, { global: { plugins: [router] } })
}

function ruleOk(wrapper: VueWrapper, id: string): string | undefined {
  return wrapper.get(`[data-testid="password-rule-${id}"]`).attributes('data-ok')
}

async function fill(wrapper: VueWrapper, values: Record<string, string>) {
  for (const [name, value] of Object.entries(values)) {
    await wrapper.get(`input[name="${name}"]`).setValue(value)
  }
}

/** Validasi skema vee-validate berjalan async (debounce): hasilnya ditunggu lewat vi.waitFor di tiap test. */
async function submit(wrapper: VueWrapper) {
  await wrapper.get('[data-testid="change-password-form"]').trigger('submit')
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(authService.changePassword).mockReset()
  vi.mocked(authService.logout).mockReset()
})

describe('ChangePasswordView', () => {
  it('checklist dihitung real-time saat diketik, sebelum submit', async () => {
    const wrapper = await mountView()
    const ids = ['min_length', 'uppercase', 'lowercase', 'digit', 'not_same', 'confirmation']
    expect(ids.map((id) => ruleOk(wrapper, id))).toEqual(['false', 'false', 'false', 'false', 'false', 'false'])

    await fill(wrapper, { new_password: 'abc' })
    expect(ruleOk(wrapper, 'lowercase')).toBe('true')
    expect(ruleOk(wrapper, 'uppercase')).toBe('false')

    await fill(wrapper, { old_password: 'Rahasia2026', new_password: 'Rahasia2026' })
    expect(['min_length', 'uppercase', 'lowercase', 'digit'].map((id) => ruleOk(wrapper, id))).toEqual(['true', 'true', 'true', 'true'])
    expect(ruleOk(wrapper, 'not_same')).toBe('false')

    await fill(wrapper, { new_password: 'RahasiaBaru2026', new_password_confirmation: 'RahasiaBaru2026' })
    expect(ruleOk(wrapper, 'not_same')).toBe('true')
    expect(ruleOk(wrapper, 'confirmation')).toBe('true')
    expect(authService.changePassword).not.toHaveBeenCalled()
  })

  it('password baru di luar kebijakan diblok di klien dengan pesan backend yang sama', async () => {
    const wrapper = await mountView()
    await fill(wrapper, { old_password: 'lama', new_password: 'password1', new_password_confirmation: 'password1' })
    await submit(wrapper)

    await vi.waitFor(() => expect(wrapper.text()).toContain('Password harus mengandung minimal 1 huruf besar.'))
    expect(authService.changePassword).not.toHaveBeenCalled()
  })

  it('422 backend dipetakan ke field; sesi tetap utuh', async () => {
    vi.mocked(authService.changePassword).mockRejectedValue(
      apiError(422, 'Password lama salah.', { old_password: ['Password lama salah.'] }),
    )
    const wrapper = await mountView()
    await fill(wrapper, { old_password: 'SalahSatu1', new_password: 'RahasiaBaru2026', new_password_confirmation: 'RahasiaBaru2026' })
    await submit(wrapper)

    await vi.waitFor(() => expect(wrapper.text()).toContain('Password lama salah.'))
    const oldField = wrapper.get('input[name="old_password"]')
    expect(oldField.attributes('aria-invalid')).toBe('true')
    expect(wrapper.text()).toContain('Password lama salah.')
    expect(wrapper.find('[data-testid="change-password-error"]').exists()).toBe(false)
    expect(useAuthStore().isAuthenticated).toBe(true)
    expect(router.currentRoute.value.name).toBe('change-password')
  })

  it('sukses: sesi dikosongkan, ke login dengan pesan, tanpa /auth/logout', async () => {
    vi.mocked(authService.changePassword).mockResolvedValue(undefined)
    const wrapper = await mountView()
    await fill(wrapper, { old_password: 'Rahasia2026', new_password: 'RahasiaBaru2026', new_password_confirmation: 'RahasiaBaru2026' })
    await submit(wrapper)

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))
    expect(authService.changePassword).toHaveBeenCalledWith({
      old_password: 'Rahasia2026',
      new_password: 'RahasiaBaru2026',
      new_password_confirmation: 'RahasiaBaru2026',
    })
    expect(authService.logout).not.toHaveBeenCalled()
    expect(useAuthStore().status).toBe('guest')
    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query).toEqual({ reason: 'password-changed' })
  })

  it('error jaringan → banner umum, tetap di halaman', async () => {
    vi.mocked(authService.changePassword).mockRejectedValue(apiError(null, 'Tidak dapat terhubung ke server. Periksa koneksi Anda.'))
    const wrapper = await mountView()
    await fill(wrapper, { old_password: 'Rahasia2026', new_password: 'RahasiaBaru2026', new_password_confirmation: 'RahasiaBaru2026' })
    await submit(wrapper)

    await vi.waitFor(() =>
      expect(wrapper.get('[data-testid="change-password-error"]').text()).toContain('Tidak dapat terhubung ke server'),
    )
    expect(router.currentRoute.value.name).toBe('change-password')
  })

  it('input password memakai autocomplete yang benar dan ada username tersembunyi untuk password manager', async () => {
    const wrapper = await mountView()
    expect(wrapper.get('input[name="old_password"]').attributes('autocomplete')).toBe('current-password')
    expect(wrapper.get('input[name="new_password"]').attributes('autocomplete')).toBe('new-password')
    expect(wrapper.get('input[name="new_password_confirmation"]').attributes('autocomplete')).toBe('new-password')
    expect((wrapper.get('input[name="username"]').element as HTMLInputElement).value).toBe(user.username)

    // Tombol mata menampilkan password.
    await wrapper.get('[data-testid="toggle-new_password"]').trigger('click')
    expect(wrapper.get('input[name="new_password"]').attributes('type')).toBe('text')
  })
})
