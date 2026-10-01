/** HomeView — dispatcher dashboard per role (Admin vs Pengguna/Pimpinan), tab Kehadiran Tim, popup pengumuman. */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import { Role, type RoleCode } from '@/features/auth/types'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import HomeView from '../views/HomeView.vue'

async function mountHome(role: RoleCode) {
  loginAs(role)
  const router = createShellRouter()
  await router.push('/')
  await router.isReady()
  const wrapper = mount(HomeView, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

beforeEach(() => sessionStorage.clear())
afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('HomeView', () => {
  it.each([Role.SUPER_ADMIN, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1])('role %i memakai Dashboard Admin', async (role) => {
    const wrapper = await mountHome(role)
    expect(wrapper.find('[data-testid="dashboard-admin"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="dashboard-user"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="stat-pns"]').text()).toContain('1.826')
    expect(wrapper.findAll('[data-testid="birthday-list"] li').length).toBeGreaterThan(0)
  })

  it('baris identitas role tetap ada (MTC-001)', async () => {
    const wrapper = await mountHome(Role.SUPER_ADMIN)
    expect(wrapper.get('[data-testid="home-role"]').text()).toMatch(/^1 —/)
  })

  it('Pegawai memakai Dashboard Pengguna tanpa tab Kehadiran Tim', async () => {
    const wrapper = await mountHome(Role.PEGAWAI)
    expect(wrapper.find('[data-testid="dashboard-user"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="clock"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('Kehadiran Tim')
  })

  it('Rekam Masuk memberi pesan jujur bahwa belum tersambung', async () => {
    const wrapper = await mountHome(Role.PEGAWAI)
    await wrapper.get('[data-testid="rekam-masuk"]').trigger('click')
    expect(wrapper.text()).toContain('belum tersambung')
  })

  it('Pimpinan punya tab Kehadiran Tim', async () => {
    const wrapper = await mountHome(Role.PIMPINAN)
    const tabs = wrapper.findAll('[role="tab"]')
    expect(tabs.map((t) => t.text())).toEqual(['Dashboard Saya', 'Kehadiran Tim'])
    await tabs[1].trigger('click')
    expect(wrapper.findAll('[data-testid="team-list"] li').length).toBeGreaterThan(0)
  })

  it('popup pengumuman muncul sekali per sesi dan bisa ditutup', async () => {
    const first = await mountHome(Role.PEGAWAI)
    expect(document.body.querySelector('[data-testid="announcement-popup"]')).not.toBeNull()
    ;(document.body.querySelector('[data-testid="announcement-close"]') as HTMLElement).click()
    await flushPromises()
    expect(document.body.querySelector('[data-testid="announcement-popup"]')).toBeNull()
    expect(sessionStorage.getItem('simpeg.pengumuman.dilihat')).toBe('1')
    first.unmount()
    await mountHome(Role.PEGAWAI)
    expect(document.body.querySelector('[data-testid="announcement-popup"]')).toBeNull()
  })
})
