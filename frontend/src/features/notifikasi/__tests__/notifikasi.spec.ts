/**
 * F-08 Pusat Notifikasi (MAKE-085): klien (amplop dibuka interceptor, normalisasi), composable lonceng singleton
 * (polling, null ≠ 0, galat dipertahankan), tab daftar (⋮ Buka/Tandai dibaca/Hapus, Hapus tersembunyi untuk layanan,
 * Buka menandai dibaca lalu membuka halaman sumber), tab per role, dan lonceng topbar (titik merah + klik).
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@/lib/axios', () => ({
  api: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
  isApiError: (v: unknown) => typeof v === 'object' && v !== null && 'isNetworkError' in v && 'original' in v,
}))
vi.mock('@/features/auth/services/auth.service', () => ({
  authService: { me: vi.fn(), login: vi.fn(), logout: vi.fn().mockResolvedValue(undefined), changePassword: vi.fn() },
}))

import { Role, type RoleCode } from '@/features/auth/types'
import { api } from '@/lib/axios'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import DaftarNotifikasiTab from '../components/DaftarNotifikasiTab.vue'
import { INTERVAL_POLLING_MS, resetRingkasanNotifikasi, totalRingkasan, useRingkasanNotifikasi } from '../composables/useRingkasanNotifikasi'
import { BUTUH_APPROVAL_ROLES, NOTIFIKASI_DAFTAR_ROLES } from '../index'
import { notifikasiService } from '../services/notifikasi.service'
import NotifikasiPage from '../views/NotifikasiPage.vue'

const get = vi.mocked(api.get)
const post = vi.mocked(api.post)
const del = vi.mocked(api.delete)
const stub = { render: () => h('div') }

const RINGKASAN = { notifikasi_belum_dibaca: 2, butuh_approval: 1, berita_belum_dibaca: null, tiket_belum_dibaca: null, diperbarui_at: '2026-11-10T08:00:00Z' }

const ITEM_RIWAYAT = {
  id: 'alamat:5', sumber: 'alamat', judul: 'Riwayat Alamat', keterangan: 'Riwayat Alamat disetujui.', status_label: 'Disetujui',
  reason_note: null, url_fe: '/pegawai/123?tab=alamat', dibaca: false, waktu: '2026-11-02T09:00:00Z', dapat_dihapus: true,
}
const ITEM_LAYANAN = {
  id: 'layanan:9', sumber: 'layanan', judul: 'Pengajuan cuti', keterangan: 'Cuti ditolak', status_label: 'Keputusan',
  reason_note: 'Alasan uji', url_fe: '/layanan/cuti/7', dibaca: true, waktu: '2026-11-01T08:00:00Z', dapat_dihapus: false,
}

function jawab(path: string): unknown {
  if (path === '/notifikasi/ringkasan') return RINGKASAN
  if (path === '/notifikasi') return { items: [ITEM_RIWAYAT, ITEM_LAYANAN], total: 2, belum_dibaca: 1, page: 1, per_page: 20 }
  if (path === '/notifikasi/butuh-approval') return { total: 0, grup: [], riwayat: null }
  if (path === '/notifikasi/histori-approval') return { bulan: '2026-11', pilihan_bulan: ['2026-11', '2026-10'], items: [], total: 0, page: 1, per_page: 20 }
  return {}
}

function routerUji() {
  const router = createShellRouter()
  router.addRoute({ path: '/notifikasi', name: 'notifikasi', component: stub })
  router.addRoute({ path: '/pegawai/:nip/x', component: stub })
  router.addRoute({ path: '/layanan/cuti/:id', component: stub })
  return router
}

beforeEach(() => {
  resetRingkasanNotifikasi()
  get.mockReset().mockImplementation(async (path: string) => ({ data: jawab(path) }) as never)
  post.mockReset().mockResolvedValue({ data: { diubah: 1 } } as never)
  del.mockReset().mockResolvedValue({ data: { dihapus: true } } as never)
})

afterEach(() => {
  vi.useRealTimers()
  resetRingkasanNotifikasi()
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('notifikasi.service', () => {
  it('membaca res.data langsung (amplop dibuka interceptor) dan menormalkan null sumber', async () => {
    get.mockResolvedValueOnce({ data: { notifikasi_belum_dibaca: '3', butuh_approval: 0, berita_belum_dibaca: null, diperbarui_at: 'x' } } as never)
    const r = await notifikasiService.ringkasan()
    expect(get).toHaveBeenCalledWith('/notifikasi/ringkasan')
    expect(r).toEqual({ notifikasi_belum_dibaca: 3, butuh_approval: 0, berita_belum_dibaca: null, tiket_belum_dibaca: null, diperbarui_at: 'x' })
  })

  it('baca/hapus memakai id gabungan sumber:id', async () => {
    await notifikasiService.baca({ ids: ['alamat:5'] })
    expect(post).toHaveBeenCalledWith('/notifikasi/baca', { ids: ['alamat:5'] })
    await notifikasiService.hapus('alamat:5')
    expect(del).toHaveBeenCalledWith('/notifikasi/alamat/5')
  })

  it('ekspor role untuk jalur lain ikut legacy (Q7-29)', () => {
    expect(BUTUH_APPROVAL_ROLES).toEqual([Role.SUPER_ADMIN, Role.PEGAWAI, Role.ADMIN_SATKER])
    expect(NOTIFIKASI_DAFTAR_ROLES).toEqual([Role.PEGAWAI, Role.ADMIN_SATKER, Role.PTT, Role.PPPK])
  })
})

describe('useRingkasanNotifikasi', () => {
  it('total menjumlah angka dengan null = 0', () => {
    expect(totalRingkasan(null)).toBe(0)
    expect(totalRingkasan({ ...RINGKASAN, berita_belum_dibaca: 4 })).toBe(7)
  })

  it('satu instance bersama, polling 60 detik, galat mempertahankan nilai terakhir', async () => {
    vi.useFakeTimers()
    const Pemakai = { setup: () => useRingkasanNotifikasi(), render: () => h('div') }
    const a = mount(Pemakai)
    const b = mount(Pemakai)
    await flushPromises()

    expect(get.mock.calls.filter(([p]) => p === '/notifikasi/ringkasan')).toHaveLength(1)
    expect((a.vm as unknown as { total: number }).total).toBe(3)
    expect((b.vm as unknown as { ringkasan: unknown }).ringkasan).toEqual(RINGKASAN)

    get.mockRejectedValueOnce({ isNetworkError: true, original: {}, message: 'x' })
    await vi.advanceTimersByTimeAsync(INTERVAL_POLLING_MS)
    expect(get.mock.calls.filter(([p]) => p === '/notifikasi/ringkasan')).toHaveLength(2)
    expect((a.vm as unknown as { total: number }).total).toBe(3)

    a.unmount()
    b.unmount()
    await vi.advanceTimersByTimeAsync(INTERVAL_POLLING_MS * 2)
    expect(get.mock.calls.filter(([p]) => p === '/notifikasi/ringkasan')).toHaveLength(2)
  })
})

describe('DaftarNotifikasiTab', () => {
  async function mountTab(bolehHapus = true) {
    loginAs(Role.PEGAWAI)
    const router = routerUji()
    await router.push('/notifikasi')
    const wrapper = mount(DaftarNotifikasiTab, { props: { bolehHapus }, global: { plugins: [router] }, attachTo: document.body })
    await flushPromises()
    return { wrapper, router }
  }

  it('aksi ⋮ per baris: Hapus hanya untuk riwayat, Tandai dibaca hanya yang belum dibaca', async () => {
    const { wrapper } = await mountTab()
    expect(wrapper.findAll('[data-testid^="notifikasi-row-"]')).toHaveLength(2)

    expect((await rowMenuActions(wrapper, 'notifikasi-actions-alamat-5')).map((a) => a.label)).toEqual(['Buka', 'Tandai dibaca', 'Hapus'])
    expect((await rowMenuActions(wrapper, 'notifikasi-actions-layanan-9')).map((a) => a.label)).toEqual(['Buka'])
  })

  it('role tanpa hak hapus tidak melihat Hapus', async () => {
    const { wrapper } = await mountTab(false)
    expect((await rowMenuActions(wrapper, 'notifikasi-actions-alamat-5')).map((a) => a.label)).toEqual(['Buka', 'Tandai dibaca'])
  })

  it('Buka menandai dibaca lalu membuka halaman sumber (Q7-31)', async () => {
    const { wrapper, router } = await mountTab()
    const push = vi.spyOn(router, 'push')
    await selectRowAction(wrapper, 'notifikasi-actions-alamat-5', 'buka')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/notifikasi/baca', { ids: ['alamat:5'] })
    expect(push).toHaveBeenCalledWith('/pegawai/123?tab=alamat')
  })

  it('Tandai semua dibaca memanggil semua = true', async () => {
    const { wrapper } = await mountTab()
    await wrapper.get('[data-testid="notifikasi-baca-semua"]').trigger('click')
    await flushPromises()
    expect(post).toHaveBeenCalledWith('/notifikasi/baca', { semua: true })
  })
})

describe('NotifikasiPage — tab per role (Q7-29)', () => {
  async function tabUntuk(role: RoleCode): Promise<string[]> {
    loginAs(role)
    const router = routerUji()
    await router.push('/notifikasi')
    const wrapper = mount(NotifikasiPage, { global: { plugins: [router] }, attachTo: document.body })
    await flushPromises()
    const tabs = wrapper.find('[data-testid="notifikasi-tabs"]')
    return tabs.exists() ? tabs.findAll('[role="tab"]').map((t) => t.text().replace(/\d+$/, '').trim()) : []
  }

  it('pegawai: tiga tab; PTT: hanya daftar (tanpa tab); SA: approval saja', async () => {
    expect(await tabUntuk(Role.PEGAWAI)).toEqual(['Notifikasi', 'Butuh Approval', 'Histori Approval'])
    expect(await tabUntuk(Role.PTT)).toEqual([])
    expect(await tabUntuk(Role.SUPER_ADMIN)).toEqual(['Butuh Approval', 'Histori Approval'])
  })
})

describe('Lonceng topbar (RedesignShell) — ACTIVE_PHASE < FASE_LONCENG', () => {
  it('lonceng tanpa angka, tanpa polling ringkasan, dan klik tidak berpindah sampai penutup Fase 7', async () => {
    loginAs(Role.PEGAWAI)
    const router = routerUji()
    await router.push('/')
    await router.isReady()
    const wrapper = mount(RedesignShell, { global: { plugins: [router] }, attachTo: document.body })
    await flushPromises()

    const bell = wrapper.get('[data-testid="topbar-bell"]')
    expect(bell.attributes('aria-label')).toBe('Notifikasi')
    expect(bell.find('span').exists()).toBe(false)
    expect(get.mock.calls.filter(([p]) => p === '/notifikasi/ringkasan')).toHaveLength(0)

    await bell.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.path).toBe('/')
  })
})
