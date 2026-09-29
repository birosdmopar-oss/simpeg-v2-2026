/**
 * Skema Zod form Hari Libur (ADR-026), mengikuti validasi backend HariLiburController + HariLiburService:
 * tanggal `YYYY-MM-DD` yang ada di kalender (tahun 1900-2100), selesai ≥ mulai, jenis wajib, nama ≤ 100 karakter,
 * keterangan ≤ 65.535 byte. Overlap & jenis aktif tetap dicek backend (422 → field).
 */
import { z } from 'zod'

import { utf8ByteLength } from '@/shared/utils/byteLength'

export const HARI_LIBUR_YEAR_MIN = 1900
export const HARI_LIBUR_YEAR_MAX = 2100
export const HARI_LIBUR_NAMA_MAX = 100
export const HARI_LIBUR_KETERANGAN_MAX_BYTES = 65_535

/** Sama dengan HariLiburRules::isValidDate backend. */
export function isValidHariLiburDate(value: string): boolean {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (!match) return false
  const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])]
  if (year < HARI_LIBUR_YEAR_MIN || year > HARI_LIBUR_YEAR_MAX) return false
  const date = new Date(Date.UTC(year, month - 1, day))
  return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day
}

/** Jumlah hari kalender inklusif (mulai = selesai → 1). */
export function jumlahHari(mulai: string, akhir: string): number {
  const start = Date.parse(`${mulai}T00:00:00Z`)
  const end = Date.parse(`${akhir}T00:00:00Z`)
  if (Number.isNaN(start) || Number.isNaN(end) || end < start) return 0
  return Math.round((end - start) / 86_400_000) + 1
}

function dateField(label: string) {
  return z
    .string({ required_error: `${label} wajib diisi.` })
    .trim()
    .min(1, `${label} wajib diisi.`)
    // Kosong sudah dilaporkan min(1); pesan format hanya untuk isian yang ada.
    .refine((v) => v === '' || isValidHariLiburDate(v), {
      message: `${label} harus tanggal yang valid (YYYY-MM-DD, tahun ${HARI_LIBUR_YEAR_MIN}-${HARI_LIBUR_YEAR_MAX}).`,
    })
}

export const hariLiburSchema = z
  .object({
    tgl_mulai: dateField('Tanggal mulai'),
    tgl_akhir: dateField('Tanggal selesai'),
    id_jenis_libur: z.string({ required_error: 'Jenis libur wajib dipilih.' }).min(1, 'Jenis libur wajib dipilih.'),
    nama_libur: z
      .string({ required_error: 'Nama libur wajib diisi.' })
      .transform((v) => v.replace(/\s+/g, ' ').trim())
      .pipe(
        z
          .string()
          .min(1, 'Nama libur wajib diisi.')
          .max(HARI_LIBUR_NAMA_MAX, `Nama libur maksimal ${HARI_LIBUR_NAMA_MAX} karakter.`),
      ),
    keterangan: z
      .string()
      .optional()
      .refine((v) => utf8ByteLength(v ?? '') <= HARI_LIBUR_KETERANGAN_MAX_BYTES, {
        message: `Keterangan maksimal ${HARI_LIBUR_KETERANGAN_MAX_BYTES.toLocaleString('id-ID')} byte.`,
      }),
    status: z.union([z.literal(''), z.literal('1'), z.literal('2')]).optional(),
  })
  .superRefine((values, ctx) => {
    if (isValidHariLiburDate(values.tgl_mulai) && isValidHariLiburDate(values.tgl_akhir) && values.tgl_akhir < values.tgl_mulai) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['tgl_akhir'], message: 'Tanggal selesai tidak boleh sebelum tanggal mulai.' })
    }
  })

export type HariLiburSchema = z.infer<typeof hariLiburSchema>
