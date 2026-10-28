/**
 * Paket 3 M7 (MAKE-026) — Terbitkan TTE surat cuti: item ⋮ hanya bila `aksi.esign` server (urutan AGENTS §1), dialog
 * passphrase bertipe password yang dikosongkan setelah kirim/tutup dan tidak bocor ke storage/URL, galat 422 per kolom
 * (`passphrase`/`nip`/`status`), sukses → muat ulang. Service dengan klien asli ada di cuti.service.amplop.spec.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/cuti.service', () => ({
  cutiService: {
    list: vi.fn(),
    detail: vi.fn(),
    process: vi.fn(),
    tunda: vi.fn(),
    remove: vi.fn(),
    pengingat: vi.fn(),
    lampiran: vi.fn(),
    surat: vi.fn(),
    opsi: vi.fn(),
    hitungLama: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    esign: vi.fn(),
  },
}))

import { Role } from '@/features/auth/types'
import type { ApiError } from '@/lib/axios'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import EsignDialog from '../components/EsignDialog.vue'
import { cutiService } from '../services/cuti.service'
import type { CutiItem, CutiListResponse } from '../types'
import CutiPage from '../views/CutiPage.vue'

const svc = vi.mocked(cutiService)
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)
const PASS = 'rahasia-Passphrase-123'

const apiError = (status: number, errors: Record<string, string[]> | null = null): ApiError =>
  ({ status, message: `HTTP ${status}`, errors, isNetworkError: false, original: new Error('x') }) as ApiError

const AKSI = { ubah: false, hapus: false, proses: false, tangguhkan: false, tunda: false, esign: false, unduh_surat: false, proses_atas_nama: false, tangguhkan_atas_nama: false }

const baris = (id: number, aksi: Partial<typeof AKSI> = {}): CutiItem =>
  ({
    id,
    nip: '198001012005011001',
    nama: 'Budi Santoso',
    nama_jenis_cuti: 'Cuti Tahunan',
    tgl_mulai: '2026-11-04',
    tgl_akhir: '2026-11-06',
    lama_cuti: 3,
    status: 1,
    nama_atasan_langsung: null,
    nama_yang_menyetujui: null,
    label_status: 'Disetujui',
    nada_status: 'success',
    tahap: [],
    aksi: { ...AKSI, ...aksi },
  }) as unknown as CutiItem

const daftar = (items: CutiItem[]): CutiListResponse => ({ items, total: items.length, page: 1, per_page: 20, mode: 'admin', can_create: false })

async function mountPage(role: number = Role.ADMIN_SATKER) {
  loginAs(role as never)
  const router = createShellRouter()
  router.addRoute({ path: '/layanan/cuti', name: 'layanan-cuti', component: CutiPage })
  router.addRoute({ path: '/layanan/cuti/:id', name: 'layanan-cuti-detail', component: { render: () => null } })
  await router.push('/layanan/cuti?tab=admin')
  await router.isReady()
  const wrapper = mount(CutiPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

function ketik(sel: string, nilai: string): void {
  const el = q<HTMLInputElement>(sel)
  if (!el) throw new Error(`isian ${sel} tidak ada`)
  el.value = nilai
  el.dispatchEvent(new Event('input'))
}


afterEach(() => {
  document.body.innerHTML = ''
  window.sessionStorage.clear()
  window.localStorage.clear()
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  for (const fn of Object.values(svc)) fn.mockReset()
  svc.pengingat.mockResolvedValue({ items: [], total: 0 })
  svc.list.mockImplementation(async () => daftar([]))
})

describe('EsignDialog — passphrase', () => {
  async function bukaDialog(props: Record<string, unknown> = {}) {
    const wrapper = mount(EsignDialog, { props: { open: true, subject: 'surat cuti Budi', ...props }, attachTo: document.body })
    await flushPromises()
    return wrapper
  }

  it('input bertipe password tanpa isi-otomatis', async () => {
    await bukaDialog()
    const input = q<HTMLInputElement>('[data-testid="esign-passphrase"] input')!
    expect(input.type).toBe('password')
    expect(input.autocomplete).toBe('off')
  })

  it('passphrase kosong tidak terkirim dan menampilkan pesan', async () => {
    const wrapper = await bukaDialog()
    q<HTMLButtonElement>('[data-testid="esign-confirm"]')!.click()
    await flushPromises()
    expect(wrapper.emitted('confirm')).toBeUndefined()
    expect(q('[data-testid="esign-passphrase"]')?.textContent).toContain('wajib')
  })

  it('submit mengirim passphrase apa adanya lalu mengosongkan input (tidak tersisa di DOM, storage, atau URL)', async () => {
    const wrapper = await bukaDialog()
    ketik('[data-testid="esign-passphrase"] input', PASS)
    q<HTMLButtonElement>('[data-testid="esign-confirm"]')!.click()
    await flushPromises()

    expect(wrapper.emitted('confirm')?.[0]).toEqual([PASS])
    expect(q<HTMLInputElement>('[data-testid="esign-passphrase"] input')!.value).toBe('')
    expect(document.body.innerHTML).not.toContain(PASS)
    expect(JSON.stringify({ ...window.localStorage })).not.toContain(PASS)
    expect(JSON.stringify({ ...window.sessionStorage })).not.toContain(PASS)
    expect(window.location.href).not.toContain(PASS)
  })

  it('menutup dialog mengosongkan input; dibuka lagi tetap kosong', async () => {
    const wrapper = await bukaDialog()
    ketik('[data-testid="esign-passphrase"] input', PASS)
    await wrapper.setProps({ open: false })
    await flushPromises()
    await wrapper.setProps({ open: true })
    await flushPromises()

    expect(q<HTMLInputElement>('[data-testid="esign-passphrase"] input')!.value).toBe('')
  })

  it('galat per kolom: passphrase di bawah input; nip dan status sebagai peringatan; umum', async () => {
    await bukaDialog({
      errors: { passphrase: 'Passphrase salah.', nip: 'Data pejabat belum terdaftar.', status: 'Surat sudah ber-TTE.' },
      umum: 'Gateway tidak tersedia.',
    })
    expect(q('[data-testid="esign-passphrase"]')?.textContent).toContain('Passphrase salah.')
    expect(q('[data-testid="esign-error-nip"]')?.textContent).toContain('belum terdaftar')
    expect(q('[data-testid="esign-error-status"]')?.textContent).toContain('sudah ber-TTE')
    expect(q('[data-testid="esign-error"]')?.textContent).toContain('Gateway')
  })
})

describe('CutiPage — Terbitkan TTE', () => {
  it('item hanya bila aksi.esign dan berurutan: Tunda → Terbitkan TTE → Unduh surat → Hapus (danger)', async () => {
    svc.list.mockImplementation(async () => daftar([baris(7, { esign: true, unduh_surat: true, hapus: true }), baris(8, { unduh_surat: true })]))
    const wrapper = await mountPage(Role.SUPER_ADMIN)

    expect((await rowMenuActions(wrapper, 'cuti-actions-7')).map((a) => a.key)).toEqual(['detail', 'esign', 'surat', 'hapus'])
    expect((await rowMenuActions(wrapper, 'cuti-actions-8')).map((a) => a.key)).toEqual(['detail', 'surat'])
  })

  it('alur sukses: passphrase dikirim ke service, dialog menutup, daftar dimuat ulang, passphrase tidak tersisa', async () => {
    svc.list.mockImplementation(async () => daftar([baris(7, { esign: true })]))
    svc.esign.mockResolvedValue(baris(7))
    const wrapper = await mountPage()
    const panggilanAwal = svc.list.mock.calls.length

    await selectRowAction(wrapper, 'cuti-actions-7', 'esign')
    await flushPromises()
    ketik('[data-testid="esign-passphrase"] input', PASS)
    q<HTMLButtonElement>('[data-testid="esign-confirm"]')!.click()
    await flushPromises()

    expect(svc.esign).toHaveBeenCalledWith(7, PASS)
    expect(q('[data-testid="esign-dialog"]')).toBeNull()
    expect(q('[data-testid="cuti-notice"]')?.textContent).toContain('ber-TTE')
    expect(svc.list.mock.calls.length).toBeGreaterThan(panggilanAwal)
    expect(document.body.innerHTML).not.toContain(PASS)
    expect(JSON.stringify({ ...window.sessionStorage, ...window.localStorage })).not.toContain(PASS)
  })

  it('422 passphrase/nip/status tampil per kolom; dialog tetap terbuka dan input kosong untuk diulang', async () => {
    svc.list.mockImplementation(async () => daftar([baris(7, { esign: true })]))
    svc.esign.mockRejectedValueOnce(apiError(422, { passphrase: ['Passphrase tidak sesuai.'], nip: ['Data pejabat tidak terdaftar.'], status: ['Surat sudah ber-TTE.'] }))
    const wrapper = await mountPage()

    await selectRowAction(wrapper, 'cuti-actions-7', 'esign')
    await flushPromises()
    ketik('[data-testid="esign-passphrase"] input', PASS)
    q<HTMLButtonElement>('[data-testid="esign-confirm"]')!.click()
    await flushPromises()

    expect(q('[data-testid="esign-passphrase"]')?.textContent).toContain('Passphrase tidak sesuai.')
    expect(q('[data-testid="esign-error-nip"]')?.textContent).toContain('tidak terdaftar')
    expect(q('[data-testid="esign-error-status"]')?.textContent).toContain('sudah ber-TTE')
    expect(q<HTMLInputElement>('[data-testid="esign-passphrase"] input')!.value).toBe('')
    expect(document.body.innerHTML).not.toContain(PASS)
  })

  it('403 dan galat tak dikenal → pesan baku Indonesia di dialog', async () => {
    svc.list.mockImplementation(async () => daftar([baris(7, { esign: true })]))
    svc.esign.mockRejectedValueOnce(apiError(403))
    const wrapper = await mountPage()

    await selectRowAction(wrapper, 'cuti-actions-7', 'esign')
    await flushPromises()
    ketik('[data-testid="esign-passphrase"] input', PASS)
    q<HTMLButtonElement>('[data-testid="esign-confirm"]')!.click()
    await flushPromises()

    expect(q('[data-testid="esign-error"]')?.textContent).toContain('tidak berhak')
  })
})
