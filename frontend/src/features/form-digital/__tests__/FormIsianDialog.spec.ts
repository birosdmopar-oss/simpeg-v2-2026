/**
 * MAKE-083 — dialog isian form digital: tidak bisa ditutup (Esc ditahan, tanpa X), validasi wajib klien, payload jawaban
 * per tipe, 409 → selesai dengan pesan, 422 → galat di bawah pertanyaan, label dirender sebagai teks (bukan HTML).
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/formDigital.service', () => ({ formDigitalService: { isi: vi.fn() } }))

import FormIsianDialog from '../components/FormIsianDialog.vue'
import { formDigitalService } from '../services/formDigital.service'
import type { FormSaya } from '../types'

const svc = vi.mocked(formDigitalService)
const apiError = (status: number, message: string, errors: Record<string, string[]> | null = null) => ({ status, message, errors, isNetworkError: false, original: new AxiosError(message) })
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

const form: FormSaya = {
  id: 7,
  judul_form: 'Survei Kepuasan',
  keterangan: null,
  tgl_mulai: '2026-11-01',
  tgl_akhir: '2026-11-30',
  form: {
    versi: 1,
    pertanyaan: [
      { id: 'nama', tipe: 'teks', label: '<b>Nama</b>', wajib: true, maks: 255 },
      { id: 'minat', tipe: 'pilihan_ganda', label: 'Minat', wajib: false, opsi: ['A', 'B'] },
      { id: 'umur', tipe: 'angka', label: 'Umur', wajib: false },
    ],
  },
}

function isiTeks(sel: string, nilai: string): void {
  const el = q<HTMLInputElement>(sel)!
  el.value = nilai
  el.dispatchEvent(new Event('input'))
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('FormIsianDialog', () => {
  it('tidak bisa ditutup: tanpa tombol Tutup, Esc tidak menutup', async () => {
    mount(FormIsianDialog, { props: { form }, attachTo: document.body })
    await flushPromises()

    expect(q('[data-testid="form-isian-dialog"]')).not.toBeNull()
    expect(q('[aria-label="Tutup"]')).toBeNull()
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()
    expect(q('[data-testid="form-isian-dialog"]')).not.toBeNull()
  })

  it('label HTML dirender sebagai teks', async () => {
    mount(FormIsianDialog, { props: { form }, attachTo: document.body })
    await flushPromises()

    expect(q('[data-testid="form-isian-q-nama"]')!.innerHTML).toContain('&lt;b&gt;Nama&lt;/b&gt;')
    expect(q('[data-testid="form-isian-q-nama"] b')).toBeNull()
  })

  it('wajib kosong → galat lokal tanpa request; terisi → payload per tipe lalu selesai', async () => {
    svc.isi.mockResolvedValue({ id: 1 })
    const w = mount(FormIsianDialog, { props: { form }, attachTo: document.body })
    await flushPromises()

    q<HTMLButtonElement>('[data-testid="form-isian-simpan"]')!.click()
    await flushPromises()
    expect(svc.isi).not.toHaveBeenCalled()
    expect(q('[data-testid="form-isian-q-nama"]')!.textContent).toContain('Wajib diisi.')

    isiTeks('[data-testid="form-isian-q-nama"] input', ' Budi ')
    isiTeks('[data-testid="form-isian-q-umur"] input', '30')
    q<HTMLElement>('[data-testid="form-isian-q-minat"] label')!.click()
    await flushPromises()
    q<HTMLButtonElement>('[data-testid="form-isian-simpan"]')!.click()
    await flushPromises()

    expect(svc.isi).toHaveBeenCalledWith(7, { nama: 'Budi', minat: ['A'], umur: 30 }, {})
    expect(w.emitted('selesai')).toEqual([[null]])
  })

  it('409 → selesai dengan pesan sudah diisi', async () => {
    svc.isi.mockRejectedValue(apiError(409, 'Sudah diisi'))
    const w = mount(FormIsianDialog, { props: { form }, attachTo: document.body })
    await flushPromises()
    isiTeks('[data-testid="form-isian-q-nama"] input', 'Budi')
    q<HTMLButtonElement>('[data-testid="form-isian-simpan"]')!.click()
    await flushPromises()

    expect(w.emitted('selesai')).toEqual([['Form ini sudah pernah Anda isi.']])
  })

  it('422 → galat server di bawah pertanyaan, dialog tetap', async () => {
    svc.isi.mockRejectedValue(apiError(422, 'Jawaban tidak valid.', { 'jawaban.umur': ['Minimal 18.'] }))
    const w = mount(FormIsianDialog, { props: { form }, attachTo: document.body })
    await flushPromises()
    isiTeks('[data-testid="form-isian-q-nama"] input', 'Budi')
    q<HTMLButtonElement>('[data-testid="form-isian-simpan"]')!.click()
    await flushPromises()

    expect(w.emitted('selesai')).toBeUndefined()
    expect(q('[data-testid="form-isian-q-umur"]')!.textContent).toContain('Minimal 18.')
  })
})
