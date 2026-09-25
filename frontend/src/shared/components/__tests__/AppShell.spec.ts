/**
 * AppShell — menu per role (A-12, Modul G, G-10) dan navigasi yang boleh membungkus di layar HP.
 * Layout sungguhan tidak bisa diukur di jsdom; pengecekan lebar 375px dilakukan di browser (uji UI CR-003),
 * test ini mengunci syarat CSS-nya: nav wajib `flex-wrap`, bukan satu baris kaku.
 */
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { h } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role, type RoleCode, type User } from '@/features/auth/types'

import AppShell from '../AppShell.vue'

const stub = { render: () => h('div') }

function userWithRole(role: RoleCode): User {
  return { username: '198501012010011001', user_level: role } as User
}

async function mountAs(role: RoleCode) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: stub },
      { path: '/login', name: 'login', component: stub },
      { path: '/users', name: 'users', component: stub },
      { path: '/master/:entity?', name: 'master-data', component: stub },
      { path: '/faq/:id?', name: 'faq', component: stub },
    ],
  })
  await router.push('/')
  await router.isReady()

  const auth = useAuthStore()
  auth.user = userWithRole(role)

  return mount(AppShell, { global: { plugins: [router] } })
}

describe('AppShell', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('Super Admin melihat 4 menu dan nav boleh membungkus (tidak melebar di layar HP)', async () => {
    const wrapper = await mountAs(Role.SUPER_ADMIN)
    const nav = wrapper.get('[data-testid="nav-main"]')

    expect(nav.findAll('a').map((a) => a.text())).toEqual(['Beranda', 'Manajemen Akun', 'Master Data', 'FAQ'])
    expect(nav.classes()).toContain('flex-wrap')
  })

  it('Pegawai hanya melihat Beranda dan FAQ', async () => {
    const wrapper = await mountAs(Role.PEGAWAI)

    expect(wrapper.get('[data-testid="nav-main"]').findAll('a').map((a) => a.text())).toEqual(['Beranda', 'FAQ'])
    expect(wrapper.find('[data-testid="nav-master-data"]').exists()).toBe(false)
  })
})
