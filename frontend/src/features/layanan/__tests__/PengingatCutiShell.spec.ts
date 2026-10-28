/**
 * Paket 10 M7 (MAKE-026) — pengingat persetujuan cuti setelah login (PRD Fase 4 §6.4.7, keputusan Horii 28-10) dipasang di
 * shell: tampil di halaman mana pun yang pertama dibuka, sekali per sesi tab, hanya role Cuti dan bukan sesi wajib ganti
 * password; Tunda menyaring baris; Proses membuka detail; Keluar mengosongkan penanda sehingga login berikutnya
 * menampilkan lagi. Service di-mock.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/cuti.service', () => ({
  cutiService: { pengingat: vi.fn(), tunda: vi.fn() },
}))
vi.mock('@/features/auth/services/auth.service', () => ({
  authService: { me: vi.fn(), login: vi.fn(), logout: vi.fn().mockResolvedValue(undefined), changePassword: vi.fn() },
}))

import { Role, type RoleCode } from '@/features/auth/types'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'

import { cutiService } from '../services/cuti.service'
import type { CutiItem } from '../types'

const svc = vi.mocked(cutiService)
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

const baris = (id: number, tunda = true): CutiItem =>
  ({
    id,
    nama: 'Budi Santoso',
    nama_jenis_cuti: 'Cuti Tahunan',
    tgl_mulai: '2026-11-04',
    tgl_akhir: '2026-11-06',
    lama_cuti: 3,
    label_status: 'Menunggu Atasan',
    nada_status: 'warning',
    tahap: [],
    aksi: { tunda },
  }) as unknown as CutiItem

async function mountShell(role: RoleCode, extra: Parameters<typeof loginAs>[1] = {}) {
  const auth = loginAs(role, extra)
  const router = createShellRouter()
  router.addRoute({ path: '/layanan/cuti/:id', name: 'layanan-cuti-detail', component: { render: () => null } })
  await router.push('/')
  await router.isReady()
  const wrapper = mount(RedesignShell, { slots: { default: '<p>beranda</p>' }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router, auth }
}

afterEach(() => {
  document.body.innerHTML = ''
  window.sessionStorage.clear()
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  window.localStorage.clear()
  for (const fn of Object.values(svc)) fn.mockReset()
  svc.pengingat.mockResolvedValue({ items: [], total: 0 })
})

describe('PengingatCutiShell', () => {
  it('tampil di halaman mana pun bila antrean tidak kosong; Tunda menghapus baris dan menutup dialog bila habis', async () => {
    svc.pengingat.mockResolvedValue({ items: [baris(5), baris(6)], total: 2 })
    svc.tunda.mockResolvedValue(baris(5))
    await mountShell(Role.PEGAWAI)

    expect(q('[data-testid="pengingat-cuti-dialog"]')).not.toBeNull()
    expect(q('[data-testid="pengingat-row-5"]')?.textContent).toContain('Budi Santoso')

    q<HTMLButtonElement>('[data-testid="pengingat-tunda-5"]')!.click()
    await flushPromises()
    expect(svc.tunda).toHaveBeenCalledWith(5)
    expect(q('[data-testid="pengingat-row-5"]')).toBeNull()
    expect(q('[data-testid="pengingat-row-6"]')).not.toBeNull()

    q<HTMLButtonElement>('[data-testid="pengingat-tunda-6"]')!.click()
    await flushPromises()
    expect(q('[data-testid="pengingat-cuti-dialog"]')).toBeNull()
  })

  it('Proses membuka detail cuti dan menutup dialog', async () => {
    svc.pengingat.mockResolvedValue({ items: [baris(5)], total: 1 })
    const { router } = await mountShell(Role.ADMIN_SATKER)

    q<HTMLButtonElement>('[data-testid="pengingat-proses-5"]')!.click()
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/layanan/cuti/5')
    expect(q('[data-testid="pengingat-cuti-dialog"]')).toBeNull()
  })

  it('tunda gagal → pesan galat tampil dan baris tetap', async () => {
    svc.pengingat.mockResolvedValue({ items: [baris(5)], total: 1 })
    svc.tunda.mockRejectedValue({ status: 422, message: 'x', errors: { status: ['Sudah lewat batas waktu tunda.'] }, isNetworkError: false, original: new Error('x') })
    await mountShell(Role.PEGAWAI)

    q<HTMLButtonElement>('[data-testid="pengingat-tunda-5"]')!.click()
    await flushPromises()
    expect(q('[data-testid="pengingat-error"]')).not.toBeNull()
    expect(q('[data-testid="pengingat-row-5"]')).not.toBeNull()
  })

  it('antrean kosong → dialog tidak muncul, tetapi tidak dimuat ulang pada pemasangan shell berikutnya (sekali per sesi)', async () => {
    const { wrapper } = await mountShell(Role.PEGAWAI)
    expect(q('[data-testid="pengingat-cuti-dialog"]')).toBeNull()
    expect(svc.pengingat).toHaveBeenCalledTimes(1)

    wrapper.unmount()
    await mountShell(Role.PEGAWAI)
    expect(svc.pengingat).toHaveBeenCalledTimes(1)
  })

  it.each([Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PIMPINAN])('role %i (bukan route cuti) tidak memanggil pengingat', async (role) => {
    await mountShell(role)
    expect(svc.pengingat).not.toHaveBeenCalled()
  })

  it('sesi wajib ganti password tidak memanggil pengingat', async () => {
    await mountShell(Role.PEGAWAI, { must_change_password: true })
    expect(svc.pengingat).not.toHaveBeenCalled()
  })

  it('gagal memuat → diam (tidak ada dialog, tidak ada galat) dan boleh dicoba lagi pada muat berikutnya', async () => {
    svc.pengingat.mockRejectedValue(new Error('jaringan'))
    const { wrapper } = await mountShell(Role.PEGAWAI)
    expect(q('[data-testid="pengingat-cuti-dialog"]')).toBeNull()

    wrapper.unmount()
    await mountShell(Role.PEGAWAI)
    expect(svc.pengingat).toHaveBeenCalledTimes(2)
  })

  it('Keluar mengosongkan penanda sehingga login berikutnya menampilkan pengingat lagi', async () => {
    svc.pengingat.mockResolvedValue({ items: [baris(5)], total: 1 })
    const { wrapper } = await mountShell(Role.PEGAWAI)
    expect(window.sessionStorage.getItem('layanan-pengingat-cuti-tampil')).toBe('1')

    wrapper.findComponent({ name: 'AppSidebar' }).vm.$emit('logout')
    await flushPromises()
    expect(window.sessionStorage.getItem('layanan-pengingat-cuti-tampil')).toBeNull()
  })
})
