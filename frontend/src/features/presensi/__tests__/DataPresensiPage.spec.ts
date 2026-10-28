/**
 * DataPresensiPage (F5-15, D-03, AC-FE.5): daftar pegawai (lingkup server) → detail per pegawai (`?nip=`) dengan badge
 * Sumber; ⋮ Edit → Hapus (`danger`, `ConfirmDialog`) dan "Tambah Presensi" HANYA bila server memberi `aksi`/`can_*`
 * (akun khusus); testid `presensi-actions-<nip>-<tgl>`. Payload memakai angka/boolean string (MySQLi).
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/axios', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  isApiError: (v: unknown) => typeof v === 'object' && v !== null && 'status' in v && 'original' in v,
}))

import { Role, type RoleCode } from '@/features/auth/types'
import { api } from '@/lib/axios'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import DataPresensiPage from '../views/DataPresensiPage.vue'

const get = vi.mocked(api.get)
const del = vi.mocked(api.delete)
const ok = <T>(data: T) => Promise.resolve({ data, headers: {} })
const gagal = (status: number, message = 'x') => Promise.reject({ status, message, errors: null, isNetworkError: false, original: {} })

const DAFTAR = (khusus: boolean) => ({
  items: [{ nip: '199001012020011001', nama: 'Budi Santoso', unit: 'Biro Uji', satker: 'Satker Uji' }],
  total: '1', page: '1', per_page: '20', can_kelola_manual: khusus ? '1' : '0',
})
const DETAIL = (khusus: boolean) => ({
  pegawai: { nip: '199001012020011001', nama: 'Budi Santoso' },
  items: [
    { nip: '199001012020011001', tgl: '2026-10-28', jam_masuk: '07:15:00', jam_pulang: null, sumber: '1', lokasi_masuk: 'Kantor Pusat', lokasi_pulang: null, aksi: { ubah: khusus, hapus: khusus } },
    { nip: '199001012020011001', tgl: '2026-10-27', jam_masuk: '07:00:00', jam_pulang: '16:00:00', sumber: '2', lokasi_masuk: null, lokasi_pulang: null, aksi: { ubah: khusus, hapus: khusus } },
    { nip: '199001012020011001', tgl: '2026-10-26', jam_masuk: '07:30:00', jam_pulang: '16:30:00', sumber: null, lokasi_masuk: null, lokasi_pulang: null, aksi: { ubah: khusus, hapus: khusus } },
  ],
  total: '3', page: '1', per_page: '100', can_create: khusus,
})

let detailParams: unknown[] = []

function server(khusus = false): void {
  get.mockImplementation(((url: string, cfg?: { params?: unknown }) => {
    if (url === '/presensi/pegawai') return ok(DAFTAR(khusus))
    if (url === '/presensi/pegawai/199001012020011001') {
      detailParams.push(cfg?.params)
      return ok(DETAIL(khusus))
    }
    if (url === '/master/unit/options') return ok([{ id: 1, nama: 'Biro Uji', parent: null }])
    return gagal(404)
  }) as never)
}

async function mountPage(path = '/presensi/data', role: RoleCode = Role.SUPER_ADMIN) {
  loginAs(role)
  const router = createShellRouter()
  router.addRoute({ path: '/presensi/data', name: 'presensi-data', component: { render: () => null } })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(DataPresensiPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  await flushPromises()
  return { wrapper, router }
}

const testid = (id: string) => document.body.querySelector<HTMLElement>(`[data-testid="${id}"]`)
const tombol = (teks: string) => Array.from(document.body.querySelectorAll<HTMLButtonElement>('button')).find((b) => b.textContent?.trim() === teks)

beforeEach(() => {
  get.mockReset()
  del.mockReset()
  detailParams = []
})
afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('DataPresensiPage', () => {
  it('daftar pegawai → ⋮ "Lihat presensi" membuka detail (?nip=) dengan badge Sumber Online/Manual/Impor', async () => {
    server(false)
    const { wrapper, router } = await mountPage()
    expect(testid('data-presensi-pegawai-199001012020011001')?.textContent).toContain('Budi Santoso')
    expect(testid('data-presensi-tambah')).toBeNull()

    await selectRowAction(wrapper, 'presensi-pegawai-actions-199001012020011001', 'lihat')
    await flushPromises()
    expect(router.currentRoute.value.query.nip).toBe('199001012020011001')

    expect(testid('data-presensi-sumber-2026-10-28')?.textContent).toContain('Online')
    expect(testid('data-presensi-sumber-2026-10-27')?.textContent).toContain('Manual')
    expect(testid('data-presensi-sumber-2026-10-26')?.textContent).toContain('Impor')
    expect(testid('data-presensi-baris-2026-10-28')?.textContent).toContain('Kantor Pusat')
  })

  it('bukan akun khusus: tanpa "Tambah Presensi" dan tanpa kolom/menu aksi baris', async () => {
    server(false)
    await mountPage('/presensi/data?nip=199001012020011001', Role.ADMIN_VIEW_ESELON1)
    expect(testid('data-presensi-tambah')).toBeNull()
    expect(document.body.querySelector('[data-testid^="presensi-actions-"]')).toBeNull()
  })

  it('akun khusus: "Tambah Presensi" + ⋮ Edit → Hapus (danger)', async () => {
    server(true)
    const { wrapper } = await mountPage('/presensi/data?nip=199001012020011001')
    expect(testid('data-presensi-tambah')).not.toBeNull()
    const aksi = await rowMenuActions(wrapper, 'presensi-actions-199001012020011001-2026-10-27')
    expect(aksi.map((a) => [a.label, a.danger])).toEqual([
      ['Edit', false],
      ['Hapus', true],
    ])
  })

  it('Hapus lewat ConfirmDialog → DELETE lalu detail dimuat ulang', async () => {
    server(true)
    del.mockImplementation(() => ok({}) as never)
    const { wrapper } = await mountPage('/presensi/data?nip=199001012020011001')
    const sebelum = detailParams.length

    await selectRowAction(wrapper, 'presensi-actions-199001012020011001-2026-10-27', 'hapus')
    expect(document.body.textContent).toContain('Hapus presensi NIP 199001012020011001 tanggal 27 Oktober 2026?')
    expect(del).not.toHaveBeenCalled()

    tombol('Ya, hapus')?.click()
    await flushPromises()
    expect(del).toHaveBeenCalledWith('/presensi/manual/199001012020011001/2026-10-27')
    expect(testid('data-presensi-notice')?.textContent).toContain('dihapus')
    expect(detailParams.length).toBe(sebelum + 1)
  })

  it('tanpa rentang → tanpa parameter tanggal (server: 100 data terbaru)', async () => {
    server(false)
    await mountPage('/presensi/data?nip=199001012020011001', Role.ADMIN_SATKER)
    expect(detailParams[0]).toEqual({ page: 1 })
  })

  it('403 pegawai di luar lingkup → pesan server, tanpa tabel data', async () => {
    get.mockImplementation(((url: string) =>
      url === '/presensi/pegawai/199001012020011001' ? gagal(403, 'Pegawai di luar lingkup Anda.') : ok([])) as never)
    await mountPage('/presensi/data?nip=199001012020011001', Role.ADMIN_SATKER)
    expect(testid('data-presensi-galat-detail')?.textContent).toContain('Pegawai di luar lingkup Anda.')
    expect(testid('data-presensi-detail-kosong')).not.toBeNull()
  })
})
