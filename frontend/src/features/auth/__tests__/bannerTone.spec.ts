/**
 * CR-190 R2: nada Alert V5 banner auth mengikuti Figma A-11 — gangguan jaringan (2130-65378) = error, akun terkunci
 * (2130-65345) dan batas permintaan 429 = warning. Semua banner tetap role="alert"; teks pesan backend tidak berubah.
 */
import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { type Component, h } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('../services/auth.service', () => ({
  authService: { me: vi.fn(), login: vi.fn(), logout: vi.fn(), forgotPassword: vi.fn() },
}))

import { authService } from '../services/auth.service'
import ForgotPasswordView from '../views/ForgotPasswordView.vue'
import LoginView from '../views/LoginView.vue'

const stub = { render: () => h('div') }

function apiError(status: number | null, message: string) {
  return { status, message, errors: null, isNetworkError: status === null, original: new AxiosError(message) }
}

async function pasang(view: Component, path: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: stub },
      { path: '/login', name: 'login', component: view === LoginView ? LoginView : stub },
      { path: '/lupa-password', name: 'forgot-password', component: view === ForgotPasswordView ? ForgotPasswordView : stub },
      { path: '/reset-password', name: 'reset-password', component: stub },
    ],
  })
  await router.push(path)
  await router.isReady()
  return mount(view, { global: { plugins: [router] } })
}

async function kirim(wrapper: VueWrapper, form: string, password: boolean) {
  await wrapper.get('input[name="username"]').setValue('pengguna.contoh')
  if (password) await wrapper.get('input[name="password"]').setValue('Rahasia123')
  await wrapper.get('[data-testid="captcha-mock"] input').setValue(true)
  await wrapper.get(`[data-testid="${form}"]`).trigger('submit')
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(authService.login).mockReset()
  vi.mocked(authService.forgotPassword).mockReset()
})

enableAutoUnmount(afterEach)

describe('LoginView — nada banner V5', () => {
  it.each([
    ['network', null, 'Tidak dapat terhubung ke server. Periksa koneksi Anda.', 'bg-v5-error-subtle'],
    ['credentials', 401, 'Username atau password salah.', 'bg-v5-error-subtle'],
    ['locked', 423, 'Akun terkunci sementara.', 'bg-v5-warning-subtle'],
  ] as const)('%s (HTTP %s) → role alert, kelas sesuai nada', async (kind, status, pesan, kelas) => {
    vi.mocked(authService.login).mockRejectedValue(apiError(status, pesan))
    const wrapper = await pasang(LoginView, '/login')
    await kirim(wrapper, 'login-form', true)

    const banner = await vi.waitFor(() => wrapper.get(`[data-testid="login-error-${kind}"]`))
    expect(banner.attributes('role')).toBe('alert')
    expect(banner.classes()).toContain(kelas)
    expect(banner.text()).toBe(pesan)
  })
})

describe('ForgotPasswordView — nada banner V5', () => {
  it.each([
    ['network', null, 'Tidak dapat terhubung ke server. Periksa koneksi Anda.', 'bg-v5-error-subtle'],
    ['rate-limit', 429, 'Terlalu banyak permintaan reset password. Coba lagi nanti.', 'bg-v5-warning-subtle'],
    ['generic', 500, 'Terjadi kesalahan server.', 'bg-v5-error-subtle'],
  ] as const)('%s (HTTP %s) → role alert, kelas sesuai nada', async (kind, status, pesan, kelas) => {
    vi.mocked(authService.forgotPassword).mockRejectedValue(apiError(status, pesan))
    const wrapper = await pasang(ForgotPasswordView, '/lupa-password')
    await kirim(wrapper, 'forgot-form', false)

    const banner = await vi.waitFor(() => wrapper.get(`[data-testid="forgot-error-${kind}"]`))
    expect(banner.attributes('role')).toBe('alert')
    expect(banner.classes()).toContain(kelas)
    expect(banner.text()).toBe(pesan)
  })

  it('hasil "Permintaan diterima": satu live region (root Alert sukses role="status"); pesan tanpa role sendiri', async () => {
    vi.mocked(authService.forgotPassword).mockResolvedValue({ accepted: true, message: 'Jika akun terdaftar, tautan reset telah dikirim.' })
    const wrapper = await pasang(ForgotPasswordView, '/lupa-password')
    await kirim(wrapper, 'forgot-form', false)

    const pesan = await vi.waitFor(() => wrapper.get('[data-testid="forgot-message"]'))
    expect(pesan.attributes('role')).toBeUndefined()
    expect(pesan.text()).toBe('Jika akun terdaftar, tautan reset telah dikirim.')
    expect(wrapper.get('[data-testid="forgot-result"]').findAll('[role="status"]')).toHaveLength(1)
    expect(pesan.element.closest('[role="status"]')?.classList.contains('bg-v5-success-subtle')).toBe(true)
  })
})
