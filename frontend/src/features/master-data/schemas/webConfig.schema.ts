/**
 * Validasi form Web Config (G-09, DBV-006/CR-030) per tipe key, mengikuti App\Libraries\MasterData\WebConfigTypes
 * backend (ADR-026). Backend tetap penentu akhir (422 → field `config_value`); HTML hanya disanitasi di backend.
 */
import { z } from 'zod'

import { utf8ByteLength } from '@/shared/utils/byteLength'

import type { WebConfigItem } from '../webConfig.types'

export const WEB_CONFIG_TEXT_MAX_BYTES = 65_535
export const WEB_CONFIG_REMARK_MAX = 255
export const WEB_CONFIG_EMAIL_MAX = 20
/** Fase 5A F5-16 (`date_range_list`): batas jumlah rentang = WebConfigTypes::RANGE_MAX_COUNT backend. */
export const WEB_CONFIG_RANGE_MAX = 50

const EMAIL = /^[^\s@,]+@[^\s@,]+\.[^\s@,]+$/

/** Pesan galat nilai untuk tipe key, atau null bila sah. */
export function webConfigValueError(item: Pick<WebConfigItem, 'type' | 'constraints'>, raw: string): string | null {
  const value = raw.trim()
  const c = item.constraints
  if (value === '') return 'Nilai wajib diisi. Untuk kembali ke nilai bawaan, hapus nilainya.'

  switch (item.type) {
    case 'text': {
      if (/[\r\n]/.test(value)) return 'Nilai harus satu baris (tanpa baris baru).'
      const max = c.max_length ?? 255
      return [...value].length > max ? `Nilai maksimal ${max} karakter.` : null
    }
    case 'textarea':
    case 'html':
      return utf8ByteLength(value) > WEB_CONFIG_TEXT_MAX_BYTES ? 'Nilai maksimal 65.535 byte.' : null
    case 'integer': {
      if (!/^(0|-?[1-9]\d{0,17})$/.test(value)) return 'Nilai harus bilangan bulat (tanpa titik, koma, atau pemisah ribuan).'
      return rangeError(Number(value), c)
    }
    case 'decimal': {
      if (value.includes(',')) return 'Gunakan titik sebagai pemisah desimal, mis. 1.5.'
      const match = /^-?(0|[1-9]\d{0,11})(\.\d+)?$/.exec(value)
      if (!match) return 'Nilai harus angka desimal, mis. 1.5.'
      const fraction = (match[2] ?? '').slice(1).replace(/0+$/, '')
      const scale = c.scale ?? 2
      if (fraction.length > scale) return `Nilai maksimal ${scale} digit di belakang titik.`
      return rangeError(Number(value), c)
    }
    case 'id_ref':
      return /^[1-9]\d{0,9}$/.test(value) && Number(value) <= 2_147_483_647 ? null : 'Nilai harus ID angka positif (tanpa nol di depan).'
    case 'url':
      return /^https?:\/\/[^\s]+$/i.test(value) && value.length <= 2048 ? null : 'Nilai harus URL lengkap yang diawali http:// atau https://.'
    case 'asset_path':
      return value.length <= 255 && /^[A-Za-z0-9_-]+(\/[A-Za-z0-9_-]+)*\.(png|jpe?g)$/i.test(value)
        ? null
        : 'Nilai harus path relatif berkas gambar .png/.jpg (mis. kop/logo-kiri.png), tanpa path absolut atau "..".'
    case 'time':
      return /^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/.test(value) ? null : 'Nilai harus jam dengan format HH:MM atau HH:MM:SS (00:00 s.d. 23:59:59).'
    case 'email_list': {
      const parts = value.split(',').map((p) => p.trim())
      if (parts.some((p) => p === '')) return 'Daftar email tidak boleh berisi isian kosong (cek koma ganda).'
      const bad = parts.find((p) => !EMAIL.test(p))
      if (bad) return `Alamat email tidak valid: ${bad}.`
      if (new Set(parts.map((p) => p.toLowerCase())).size !== parts.length) return 'Ada alamat email ganda.'
      return parts.length > WEB_CONFIG_EMAIL_MAX ? `Maksimal ${WEB_CONFIG_EMAIL_MAX} alamat email.` : null
    }
    case 'date_range_list':
      return dateRangeListError(value)
    default:
      return 'Key ini tidak ada di katalog sehingga nilainya tidak bisa diubah.'
  }
}

/**
 * `date_range_list` (Fase 5A F5-16): pesan & urutan pemeriksaan sama dengan WebConfigTypes::dateRangeList backend —
 * per baris (nomor baris masukan, baris kosong diabaikan), lalu jumlah, lalu tumpang tindih setelah duplikat dibuang.
 */
function dateRangeListError(value: string): string | null {
  const ranges = new Map<string, [string, string]>()
  const lines = value.split(/\r\n|\r|\n/)

  for (const [index, raw] of lines.entries()) {
    const line = raw.trim()
    if (line === '') continue
    const no = index + 1
    const m = /^(\d{4})-(\d{2})-(\d{2})\.\.(\d{4})-(\d{2})-(\d{2})$/.exec(line)
    if (!m) return `Baris ${no}: format harus YYYY-MM-DD..YYYY-MM-DD (mis. 2026-02-18..2026-03-19).`
    if (!validDate(m[1]!, m[2]!, m[3]!) || !validDate(m[4]!, m[5]!, m[6]!)) return `Baris ${no}: tanggal tidak valid.`
    const start = `${m[1]}-${m[2]}-${m[3]}`
    const end = `${m[4]}-${m[5]}-${m[6]}`
    if (start > end) return `Baris ${no}: tanggal mulai tidak boleh setelah tanggal selesai.`
    ranges.set(`${start}..${end}`, [start, end])
  }

  if (ranges.size === 0) return 'Nilai wajib diisi. Untuk kembali ke nilai bawaan, hapus nilainya.'
  if (ranges.size > WEB_CONFIG_RANGE_MAX) return `Maksimal ${WEB_CONFIG_RANGE_MAX} rentang tanggal.`

  const sorted = [...ranges.entries()].sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
  for (let i = 1; i < sorted.length; i++) {
    const [prevKey, [, prevEnd]] = sorted[i - 1]!
    const [key, [start]] = sorted[i]!
    if (start <= prevEnd) return `Rentang ${prevKey} dan ${key} tumpang tindih.`
  }

  return null
}

function validDate(y: string, m: string, d: string): boolean {
  const date = new Date(Date.UTC(Number(y), Number(m) - 1, Number(d)))
  return date.getUTCFullYear() === Number(y) && date.getUTCMonth() === Number(m) - 1 && date.getUTCDate() === Number(d)
}

function rangeError(n: number, c: WebConfigItem['constraints']): string | null {
  if (c.min !== undefined && n < c.min) return `Nilai harus antara ${fmt(c.min)} dan ${fmt(c.max)}.`
  if (c.max !== undefined && n > c.max) return `Nilai harus antara ${fmt(c.min)} dan ${fmt(c.max)}.`
  return null
}

function fmt(n: number | undefined): string {
  return n === undefined ? '' : new Intl.NumberFormat('id-ID').format(n)
}

/** Skema form (config_value + remark) untuk satu key. */
export function webConfigSchema(item: Pick<WebConfigItem, 'type' | 'constraints'>) {
  return z.object({
    config_value: z.string().superRefine((value, ctx) => {
      const message = webConfigValueError(item, value)
      if (message) ctx.addIssue({ code: z.ZodIssueCode.custom, message })
    }),
    remark: z.string().trim().max(WEB_CONFIG_REMARK_MAX, `Keterangan maksimal ${WEB_CONFIG_REMARK_MAX} karakter.`),
  })
}
