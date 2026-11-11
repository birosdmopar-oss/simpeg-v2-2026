/**
 * CR-117 (temuan QA Lapis 1 F7 T-06): route popup↔banner dan berita-kategori↔dibo-jenis memakai instans view yang sama; tanpa
 * `:key` data lama (dan service/testid aksi lama) terbawa ke halaman baru. Di sini KontenPage dipasang dengan router sungguhan,
 * lalu pindah halaman lewat router.push — service jenis BARU harus dipanggil, baris diganti, dan testid aksi menunjuk id baris baru.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'

import type { KontenGambarRow, MasterKontenRow } from '../types'

const gambar = vi.hoisted(() => ({
  popup: { list: vi.fn(), setStatus: vi.fn(), geser: vi.fn(), remove: vi.fn(), opsiUnitSatker: vi.fn(), target: vi.fn() },
  banner: { list: vi.fn(), setStatus: vi.fn(), geser: vi.fn(), remove: vi.fn(), opsiUnitSatker: vi.fn(), target: vi.fn() },
}))
const master = vi.hoisted(() => ({
  'berita-kategori': { list: vi.fn(), simpan: vi.fn(), setStatus: vi.fn(), geser: vi.fn(), remove: vi.fn() },
  'dibo-jenis': { list: vi.fn(), simpan: vi.fn(), setStatus: vi.fn(), geser: vi.fn(), remove: vi.fn() },
}))

vi.mock('../services/kelola.service', async (orig) => ({
  ...(await orig<typeof import('../services/kelola.service')>()),
  kelolaService: (j: 'popup' | 'banner') => gambar[j],
}))
vi.mock('../services/kelolaTeks.service', async (orig) => ({
  ...(await orig<typeof import('../services/kelolaTeks.service')>()),
  masterKontenService: (j: 'berita-kategori' | 'dibo-jenis') => master[j],
}))

import KontenPage from '../views/KontenPage.vue'

const barisGambar = (o: Partial<KontenGambarRow>): KontenGambarRow => ({
  id: '1',
  keterangan: 'Libur',
  url: '',
  gambar_url: null,
  tgl_mulai: '2026-03-01',
  tgl_akhir: '2026-03-31',
  jam_mulai: '',
  jam_akhir: '',
  urutan: 1,
  status: '1',
  jumlah_penerima: 3,
  ...o,
})
const barisMaster = (o: Partial<MasterKontenRow>): MasterKontenRow => ({ id: '1', nama: 'Umum', urutan: 1, allow_ap: true, status: '1', ...o })

const RedesignShellStub = defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) })

async function pasang() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/konten/popup', component: KontenPage, props: { halaman: 'popup' } },
      { path: '/konten/banner', component: KontenPage, props: { halaman: 'banner' } },
      { path: '/konten/berita-kategori', component: KontenPage, props: { halaman: 'berita-kategori' } },
      { path: '/konten/dibo-jenis', component: KontenPage, props: { halaman: 'dibo-jenis' } },
    ],
  })
  await router.push('/konten/popup')
  await router.isReady()
  const w = mount(
    defineComponent({ setup: () => () => h(RouterView) }),
    { global: { plugins: [router], stubs: { RedesignShell: RedesignShellStub, KontenGambarFormDialog: true } }, attachTo: document.body },
  )
  await flushPromises()

  return { router, w }
}

beforeEach(() => {
  vi.clearAllMocks()
  for (const s of Object.values(gambar)) s.opsiUnitSatker.mockResolvedValue([])
  gambar.popup.list.mockResolvedValue({
    items: [barisGambar({ id: '11', keterangan: 'Popup A', url: 'https://example.invalid/popup-a-yang-sangat-panjang-sekali' }), barisGambar({ id: '12', keterangan: 'Popup B' })],
    total: 2,
  })
  gambar.banner.list.mockResolvedValue({ items: [barisGambar({ id: '21', keterangan: 'Banner A', url: 'https://example.invalid/banner' })], total: 1 })
  master['berita-kategori'].list.mockResolvedValue([barisMaster({ id: '31', nama: 'Kategori A' }), barisMaster({ id: '32', nama: 'Kategori B', urutan: 2 })])
  master['dibo-jenis'].list.mockResolvedValue([barisMaster({ id: '41', nama: 'UU' }), barisMaster({ id: '42', nama: 'PP', urutan: 2 })])
})

describe('KontenPage — pindah halaman memuat ulang (T-06)', () => {
  it('popup → banner: service banner dipanggil, baris popup diganti, testid milik banner', async () => {
    const { router, w } = await pasang()
    expect(gambar.popup.list).toHaveBeenCalledTimes(1)
    expect(w.find('[data-testid="konten-popup-row-11"]').exists()).toBe(true)

    await router.push('/konten/banner')
    await flushPromises()

    expect(gambar.banner.list).toHaveBeenCalledTimes(1)
    expect(w.find('[data-testid="konten-popup-row-11"]').exists()).toBe(false)
    expect(w.find('[data-testid="konten-banner-row-21"]').text()).toContain('Banner A')
    expect(w.find('[data-testid="konten-banner-actions-21"]').exists()).toBe(true)
    expect(w.find('[data-testid="konten-popup-actions-11"]').exists()).toBe(false)
    expect(w.text()).toContain('Banner')

    // aksi baris banner memakai service banner dengan id baris banner (bukan popup)
    await w.get('[data-testid="konten-banner-actions-21"]').trigger('click')
    await flushPromises()
    expect(gambar.popup.setStatus).not.toHaveBeenCalled()
    expect(gambar.popup.geser).not.toHaveBeenCalled()
    w.unmount()
  })

  it('berita-kategori → dibo-jenis: service jenis DIBO dipanggil, baris diganti, aksi menunjuk id jenis', async () => {
    const { router, w } = await pasang()
    await router.push('/konten/berita-kategori')
    await flushPromises()
    expect(master['berita-kategori'].list).toHaveBeenCalledTimes(1)
    expect(w.find('[data-testid="konten-berita-kategori-actions-31"]').exists()).toBe(true)

    await router.push('/konten/dibo-jenis')
    await flushPromises()

    expect(master['dibo-jenis'].list).toHaveBeenCalledTimes(1)
    expect(w.find('[data-testid="konten-berita-kategori-row-31"]').exists()).toBe(false)
    expect(w.find('[data-testid="konten-dibo-jenis-row-41"]').text()).toContain('UU')
    expect(w.find('[data-testid="konten-dibo-jenis-actions-41"]').exists()).toBe(true)
    expect(w.find('[data-testid="konten-dibo-jenis-actions-31"]').exists()).toBe(false)

    // Nonaktifkan lewat menu ⋮ baris jenis → service dibo-jenis dipanggil dengan id 41; service kategori tidak disentuh
    await w.get('[data-testid="konten-dibo-jenis-actions-41"]').trigger('click')
    await flushPromises()
    const item = document.body.querySelector('[data-action="deactivate"]') as HTMLElement | null
    expect(item).not.toBeNull()
    item?.click()
    await flushPromises()
    expect(master['dibo-jenis'].setStatus).toHaveBeenCalledWith('41', '2')
    expect(master['berita-kategori'].setStatus).not.toHaveBeenCalled()
    w.unmount()
  })

  it('kolom Tautan: tautan aman terpotong untuk url terisi, "—" bila kosong', async () => {
    const { w } = await pasang()
    const a = w.get('[data-testid="konten-popup-tautan-11"] a')
    expect(a.attributes('href')).toBe('https://example.invalid/popup-a-yang-sangat-panjang-sekali')
    expect(a.attributes('target')).toBe('_blank')
    expect(a.attributes('rel')).toBe('noopener noreferrer')
    expect(a.classes()).toContain('truncate')
    expect(w.get('[data-testid="konten-popup-tautan-12"]').text()).toBe('—')
    expect(w.get('[data-testid="konten-popup-tautan-12"]').find('a').exists()).toBe(false)
    expect(w.findAll('th').map((t) => t.text())).toContain('Tautan')
    w.unmount()
  })
})
