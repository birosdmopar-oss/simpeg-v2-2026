/**
 * G-10 — validasi alasan rating "Kurang Membantu": 4 alasan baku legacy atau "Lainnya" + teks bebas wajib,
 * maks 255 byte UTF-8 (sama dengan rules backend POST /faq/{id}/rate).
 */
import { describe, expect, it } from 'vitest'

import { FAQ_RATE_REASONS, FAQ_REASON_OTHER, faqReasonSchema } from '../schemas/faq.schema'

describe('faqReasonSchema', () => {
  it('4 alasan baku persis teks legacy (detail.php:142-156)', () => {
    expect(FAQ_RATE_REASONS).toEqual([
      'Langkah untuk mengatasi kendala terlalu panjang.',
      'Butuh waktu tambahan untuk mengatasi kendala.',
      'Informasinya terlalu rumit.',
      'Sudah melakukan langkah yang ada di artikel ini, tapi kendalanya tidak teratasi.',
    ])
  })

  it('alasan baku → teks alasan itu sendiri', () => {
    for (const reason of FAQ_RATE_REASONS) {
      expect(faqReasonSchema.parse({ choice: reason, other: 'diabaikan' })).toBe(reason)
    }
  })

  it('belum memilih alasan → error pada choice', () => {
    const result = faqReasonSchema.safeParse({ choice: '', other: '' })
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues[0]?.path).toEqual(['choice'])
      expect(result.error.issues[0]?.message).toBe('Pilih salah satu alasan.')
    }
  })

  it('pilihan di luar daftar ditolak', () => {
    expect(faqReasonSchema.safeParse({ choice: 'Alasan palsu', other: '' }).success).toBe(false)
  })

  it('"Lainnya" → teks bebas (di-trim) yang dikirim, bukan kata "Lainnya"', () => {
    expect(faqReasonSchema.parse({ choice: FAQ_REASON_OTHER, other: '  Gambar tidak muncul.  ' })).toBe('Gambar tidak muncul.')
  })

  it('"Lainnya" kosong / spasi saja → error pada other', () => {
    const result = faqReasonSchema.safeParse({ choice: FAQ_REASON_OTHER, other: '   ' })
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues[0]?.path).toEqual(['other'])
      expect(result.error.issues[0]?.message).toBe('Alasan lainnya wajib diisi.')
    }
  })

  it('"Lainnya" dibatasi 255 byte UTF-8, bukan 255 karakter', () => {
    expect(faqReasonSchema.safeParse({ choice: FAQ_REASON_OTHER, other: 'a'.repeat(255) }).success).toBe(true)
    // 128 karakter "é" = 256 byte: lolos maxlength input (karakter) tetapi ditolak backend → ditolak juga di sini.
    const multibyte = faqReasonSchema.safeParse({ choice: FAQ_REASON_OTHER, other: 'é'.repeat(128) })
    expect(multibyte.success).toBe(false)
    if (!multibyte.success) expect(multibyte.error.issues[0]?.path).toEqual(['other'])
  })
})
