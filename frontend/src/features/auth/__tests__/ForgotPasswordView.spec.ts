/**
 * A-07 / ISSUE-006 — halaman lupa password: captcha wajib, pesan generik setelah 200, 429/422 captcha jadi banner,
 * kotak tautan dev hanya kalau backend mengembalikan token DAN build development.
 */
import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('../services/auth.service', () => ({
  authService: { me: vi.fn(), login: vi.fn(), logout: vi.fn(), forgotPassword: vi.fn() },
}))

import { authService } from '../services/auth.service'
import ForgotPasswordView from '../views/ForgotPasswordView.vue'

const stub = { render: () => h('div') }
const GENERIC = 'Jika username terdaftar, instruksi reset password akan dikirimkan.'

function apiError(status: number, message: string, errors: Record<string, string[]> | null = null) {
  return { status, message, errors, isNetworkError: false, original: new AxiosError(message) }
}

async function mountView() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/login', name: 'login', component: stub },
      { path: '/lupa-password', name: 'forgot-password', component: ForgotPasswordView },
      { path: '/reset-password', name: 'reset-password', component: stub },
    ],
  })
  await router.push('/lupa-password')
  await router.isReady()
  return mount(ForgotPasswordView, { global: { plugins: [router] } })
}

async function fillAndSubmit(wrapper: VueWrapper, withCaptcha = true) {
  await wrapper.get('input[name="username"]').setValue('199002152015022002')
  if (withCaptcha) await wrapper.get('[data-testid="captcha-mock"] input').setValue(true)
  await wrapper.get('[data-testid="forgot-form"]').trigger('submit')
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(authService.forgotPassword).mockReset()
})

afterEach(() => {
  vi.unstubAllEnvs()
})

enableAutoUnmount(afterEach)

describe('ForgotPasswordView', () => {
  it('captcha wajib sebelum request dikirim', async () => {
    const wrapper = await mountView()
    await fillAndSubmit(wrapper, false)

    await vi.waitFor(() => expect(wrapper.text()).toContain('Selesaikan verifikasi captcha terlebih dahulu.'))
    expect(authService.forgotPassword).not.toHaveBeenCalled()
  })

  it('200 → form diganti pesan generik dari backend; tanpa token tidak ada kotak dev', async () => {
    vi.mocked(authService.forgotPassword).mockResolvedValue({ accepted: true, message: GENERIC })
    const wrapper = await mountView()
    await fillAndSubmit(wrapper)

    await vi.waitFor(() => expect(wrapper.find('[data-testid="forgot-form"]').exists()).toBe(false))
    expect(authService.forgotPassword).toHaveBeenCalledWith({ username: '199002152015022002', captcha_token: 'mock-dev-token' })
    expect(wrapper.get('[data-testid="forgot-message"]').text()).toBe(GENERIC)
    expect(wrapper.find('[data-testid="forgot-dev-token"]').exists()).toBe(false)
  })

  it('development + token dari backend → tautan langsung ke /reset-password#token=… (fragment, seperti tautan backend)', async () => {
    vi.mocked(authService.forgotPassword).mockResolvedValue({ accepted: true, message: GENERIC, token: 'abc123', expires_at: '2026-09-25 10:30:00' })
    const wrapper = await mountView()
    await fillAndSubmit(wrapper)

    await vi.waitFor(() =>
      expect(wrapper.get('[data-testid="forgot-dev-link"]').attributes('href')).toBe('/reset-password#token=abc123'),
    )
  })

  it('build production tidak pernah menampilkan token walau backend salah konfigurasi', async () => {
    vi.stubEnv('DEV', false)
    vi.mocked(authService.forgotPassword).mockResolvedValue({ accepted: true, message: GENERIC, token: 'abc123' })
    const wrapper = await mountView()
    await fillAndSubmit(wrapper)

    await vi.waitFor(() => expect(wrapper.get('[data-testid="forgot-message"]').text()).toBe(GENERIC))
    expect(wrapper.find('[data-testid="forgot-dev-token"]').exists()).toBe(false)
  })

  it('429 → banner kuning berisi pesan backend; captcha direset', async () => {
    vi.mocked(authService.forgotPassword).mockRejectedValue(
      apiError(429, 'Terlalu banyak permintaan reset password. Coba lagi dalam 60 menit.'),
    )
    const wrapper = await mountView()
    await fillAndSubmit(wrapper)

    await vi.waitFor(() =>
      expect(wrapper.get('[data-testid="forgot-error-rate-limit"]').text()).toContain('Coba lagi dalam 60 menit'),
    )
    expect((wrapper.get('[data-testid="captcha-mock"] input').element as HTMLInputElement).checked).toBe(false)

    // Token captcha lama benar-benar dikosongkan: kirim ulang tanpa verifikasi baru diblok di klien.
    await wrapper.get('[data-testid="forgot-form"]').trigger('submit')
    await flushPromises()
    await vi.waitFor(() => expect(wrapper.text()).toContain('Selesaikan verifikasi captcha terlebih dahulu.'))
    expect(authService.forgotPassword).toHaveBeenCalledTimes(1)
  })

  it('422 errors.username → error di field username, bukan banner', async () => {
    vi.mocked(authService.forgotPassword).mockRejectedValue(
      apiError(422, 'Validasi gagal.', { username: ['Username/NIP tidak valid.'] }),
    )
    const wrapper = await mountView()
    await fillAndSubmit(wrapper)

    await vi.waitFor(() => expect(wrapper.get('input[name="username"]').attributes('aria-invalid')).toBe('true'))
    expect(wrapper.text()).toContain('Username/NIP tidak valid.')
    expect(wrapper.find('[role="alert"][data-testid^="forgot-error-"]').exists()).toBe(false)
  })

  it('422 captcha → banner captcha', async () => {
    vi.mocked(authService.forgotPassword).mockRejectedValue(
      apiError(422, 'Verifikasi captcha gagal. Silakan ulangi.', { captcha_token: ['Verifikasi captcha gagal. Silakan ulangi.'] }),
    )
    const wrapper = await mountView()
    await fillAndSubmit(wrapper)

    await vi.waitFor(() =>
      expect(wrapper.get('[data-testid="forgot-error-captcha"]').text()).toBe('Verifikasi captcha gagal. Silakan ulangi.'),
    )
  })
})
