/**
 * Skema Zod respons API chatbot (PRD Fase 8 v1.1 §6.5). Service mem-parse setiap respons di sini, sehingga penyesuaian
 * kontrak MAKE-094 cukup di berkas ini. Angka/boolean dari PHP bisa datang sebagai string ("1") → dikoersi.
 */
import { z } from 'zod'

import type { NilaiFeedback, StatusChatbot } from './types'

const id = z.coerce.number().int()
const angka = z.coerce.number()
const teks = z.string()

/** 1/2/10 dari angka atau string; nilai lain dianggap Aktif agar baris tetap tampil (badge apa adanya di BE). */
const status = z.union([z.number(), z.string()]).transform((v): StatusChatbot => {
  const n = Number(v)
  return n === 2 ? 2 : n === 10 ? 10 : 1
})

const bendera = z.union([z.boolean(), z.number(), z.string()]).transform((v) => v === true || Number(v) === 1)

const feedback = z
  .union([z.number(), z.string(), z.null()])
  .optional()
  .transform((v): NilaiFeedback | null => (v === 1 || v === '1' ? 1 : v === -1 || v === '-1' ? -1 : null))

export function daftar<T extends z.ZodTypeAny>(item: T) {
  return z.object({
    items: z.array(item),
    total: angka,
    page: angka,
    per_page: angka,
  })
}

// --- widget -----------------------------------------------------------------------------------------------------------

export const topikSchema = z.object({ id, judul: teks })

export const pertanyaanSchema = z.object({ id_qna: id, pertanyaan: teks })

export const balasanSchema = z.object({
  balasan: teks,
  cocok: bendera,
  skor: angka,
  id_log: id.nullable().optional().transform((v) => v ?? null),
  bisa_feedback: bendera,
  token_feedback: teks.nullable().optional().transform((v) => v ?? null),
  saran: z.array(pertanyaanSchema).optional().transform((v) => v ?? []),
})

// --- admin ------------------------------------------------------------------------------------------------------------

export const kategoriSchema = z.object({ id, judul: teks, tampil_bubble: bendera, status })

export const tagSchema = z.object({ id, judul: teks, status })

const rujukanSchema = z.object({ id, judul: teks, status })

export const qnaSchema = z.object({
  id,
  pertanyaan: teks,
  is_fallback_option: bendera,
  status,
  kategori: rujukanSchema.nullable(),
  tag: z.array(rujukanSchema),
})

export const qnaDetailSchema = qnaSchema.extend({ jawaban: teks })

export const opsiQnaSchema = z.object({
  kategori: z.array(z.object({ id, judul: teks })),
  tag: z.array(z.object({ id, judul: teks })),
})

const barisImpor = z.object({ baris: angka, alasan: teks })

export const hasilImporSchema = z.object({
  ditambah: angka,
  kategori_baru: angka,
  tag_baru: angka,
  dilewati: z.array(barisImpor),
  galat: z.array(barisImpor),
})

const qnaHitung = z.object({ id_qna: id, pertanyaan: teks.nullable(), jumlah: angka })

export const statistikSchema = z.object({
  kpi: z.object({ total_chat: angka, chat_hari_ini: angka, rata_skor: angka, rata_skor_cocok: angka }),
  kepuasan: z.object({ membantu: angka, tidak_membantu: angka, total: angka, persen_puas: angka }),
  top_pesan: z.array(z.object({ pesan_user: teks, jumlah: angka })),
  top_qna_cocok: z.array(qnaHitung),
  top_qna_negatif: z.array(qnaHitung),
})

export const riwayatSchema = z.object({
  id_log: id,
  waktu: teks,
  pesan_user: teks,
  pertanyaan_teratas: teks.nullable(),
  skor: angka,
  cocok: bendera,
  feedback,
})

/** Galat parse respons dianggap galat muat data (ditampilkan sebagai pesan galat umum, bukan crash). */
export class KontrakChatbotError extends Error {
  readonly path: string

  constructor(path: string, cause: unknown) {
    super(`Respons ${path} tidak sesuai kontrak.`)
    this.path = path
    this.cause = cause
  }
}
