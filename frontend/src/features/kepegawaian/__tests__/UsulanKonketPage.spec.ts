/**
 * UsulanKonketPage (B-13, MAKE-013): mode pegawai (Ajukan, daftar sendiri) dan admin (filter rentang, antrean),
 * menu ⋮ dari `aksi` server (Edit → Setujui/Tolak → Unduh bukti → Hapus), form multipart + 422 per kolom, proses.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/konket.service', () => ({
  konketService: { list: vi.fn(), opsi: vi.fn(), detail: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn(), process: vi.fn(), berkas: vi.fn() },
}))

import { Role } from '@/features/auth/types'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { konketService } from '../services/konket.service'
import type { KonketItem, KonketOpsi } from '../types'
import UsulanKonketPage from '../views/UsulanKonketPage.vue'

import { apiError } from './fixtures'

const svc = vi.mocked(konketService)
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

const OPSI = (mode: 'pegawai' | 'admin'): KonketOpsi => ({
  mode,
  tanggal_minimal: '2026-10-01',
  hari_ini: '2026-10-13',
  kategori_dinas: 8,
  kategori_sakit: 10,
  jenis: [
    { kategori: 1, jenis_konket: 'Izin', affect_tukin: 1 },
    { kategori: 8, jenis_konket: 'Dinas Luar', affect_tukin: 2 },
  ],
})

const baris = (id: number, extra: Partial<KonketItem> = {}): KonketItem => ({
  id,
  nip: '198001012005011001',
  nama_lengkap: 'Budi',
  date_start: '2026-10-05',
  date_end: '2026-10-05',
  kategori: 1,
  jenis_konket: 'Izin',
  jenis_dinas: null,
  affect_tukin: 1,
  id_parent: null,
  alasan: 'Keperluan keluarga',
  status: 'W',
  reason_note: null,
  unit: null,
  satker: null,
  ada_berkas: true,
  aksi: { ubah: true, hapus: true, proses: false },
  ...extra,
})

async function mountPage(role: number) {
  loginAs(role as never)
  const router = createShellRouter()
  router.addRoute({ path: '/konket', name: 'konket-usulan', component: UsulanKonketPage })
  await router.push('/konket')
  await router.isReady()
  const wrapper = mount(UsulanKonketPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

function isi(sel: string, nilai: string): void {
  const el = q<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>(sel)
  if (!el) throw new Error(`isian ${sel} tidak ada`)
  el.value = nilai
  el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input'))
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  for (const fn of Object.values(svc)) fn.mockReset()
})

describe('UsulanKonketPage', () => {
  it('pegawai: daftar sendiri, Ajukan → form multipart; 422 tampil per kolom', async () => {
    svc.opsi.mockResolvedValue(OPSI('pegawai'))
    svc.list.mockResolvedValue({ mode: 'pegawai', items: [baris(1)], meta: { page: 1, per_page: 20, total: 1 }, can_create: true, rentang: null })
    svc.create.mockRejectedValueOnce(apiError(422, { date_start: ['Tanggal minimal konfirmasi ketidakhadiran adalah 1 Oktober 2026.'] }))
    svc.create.mockResolvedValueOnce(baris(2))
    const w = await mountPage(Role.PEGAWAI)

    expect(w.find('[data-testid="konket-filter"]').exists()).toBe(false)
    expect(w.get('[data-testid="konket-row-1"]').text()).toContain('Izin')
    expect(w.get('[data-testid="konket-row-1"]').text()).toContain('5 Oktober 2026')

    await w.get('[data-testid="konket-tambah"]').trigger('click')
    await flushPromises()
    isi('[data-testid="konket-date-start"] input', '2026-09-30')
    isi('[data-testid="konket-date-end"] input', '2026-09-30')
    isi('[data-testid="konket-kategori"] select', '1')
    isi('[data-testid="konket-alasan"]', 'Keperluan keluarga')
    await flushPromises()
    q('[data-testid="konket-form"]')?.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(svc.create).toHaveBeenCalledWith({ date_start: '2026-09-30', date_end: '2026-09-30', kategori: '1', alasan: 'Keperluan keluarga' }, null)
    expect(q('[data-testid="konket-dialog"]')?.textContent).toContain('Tanggal minimal konfirmasi')

    isi('[data-testid="konket-date-start"] input', '2026-10-05')
    q('[data-testid="konket-form"]')?.dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(svc.create).toHaveBeenCalledTimes(2)
    expect(w.get('[data-testid="konket-notice"]').text()).toContain('disimpan')
  })

  it('admin: filter rentang, kolom pegawai & tukin; ⋮ Edit → Setujui/Tolak → Unduh bukti → Hapus; proses tolak', async () => {
    svc.opsi.mockResolvedValue(OPSI('admin'))
    svc.list.mockResolvedValue({
      mode: 'admin',
      items: [baris(1, { aksi: { ubah: true, hapus: true, proses: true } }), baris(2, { status: 'V', ada_berkas: false, aksi: { ubah: true, hapus: true, proses: false } })],
      meta: { page: 1, per_page: 20, total: 2 },
      can_create: true,
      rentang: { ds: '2026-10-01', de: '2026-10-31' },
    })
    svc.process.mockResolvedValue(baris(1, { status: 'X' }))
    const w = await mountPage(Role.SUPER_ADMIN)

    // Rentang bawaan dari respons pertama tidak memicu muat kedua (review FE-3 MAKE-013).
    expect(svc.list).toHaveBeenCalledTimes(1)
    expect(w.find('[data-testid="konket-filter"]').exists()).toBe(true)
    expect(w.get('[data-testid="konket-row-1"]').text()).toContain('198001012005011001')
    expect((await rowMenuActions(w, 'konket-actions-1')).map((a) => a.key)).toEqual(['edit', 'setujui', 'tolak', 'berkas', 'hapus'])
    expect((await rowMenuActions(w, 'konket-actions-2')).map((a) => a.key)).toEqual(['edit', 'hapus'])

    await selectRowAction(w, 'konket-actions-1', 'tolak')
    await flushPromises()
    const alasan = q<HTMLTextAreaElement>('[data-testid="approval-reason"]') ?? q<HTMLTextAreaElement>('textarea')
    if (alasan) {
      alasan.value = 'Bukti tidak sesuai'
      alasan.dispatchEvent(new Event('input'))
    }
    await flushPromises()
    q<HTMLButtonElement>('[data-testid="approval-confirm"]')?.click()
    await flushPromises()

    expect(svc.process).toHaveBeenCalledWith(1, { aksi: 'tolak', reason_note: 'Bukti tidak sesuai' })
    expect(svc.list).toHaveBeenCalledTimes(2)
  })

  it('admin: Tambah dinas gabungan → Pengaruh Tukin bawaan master, peserta[] dari teks', async () => {
    svc.opsi.mockResolvedValue(OPSI('admin'))
    svc.list.mockResolvedValue({ mode: 'admin', items: [], meta: { page: 1, per_page: 20, total: 0 }, can_create: true, rentang: { ds: '2026-10-01', de: '2026-10-31' } })
    svc.create.mockResolvedValue(baris(3))
    const w = await mountPage(Role.SUPER_ADMIN)

    await w.get('[data-testid="konket-tambah"]').trigger('click')
    await flushPromises()
    isi('[data-testid="konket-nip"] input', '198001012005011001')
    isi('[data-testid="konket-date-start"] input', '2026-10-06')
    isi('[data-testid="konket-date-end"] input', '2026-10-07')
    isi('[data-testid="konket-kategori"] select', '8')
    await flushPromises()
    const gabungan = [...document.body.querySelectorAll<HTMLLabelElement>('[data-testid="konket-dialog"] label')].find((l) => l.textContent?.trim() === 'Gabungan')
    gabungan?.querySelector<HTMLInputElement>('input')?.click()
    await flushPromises()
    isi('[data-testid="konket-peserta"] textarea', '199001012015011001\n199101012015011002, 199201012015011003')
    isi('[data-testid="konket-alasan"]', 'Rapat koordinasi')
    await flushPromises()
    q('[data-testid="konket-form"]')?.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(svc.create).toHaveBeenCalledWith({
      nip: '198001012005011001',
      date_start: '2026-10-06',
      date_end: '2026-10-07',
      kategori: '8',
      alasan: 'Rapat koordinasi',
      affect_tukin: '2',
      jenis_dinas: '1',
      peserta: ['199001012015011001', '199101012015011002', '199201012015011003'],
    }, null)
  })

  it('Edit dinas gabungan memuat detail (peserta tanpa pemohon)', async () => {
    svc.opsi.mockResolvedValue(OPSI('pegawai'))
    const dinas = baris(5, { kategori: 8, jenis_konket: 'Dinas Luar', jenis_dinas: 1 })
    svc.list.mockResolvedValue({ mode: 'pegawai', items: [dinas], meta: { page: 1, per_page: 20, total: 1 }, can_create: true, rentang: null })
    svc.detail.mockResolvedValue({ ...dinas, peserta: [{ nip: dinas.nip, nama: 'Budi', pemohon: true }, { nip: '199001012015011001', nama: 'Ani', pemohon: false }] } as never)
    const w = await mountPage(Role.PEGAWAI)

    await selectRowAction(w, 'konket-actions-5', 'edit')
    await flushPromises()

    expect(svc.detail).toHaveBeenCalledWith(5)
    expect(q<HTMLTextAreaElement>('[data-testid="konket-peserta"] textarea')?.value).toBe('199001012015011001')
  })

  it('Unduh bukti → nama berkas dari server (Content-Disposition)', async () => {
    svc.opsi.mockResolvedValue(OPSI('pegawai'))
    svc.list.mockResolvedValue({ mode: 'pegawai', items: [baris(1)], meta: { page: 1, per_page: 20, total: 1 }, can_create: true, rentang: null })
    svc.berkas.mockResolvedValue({ blob: new Blob(['x'], { type: 'image/png' }), nama: 'bukti_konket_1.png' })
    Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:kk'), revokeObjectURL: vi.fn() })
    const nama: string[] = []
    const klik = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      nama.push(this.download)
    })
    const w = await mountPage(Role.PEGAWAI)

    await selectRowAction(w, 'konket-actions-1', 'berkas')
    await flushPromises()

    expect(svc.berkas).toHaveBeenCalledWith(1)
    expect(nama).toEqual(['bukti_konket_1.png'])
    klik.mockRestore()
  })

  it('Hapus → konfirmasi → DELETE', async () => {
    svc.opsi.mockResolvedValue(OPSI('pegawai'))
    svc.list.mockResolvedValue({ mode: 'pegawai', items: [baris(1)], meta: { page: 1, per_page: 20, total: 1 }, can_create: true, rentang: null })
    svc.remove.mockResolvedValue()
    const w = await mountPage(Role.PEGAWAI)

    await selectRowAction(w, 'konket-actions-1', 'hapus')
    await flushPromises()
    ;[...document.body.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.trim() === 'Ya, hapus')?.click()
    await flushPromises()

    expect(svc.remove).toHaveBeenCalledWith(1)
  })
})
