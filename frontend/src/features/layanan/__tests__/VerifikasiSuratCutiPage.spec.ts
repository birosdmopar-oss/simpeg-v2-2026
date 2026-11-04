/**
 * VerifikasiSuratCutiPage (PRD Fase 6 v1.0f Q6-23, AC-02.8; MAKE-065): halaman publik tanpa layout berlogin; hanya memanggil
 * `GET publik/surat-cuti/{token}`; meta robots `noindex, nofollow` selama halaman aktif; keadaan asli (ditandatangani / belum)
 * / tidak ditemukan (pesan seragam) / 429; tanpa NIP dan tanpa tautan unduh PDF.
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

import { LAYANAN_ROUTE, layananRoutes } from '../routes.layanan'
import VerifikasiSuratCutiPage from '../views/VerifikasiSuratCutiPage.vue'

const get = vi.mocked(api.get)
const stub = { render: () => h('div') }
const TOKEN = 'cd'.repeat(32)

async function mountPage(token = TOKEN) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/login', name: 'login', component: stub },
      { path: '/verify/surat-cuti/:token', name: 'verifikasi-surat-cuti', component: stub },
    ],
  })
  await router.push(`/verify/surat-cuti/${token}`)
  await router.isReady()
  const wrapper = mount(VerifikasiSuratCutiPage, { global: { plugins: [router] }, attachTo: document.body })
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

describe('VerifikasiSuratCutiPage', () => {
  it('ditandatangani → kartu "Dokumen Asli" berisi penanda tangan, tanggal, status; hanya endpoint publik', async () => {
    get.mockResolvedValue({
      data: { status_keaslian: 'asli', jenis_surat: 'Surat Cuti', jenis_cuti: 'Cuti Tahunan', penanda_tangan: 'Dr. Pejabat Uji', tanggal_tanda_tangan: '2026-11-03 10:00 WIB', status_tte: 'ditandatangani' },
    } as never)
    const wrapper = await mountPage()

    expect(get).toHaveBeenCalledWith(`/publik/surat-cuti/${TOKEN}`)
    expect(wrapper.find('[data-testid="verifikasi-asli"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="verifikasi-jenis"]').text()).toBe('Surat Cuti — Cuti Tahunan')
    expect(wrapper.find('[data-testid="verifikasi-penanda"]').text()).toBe('Dr. Pejabat Uji')
    expect(wrapper.find('[data-testid="verifikasi-status-tte"]').text()).toBe('Ditandatangani elektronik')
    expect(robots()?.getAttribute('content')).toBe('noindex, nofollow')
    expect(wrapper.html()).not.toMatch(/\d{18}/)
    expect(wrapper.find('a[href*="/pdf"]').exists()).toBe(false)
  })

  it('belum ditandatangani → status "Belum ditandatangani elektronik"', async () => {
    get.mockResolvedValue({ data: { jenis_surat: 'Surat Cuti', jenis_cuti: null, penanda_tangan: 'Pejabat Uji', tanggal_tanda_tangan: null, status_tte: 'belum' } } as never)
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="verifikasi-status-tte"]').text()).toBe('Belum ditandatangani elektronik')
  })

  it('404 → pesan seragam; 429 → pesan server; meta robots dilepas saat keluar', async () => {
    get.mockImplementationOnce(() => gagal(404))
    const wrapper = await mountPage()
    expect(wrapper.find('[data-testid="verifikasi-tidak-ditemukan"]').text()).toContain('Dokumen tidak ditemukan atau tidak valid.')
    wrapper.unmount()
    expect(robots()).toBeNull()

    get.mockImplementationOnce(() => gagal(429, 'Terlalu banyak permintaan.'))
    const kedua = await mountPage()
    expect(kedua.find('[data-testid="verifikasi-429"]').text()).toContain('Terlalu banyak permintaan.')
  })

  it('route publik /verify/surat-cuti/:token tanpa login', () => {
    const rute = layananRoutes.find((r) => r.name === LAYANAN_ROUTE.VERIFIKASI_SURAT_CUTI)
    expect(rute?.path).toBe('/verify/surat-cuti/:token')
    expect(rute?.meta?.requiresAuth).toBe(false)
  })
})
