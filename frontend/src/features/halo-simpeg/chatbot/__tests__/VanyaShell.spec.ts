/**
 * Titik pasang widget di RedesignShell (review CR M-11a): dengan gerbang fase terbuka (ACTIVE_PHASE = 8) pegawai melihat tepat
 * satu launcher Vanya; selama wajib ganti password tidak ada. `buildNav` dikosongkan agar router tiruan shell tidak perlu
 * memuat semua route fase 4–8 (menu diuji di nav.config.spec / ChatbotManager.spec).
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/shared/layouts/nav.config', async (asli) => ({
  ...(await asli<typeof import('@/shared/layouts/nav.config')>()),
  ACTIVE_PHASE: 8,
  buildNav: () => [],
}))
vi.mock('@/features/notifikasi', async (asli) => {
  const a = await asli<typeof import('@/features/notifikasi')>()
  return { ...a, FASE_LONCENG: 99 }
})
vi.mock('../services/chatbot.service', async (asli) => ({
  ...(await asli<typeof import('../services/chatbot.service')>()),
  vanyaService: { topik: vi.fn(), pertanyaan: vi.fn(), populer: vi.fn(), balas: vi.fn(), feedback: vi.fn() },
}))

import { Role, type RoleCode } from '@/features/auth/types'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { resetChatbot } from '../stores/percakapan'

async function mountShell(role: RoleCode, extra: Parameters<typeof loginAs>[1] = {}) {
  resetChatbot()
  loginAs(role, extra)
  const router = createShellRouter()
  await router.push('/')
  await router.isReady()
  const wrapper = mount(RedesignShell, { slots: { default: '<p>isi</p>' }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('RedesignShell — widget Vanya', () => {
  it('ACTIVE_PHASE 8 + pegawai → tepat satu launcher', async () => {
    const w = await mountShell(Role.PEGAWAI)
    expect(w.findAll('[data-testid="vanya-launcher"]')).toHaveLength(1)
  })

  it('wajib ganti password → tidak ada launcher', async () => {
    const w = await mountShell(Role.PEGAWAI, { must_change_password: true })
    expect(w.find('[data-testid="vanya-launcher"]').exists()).toBe(false)
  })

  it('role 1 → tidak ada launcher', async () => {
    const w = await mountShell(Role.SUPER_ADMIN)
    expect(w.find('[data-testid="vanya-launcher"]').exists()).toBe(false)
  })
})
