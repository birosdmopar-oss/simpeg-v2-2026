/**
 * ISSUE-006 — halaman login: tautan "Lupa password?" hanya kalau VITE_PASSWORD_RESET_ENABLED=true (default tetap
 * "Hubungi Admin"), dan banner sukses untuk ?reason=password-changed|password-reset.
 */
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('../services/auth.service', () => ({
  authService: { me: vi.fn(), login: vi.fn(), logout: vi.fn() },
}))

import LoginView from '../views/LoginView.vue'

const stub = { render: () => h('div') }

async function mountAt(path: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: stub },
      { path: '/login', name: 'login', component: LoginView },
      { path: '/lupa-password', name: 'forgot-password', component: stub },
    ],
  })
  await router.push(path)
  await router.isReady()
  return mount(LoginView, { global: { plugins: [router] } })
}

beforeEach(() => {
  setActivePinia(createPinia())
})

afterEach(() => {
  vi.unstubAllEnvs()
})

describe('LoginView — lupa password', () => {
  it.each([undefined, 'false', ''])('flag %s → tetap "Hubungi Admin", tanpa tautan', async (flag) => {
    vi.stubEnv('VITE_PASSWORD_RESET_ENABLED', flag)
    const wrapper = await mountAt('/login')

    expect(wrapper.get('[data-testid="login-forgot-contact-admin"]').text()).toContain('Hubungi Admin')
    expect(wrapper.find('[data-testid="login-forgot-link"]').exists()).toBe(false)
  })

  it('flag true → tautan "Lupa password?" ke /lupa-password', async () => {
    vi.stubEnv('VITE_PASSWORD_RESET_ENABLED', 'true')
    const wrapper = await mountAt('/login')

    const link = wrapper.get('[data-testid="login-forgot-link"]')
    expect(link.text()).toBe('Lupa password?')
    expect(link.attributes('href')).toBe('/lupa-password')
    expect(wrapper.find('[data-testid="login-forgot-contact-admin"]').exists()).toBe(false)
  })
})

describe('LoginView — banner ?reason=', () => {
  it.each([
    ['password-changed', 'Password berhasil diubah. Silakan masuk kembali dengan password baru.'],
    ['password-reset', 'Password berhasil direset. Silakan masuk dengan password baru.'],
  ])('%s → banner sukses', async (reason, message) => {
    const wrapper = await mountAt(`/login?reason=${reason}`)
    expect(wrapper.get('[data-testid="login-notice"]').text()).toBe(message)
  })

  it('reason lain / kosong → tanpa banner', async () => {
    expect((await mountAt('/login?reason=lainnya')).find('[data-testid="login-notice"]').exists()).toBe(false)
    expect((await mountAt('/login')).find('[data-testid="login-notice"]').exists()).toBe(false)
  })
})
