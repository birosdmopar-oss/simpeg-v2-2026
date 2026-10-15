/**
 * LkhTab (tab LKH Detail Pegawai, MAKE-014): `GET lkh?nip=`, menu ⋮ dari `aksi` server (Edit → Unduh PDF hanya Disetujui
 * → Hapus), Hapus lewat ConfirmDialog, Tambah mengirim NIP pegawai tab (opsi `nip`).
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/lkh.service', () => ({
  lkhService: { list: vi.fn(), opsi: vi.fn(), detail: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn(), pdf: vi.fn() },
}))

import { Role } from '@/features/auth/types'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import LkhTab from '../components/LkhTab.vue'
import { lkhService } from '../services/lkh.service'
import type { LkhItem, RiwayatTabDescriptor } from '../types'

import { apiError } from './fixtures'

const svc = vi.mocked(lkhService)
const NIP = '198001012005011001'
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

const baris = (id: number, extra: Partial<LkhItem> = {}): LkhItem => ({
  id,
  nip: NIP,
  nama: 'Budi',
  unit: null,
  satker: null,
  tgl_laporan: '2026-10-12',
  nip_atasan: '197001012000011001',
  nama_atasan: 'Atasan',
  status: 0,
  catatan: null,
  jumlah: 1,
  aksi: { ubah: true, hapus: true, proses: false },
  ...extra,
})

const descriptor = (extra: Partial<RiwayatTabDescriptor> = {}): RiwayatTabDescriptor => ({
  jenis: 'lkh',
  label: 'Laporan Kerja Harian',
  can_view: true,
  can_create: true,
  can_edit: true,
  can_delete: true,
  can_process: false,
  ...extra,
})

async function mountTab(d = descriptor()) {
  loginAs(Role.SUPER_ADMIN)
  const wrapper = mount(LkhTab, { props: { nip: NIP, descriptor: d }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  for (const fn of Object.values(svc)) fn.mockReset()
  svc.list.mockResolvedValue({
    items: [baris(1), baris(2, { status: 1, aksi: { ubah: false, hapus: false, proses: false } })],
    meta: { page: 1, per_page: 20, total: 2 },
  })
  svc.opsi.mockResolvedValue({ nip: NIP, tanggal_minimal: '2026-10-08', tanggal_maksimal: '2026-10-13', atasan: { nip: '1970', nama: 'Atasan' }, punya_jabatan: true })
})

describe('LkhTab', () => {
  it('memuat LKH pegawai tab; ⋮ mengikuti aksi server (PDF hanya Disetujui)', async () => {
    const w = await mountTab()
    expect(svc.list).toHaveBeenCalledWith({ page: 1, per_page: 20, nip: NIP })
    expect(svc.opsi).toHaveBeenCalledWith(NIP)
    expect((await rowMenuActions(w, 'lkh-actions-1')).map((a) => a.key)).toEqual(['edit', 'hapus'])
    expect((await rowMenuActions(w, 'lkh-actions-2')).map((a) => a.key)).toEqual(['pdf'])
  })

  it('Hapus → ConfirmDialog → DELETE lalu muat ulang; galat tampil lalu hilang setelah muat sukses', async () => {
    svc.remove.mockRejectedValueOnce(apiError(403)).mockResolvedValueOnce(undefined)
    const w = await mountTab()

    await selectRowAction(w, 'lkh-actions-1', 'hapus')
    await flushPromises()
    ;[...document.body.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.trim() === 'Ya, hapus')?.click()
    await flushPromises()
    expect(svc.remove).toHaveBeenCalledWith(1)
    expect(w.get('[data-testid="lkh-tab-notice"]').text()).not.toBe('')

    await selectRowAction(w, 'lkh-actions-1', 'hapus')
    await flushPromises()
    ;[...document.body.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.trim() === 'Ya, hapus')?.click()
    await flushPromises()
    expect(svc.list).toHaveBeenCalledTimes(2)
    expect(w.get('[data-testid="lkh-tab-notice"]').text()).toContain('dihapus')
  })

  it('Tambah mengirim NIP pegawai tab; tanpa can_create tombol tidak ada', async () => {
    svc.create.mockResolvedValue(baris(3))
    const w = await mountTab()
    await w.get('[data-testid="lkh-tab-tambah"]').trigger('click')
    await flushPromises()
    const b = q('[data-testid="lkh-baris-0"]')
    for (const [field, nilai] of [['kegiatan', 'Rapat'], ['output', 'Notula'], ['jumlah_diselesaikan', '1'], ['jam_mulai', '08:00'], ['jam_selesai', '09:00']]) {
      const el = b?.querySelector<HTMLInputElement>(`[data-field="${field}"]`)
      if (el) {
        el.value = nilai
        el.dispatchEvent(new Event('input'))
      }
    }
    q('[data-testid="lkh-form"]')?.dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(svc.create).toHaveBeenCalledWith(expect.objectContaining({ nip: NIP, tgl_laporan: '2026-10-13' }))

    w.unmount()
    const tanpa = await mountTab(descriptor({ can_create: false }))
    expect(tanpa.find('[data-testid="lkh-tab-tambah"]').exists()).toBe(false)
  })
})
