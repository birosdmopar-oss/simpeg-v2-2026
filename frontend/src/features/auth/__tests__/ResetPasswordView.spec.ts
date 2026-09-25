/**
 * A-07 / ISSUE-006 — halaman reset password: token dari fragment #token= (tautan backend) atau ?token= (cadangan legacy)
 * dibaca lalu dihapus dari URL, checklist real-time tanpa aturan "beda dari lama", token ditolak → form dikunci +
 * tautan minta baru, sukses → /login?reason=password-reset.
 */
import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'

vi.mock('../services/auth.service', () => ({
  authService: { me: vi.fn(), login: vi.fn(), logout: vi.fn(), resetPassword: vi.fn() },
}))

import { authService } from '../services/auth.service'
import ResetPasswordView from '../views/ResetPasswordView.vue'

const stub = { render: () => h('div') }
const NEW = 'RahasiaBaru2026'

function apiError(status: number, message: string, errors: Record<string, string[]> | null = null) {
  return { status, message, errors, isNetworkError: false, original: new AxiosError(message) }
}

let router: Router

async function mountAt(path: string) {
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/login', name: 'login', component: stub },
      { path: '/lupa-password', name: 'forgot-password', component: stub },
      { path: '/reset-password', name: 'reset-password', component: ResetPasswordView },
    ],
  })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(ResetPasswordView, { global: { plugins: [router] } })
  await flushPromises()
  return wrapper
}

async function fillAndSubmit(wrapper: VueWrapper, password = NEW, confirmation = password) {
  await wrapper.get('input[name="new_password"]').setValue(password)
  await wrapper.get('input[name="new_password_confirmation"]').setValue(confirmation)
  await wrapper.get('[data-testid="reset-form"]').trigger('submit')
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(authService.resetPassword).mockReset()
})

enableAutoUnmount(afterEach)

describe('ResetPasswordView', () => {
  it('token dari fragment #token= (tautan backend) disimpan di memori lalu dihapus dari URL', async () => {
    vi.mocked(authService.resetPassword).mockResolvedValue(undefined)
    const wrapper = await mountAt('/reset-password#token=tokhash789')

    expect(router.currentRoute.value.fullPath).toBe('/reset-password')
    expect(router.currentRoute.value.hash).toBe('')
    expect(wrapper.find('input[name="token"]').exists()).toBe(false)

    await fillAndSubmit(wrapper)

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))
    expect(authService.resetPassword).toHaveBeenCalledWith({
      token: 'tokhash789',
      new_password: NEW,
      new_password_confirmation: NEW,
    })
  })

  it('fragment #token= didahulukan dari ?token=; keduanya dihapus dari URL', async () => {
    vi.mocked(authService.resetPassword).mockResolvedValue(undefined)
    const wrapper = await mountAt('/reset-password?token=dariquery&lang=id#token=darihash')

    expect(router.currentRoute.value.fullPath).toBe('/reset-password?lang=id')

    await fillAndSubmit(wrapper)
    await vi.waitFor(() => expect(authService.resetPassword).toHaveBeenCalledTimes(1))
    expect(vi.mocked(authService.resetPassword).mock.calls[0]?.[0].token).toBe('darihash')
  })

  it('cadangan legacy: token dari ?token= disimpan di memori lalu dihapus dari URL; sukses → login dengan pesan', async () => {
    vi.mocked(authService.resetPassword).mockResolvedValue(undefined)
    const wrapper = await mountAt('/reset-password?token=tok123')

    expect(router.currentRoute.value.fullPath).toBe('/reset-password')
    expect(wrapper.find('input[name="token"]').exists()).toBe(false)

    await fillAndSubmit(wrapper)

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))
    expect(authService.resetPassword).toHaveBeenCalledWith({ token: 'tok123', new_password: NEW, new_password_confirmation: NEW })
    expect(router.currentRoute.value.query).toEqual({ reason: 'password-reset' })
  })

  it('checklist real-time memakai aturan yang sama, tanpa "beda dari password lama"', async () => {
    const wrapper = await mountAt('/reset-password?token=tok123')
    const ids = wrapper.findAll('[data-testid^="password-rule-"]').map((li) => li.attributes('data-testid'))
    expect(ids).toEqual([
      'password-rule-min_length',
      'password-rule-uppercase',
      'password-rule-lowercase',
      'password-rule-digit',
      'password-rule-confirmation',
    ])

    await wrapper.get('input[name="new_password"]').setValue('ABCDEFGH')
    expect(wrapper.get('[data-testid="password-rule-uppercase"]').attributes('data-ok')).toBe('true')
    expect(wrapper.get('[data-testid="password-rule-lowercase"]').attributes('data-ok')).toBe('false')
  })

  it('token ditolak backend → banner + tautan minta baru, form dikunci', async () => {
    vi.mocked(authService.resetPassword).mockRejectedValue(
      apiError(422, 'Token reset sudah kedaluwarsa.', { token: ['Token reset sudah kedaluwarsa.'] }),
    )
    const wrapper = await mountAt('/reset-password?token=tok123')
    await fillAndSubmit(wrapper)

    await vi.waitFor(() =>
      expect(wrapper.get('[data-testid="reset-token-error"]').text()).toContain('Token reset sudah kedaluwarsa.'),
    )
    expect(wrapper.get('[data-testid="reset-request-new"]').attributes('href')).toBe('/lupa-password')
    expect(wrapper.get('[data-testid="reset-fields"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="reset-submit"]').attributes('disabled')).toBeDefined()
    expect(router.currentRoute.value.name).toBe('reset-password')
  })

  it('422 kebijakan password dari backend dipetakan ke field', async () => {
    vi.mocked(authService.resetPassword).mockRejectedValue(
      apiError(422, 'Validasi gagal.', { new_password: ['Password harus mengandung minimal 1 huruf kecil.'] }),
    )
    const wrapper = await mountAt('/reset-password?token=tok123')
    await fillAndSubmit(wrapper)

    await vi.waitFor(() => expect(wrapper.get('input[name="new_password"]').attributes('aria-invalid')).toBe('true'))
    expect(wrapper.text()).toContain('Password harus mengandung minimal 1 huruf kecil.')
    expect(wrapper.find('[data-testid="reset-token-error"]').exists()).toBe(false)
  })

  it('422 tanpa field yang dikenali → pesan backend tampil sebagai banner', async () => {
    vi.mocked(authService.resetPassword).mockRejectedValue(apiError(422, 'Validasi gagal.', {}))
    const wrapper = await mountAt('/reset-password#token=tok123')
    await fillAndSubmit(wrapper)

    await vi.waitFor(() => expect(wrapper.get('[data-testid="reset-error"]').text()).toBe('Validasi gagal.'))
    expect(wrapper.find('[data-testid="reset-token-error"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="reset-fields"]').attributes('disabled')).toBeUndefined()
  })

  it('keterangan sesi tidak menjanjikan sesi lain langsung diakhiri (access token berlaku s.d. 60 menit)', async () => {
    const wrapper = await mountAt('/reset-password#token=tok123')
    const note = wrapper.get('[data-testid="reset-note"]').text()

    expect(note).toContain('paling lambat 60 menit')
    expect(note).not.toMatch(/semua sesi .*diakhiri/)
  })

  it('500 → sarankan coba lagi karena token belum terpakai', async () => {
    vi.mocked(authService.resetPassword).mockRejectedValue(apiError(500, 'Terjadi kesalahan pada server.'))
    const wrapper = await mountAt('/reset-password?token=tok123')
    await fillAndSubmit(wrapper)

    await vi.waitFor(() => expect(wrapper.get('[data-testid="reset-error"]').text()).toContain('Tautan reset masih berlaku'))
    expect(wrapper.find('[data-testid="reset-fields"]').attributes('disabled')).toBeUndefined()
  })

  it('tanpa token di URL → field tempel token wajib diisi', async () => {
    vi.mocked(authService.resetPassword).mockResolvedValue(undefined)
    const wrapper = await mountAt('/reset-password')

    await fillAndSubmit(wrapper)
    await vi.waitFor(() => expect(wrapper.text()).toContain('Token reset wajib diisi.'))
    expect(authService.resetPassword).not.toHaveBeenCalled()

    await wrapper.get('input[name="token"]').setValue('  manual456  ')
    await wrapper.get('[data-testid="reset-form"]').trigger('submit')
    await vi.waitFor(() => expect(authService.resetPassword).toHaveBeenCalledTimes(1))
    expect(authService.resetPassword).toHaveBeenCalledWith({ token: 'manual456', new_password: NEW, new_password_confirmation: NEW })
  })
})
