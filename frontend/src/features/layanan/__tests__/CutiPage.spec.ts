/**
 * Paket 1 M7 (MAKE-026) — CutiPage: tab per role (Cuti Saya 2/6/7, Perlu Persetujuan semua, Monitoring 1/3), ⋮ dari
 * `aksi {}` server dipilih lewat `data-action` (AGENTS §1), proses Setujui/Tolak/Tangguhkan, proses atas nama (alasan
 * wajib), Tunda, Hapus, dan pengingat sekali per sesi. Service di-mock; bentuk amplop diuji di cuti.service.amplop.spec.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/cuti.service', () => ({
  cutiService: { list: vi.fn(), detail: vi.fn(), process: vi.fn(), tunda: vi.fn(), remove: vi.fn(), pengingat: vi.fn(), lampiran: vi.fn(), surat: vi.fn(), opsi: vi.fn(), hitungLama: vi.fn(), create: vi.fn(), update: vi.fn() },
}))

import { Role } from '@/features/auth/types'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import type { ApiError } from '@/lib/axios'

import { cutiService } from '../services/cuti.service'
import type { CutiItem, CutiListResponse, ModeCuti } from '../types'
import CutiPage from '../views/CutiPage.vue'

const svc = vi.mocked(cutiService)

const apiError = (status: number, errors: Record<string, string[]> | null = null): ApiError =>
  ({ status, message: `HTTP ${status}`, errors, isNetworkError: false, original: new Error('x') }) as ApiError
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

const AKSI_KOSONG = {
  ubah: false,
  hapus: false,
  proses: false,
  tangguhkan: false,
  tunda: false,
  esign: false,
  unduh_surat: false,
  proses_atas_nama: false,
  tangguhkan_atas_nama: false,
}

const baris = (id: number, aksi: Partial<typeof AKSI_KOSONG> = {}, extra: Partial<CutiItem> = {}): CutiItem => ({
  id,
  nip: '198001012005011001',
  nama: 'Budi Santoso',
  id_jenis_cuti: 1,
  nama_jenis_cuti: 'Cuti Tahunan',
  tgl_mulai: '2026-11-04',
  tgl_akhir: '2026-11-06',
  lama_cuti: 3,
  status: 0,
  keterangan: 'Keperluan keluarga besar',
  no_telp_saat_cuti: '08123456789',
  alamat_saat_cuti: 'Jl. Uji No. 1',
  nip_atasan_langsung: '197001012000011001',
  nama_atasan_langsung: 'Atasan Uji',
  nip_yang_menyetujui: null,
  nama_yang_menyetujui: null,
  ada_lampiran: false,
  ada_surat: false,
  perlu_atas_nama: false,
  created_at: null,
  label_status: 'Menunggu Atasan',
  nada_status: 'warning',
  tahap: [],
  aksi: { ...AKSI_KOSONG, ...aksi },
  ...extra,
})

const daftar = (mode: ModeCuti, items: CutiItem[], can_create = false): CutiListResponse => ({ items, total: items.length, page: 1, per_page: 20, mode, can_create })

async function mountPage(role: number, query = '') {
  loginAs(role as never)
  const router = createShellRouter()
  router.addRoute({ path: '/layanan/cuti', name: 'layanan-cuti', component: CutiPage })
  router.addRoute({ path: '/layanan/cuti/:id', name: 'layanan-cuti-detail', component: { render: () => null } })
  await router.push(`/layanan/cuti${query}`)
  await router.isReady()
  const wrapper = mount(CutiPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

function isi(sel: string, nilai: string): void {
  const el = q<HTMLTextAreaElement>(sel)
  if (!el) throw new Error(`isian ${sel} tidak ada`)
  el.value = nilai
  el.dispatchEvent(new Event('input'))
}

afterEach(() => {
  document.body.innerHTML = ''
  window.sessionStorage.clear()
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  for (const fn of Object.values(svc)) fn.mockReset()
  svc.pengingat.mockResolvedValue({ items: [], total: 0 })
  svc.list.mockImplementation(async (query) => daftar(query.mode, []))
})

describe('CutiPage — tab per role', () => {
  it('pegawai (2): Cuti Saya + Perlu Persetujuan, tanpa Monitoring', async () => {
    const { wrapper } = await mountPage(Role.PEGAWAI)
    const tabs = wrapper.findAll('[role="tab"]').map((t) => t.text())
    expect(tabs[0]).toContain('Cuti Saya')
    expect(tabs.some((t) => t.includes('Perlu Persetujuan'))).toBe(true)
    expect(tabs.some((t) => t.includes('Monitoring'))).toBe(false)
    expect(svc.list).toHaveBeenCalledWith(expect.objectContaining({ mode: 'saya' }))
  })

  it('admin satker (3): Perlu Persetujuan + Monitoring, tanpa Cuti Saya; bawaan tab pertama', async () => {
    const { wrapper } = await mountPage(Role.ADMIN_SATKER)
    const tabs = wrapper.findAll('[role="tab"]').map((t) => t.text())
    expect(tabs.some((t) => t.includes('Cuti Saya'))).toBe(false)
    expect(tabs.some((t) => t.includes('Monitoring'))).toBe(true)
    expect(svc.list).toHaveBeenCalledWith(expect.objectContaining({ mode: 'persetujuan' }))
  })

  it('badge jumlah pada Perlu Persetujuan dari total server', async () => {
    svc.list.mockImplementation(async (query) => daftar(query.mode, query.mode === 'persetujuan' ? [baris(1), baris(2)] : []))
    const { wrapper } = await mountPage(Role.PEGAWAI)
    const tab = wrapper.findAll('[role="tab"]').find((t) => t.text().includes('Perlu Persetujuan'))
    expect(tab?.text()).toContain('2')
  })
})

describe('CutiPage — menu ⋮ dari aksi server', () => {
  it('urutan AGENTS §1: Lihat detail → Setujui → Tolak → Tangguhkan → Tunda → Unduh surat → Hapus (danger)', async () => {
    svc.list.mockImplementation(async (query) =>
      daftar(query.mode, [baris(7, { proses: true, tangguhkan: true, tunda: true, unduh_surat: true, hapus: true })]),
    )
    const { wrapper } = await mountPage(Role.PEGAWAI, '?tab=persetujuan')

    const aksi = await rowMenuActions(wrapper, 'cuti-actions-7')

    expect(aksi.map((a) => a.key)).toEqual(['detail', 'setujui', 'tolak', 'tangguhkan', 'tunda', 'surat', 'hapus'])
    expect(aksi.at(-1)).toMatchObject({ key: 'hapus', danger: true })
    expect(q('[data-testid="cuti-actions-7"]')?.getAttribute('aria-label')).toBe('Aksi untuk cuti Budi Santoso 4 November 2026 s.d. 6 November 2026')
  })

  it('aksi yang tidak berlaku disembunyikan (hanya Lihat detail)', async () => {
    svc.list.mockImplementation(async (query) => daftar(query.mode, [baris(8)]))
    const { wrapper } = await mountPage(Role.PEGAWAI, '?tab=persetujuan')

    const aksi = await rowMenuActions(wrapper, 'cuti-actions-8')

    expect(aksi.map((a) => a.key)).toEqual(['detail'])
  })

  it('Lihat detail membuka route detail', async () => {
    svc.list.mockImplementation(async (query) => daftar(query.mode, [baris(9)]))
    const { wrapper, router } = await mountPage(Role.PEGAWAI, '?tab=persetujuan')

    await selectRowAction(wrapper, 'cuti-actions-9', 'detail')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('layanan-cuti-detail')
    expect(router.currentRoute.value.params.id).toBe('9')
  })
})

describe('CutiPage — proses', () => {
  beforeEach(() => {
    svc.list.mockImplementation(async (query) => daftar(query.mode, [baris(7, { proses: true, tangguhkan: true })]))
  })

  it('Tolak tanpa alasan tidak terkirim; dengan alasan memanggil process dan memuat ulang', async () => {
    svc.process.mockResolvedValue(baris(7, {}, { status: 2 }))
    const { wrapper } = await mountPage(Role.PEGAWAI, '?tab=persetujuan')

    await selectRowAction(wrapper, 'cuti-actions-7', 'tolak')
    await flushPromises()
    q<HTMLButtonElement>('[data-testid="approval-confirm"]')!.click()
    await flushPromises()
    expect(svc.process).not.toHaveBeenCalled()
    expect(q('[data-testid="approval-reason-error"]')?.textContent).toContain('wajib')

    isi('[data-testid="approval-reason"]', 'Kuota tidak cukup')
    q<HTMLButtonElement>('[data-testid="approval-confirm"]')!.click()
    await flushPromises()

    expect(svc.process).toHaveBeenCalledWith(7, { aksi: 'tolak', reason_note: 'Kuota tidak cukup' })
    expect(q('[data-testid="cuti-notice"]')?.textContent).toContain('ditolak')
    expect(svc.list).toHaveBeenCalledTimes(2)
  })

  it('Setujui mengirim aksi setujui; galat server ditampilkan di dialog', async () => {
    svc.process.mockRejectedValueOnce(apiError(422, { status: ['Tahap tidak sesuai.'] }))
    const { wrapper } = await mountPage(Role.PEGAWAI, '?tab=persetujuan')

    await selectRowAction(wrapper, 'cuti-actions-7', 'setujui')
    await flushPromises()
    q<HTMLButtonElement>('[data-testid="approval-confirm"]')!.click()
    await flushPromises()

    expect(svc.process).toHaveBeenCalledWith(7, { aksi: 'setujui', reason_note: '' })
    expect(q('[data-testid="approval-error"]')).not.toBeNull()
  })

  it('Tangguhkan memakai dialog Tangguhkan dan mengirim aksi tangguhkan', async () => {
    svc.process.mockResolvedValue(baris(7, {}, { status: 4 }))
    const { wrapper } = await mountPage(Role.PEGAWAI, '?tab=persetujuan')

    await selectRowAction(wrapper, 'cuti-actions-7', 'tangguhkan')
    await flushPromises()
    q<HTMLButtonElement>('[data-testid="tangguhkan-confirm"]')!.click()
    await flushPromises()

    expect(svc.process).toHaveBeenCalledWith(7, { aksi: 'tangguhkan', reason_note: '' })
  })

  it('proses atas nama (role 1): alasan wajib untuk Setujui, dikirim dengan atas_nama', async () => {
    svc.list.mockImplementation(async (query) => daftar(query.mode, [baris(7, { proses_atas_nama: true }, { perlu_atas_nama: true })]))
    svc.process.mockResolvedValue(baris(7, {}, { status: 1 }))
    const { wrapper } = await mountPage(Role.SUPER_ADMIN, '?tab=admin')

    await selectRowAction(wrapper, 'cuti-actions-7', 'setujui-atas-nama')
    await flushPromises()
    q<HTMLButtonElement>('[data-testid="approval-confirm"]')!.click()
    await flushPromises()
    expect(svc.process).not.toHaveBeenCalled()
    expect(q('[data-testid="approval-error"]')?.textContent).toContain('atas nama')

    isi('[data-testid="approval-reason"]', 'Atasan cuti panjang')
    q<HTMLButtonElement>('[data-testid="approval-confirm"]')!.click()
    await flushPromises()

    expect(svc.process).toHaveBeenCalledWith(7, { aksi: 'setujui', reason_note: 'Atasan cuti panjang', atas_nama: true })
  })
})

describe('CutiPage — form pengajuan', () => {
  it('tombol Ajukan Cuti hanya bila can_create dari server; membuka form', async () => {
    svc.opsi.mockResolvedValue({
      jenis_cuti: [],
      atasan_langsung: null,
      pyb: [],
      langkah_tunggal: false,
      eselon_i: false,
      saldo: null,
      tgl_akhir_maks: null,
      ketua_tim: [],
      can_create: true,
    })
    svc.list.mockImplementation(async (query) => daftar(query.mode, [], true))
    const { wrapper } = await mountPage(Role.PEGAWAI)

    await wrapper.get('[data-testid="cuti-tambah"]').trigger('click')
    await flushPromises()

    expect(q('[data-testid="cuti-form-dialog"]')?.textContent).toContain('Ajukan Cuti')
    expect(svc.opsi).toHaveBeenCalled()
  })

  it('tanpa can_create: tombol tidak ada', async () => {
    const { wrapper } = await mountPage(Role.PEGAWAI)
    expect(wrapper.find('[data-testid="cuti-tambah"]').exists()).toBe(false)
  })

  it('menu ⋮: Edit paling atas (sebelum Lihat detail) bila aksi.ubah', async () => {
    svc.list.mockImplementation(async (query) => daftar(query.mode, [baris(6, { ubah: true, hapus: true })]))
    const { wrapper } = await mountPage(Role.PEGAWAI)

    const aksi = await rowMenuActions(wrapper, 'cuti-actions-6')

    expect(aksi.map((a) => a.key)).toEqual(['edit', 'detail', 'hapus'])
  })
})

describe('CutiPage — tangguhkan atas nama', () => {
  it('item hanya muncul bila server mengizinkan (tangguhkan_atas_nama) dan urutannya sesuai AGENTS §1', async () => {
    svc.list.mockImplementation(async (query) =>
      daftar(query.mode, [baris(7, { proses_atas_nama: true, tangguhkan_atas_nama: true }), baris(8, { proses_atas_nama: true })]),
    )
    const { wrapper } = await mountPage(Role.SUPER_ADMIN, '?tab=admin')

    expect((await rowMenuActions(wrapper, 'cuti-actions-7')).map((a) => a.key)).toEqual([
      'detail',
      'setujui-atas-nama',
      'tolak-atas-nama',
      'tangguhkan-atas-nama',
    ])
    expect((await rowMenuActions(wrapper, 'cuti-actions-8')).map((a) => a.key)).toEqual(['detail', 'setujui-atas-nama', 'tolak-atas-nama'])
  })

  it('alasan wajib; payload { aksi: tangguhkan, reason_note, atas_nama: true }', async () => {
    svc.list.mockImplementation(async (query) => daftar(query.mode, [baris(7, { proses_atas_nama: true, tangguhkan_atas_nama: true })]))
    svc.process.mockResolvedValue(baris(7, {}, { status: 4 }))
    const { wrapper } = await mountPage(Role.SUPER_ADMIN, '?tab=admin')

    await selectRowAction(wrapper, 'cuti-actions-7', 'tangguhkan-atas-nama')
    await flushPromises()
    q<HTMLButtonElement>('[data-testid="tangguhkan-confirm"]')!.click()
    await flushPromises()
    expect(svc.process).not.toHaveBeenCalled()
    expect(q('[data-testid="tangguhkan-reason-error"]')?.textContent).toContain('wajib')

    isi('[data-testid="tangguhkan-reason"]', 'Menunggu pengganti atasan')
    q<HTMLButtonElement>('[data-testid="tangguhkan-confirm"]')!.click()
    await flushPromises()

    expect(svc.process).toHaveBeenCalledWith(7, { aksi: 'tangguhkan', reason_note: 'Menunggu pengganti atasan', atas_nama: true })
  })
})

describe('CutiPage — tunda dan hapus', () => {
  it('Tunda memanggil tunda dan memuat ulang', async () => {
    svc.list.mockImplementation(async (query) => daftar(query.mode, [baris(3, { proses: true, tunda: true })]))
    svc.tunda.mockResolvedValue(baris(3))
    const { wrapper } = await mountPage(Role.PEGAWAI, '?tab=persetujuan')

    await selectRowAction(wrapper, 'cuti-actions-3', 'tunda')
    await flushPromises()

    expect(svc.tunda).toHaveBeenCalledWith(3)
    expect(q('[data-testid="cuti-notice"]')?.textContent).toContain('ditunda')
  })

  it('Hapus meminta konfirmasi lalu memanggil remove', async () => {
    svc.list.mockImplementation(async (query) => daftar(query.mode, [baris(4, { hapus: true })]))
    svc.remove.mockResolvedValue()
    const { wrapper } = await mountPage(Role.PEGAWAI)

    await selectRowAction(wrapper, 'cuti-actions-4', 'hapus')
    await flushPromises()
    expect(svc.remove).not.toHaveBeenCalled()
    const konfirmasi = [...document.body.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.includes('Ya, hapus'))
    konfirmasi?.click()
    await flushPromises()

    expect(svc.remove).toHaveBeenCalledWith(4)
  })
})

describe('CutiPage — pengingat', () => {
  it('tampil bila antrean tidak kosong; Tunda menghapus baris; tidak tampil lagi pada sesi yang sama', async () => {
    svc.pengingat.mockResolvedValue({ items: [baris(5, { proses: true, tunda: true })], total: 1 })
    svc.tunda.mockResolvedValue(baris(5))
    const { wrapper } = await mountPage(Role.PEGAWAI)

    expect(q('[data-testid="pengingat-cuti-dialog"]')).not.toBeNull()
    expect(q('[data-testid="pengingat-row-5"]')?.textContent).toContain('Budi Santoso')

    q<HTMLButtonElement>('[data-testid="pengingat-tunda-5"]')!.click()
    await flushPromises()
    expect(svc.tunda).toHaveBeenCalledWith(5)
    expect(q('[data-testid="pengingat-cuti-dialog"]')).toBeNull()

    wrapper.unmount()
    svc.pengingat.mockClear()
    await mountPage(Role.PEGAWAI)
    expect(svc.pengingat).not.toHaveBeenCalled()
  })

  it('antrean kosong → dialog tidak muncul', async () => {
    await mountPage(Role.PEGAWAI)
    expect(q('[data-testid="pengingat-cuti-dialog"]')).toBeNull()
  })
})

describe('CutiPage — keadaan galat dan kosong', () => {
  it('403 dari server → pesan baku Indonesia', async () => {
    svc.list.mockRejectedValue(apiError(403))
    await mountPage(Role.PEGAWAI)
    expect(q('[data-testid="cuti-error"]')?.textContent).toContain('tidak berhak')
  })

  it('daftar kosong menampilkan keadaan kosong berbahasa Indonesia', async () => {
    await mountPage(Role.PEGAWAI)
    expect(q('[data-testid="cuti-empty"]')?.textContent).toContain('Belum ada pengajuan cuti')
  })
})
