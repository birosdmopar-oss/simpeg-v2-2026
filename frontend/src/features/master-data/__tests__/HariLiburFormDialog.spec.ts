/**
 * G-08 Hari Libur (DBV-003/CR-010) — form tambah/ubah: pilihan jenis libur (aktif + jenis tersimpan yang non-aktif
 * bertanda), tanggal selesai mengikuti tanggal mulai, validasi klien, payload create/update, error 422/409 backend, dan
 * tombol Simpan terkunci selama validasi + simpan (klik beruntun tidak mengirim dua kali).
 */
import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

vi.mock('../services/hariLibur.service', () => ({
  hariLiburService: { list: vi.fn(), get: vi.fn(), create: vi.fn(), update: vi.fn(), setStatus: vi.fn(), remove: vi.fn(), jenisOptions: vi.fn() },
}))

import HariLiburFormDialog from '../components/HariLiburFormDialog.vue'
import type { HariLiburRow } from '../hariLibur.types'
import { hariLiburService } from '../services/hariLibur.service'

const legacyRow: HariLiburRow = {
  id_libur: 5,
  id_jenis_libur: null,
  jenis_libur: null,
  tgl_mulai: '2025-12-25',
  tgl_akhir: '2025-12-25',
  nama_libur: 'Hari Raya Natal',
  keterangan: 'Impor legacy',
  status: '1',
  created_at: null,
  updated_at: null,
  updated_by: null,
}

/**
 * vee-validate 4.15 men-debounce validasi skema 5 ms (`debounceAsync`): panggilan baru di dalam jendela itu membatalkan
 * timer yang tertunda dan SEMUA pemanggil menerima hasil validasi terakhir. Tanpa menunggu jendela ini, submit yang
 * validasinya belum selesai bisa ikut lolos oleh perubahan field berikutnya (update terpanggil dua kali), dan error hasil
 * validasi silent sesudah resetForm (field form ini tidak punya path state, jadi errornya tetap terisi) bisa masih menutupi
 * hint. Karena itu setiap mount/interaksi di spec ini menunggu validasi tertunda tuntas sebelum assertion.
 */
const VEE_VALIDATE_DEBOUNCE_MS = 5

async function settleValidation(): Promise<void> {
  // Timer ini dipasang sesudah timer debounce yang tertunda dan lebih lama, jadi selalu berjalan sesudahnya; validasi Zod,
  // handler submit, dan mock service setelah itu hanya microtask sehingga sudah tuntas saat timer ini jalan.
  await new Promise((resolve) => setTimeout(resolve, VEE_VALIDATE_DEBOUNCE_MS * 4))
  await flushPromises()
}

async function mountDialog(row: HariLiburRow | null) {
  const wrapper = mount(HariLiburFormDialog, { props: { open: true, row }, attachTo: document.body })
  await settleValidation()
  return wrapper
}

function field<T extends HTMLElement>(selector: string): T {
  const el = document.body.querySelector<T>(selector)
  if (!el) throw new Error(`${selector} tidak ditemukan`)
  return el
}

async function setValue(selector: string, value: string, event: 'input' | 'change' = 'input'): Promise<void> {
  const el = field<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>(selector)
  el.value = value
  el.dispatchEvent(new Event(event))
  await settleValidation()
}

async function submitForm(): Promise<void> {
  field<HTMLFormElement>('form[data-testid="hari-libur-form"]').dispatchEvent(new Event('submit', { cancelable: true }))
  await settleValidation()
}

beforeEach(() => {
  vi.mocked(hariLiburService.jenisOptions).mockReset().mockResolvedValue([
    { id: '1', nama: 'Libur Nasional', parent: null },
    { id: '2', nama: 'Cuti Bersama', parent: null },
  ])
  vi.mocked(hariLiburService.create).mockReset().mockImplementation(async (payload) => ({ ...legacyRow, id_libur: 9, ...payload }))
  vi.mocked(hariLiburService.update).mockReset().mockImplementation(async (_id, payload) => ({ ...legacyRow, ...payload }))
})

afterEach(() => {
  document.body.innerHTML = ''
})

describe('HariLiburFormDialog', () => {
  it('tambah: tanggal selesai terisi dari tanggal mulai, jumlah hari tampil, payload create lengkap', async () => {
    const wrapper = await mountDialog(null)

    expect(Array.from(field<HTMLSelectElement>('select[name="id_jenis_libur"]').options).map((o) => o.value)).toEqual(['', '1', '2'])
    expect(field<HTMLSelectElement>('select[name="status"]').value).toBe('1')

    await setValue('input[name="tgl_mulai"]', '2026-08-17')
    expect(field<HTMLInputElement>('input[name="tgl_akhir"]').value).toBe('2026-08-17')
    await setValue('input[name="tgl_akhir"]', '2026-08-18')
    expect(document.body.textContent).toContain('2 hari')
    await setValue('select[name="id_jenis_libur"]', '1', 'change')
    await setValue('input[name="nama_libur"]', '  Hari   Kemerdekaan ')
    await submitForm()

    expect(hariLiburService.create).toHaveBeenCalledTimes(1)
    expect(hariLiburService.create).toHaveBeenCalledWith({
      tgl_mulai: '2026-08-17',
      tgl_akhir: '2026-08-18',
      id_jenis_libur: '1',
      nama_libur: 'Hari Kemerdekaan',
      status: '1',
    })
    expect(wrapper.emitted('saved')).toHaveLength(1)
    wrapper.unmount()
  })

  it('tanggal mulai diketik per digit: tanggal selesai ikut tahun akhir, berhenti mengikuti setelah disentuh', async () => {
    const wrapper = await mountDialog(null)

    // Urutan event `input` Chromium saat mengetik 0 8 1 7 2 0 2 6 di input tanggal.
    for (const value of ['0002-08-17', '0020-08-17', '0202-08-17', '2026-08-17']) {
      await setValue('input[name="tgl_mulai"]', value)
      expect(field<HTMLInputElement>('input[name="tgl_akhir"]').value).toBe(value)
    }

    // Setelah tanggal selesai diubah pengguna, perubahan tanggal mulai tidak lagi menimpanya.
    await setValue('input[name="tgl_akhir"]', '2026-08-17')
    await setValue('input[name="tgl_mulai"]', '2026-08-16')
    expect(field<HTMLInputElement>('input[name="tgl_akhir"]').value).toBe('2026-08-17')
    expect(document.body.textContent).toContain('2 hari')
    expect(document.body.textContent).not.toContain('Tanggal selesai harus tanggal yang valid')
    wrapper.unmount()
  })

  it('ubah rentang yang sudah ada: mengganti tanggal mulai tidak menimpa tanggal selesai', async () => {
    const row: HariLiburRow = { ...legacyRow, id_libur: 2, id_jenis_libur: 2, tgl_mulai: '2026-03-19', tgl_akhir: '2026-03-20' }
    const wrapper = await mountDialog(row)

    await setValue('input[name="tgl_mulai"]', '2026-03-18')
    expect(field<HTMLInputElement>('input[name="tgl_akhir"]').value).toBe('2026-03-20')
    expect(document.body.textContent).toContain('3 hari')
    wrapper.unmount()
  })

  it('validasi klien: selesai sebelum mulai & jenis kosong tidak dikirim', async () => {
    const wrapper = await mountDialog(null)

    await setValue('input[name="tgl_mulai"]', '2026-08-18')
    await setValue('input[name="tgl_akhir"]', '2026-08-17')
    await setValue('input[name="nama_libur"]', 'Terbalik')
    await submitForm()

    expect(document.body.textContent).toContain('Tanggal selesai tidak boleh sebelum tanggal mulai.')
    expect(document.body.textContent).toContain('Jenis libur wajib dipilih.')
    expect(hariLiburService.create).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('422 overlap dari backend dipetakan ke field; 409 tampil sebagai pesan form', async () => {
    vi.mocked(hariLiburService.create)
      .mockRejectedValueOnce({
        status: 422,
        message: 'Validasi gagal.',
        errors: { tgl_mulai: ['Rentang tanggal bentrok dengan hari libur "Hari Buruh" (2026-05-01 s.d. 2026-05-01) (tidak aktif — aktifkan atau ubah entri tersebut).'] },
        isNetworkError: false,
        original: new AxiosError('x'),
      })
      .mockRejectedValueOnce({
        status: 409,
        message: 'Data hari libur sedang diubah pengguna lain. Coba lagi.',
        errors: null,
        isNetworkError: false,
        original: new AxiosError('x'),
      })
    const wrapper = await mountDialog(null)

    await setValue('input[name="tgl_mulai"]', '2026-05-01')
    await setValue('select[name="id_jenis_libur"]', '1', 'change')
    await setValue('input[name="nama_libur"]', 'Hari Buruh')
    await submitForm()
    expect(hariLiburService.create).toHaveBeenCalledTimes(1)
    expect(document.body.textContent).toContain('Rentang tanggal bentrok dengan hari libur "Hari Buruh"')

    await submitForm()
    expect(hariLiburService.create).toHaveBeenCalledTimes(2)
    expect(field('[role="alert"]').textContent).toContain('Data hari libur sedang diubah pengguna lain. Coba lagi.')
    expect(wrapper.emitted('saved')).toBeUndefined()
    wrapper.unmount()
  })

  it('ubah data impor tanpa jenis: jenis wajib dipilih; keterangan yang dikosongkan ikut terkirim', async () => {
    const wrapper = await mountDialog(legacyRow)

    expect(document.body.querySelector('select[name="status"]')).toBeNull()
    expect(field<HTMLInputElement>('input[name="nama_libur"]').value).toBe('Hari Raya Natal')
    await submitForm()
    expect(document.body.textContent).toContain('Jenis libur wajib dipilih.')
    expect(hariLiburService.update).not.toHaveBeenCalled()

    await setValue('select[name="id_jenis_libur"]', '1', 'change')
    await setValue('textarea[name="keterangan"]', '')
    await submitForm()
    expect(hariLiburService.update).toHaveBeenCalledTimes(1)
    expect(hariLiburService.update).toHaveBeenCalledWith('5', {
      tgl_mulai: '2025-12-25',
      tgl_akhir: '2025-12-25',
      id_jenis_libur: '1',
      nama_libur: 'Hari Raya Natal',
      keterangan: '',
    })
    wrapper.unmount()
  })

  it('klik Simpan beruntun selama validasi masih berjalan: tombol terkunci, update terkirim sekali', async () => {
    const wrapper = await mountDialog({ ...legacyRow, id_jenis_libur: 1, jenis_libur: 'Libur Nasional' })
    const button = field<HTMLButtonElement>('button[type="submit"]')
    expect(button.disabled).toBe(false)

    button.click()
    await nextTick()
    // Validasi skema klik pertama masih tertunda (debounce), tetapi tombol sudah terkunci: klik kedua diabaikan.
    expect(button.disabled).toBe(true)
    button.click()
    await settleValidation()

    expect(hariLiburService.update).toHaveBeenCalledTimes(1)
    expect(hariLiburService.update).toHaveBeenCalledWith('5', {
      tgl_mulai: '2025-12-25',
      tgl_akhir: '2025-12-25',
      id_jenis_libur: '1',
      nama_libur: 'Hari Raya Natal',
      keterangan: 'Impor legacy',
    })
    expect(wrapper.emitted('saved')).toHaveLength(1)
    expect(button.disabled).toBe(false)
    wrapper.unmount()
  })

  it('ubah: jenis tersimpan yang kini non-aktif tetap tampil bertanda', async () => {
    const row: HariLiburRow = { ...legacyRow, id_libur: 7, id_jenis_libur: 3, jenis_libur: 'Libur Daerah' }
    const wrapper = await mountDialog(row)

    const select = field<HTMLSelectElement>('select[name="id_jenis_libur"]')
    expect(select.value).toBe('3')
    expect(Array.from(select.options).map((o) => o.textContent?.trim())).toContain('Libur Daerah — tidak aktif')
    wrapper.unmount()
  })
})

describe('HariLiburFormDialog — tata letak redesign (CR-028 / ISSUE-007)', () => {
  it('form grid dua kolom di layar lebar; Keterangan dan baris tombol memakai dua kolom penuh', async () => {
    await mountDialog(null)
    const form = field<HTMLFormElement>('form[data-testid="hari-libur-form"]')
    expect(form.classList).toContain('md:grid-cols-2')
    // Tanggal Mulai & Selesai menjadi sel grid langsung (sebaris), bukan dibungkus grid terpisah.
    expect(field('input[name="tgl_mulai"]').closest('form > *')?.parentElement).toBe(form)
    expect(field('textarea[name="keterangan"]').closest('form > *')?.classList).toContain('md:col-span-2')
    expect(field('button[type="submit"]').parentElement?.classList).toContain('md:col-span-2')
  })
})
