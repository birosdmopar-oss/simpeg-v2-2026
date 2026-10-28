/**
 * Paket 1 M7 (MAKE-026) — CutiDetailPage: data + stepper dari `tahap[]` server, catatan approver, berkas, menu ⋮ dari
 * `aksi {}` (tanpa "Lihat detail"), proses dari detail, galat 404/403 berbahasa Indonesia, dan Hapus kembali ke daftar.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/cuti.service', () => ({
  cutiService: { list: vi.fn(), detail: vi.fn(), process: vi.fn(), tunda: vi.fn(), remove: vi.fn(), pengingat: vi.fn(), lampiran: vi.fn(), surat: vi.fn() },
}))

import { Role } from '@/features/auth/types'
import type { ApiError } from '@/lib/axios'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { cutiService } from '../services/cuti.service'
import type { CutiDetail } from '../types'
import CutiDetailPage from '../views/CutiDetailPage.vue'

const svc = vi.mocked(cutiService)
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

const apiError = (status: number): ApiError => ({ status, message: `HTTP ${status}`, errors: null, isNetworkError: false, original: new Error('x') }) as ApiError

const AKSI = { ubah: false, hapus: false, proses: false, tangguhkan: false, tunda: false, esign: false, unduh_surat: false, proses_atas_nama: false }

const detail = (aksi: Partial<typeof AKSI> = {}, extra: Partial<CutiDetail> = {}): CutiDetail => ({
  id: 12,
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
  nip_yang_menyetujui: '196501012000011001',
  nama_yang_menyetujui: 'Pejabat Uji',
  ada_lampiran: true,
  ada_surat: false,
  perlu_atas_nama: false,
  created_at: null,
  label_status: 'Menunggu Atasan',
  nada_status: 'warning',
  tahap: [
    { kode: 'diajukan', label: 'Diajukan', state: 'done', nama_petugas: 'Budi Santoso' },
    { kode: 'atasan', label: 'Atasan Langsung', state: 'current', nama_petugas: 'Atasan Uji' },
    { kode: 'pejabat', label: 'Pejabat Berwenang', state: 'todo', nama_petugas: 'Pejabat Uji' },
    { kode: 'selesai', label: 'Selesai', state: 'todo', nama_petugas: null },
  ],
  aksi: { ...AKSI, ...aksi },
  ketua_tim_detail: [{ nip: '1', nama: 'Ketua Satu' }],
  lampiran: [{ id_attachment: 5, display_name: 'bukti.pdf' }],
  surat: [],
  riwayat_approval: { keterangan_petugas_approval: null, reason_note_al: 'Silakan lanjut', reason_note_pyb: null },
  ...extra,
})

async function mountPage(role = Role.PEGAWAI) {
  loginAs(role as never)
  const router = createShellRouter()
  router.addRoute({ path: '/layanan/cuti', name: 'layanan-cuti', component: { render: () => null } })
  router.addRoute({ path: '/layanan/cuti/:id', name: 'layanan-cuti-detail', component: CutiDetailPage })
  await router.push('/layanan/cuti/12')
  await router.isReady()
  const wrapper = mount(CutiDetailPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  for (const fn of Object.values(svc)) fn.mockReset()
})

describe('CutiDetailPage', () => {
  it('menampilkan data, stepper dari tahap server, catatan approver, dan berkas', async () => {
    svc.detail.mockResolvedValue(detail())
    const { wrapper } = await mountPage()

    expect(svc.detail).toHaveBeenCalledWith(12)
    expect(wrapper.get('[data-testid="cuti-detail-data"]').text()).toContain('4 November 2026 s.d. 6 November 2026')
    expect(wrapper.get('[data-testid="cuti-detail-data"]').text()).toContain('Ketua Satu')
    expect(wrapper.get('[data-testid="cuti-stepper"]').text()).toContain('Atasan Langsung')
    expect(wrapper.get('[data-testid="cuti-stepper"]').text()).toContain('Pejabat Berwenang')
    expect(wrapper.get('[data-testid="cuti-catatan"]').text()).toContain('Silakan lanjut')
    expect(wrapper.get('[data-testid="cuti-berkas"]').text()).toContain('bukti.pdf')
    expect(wrapper.get('[data-testid="status-layanan-badge"]').text()).toBe('Menunggu Atasan')
  })

  it('menu ⋮ tanpa Lihat detail; hanya aksi yang diizinkan server', async () => {
    svc.detail.mockResolvedValue(detail({ proses: true, tangguhkan: true, hapus: true }))
    const { wrapper } = await mountPage()

    const aksi = await rowMenuActions(wrapper, 'cuti-actions-12')

    expect(aksi.map((a) => a.key)).toEqual(['setujui', 'tolak', 'tangguhkan', 'hapus'])
  })

  it('Setujui dari detail memanggil process lalu memuat ulang detail', async () => {
    svc.detail.mockResolvedValue(detail({ proses: true }))
    svc.process.mockResolvedValue(detail())
    const { wrapper } = await mountPage()

    await selectRowAction(wrapper, 'cuti-actions-12', 'setujui')
    await flushPromises()
    q<HTMLButtonElement>('[data-testid="approval-confirm"]')!.click()
    await flushPromises()

    expect(svc.process).toHaveBeenCalledWith(12, { aksi: 'setujui', reason_note: '' })
    expect(svc.detail).toHaveBeenCalledTimes(2)
    expect(q('[data-testid="cuti-detail-notice"]')?.textContent).toContain('disetujui')
  })

  it('Hapus (konfirmasi) kembali ke daftar cuti', async () => {
    svc.detail.mockResolvedValue(detail({ hapus: true }))
    svc.remove.mockResolvedValue()
    const { wrapper, router } = await mountPage()

    await selectRowAction(wrapper, 'cuti-actions-12', 'hapus')
    await flushPromises()
    ;[...document.body.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.includes('Ya, hapus'))?.click()
    await flushPromises()

    expect(svc.remove).toHaveBeenCalledWith(12)
    expect(router.currentRoute.value.name).toBe('layanan-cuti')
  })

  it('404 dan 403 menampilkan pesan baku Indonesia', async () => {
    svc.detail.mockRejectedValueOnce(apiError(404))
    const { wrapper } = await mountPage()
    expect(q('[data-testid="cuti-detail-error"]')?.textContent).toContain('Data tidak ditemukan')

    wrapper.unmount()
    svc.detail.mockRejectedValueOnce(apiError(403))
    await mountPage()
    expect(q('[data-testid="cuti-detail-error"]')?.textContent).toContain('tidak berhak')
  })
})
