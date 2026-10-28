/**
 * Paket 9 (MAKE-026) — PetugasLayananPage: ⋮ baris memakai `aksi {}` server (Edit, Aktifkan/Nonaktifkan, Hapus; AGENTS §1,
 * dipilih lewat `data-action`), Hapus lewat ConfirmDialog memanggil service, tombol Tambah mengikuti `can_create`, dan route
 * hanya untuk role 1. Service di-mock; bentuk amplop diuji di petugasLayanan.amplop.spec.ts.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/petugasLayanan.service', () => ({
  petugasLayananService: { jenis: vi.fn(), list: vi.fn(), kandidat: vi.fn(), tambah: vi.fn(), ubah: vi.fn(), hapus: vi.fn() },
}))

import { Role } from '@/features/auth/types'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { LAYANAN_ROUTE, layananRoutes } from '../routes.layanan'
import { petugasLayananService, type PetugasLayanan } from '../services/petugasLayanan.service'
import PetugasLayananPage from '../views/PetugasLayananPage.vue'

const svc = vi.mocked(petugasLayananService)
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

const pic = (id: number, status: number, aksi: Partial<PetugasLayanan['aksi']> = {}): PetugasLayanan => ({
  id,
  id_jenis_layanan: 3,
  jenis_layanan: 'Tugas Belajar',
  nip: '198001012005011001',
  nama: 'Budi Santoso',
  jabatan: 'Kepala Bagian',
  satker: 'Satker A',
  unit: 'Biro SDM',
  status,
  label_status: status === 1 ? 'Aktif' : 'Tidak Aktif',
  aksi: { ubah: true, aktifkan: status !== 1, nonaktifkan: status === 1, hapus: true, ...aksi },
})

async function mountHalaman(items: PetugasLayanan[], canCreate = true) {
  loginAs(Role.SUPER_ADMIN as never)
  svc.jenis.mockResolvedValue([{ id_jenis_layanan: 3, jenis_layanan: 'Tugas Belajar', module_name: 'tb' }])
  svc.list.mockResolvedValue({ items, total: items.length, page: 1, per_page: 20, can_create: canCreate })
  const router = createShellRouter()
  router.addRoute({ path: '/layanan/petugas', name: 'layanan-petugas', component: PetugasLayananPage })
  router.addRoute({ path: '/layanan/cuti', name: 'layanan-cuti', component: { render: () => null } })
  await router.push('/layanan/petugas')
  await router.isReady()
  const wrapper = mount(PetugasLayananPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper }
}

afterEach(() => {
  document.body.innerHTML = ''
  window.sessionStorage.clear()
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  for (const fn of Object.values(svc)) (fn as ReturnType<typeof vi.fn>).mockReset()
})

describe('PetugasLayananPage', () => {
  it('⋮ aktif: Edit, Nonaktifkan, Hapus (Aktifkan disembunyikan) — dari aksi server', async () => {
    const { wrapper } = await mountHalaman([pic(7, 1)])
    const menu = await rowMenuActions(wrapper, 'layanan-petugas-actions-7')
    expect(menu.map((m) => m.key)).toEqual(['edit', 'nonaktifkan', 'hapus'])
    expect(menu.find((m) => m.key === 'hapus')?.danger).toBe(true)
  })

  it('⋮ nonaktif: Edit, Aktifkan, Hapus', async () => {
    const { wrapper } = await mountHalaman([pic(8, 0)])
    const menu = await rowMenuActions(wrapper, 'layanan-petugas-actions-8')
    expect(menu.map((m) => m.key)).toEqual(['edit', 'aktifkan', 'hapus'])
  })

  it('Nonaktifkan memanggil ubah dengan status 0 lalu memuat ulang', async () => {
    const { wrapper } = await mountHalaman([pic(7, 1)])
    svc.ubah.mockResolvedValue(undefined)
    await selectRowAction(wrapper, 'layanan-petugas-actions-7', 'nonaktifkan')
    await flushPromises()
    expect(svc.ubah).toHaveBeenCalledWith(7, { status: 0 })
  })

  it('Hapus lewat konfirmasi memanggil service', async () => {
    const { wrapper } = await mountHalaman([pic(7, 1)])
    svc.hapus.mockResolvedValue(undefined)
    await selectRowAction(wrapper, 'layanan-petugas-actions-7', 'hapus')
    await flushPromises()
    expect(svc.hapus).not.toHaveBeenCalled()
    const ya = [...document.body.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.includes('Ya, hapus'))
    ya?.click()
    await flushPromises()
    expect(svc.hapus).toHaveBeenCalledWith(7)
  })

  it('tombol Tambah PIC hanya bila can_create', async () => {
    const { wrapper } = await mountHalaman([pic(7, 1)], false)
    expect(wrapper.find('[data-testid="layanan-petugas-tambah"]').exists()).toBe(false)
    expect(q('[data-testid="layanan-petugas-tabel"]')).not.toBeNull()
  })
})

describe('route PIC Layanan', () => {
  it('hanya role 1 (Super Admin) yang diizinkan', () => {
    const r = layananRoutes.find((x) => x.name === LAYANAN_ROUTE.PETUGAS)
    expect(r?.meta?.roles).toEqual([Role.SUPER_ADMIN])
  })
})
