/**
 * MAKE-087 M3 — konten Jalur A di beranda saat gerbang dibuka (ACTIVE_PHASE dimock 7 = FASE_KONTEN): banner di bawah sapaan
 * untuk semua role (Q7-39), berita terbaru di kedua keluarga, popup aktif di akar halaman. Perilaku di dalam komponen diuji
 * Jalur A (`konten/__tests__/komponen-beranda.spec.ts`); fase < 7 di `HomeView.spec.ts`. M3 bagian B: kartu ringkasan SKL
 * role 1 (Q7-26) dengan respons persis kontrak F-11 (`survei-layanan/ringkasan`).
 */
import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/beranda.service', () => ({
  berandaService: { ringkasan: vi.fn(), direktur: vi.fn(), ulangTahun: vi.fn(), fotoUlangTahun: vi.fn(), ringkasanSkl: vi.fn() },
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
import type { RingkasanAdmin, RingkasanPegawai, RingkasanSkl } from '../types'
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

const skl = (jumlah: number[], rata: (number | null)[], total: { jumlah: number; rata_rata: number | null }): RingkasanSkl => ({
  periode: { dari: '2025-11-12', sampai: '2026-11-11' },
  per_jenis: ['Kenaikan Pangkat', 'Kenaikan Gaji Berkala', 'Pensiun', 'Cuti', 'Tugas Belajar'].map((jenis_layanan, i) => ({
    jenis_rwy: i + 1,
    jenis_layanan,
    jumlah: jumlah[i] ?? 0,
    rata_rata: rata[i] ?? null,
    sebaran: { '1': 0, '2': 0, '3': 0, '4': 0, '5': jumlah[i] ?? 0 },
  })),
  total,
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
  vi.mocked(berandaService.ringkasanSkl).mockReset().mockResolvedValue(skl([], [], { jumlah: 0, rata_rata: null }))
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

describe('HomeView — ringkasan SKL admin (M3 bagian B, Q7-26)', () => {
  it('role 1: rata-rata total + lima jenis urut 1→5, satu desimal koma, "—" bila belum dinilai', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(admin())
    vi.mocked(berandaService.ringkasanSkl).mockResolvedValue(skl([10, 4, 0, 6, 0], [4.5, 3, null, 4.2, null], { jumlah: 20, rata_rata: 4.1 }))
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    const kartu = wrapper.get('[data-testid="beranda-skl"]')
    expect(kartu.text()).toContain('Survei Kepuasan Layanan')
    expect(kartu.text()).toContain('Periode 12 November 2025 – 11 November 2026')
    expect(wrapper.get('[data-testid="beranda-skl-total"]').text()).toBe('4,1')
    expect(kartu.text()).toContain('20 penilaian')
    const baris = [1, 2, 3, 4, 5].map((n) => wrapper.get(`[data-testid="beranda-skl-jenis-${n}"]`).findAll('td').map((td) => td.text()))
    expect(baris).toEqual([
      ['Kenaikan Pangkat', '10', '4,5'],
      ['Kenaikan Gaji Berkala', '4', '3,0'],
      ['Pensiun', '0', '—'],
      ['Cuti', '6', '4,2'],
      ['Tugas Belajar', '0', '—'],
    ])
    expect(berandaService.ringkasanSkl).toHaveBeenCalledTimes(1)
  })

  it('total jumlah 0 → "Belum ada penilaian" tanpa tabel', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(admin())
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    expect(wrapper.get('[data-testid="beranda-skl-kosong"]').text()).toBe('Belum ada penilaian')
    expect(wrapper.find('[data-testid="beranda-skl"] table').exists()).toBe(false)
  })

  it('galat/403 → kartu diam, beranda admin tetap tampil', async () => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(admin())
    vi.mocked(berandaService.ringkasanSkl).mockRejectedValue({ status: 403, message: 'x', errors: null, isNetworkError: false, original: new AxiosError('x') })
    const wrapper = await mountAs(Role.SUPER_ADMIN)

    expect(wrapper.find('[data-testid="beranda-skl"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="beranda-admin"]').exists()).toBe(true)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it.each([Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PIMPINAN])('role %i: tanpa kartu dan tanpa request SKL', async (role) => {
    vi.mocked(berandaService.ringkasan).mockResolvedValue(admin())
    const wrapper = await mountAs(role)

    expect(wrapper.find('[data-testid="beranda-skl"]').exists()).toBe(false)
    expect(berandaService.ringkasanSkl).not.toHaveBeenCalled()
  })
})
