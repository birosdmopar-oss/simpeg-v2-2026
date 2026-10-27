/**
 * VerifikasiLkhPage (B-12b, MAKE-013): tab LKH Saya (Tambah dengan baris kegiatan, ⋮ Edit → Unduh PDF → Hapus) dan
 * Verifikasi Bawahan (antrean atasan, ⋮ Verifikasi → proses revisi wajib catatan).
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/lkh.service', () => ({
  lkhService: { list: vi.fn(), verifikasi: vi.fn(), opsi: vi.fn(), detail: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn(), process: vi.fn(), pdf: vi.fn(), rekap: vi.fn(), rekapBawahan: vi.fn() },
}))

import { Role } from '@/features/auth/types'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { lkhService } from '../services/lkh.service'
import type { LkhItem } from '../types'
import VerifikasiLkhPage from '../views/VerifikasiLkhPage.vue'

const svc = vi.mocked(lkhService)
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

const baris = (id: number, extra: Partial<LkhItem> = {}): LkhItem => ({
  id,
  nip: '198001012005011001',
  nama: 'Budi',
  unit: 'Unit',
  satker: 'Satker',
  tgl_laporan: '2026-10-12',
  nip_atasan: '197001012000011001',
  nama_atasan: 'Atasan',
  status: 0,
  catatan: null,
  jumlah: 2,
  aksi: { ubah: true, hapus: true, proses: false },
  ...extra,
})

async function mountPage(query = '', role: number = Role.PTT) {
  loginAs(role as never)
  const router = createShellRouter()
  router.addRoute({ path: '/lkh/verifikasi', name: 'lkh-verifikasi', component: VerifikasiLkhPage })
  await router.push(`/lkh/verifikasi${query}`)
  await router.isReady()
  const wrapper = mount(VerifikasiLkhPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

function isi(el: Element | null, nilai: string): void {
  if (!el) throw new Error('isian tidak ada')
  ;(el as HTMLInputElement).value = nilai
  el.dispatchEvent(new Event('input'))
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  for (const fn of Object.values(svc)) fn.mockReset()
  svc.opsi.mockResolvedValue({ nip: '198001012005011001', tanggal_minimal: '2026-10-08', tanggal_maksimal: '2026-10-13', atasan: { nip: '1970', nama: 'Atasan' }, punya_jabatan: true })
  svc.list.mockResolvedValue({ items: [baris(1), baris(2, { status: 1, aksi: { ubah: false, hapus: false, proses: false } })], meta: { page: 1, per_page: 20, total: 2 } })
  svc.verifikasi.mockResolvedValue({ items: [baris(5, { aksi: { ubah: false, hapus: false, proses: true } })], meta: { page: 1, per_page: 20, total: 1 } })
})

describe('VerifikasiLkhPage', () => {
  it('LKH Saya: ⋮ sesuai aksi server; Tambah mengirim tanggal + baris kegiatan', async () => {
    svc.create.mockResolvedValue(baris(3))
    const w = await mountPage()

    expect((await rowMenuActions(w, 'lkh-actions-1')).map((a) => a.key)).toEqual(['edit', 'hapus'])
    expect((await rowMenuActions(w, 'lkh-actions-2')).map((a) => a.key)).toEqual(['pdf'])

    await w.get('[data-testid="lkh-tambah"]').trigger('click')
    await flushPromises()
    const b = q('[data-testid="lkh-baris-0"]')
    isi(b?.querySelector('[data-field="kegiatan"]') ?? null, 'Menyusun laporan')
    isi(b?.querySelector('[data-field="output"]') ?? null, 'Draf')
    isi(b?.querySelector('[data-field="jumlah_diselesaikan"]') ?? null, '1')
    isi(b?.querySelector('[data-field="jam_mulai"]') ?? null, '08:00')
    isi(b?.querySelector('[data-field="jam_selesai"]') ?? null, '09:00')
    q('[data-testid="lkh-form"]')?.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(svc.create).toHaveBeenCalledWith({
      tgl_laporan: '2026-10-13',
      nip: '198001012005011001',
      kegiatan: [{ kegiatan: 'Menyusun laporan', output: 'Draf', jumlah_diselesaikan: '1', jam_mulai: '08:00', jam_selesai: '09:00' }],
    })
    expect(w.get('[data-testid="lkh-notice"]').text()).toContain('disimpan')
    expect(q('[data-testid="lkh-nip-atasan"]')).toBeNull()
  })

  it('Super Admin: NIP atasan opsional ikut dikirim; Edit memuat detail kegiatan', async () => {
    svc.create.mockResolvedValue(baris(3))
    svc.update.mockResolvedValue(baris(1))
    svc.detail.mockResolvedValue({ ...baris(1), kegiatan: [{ kegiatan: 'Rapat', output: 'Notula', jumlah_diselesaikan: '1', jam_mulai: '08:00', jam_selesai: '09:00' }] } as never)
    const w = await mountPage('', Role.SUPER_ADMIN)

    await selectRowAction(w, 'lkh-actions-1', 'edit')
    await flushPromises()
    expect(svc.detail).toHaveBeenCalledWith(1)
    expect(q<HTMLTextAreaElement>('[data-testid="lkh-baris-0"] [data-field="kegiatan"]')?.value).toBe('Rapat')
    isi(q('[data-testid="lkh-nip-atasan"] input'), '197501012000011001')
    q('[data-testid="lkh-form"]')?.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(svc.update).toHaveBeenCalledWith(1, {
      tgl_laporan: '2026-10-12',
      kegiatan: [{ kegiatan: 'Rapat', output: 'Notula', jumlah_diselesaikan: '1', jam_mulai: '08:00', jam_selesai: '09:00' }],
      nip_atasan: '197501012000011001',
    })
  })

  it('Unduh PDF (hanya Disetujui) → nama berkas dari server', async () => {
    svc.pdf.mockResolvedValue({ blob: new Blob(['%PDF'], { type: 'application/pdf' }), nama: 'lkh_198001012005011001_20261012.pdf' })
    Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:lkh'), revokeObjectURL: vi.fn() })
    const nama: string[] = []
    const klik = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      nama.push(this.download)
    })
    const w = await mountPage()

    await selectRowAction(w, 'lkh-actions-2', 'pdf')
    await flushPromises()

    expect(svc.pdf).toHaveBeenCalledWith(2)
    expect(nama).toEqual(['lkh_198001012005011001_20261012.pdf'])
    klik.mockRestore()
  })

  it('Verifikasi Bawahan: antrean atasan; revisi tanpa catatan ditolak di form, lalu terkirim', async () => {
    svc.process.mockResolvedValue(baris(5, { status: 3 }))
    const w = await mountPage('?tab=verifikasi')

    expect(svc.verifikasi).toHaveBeenCalledWith({ page: 1, per_page: 20, status: '0' })
    expect((await rowMenuActions(w, 'lkh-actions-5')).map((a) => a.key)).toEqual(['verifikasi'])

    await selectRowAction(w, 'lkh-actions-5', 'verifikasi')
    await flushPromises()
    const revisi = [...document.body.querySelectorAll<HTMLInputElement>('input[type="radio"]')].find((r) => r.value === 'revisi')
    revisi?.click()
    revisi?.dispatchEvent(new Event('change'))
    await flushPromises()
    q<HTMLButtonElement>('[data-testid="lkh-proses-kirim"]')?.click()
    await flushPromises()
    expect(q('[data-testid="lkh-proses-error"]')?.textContent).toContain('Catatan wajib')
    // CR-049 D-F11: galat di bawah textarea, bukan banner; textarea aria-invalid + aria-describedby.
    expect(q('[data-testid="lkh-catatan"]')?.getAttribute('aria-invalid')).toBe('true')
    expect(q('[data-testid="lkh-proses-error"]')?.getAttribute('role')).toBe('alert')
    expect(q('[data-testid="lkh-catatan"]')?.getAttribute('aria-describedby')).toBe('lkh-catatan-galat')
    expect(q('[data-testid="lkh-proses-galat-umum"]')).toBeNull()
    expect(svc.process).not.toHaveBeenCalled()

    isi(q('[data-testid="lkh-catatan"]'), 'Rinci output')
    q<HTMLButtonElement>('[data-testid="lkh-proses-kirim"]')?.click()
    await flushPromises()
    expect(svc.process).toHaveBeenCalledWith(5, 'revisi', 'Rinci output')
  })

  it('Rekap Bawahan (MAKE-014): bulan bawaan dari server, jumlah per status, ⋮ Lihat LKH → daftar bawahan', async () => {
    svc.rekap.mockResolvedValue({
      bulan: '2026-10', ds: '2026-10-01', de: '2026-10-13',
      items: [{ nip: '199001012015011001', nama: 'Ani', jabatan: 'Analis', satker: 'Satker', menunggu: 1, disetujui: 2, ditolak: 0, revisi: 1, total: 4 }],
    })
    svc.rekapBawahan.mockResolvedValue({ bulan: '2026-10', pegawai: { nip: '199001012015011001', nama: 'Ani', jabatan: 'Analis', satker: 'Satker' }, items: [baris(9, { status: 1 })] })
    const w = await mountPage('?tab=rekap')

    expect(svc.rekap).toHaveBeenCalledTimes(1)
    expect(svc.list).not.toHaveBeenCalled()
    expect(w.get('[data-testid="lkh-rekap-row-199001012015011001"]').text()).toContain('Ani')
    expect(q<HTMLInputElement>('[data-testid="lkh-rekap-bulan"] input')?.value).toBe('10/2026')

    await selectRowAction(w, 'lkh-rekap-actions-199001012015011001', 'lihat')
    await flushPromises()
    expect(svc.rekapBawahan).toHaveBeenCalledWith('199001012015011001', '2026-10')
    expect(w.get('[data-testid="lkh-rekap-detail"]').text()).toContain('Disetujui')

    await w.get('[data-testid="lkh-rekap-bulan"] input').setValue('2026-09')
    await flushPromises()
    expect(svc.rekap).toHaveBeenLastCalledWith('2026-09')
  })
})
