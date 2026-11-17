import { AxiosError } from 'axios'
import { describe, expect, it } from 'vitest'

import { galatPerKolom, pesanKolom } from '../galatKolom'

const apiError = (errors: Record<string, unknown> | null) => ({ status: 422, message: 'x', errors, isNetworkError: false, original: new AxiosError('x') })

describe('galatKolom', () => {
  it('pesanKolom: pesan pertama dari array, string tunggal utuh, selain itu kosong', () => {
    expect(pesanKolom(['Wajib diisi.', 'Lain.'])).toBe('Wajib diisi.')
    expect(pesanKolom('Pesan tunggal.')).toBe('Pesan tunggal.')
    expect(pesanKolom([])).toBe('')
    expect(pesanKolom([1])).toBe('')
    expect(pesanKolom(null)).toBe('')
  })

  it('galatPerKolom: errors amplop v2 → {kolom: pesan}, entri kosong dibuang', () => {
    expect(galatPerKolom(apiError({ subject: ['Subjek wajib diisi.'], msg: 'Pesan wajib diisi.', arsip: [] }))).toEqual({
      subject: 'Subjek wajib diisi.',
      msg: 'Pesan wajib diisi.',
    })
  })

  it('galatPerKolom: peta nama kolom BE → field form', () => {
    expect(galatPerKolom(apiError({ 'category.0': ['Kategori tidak valid.'] }), (k) => (k.startsWith('category') ? 'kategori' : k))).toEqual({
      kategori: 'Kategori tidak valid.',
    })
  })

  it('galatPerKolom: bukan ApiError atau tanpa errors → {}', () => {
    expect(galatPerKolom(new Error('x'))).toEqual({})
    expect(galatPerKolom(apiError(null))).toEqual({})
  })
})
