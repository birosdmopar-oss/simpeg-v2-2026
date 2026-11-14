/**
 * urainGalatAksi (MAKE-096): pesan server dipakai untuk 403/404/422, pesan baku untuk jaringan/401/teks bawaan axios, dan
 * `basi` hanya untuk 404 atau 422 `errors.id_attachment`.
 */
import { describe, expect, it } from 'vitest'

import { urainGalatAksi } from '../arsip.galat'

function galat(status: number | null, message: string, errors: Record<string, string[]> | null = null) {
  return { status, message, errors, isNetworkError: status === null, original: {} }
}

describe('urainGalatAksi', () => {
  it('teks bawaan axios → pesan baku', () => {
    const hasil = urainGalatAksi(galat(500, 'Request failed with status code 500'))
    expect(hasil.umum).toBe('Terjadi kesalahan. Coba lagi.')
    expect(hasil.basi).toBe(false)
  })

  it('403 dengan pesan server → pesan server', () => {
    const hasil = urainGalatAksi(galat(403, 'Anda tidak berhak mengubah arsip ini (uji).'))
    expect(hasil.umum).toBe('Anda tidak berhak mengubah arsip ini (uji).')
    expect(hasil.basi).toBe(false)
  })

  it('404 dengan pesan server → pesan server dan basi', () => {
    const hasil = urainGalatAksi(galat(404, 'Arsip sudah tidak ada (uji).'))
    expect(hasil.umum).toBe('Arsip sudah tidak ada (uji).')
    expect(hasil.basi).toBe(true)
  })

  it('401 dan status null → pesan baku, tidak basi', () => {
    const a = urainGalatAksi(galat(401, 'Sesi uji.'))
    expect(a.umum).toBe('Sesi Anda berakhir. Silakan masuk kembali.')
    expect(a.basi).toBe(false)
    const b = urainGalatAksi(galat(null, 'jaringan uji'))
    expect(b.umum).toBe('Tidak dapat terhubung ke server. Periksa koneksi Anda.')
    expect(b.basi).toBe(false)
  })

  it('422 tanpa kolom → pesan server, atau pesan baku bila kosong', () => {
    expect(urainGalatAksi(galat(422, 'Isian tidak sah (uji).')).umum).toBe('Isian tidak sah (uji).')
    expect(urainGalatAksi(galat(422, '')).umum).toBe('Periksa kembali isian yang ditandai.')
  })

  it('422 kolom dikenal → masuk kolom; kolom tak dikenal → umum', () => {
    const hasil = urainGalatAksi(galat(422, 'x', { tag: ['Nama tidak valid (uji).'], lain: ['Lain (uji).'] }), ['tag'])
    expect(hasil.kolom).toEqual({ tag: 'Nama tidak valid (uji).' })
    expect(hasil.umum).toBe('Lain (uji).')
    expect(hasil.basi).toBe(false)
  })

  it('422 errors.id_attachment → basi', () => {
    const hasil = urainGalatAksi(galat(422, 'x', { id_attachment: ['Sudah terhubung (uji).'] }), ['id_attachment'])
    expect(hasil.kolom.id_attachment).toBe('Sudah terhubung (uji).')
    expect(hasil.basi).toBe(true)
  })

  it('bukan ApiError → pesan baku', () => {
    expect(urainGalatAksi(new Error('boom')).basi).toBe(false)
  })
})
