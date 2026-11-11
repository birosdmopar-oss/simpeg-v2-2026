/**
 * MAKE-087 M3 — konten Jalur A di beranda saat gerbang dibuka (ACTIVE_PHASE dimock 7 = FASE_KONTEN): banner di bawah sapaan
 * untuk semua role (Q7-39), berita terbaru di kedua keluarga, popup aktif di akar halaman. Perilaku di dalam komponen diuji
 * Jalur A (`konten/__tests__/komponen-beranda.spec.ts`); fase < 7 di `HomeView.spec.ts`.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/beranda.service', () => ({
  berandaService: { ringkasan: vi.fn(), direktur: vi.fn(), ulangTahun: vi.fn(), fotoUlangTahun: vi.fn() },
}))
vi.mock('@/shared/layouts/nav.config', async (asli) => ({ ...(await asli<typeof import('@/shared/layouts/nav.config')>()), ACTIVE_PHASE: 7 }))
vi.mock('@/features/konten/services/konten.service', () => ({
  kontenService: { popupAktif: vi.fn(), bannerAktif: vi.fn(), beritaTerbaru: vi.fn(), gambarBlob: vi.fn() },
}))
vi.mock('@/features/notifikasi', async (asli) => ({
  ...(await asli<typeof import('@/features/notifikasi')>()),
  // fase 7 juga membuka lonceng: Butuh Approval tanpa polling sungguhan di test ini.
  useRingkasanNotifikasi: () => ({ ringkasan: { value: null }, total: { value: 0 }, muatUlang: vi.fn() }),
}))

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role, type RoleCode, type User } from '@/features/auth/types'
import { resetSesiKonten } from '@/features/konten'
import { kontenService } from '@/features/konten/services/konten.service'
import router from '@/router'
import { resetAntreanDialog } from '@/shared/composables/antreanDialog'

import { berandaService } from '../services/beranda.service'
import type { RingkasanAdmin, RingkasanPegawai } from '../types'
import HomeView from '../views/HomeView.vue'

const admin = (): RingkasanAdmin => ({
  keluarga: 'admin',
  lingkup: { jenis: 'kementerian', id: null },
  tampilan_komposisi: 'angka',
  tanggal: '2026-11-10',
  total_aktif: 0,
  pengingat: [],
  tile: [],
})

const pegawai = (): RingkasanPegawai => ({
  keluarga: 'pegawai',
  tanggal: '2026-11-10',
  prediksi: [],
  bawahan: { atasan: false, presensi: [], ak: [] },
  kelengkapan: [],
})

const ADMIN: readonly RoleCode[] = [Role.SUPER_ADMIN, Role.ADMIN_SATKER]

async function mountAs(role: RoleCode) {
  setActivePinia(createPinia())
  useAuthStore().user = { username: 'uji', user_level: role } as User
  const wrapper = mount(HomeView, { attachTo: document.body, global: { plugins: [router], stubs: { RedesignShell: { template: '<div><slot /></div>' } } } })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  resetSesiKonten()
  vi.mocked(kontenService.popupAktif).mockReset().mockResolvedValue([])
  vi.mocked(kontenService.bannerAktif).mockReset().mockResolvedValue([])
  vi.mocked(kontenService.beritaTerbaru).mockReset().mockResolvedValue([])
  vi.mocked(kontenService.gambarBlob).mockReset().mockResolvedValue(new Blob(['x'], { type: 'image/png' }))
  vi.mocked(berandaService.ringkasan).mockReset()
  vi.mocked(berandaService.direktur).mockReset().mockResolvedValue([])
  vi.mocked(berandaService.ulangTahun).mockReset().mockResolvedValue([])
  URL.createObjectURL = vi.fn(() => 'blob:uji')
  URL.revokeObjectURL = vi.fn()
})

afterEach(() => {
  resetAntreanDialog()
  document.body.innerHTML = ''
})

describe('HomeView — konten Jalur A (M3, ACTIVE_PHASE 7)', () => {
  const gambar = (id: string) => ({ id, gambar_url: `/g/${id}.png`, url: null, keterangan: `Ket ${id}` })
  const berita = { id: '7', judul: 'Berita Uji', ringkasan: '', kategori: 'Umum', tanggal: null, foto_url: null }

  it.each([Role.SUPER_ADMIN, Role.ADMIN_SATKER, Role.PEGAWAI, Role.PTT, Role.PPPK])('role %i: banner di bawah sapaan + berita terbaru', async (role) => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(ADMIN.includes(role) ? admin() : pegawai())
    vi.mocked(kontenService.bannerAktif).mockResolvedValue([gambar('b1')])
    vi.mocked(kontenService.beritaTerbaru).mockResolvedValue([berita])
    const wrapper = await mountAs(role)

    const beranda = wrapper.get('[data-testid="beranda"]').element
    const banner = beranda.querySelector('[data-testid="konten-banner"]')
    expect(banner).not.toBeNull()
    expect(banner?.previousElementSibling?.querySelector('[data-testid="home-role"]')).not.toBeNull()
    const keluarga = wrapper.get(ADMIN.includes(role) ? '[data-testid="beranda-admin"]' : '[data-testid="beranda-pegawai"]')
    expect(keluarga.find('[data-testid="konten-berita-terbaru"]').text()).toContain('Berita Uji')
    expect(kontenService.beritaTerbaru).toHaveBeenCalledWith(5)
  })

  it('konten kosong / galat → tanpa banner, berita, popup; beranda tetap tampil', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(admin())
    vi.mocked(kontenService.bannerAktif).mockRejectedValue(new Error('403'))
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    expect(wrapper.find('[data-testid="konten-banner"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="konten-berita-terbaru"]').exists()).toBe(false)
    expect(document.body.querySelector('[data-testid="konten-popup-aktif"]')).toBeNull()
    expect(wrapper.find('[data-testid="beranda-admin"]').exists()).toBe(true)
  })

  it('popup aktif tampil di akar halaman, juga saat ringkasan galat', async () => {
    vi.mocked(berandaService.ringkasan).mockRejectedValue({ status: 500, message: 'x', errors: null, isNetworkError: false, original: new AxiosError('x') })
    vi.mocked(kontenService.popupAktif).mockResolvedValue([gambar('p1')])
    await mountAs(Role.PEGAWAI)

    expect(document.body.querySelector('[data-testid="konten-popup-aktif"]')).not.toBeNull()
    expect(kontenService.popupAktif).toHaveBeenCalledTimes(1)
  })
})
