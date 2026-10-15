/**
 * PenugasanPage (B-07 Plt/Plh, MAKE-012): tab Plt/Plh (?jenis=), daftar dari `penugasan/{jenis}`, menu ⋮ (Edit →
 * Unduh lampiran → Hapus) hanya untuk penulis (`can_create`), hapus lewat ConfirmDialog.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/penugasan.service', () => ({
  penugasanService: { list: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn(), lampiran: vi.fn() },
}))

import { Role } from '@/features/auth/types'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { penugasanFields } from '../penugasan.config'
import { pegawaiRoutes } from '../routes.pegawai'
import { penugasanService } from '../services/penugasan.service'
import type { PenugasanItem, PenugasanListResponse } from '../types'
import PenugasanPage from '../views/PenugasanPage.vue'

const svc = vi.mocked(penugasanService)

const baris = (id: number, extra: Partial<PenugasanItem> = {}): PenugasanItem => ({
  id,
  nip: `19800101200501100${id}`,
  nama_lengkap: `Pegawai ${id}`,
  status: 1,
  jenis_jabatan: 1,
  jenis_jabatan_koord: null,
  tgl_mulai: '2026-01-01',
  tgl_akhir: '2026-12-31',
  id_jabatan: 5,
  jabatan: 'Kepala Biro',
  id_jabatan_koord: null,
  jabatan_koord: null,
  id_unit: 1,
  unit: 'Unit A',
  id_satker: 2,
  satker: 'Satker A',
  keterangan: null,
  ada_lampiran: false,
  ...extra,
})

const respons = (canCreate: boolean, items = [baris(1, { ada_lampiran: true }), baris(2, { status: 2 })]): PenugasanListResponse => ({
  items,
  meta: { page: 1, per_page: 20, total: items.length },
  can_create: canCreate,
})

async function mountPage(role: number = Role.SUPER_ADMIN, query = '') {
  loginAs(role as never)
  const router = createShellRouter()
  router.addRoute({ path: '/penugasan', name: 'penugasan', component: PenugasanPage })
  await router.push(`/penugasan${query}`)
  await router.isReady()
  const wrapper = mount(PenugasanPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  for (const fn of Object.values(svc)) fn.mockReset()
  svc.list.mockResolvedValue(respons(true))
})

describe('PenugasanPage', () => {
  it('route terdaftar dengan role lihat 1, 3, 4, 5', () => {
    const route = pegawaiRoutes.find((r) => r.name === 'penugasan')
    expect(route?.path).toBe('/penugasan')
    expect(route?.meta?.roles).toEqual([Role.SUPER_ADMIN, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI])
  })

  it('memuat tab Plt, lalu Plh lewat ?jenis=', async () => {
    const { wrapper } = await mountPage()
    expect(svc.list).toHaveBeenLastCalledWith('plt', { page: 1, per_page: 20, search: '' })
    expect(wrapper.get('[data-testid="penugasan-row-1"]').text()).toContain('Kepala Biro')
    // CR-049 D-F13: periode tanggal Indonesia.
    expect(wrapper.get('[data-testid="penugasan-row-1"]').text()).toContain('1 Januari 2026 s.d. 31 Desember 2026')
    expect(wrapper.get('[data-testid="penugasan-row-2"]').text()).toContain('Tidak Aktif')

    await mountPage(Role.SUPER_ADMIN, '?jenis=plh')
    expect(svc.list).toHaveBeenLastCalledWith('plh', { page: 1, per_page: 20, search: '' })
  })

  it('menu ⋮ penulis: Edit → Unduh lampiran → Hapus; lampiran tersembunyi bila tidak ada', async () => {
    const { wrapper } = await mountPage()

    expect((await rowMenuActions(wrapper, 'penugasan-actions-1')).map((a) => a.key)).toEqual(['edit', 'lampiran', 'hapus'])
    expect((await rowMenuActions(wrapper, 'penugasan-actions-2')).map((a) => a.key)).toEqual(['edit', 'hapus'])
    expect(wrapper.find('[data-testid="penugasan-tambah"]').exists()).toBe(true)
  })

  it('pembaca (can_create false): tanpa Tambah, Edit, Hapus', async () => {
    svc.list.mockResolvedValue(respons(false))
    const { wrapper } = await mountPage(Role.ADMIN_VIEW_ESELON1)

    expect(wrapper.find('[data-testid="penugasan-tambah"]').exists()).toBe(false)
    expect((await rowMenuActions(wrapper, 'penugasan-actions-1')).map((a) => a.key)).toEqual(['lampiran'])
  })

  it('Hapus → konfirmasi → DELETE lalu muat ulang', async () => {
    svc.remove.mockResolvedValue()
    const { wrapper } = await mountPage()

    await selectRowAction(wrapper, 'penugasan-actions-2', 'hapus')
    await flushPromises()
    ;[...document.body.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.trim() === 'Ya, hapus')?.click()
    await flushPromises()

    expect(svc.remove).toHaveBeenCalledWith('plt', 2)
    expect(svc.list).toHaveBeenCalledTimes(2)
    expect(wrapper.get('[data-testid="penugasan-notice"]').text()).toContain('dihapus')
  })

  it('Unduh lampiran → berkas diunduh dengan nama dari server', async () => {
    svc.lampiran.mockResolvedValue({ blob: new Blob(['x'], { type: 'application/pdf' }), nama: 'lampiran_plt_1.pdf' })
    const create = vi.fn(() => 'blob:lampiran')
    Object.assign(URL, { createObjectURL: create, revokeObjectURL: vi.fn() })
    const klik = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      expect(this.download).toBe('lampiran_plt_1.pdf')
      expect(this.href).toBe('blob:lampiran')
    })
    const { wrapper } = await mountPage()

    await selectRowAction(wrapper, 'penugasan-actions-1', 'lampiran')
    await flushPromises()

    expect(svc.lampiran).toHaveBeenCalledWith('plt', 1)
    expect(klik).toHaveBeenCalledTimes(1)
    klik.mockRestore()
  })

  it('isian form mengikuti jenis (kolom *_plt / *_plh)', () => {
    expect(penugasanFields('plh').map((f) => f.name)).toContain('nip_plh')
    expect(penugasanFields('plt').map((f) => f.name)).toContain('id_satker_plt')
  })
})
