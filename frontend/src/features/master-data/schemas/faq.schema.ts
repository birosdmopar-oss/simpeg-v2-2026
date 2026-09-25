/**
 * G-10 — validasi alasan rating "Kurang Membantu" (DBV-002/CR-003 §6). Mengikuti rules backend
 * POST /faq/{id}/rate: reason wajib & tidak kosong untuk rate 2, maks 255 byte (TINYTEXT, dihitung byte UTF-8).
 */
import { z } from 'zod'

import { utf8ByteLength } from '@/shared/utils/byteLength'

/** 4 alasan baku — teks persis legacy (application/views/hr/faq/detail.php:142-156). */
export const FAQ_RATE_REASONS = [
  'Langkah untuk mengatasi kendala terlalu panjang.',
  'Butuh waktu tambahan untuk mengatasi kendala.',
  'Informasinya terlalu rumit.',
  'Sudah melakukan langkah yang ada di artikel ini, tapi kendalanya tidak teratasi.',
] as const

/** Pilihan "Lainnya": yang dikirim adalah teks bebas isian pegawai, bukan kata "Lainnya". */
export const FAQ_REASON_OTHER = 'Lainnya'

export const FAQ_REASON_MAX_BYTES = 255

const REASONS: readonly string[] = FAQ_RATE_REASONS

/** `{ choice, other }` dari form → string alasan yang dikirim ke backend. */
export const faqReasonSchema = z
  .object({ choice: z.string(), other: z.string() })
  .superRefine((value, ctx) => {
    if (value.choice === '') {
      ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['choice'], message: 'Pilih salah satu alasan.' })
      return
    }
    if (value.choice !== FAQ_REASON_OTHER) {
      if (!REASONS.includes(value.choice)) {
        ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['choice'], message: 'Alasan tidak valid.' })
      }
      return
    }
    const text = value.other.trim()
    if (text === '') {
      ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['other'], message: 'Alasan lainnya wajib diisi.' })
    } else if (utf8ByteLength(text) > FAQ_REASON_MAX_BYTES) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['other'],
        message: `Alasan maksimal ${FAQ_REASON_MAX_BYTES} byte (huruf beraksen/emoji dihitung lebih dari 1 byte).`,
      })
    }
  })
  .transform((value) => (value.choice === FAQ_REASON_OTHER ? value.other.trim() : value.choice))

export type FaqReasonInput = z.input<typeof faqReasonSchema>
