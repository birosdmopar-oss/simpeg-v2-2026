/** Layanan (Status Layanan) & Berita — service tiruan server dan render halaman. */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import { Role } from '@/features/auth/types'
import { beritaService, filterBerita } from '@/features/berita/berita.service'
import NewsPage from '@/features/berita/views/NewsPage.vue'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { filterLayanan } from '../services/layanan.service'
import LayananStatusPage from '../views/LayananStatusPage.vue'

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('layanan.service', () => {
  it('paginasi memotong per_page dan total menghitung seluruh hasil', () => {
    const all = filterLayanan({ page: 1, per_page: 1000 })
    const p1 = filterLayanan({ page: 1, per_page: 10 })
    expect(p1.items).toHaveLength(10)
    expect(p1.total).toBe(all.total)
  })

  it('filter status hanya mengembalikan status itu', () => {
    const r = filterLayanan({ page: 1, per_page: 1000, status: 'Selesai' })
    expect(r.items.every((i) => i.status === 'Selesai')).toBe(true)
  })
})

describe('berita.service', () => {
  it('filter kategori dan pencarian judul', async () => {
    const all = filterBerita({ page: 1, per_page: 1000 })
    const one = all.items[0]
    expect(filterBerita({ page: 1, per_page: 1000, kategori: one.kategori }).items.every((b) => b.kategori === one.kategori)).toBe(true)
    expect(filterBerita({ page: 1, per_page: 1000, search: one.judul }).items.length).toBeGreaterThan(0)
    expect((await beritaService.latest(3)).length).toBe(3)
  })
})

async function mountPage(component: object, path: string) {
  loginAs(Role.PEGAWAI)
  const router = createShellRouter()
  await router.push(path)
  await router.isReady()
  const wrapper = mount(component, { global: { plugins: [router] }, attachTo: document.body })
  await new Promise((r) => setTimeout(r, 300))
  await flushPromises()
  return wrapper
}

describe('halaman', () => {
  it('Status Layanan menampilkan 10 baris dengan menu ⋮', async () => {
    const wrapper = await mountPage(LayananStatusPage, '/layanan/status')
    expect(wrapper.findAll('[data-testid^="layanan-row-"]')).toHaveLength(10)
    expect(wrapper.find('[data-testid^="layanan-actions-"]').exists()).toBe(true)
  })

  it('News Portal menampilkan daftar berita', async () => {
    const wrapper = await mountPage(NewsPage, '/berita')
    expect(wrapper.findAll('article').length).toBeGreaterThan(0)
  })
})
