/**
 * VerifikasiSlipPage (D-14, AC-FE.7, AC-D14.5/.6): halaman publik tanpa layout berlogin; hanya memanggil
 * `GET publik/verifikasi-slip/{token}`; meta robots `noindex, nofollow` terpasang selama halaman aktif; keadaan asli /
 * tidak ditemukan (pesan seragam) / 429; tanpa nominal & NIP.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

vi.mock('@/lib/axios', () => ({
  api: { get: vi.fn(), post: vi.fn() },
  isApiError: (v: unknown) => typeof v === 'object' && v !== null && 'status' in v && 'original' in v,
}))

import { api } from '@/lib/axios'

import { presensiRoutes } from '../routes.presensi'
import VerifikasiSlipPage from '../views/VerifikasiSlipPage.vue'

const get = vi.mocked(api.get)
const stub = { render: () => h('div') }
const TOKEN = 'ab'.repeat(32)

async function mountPage(token = TOKEN) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/login', name: 'login', component: stub },
      { path: '/verify/slip/:token', name: 'verifikasi-slip', component: stub },
    ],
  })
  await router.push(`/verify/slip/${token}`)
  await router.isReady()
  const wrapper = mount(VerifikasiSlipPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

const robots = () => document.head.querySelector('meta[name="robots"]')
const gagal = (status: number | null, message = 'x') => Promise.reject({ status, message, errors: null, isNetworkError: status === null, original: {} })

beforeEach(() => {
  get.mockReset()
})
afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('VerifikasiSlipPage', () => {
  it('token sah → kartu "Dokumen Asli" dari snapshot, hanya memanggil endpoint publik', async () => {
    get.mockImplementation(() =>
      Promise.resolve({
        data: { status_keaslian: 'asli', periode: '2026-09', label_periode: 'September 2026', satker_penerbit: 'Sekretariat Uji', waktu_terbit: '27 Oktober 2026, 09:00 WIB', nama: 'B*** S***' },
        headers: {},
      }) as never,
    )
    const wrapper = await mountPage()

    expect(get).toHaveBeenCalledTimes(1)
    expect(get).toHaveBeenCalledWith(`/publik/verifikasi-slip/${TOKEN}`)
    expect(wrapper.find('[data-testid="verifikasi-asli"]').text()).toContain('Dokumen Asli')
    expect(wrapper.find('[data-testid="verifikasi-periode"]').text()).toBe('September 2026')
    expect(wrapper.find('[data-testid="verifikasi-nama"]').text()).toBe('B*** S***')
    expect(wrapper.text()).not.toMatch(/Rp\.|NIP/)
  })

  it('404 (alasan apa pun) → pesan seragam "Dokumen tidak ditemukan atau tidak valid."', async () => {
    get.mockImplementation(() => gagal(404, 'Dokumen tidak ditemukan atau tidak valid.') as never)
    const wrapper = await mountPage('BUKAN-TOKEN')
    expect(wrapper.find('[data-testid="verifikasi-tidak-ditemukan"]').text()).toBe('Dokumen tidak ditemukan atau tidak valid.')
  })

  it('429 → pesan server "terlalu banyak permintaan"', async () => {
    get.mockImplementation(() => gagal(429, 'Terlalu banyak permintaan. Coba lagi nanti.') as never)
    const wrapper = await mountPage()
    expect(wrapper.find('[data-testid="verifikasi-429"]').text()).toContain('Terlalu banyak permintaan')
  })

  it('galat jaringan → pesan koneksi, bukan "tidak ditemukan"', async () => {
    get.mockImplementation(() => gagal(null) as never)
    const wrapper = await mountPage()
    expect(wrapper.find('[data-testid="verifikasi-galat"]').text()).toContain('Tidak dapat terhubung')
  })

  it('meta robots noindex terpasang selama halaman aktif lalu dilepas', async () => {
    get.mockImplementation(() => gagal(404) as never)
    const wrapper = await mountPage()
    expect(robots()?.getAttribute('content')).toBe('noindex, nofollow')
    wrapper.unmount()
    expect(robots()).toBeNull()
  })

  it('tombol "Masuk" satu-satunya tautan ke halaman berlogin', async () => {
    get.mockImplementation(() => gagal(404) as never)
    const wrapper = await mountPage()
    const tautan = wrapper.findAll('a')
    expect(tautan.map((a) => [a.text(), a.attributes('href')])).toEqual([['Masuk', '/login']])
  })
})

describe('routes.presensi — rute publik', () => {
  it('/verify/slip/:token terdaftar tanpa requiresAuth & tanpa guestOnly (AC-D14.6)', () => {
    const rute = presensiRoutes.find((r) => r.name === 'verifikasi-slip')
    expect(rute?.path).toBe('/verify/slip/:token')
    expect(rute?.meta).toMatchObject({ requiresAuth: false })
    expect(rute?.meta?.guestOnly).toBeUndefined()
  })

  it('selain rute publik, semua rute presensi butuh login + role', () => {
    for (const r of presensiRoutes.filter((x) => x.name !== 'verifikasi-slip')) {
      expect(r.meta?.requiresAuth).not.toBe(false)
      expect(r.meta?.roles?.length).toBeGreaterThan(0)
    }
  })
})
