/**
 * Badge item menu `AppSidebar` (MAKE-073, AC-E08.12): sidebar penuh menampilkan angka; sidebar ciut (desktop) menampilkan
 * titik penanda di ikon + jumlah `sr-only` (sebelumnya badge hilang saat ciut). Berlaku untuk semua item ber-badge. Di `main`
 * belum ada item ber-badge; badge Halo Simpeg (`sisipBadgeHalo`) baru dipasang di `RedesignShell.vue` sesudah README MAKE-071
 * beku, jadi test ini memakai `groups` tiruan.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { MessageCircle } from 'lucide-vue-next'
import { afterEach, describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import AppSidebar from '../AppSidebar.vue'
import type { NavGroup } from '../nav.config'

enableAutoUnmount(afterEach)

const groups = (badge?: number): NavGroup[] => [
  {
    key: 'bantuan',
    title: 'Pusat Bantuan',
    items: [
      { key: 'halo', label: 'Halo Simpeg', icon: MessageCircle, to: '/halo-simpeg', phase: 8, ...(badge === undefined ? {} : { badge }) },
      { key: 'faq', label: 'FAQ', icon: MessageCircle, to: '/faq', phase: 2 },
    ],
  },
]

async function pasang(collapsed: boolean, badge?: number) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: ['/', '/halo-simpeg', '/faq'].map((path) => ({ path, component: { render: () => null } })),
  })
  await router.push('/')
  const w = mount(AppSidebar, {
    props: { groups: groups(badge), collapsed, mobileOpen: false, userName: 'Uji', userSubtitle: 'Pegawai' },
    global: { plugins: [router] },
    attachTo: document.body,
  })
  await flushPromises()
  return w
}

describe('AppSidebar — badge item', () => {
  it('penuh: angka tampil, tanpa titik', async () => {
    const w = await pasang(false, 4)
    expect(w.get('[data-testid="nav-halo-badge"]').text()).toBe('4')
    expect(w.find('[data-testid="nav-halo-titik"]').exists()).toBe(false)
  })

  it('ciut (desktop): titik penanda aria-hidden + jumlah sr-only; angka tidak tampil', async () => {
    const w = await pasang(true, 4)
    const titik = w.get('[data-testid="nav-halo-titik"]')
    expect(titik.attributes('aria-hidden')).toBe('true')
    expect(w.find('[data-testid="nav-halo-badge"]').exists()).toBe(false)
    const tautan = w.get('[data-testid="nav-halo"]')
    expect(tautan.findAll('.sr-only').map((s) => s.text())).toEqual(['Halo Simpeg', '(4)'])
  })

  it.each([undefined, 0])('tanpa badge (%s): tanpa angka dan tanpa titik di kedua mode', async (badge) => {
    for (const ciut of [false, true]) {
      const w = await pasang(ciut, badge)
      expect(w.find('[data-testid="nav-halo-badge"]').exists()).toBe(false)
      expect(w.find('[data-testid="nav-halo-titik"]').exists()).toBe(false)
      w.unmount()
    }
  })

  it('item lain tanpa badge tidak terpengaruh', async () => {
    const w = await pasang(true, 4)
    expect(w.find('[data-testid="nav-faq-titik"]').exists()).toBe(false)
  })
})
