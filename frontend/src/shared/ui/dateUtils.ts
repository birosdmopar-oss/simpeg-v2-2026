/**
 * Helper tanggal/waktu murni untuk UiDatePicker / UiTimePicker / UiMonthPicker (AGENTS.md bagian 3, CR-060).
 *
 * Nilai komponen selalu string: tanggal `YYYY-MM-DD`, waktu `HH:mm`, bulan `YYYY-MM` — sama dengan input native
 * sebelumnya, jadi skema/zod dan API tidak berubah. Semua perhitungan memakai komponen kalender LOKAL (y, m, d);
 * `toISOString()` TIDAK dipakai karena menggeser tanggal ke UTC (Asia/Jakarta jam 00:00 menjadi hari sebelumnya).
 * Tanpa dependensi tanggal; nama bulan/hari ditulis tetap (id-ID) agar deterministik di semua lingkungan.
 */

export interface YMD {
  y: number
  m: number // 1-12
  d: number
}

export const MONTH_NAMES = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'] as const
/** Awal pekan = Senin (kebiasaan id-ID). */
export const WEEKDAYS_SHORT = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as const
export const WEEKDAYS_LONG = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'] as const

export const pad = (n: number, width = 2): string => String(n).padStart(width, '0')

export function daysInMonth(y: number, m: number): number {
  return new Date(y, m, 0).getDate()
}

export function isValidYmd(y: number, m: number, d: number): boolean {
  return Number.isInteger(y) && Number.isInteger(m) && Number.isInteger(d) && y >= 1 && y <= 9999 && m >= 1 && m <= 12 && d >= 1 && d <= daysInMonth(y, m)
}

export function toIso({ y, m, d }: YMD): string {
  return `${pad(y, 4)}-${pad(m)}-${pad(d)}`
}

/** `YYYY-MM-DD` ketat (tanggal kalender yang benar); selain itu null. */
export function parseIso(value: unknown): YMD | null {
  if (typeof value !== 'string') return null
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value.trim())
  if (!match) return null
  const [y, m, d] = [Number(match[1]), Number(match[2]), Number(match[3])]
  return isValidYmd(y, m, d) ? { y, m, d } : null
}

/**
 * Teks ketikan manual → YMD. Diterima: `dd/mm/yyyy`, `dd-mm-yyyy`, `dd.mm.yyyy` (hari/bulan boleh satu digit),
 * `yyyy-mm-dd`, dan `ddmmyyyy` (8 digit). Tahun wajib 4 digit; tanggal harus ada di kalender.
 */
export function parseTyped(text: string): YMD | null {
  const t = text.trim()
  const iso = parseIso(t)
  if (iso) return iso
  const match = /^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$/.exec(t) ?? /^(\d{2})(\d{2})(\d{4})$/.exec(t)
  if (!match) return null
  const [d, m, y] = [Number(match[1]), Number(match[2]), Number(match[3])]
  return isValidYmd(y, m, d) ? { y, m, d } : null
}

/** `YYYY-MM-DD` → `dd/mm/yyyy` untuk tampilan; nilai yang bukan tanggal valid dikembalikan apa adanya. */
export function formatDisplay(value: string): string {
  const p = parseIso(value)
  return p ? `${pad(p.d)}/${pad(p.m)}/${pad(p.y, 4)}` : value
}

/** "20 Oktober 2026" (label aksesibel sel kalender). */
export function formatLong({ y, m, d }: YMD): string {
  return `${d} ${MONTH_NAMES[m - 1]} ${y}`
}

/** Urutan: negatif bila a < b, 0 sama, positif bila a > b. */
export function compareYmd(a: YMD, b: YMD): number {
  return a.y - b.y || a.m - b.m || a.d - b.d
}

export function addDays({ y, m, d }: YMD, n: number): YMD {
  const date = new Date(y, m - 1, d + n)
  return { y: date.getFullYear(), m: date.getMonth() + 1, d: date.getDate() }
}

/** Tambah bulan; hari dijepit ke akhir bulan tujuan (31 Jan + 1 bulan = 28/29 Feb). */
export function addMonths({ y, m, d }: YMD, n: number): YMD {
  const index = y * 12 + (m - 1) + n
  const ny = Math.floor(index / 12)
  const nm = (index % 12) + 1
  return { y: ny, m: nm, d: Math.min(d, daysInMonth(ny, nm)) }
}

/** 0 = Senin … 6 = Minggu. */
export function weekdayMon0({ y, m, d }: YMD): number {
  return (new Date(y, m - 1, d).getDay() + 6) % 7
}

/** Hari ini menurut jam lokal perangkat. */
export function today(): YMD {
  const now = new Date()
  return { y: now.getFullYear(), m: now.getMonth() + 1, d: now.getDate() }
}

/** Matriks bulan (6 baris × 7 kolom, Senin dulu); sel di luar bulan memakai `inMonth: false`. */
export function monthMatrix(y: number, m: number): Array<Array<YMD & { inMonth: boolean }>> {
  const lead = weekdayMon0({ y, m, d: 1 })
  const start = addDays({ y, m, d: 1 }, -lead)
  return Array.from({ length: 6 }, (_, row) =>
    Array.from({ length: 7 }, (_, col) => {
      const cell = addDays(start, row * 7 + col)
      return { ...cell, inMonth: cell.m === m && cell.y === y }
    }),
  )
}

// ---------------------------------------------------------------- waktu

export interface HM {
  h: number
  min: number
}

/** `HH:mm` (juga `HH:mm:ss`, detik diabaikan) ketat 00:00–23:59. */
export function parseTime(value: unknown): HM | null {
  if (typeof value !== 'string') return null
  const match = /^(\d{2}):(\d{2})(?::\d{2})?$/.exec(value.trim())
  if (!match) return null
  const [h, min] = [Number(match[1]), Number(match[2])]
  return h <= 23 && min <= 59 ? { h, min } : null
}

/** Ketikan manual: `HH:mm`, `H:mm`, `HH.mm`, `HHmm`. */
export function parseTimeTyped(text: string): HM | null {
  const t = text.trim()
  const match = /^(\d{1,2})[:.](\d{2})$/.exec(t) ?? /^(\d{2})(\d{2})$/.exec(t)
  if (!match) return null
  const [h, min] = [Number(match[1]), Number(match[2])]
  return h <= 23 && min <= 59 ? { h, min } : null
}

export const toTime = ({ h, min }: HM): string => `${pad(h)}:${pad(min)}`

// ---------------------------------------------------------------- bulan

export interface YM {
  y: number
  m: number
}

/** `YYYY-MM` ketat. */
export function parseMonth(value: unknown): YM | null {
  if (typeof value !== 'string') return null
  const match = /^(\d{4})-(\d{2})$/.exec(value.trim())
  if (!match) return null
  const [y, m] = [Number(match[1]), Number(match[2])]
  return y >= 1 && m >= 1 && m <= 12 ? { y, m } : null
}

/** Ketikan manual: `mm/yyyy`, `m/yyyy`, `mm-yyyy`, `yyyy-mm`. */
export function parseMonthTyped(text: string): YM | null {
  const t = text.trim()
  const iso = parseMonth(t)
  if (iso) return iso
  const match = /^(\d{1,2})[/.-](\d{4})$/.exec(t)
  if (!match) return null
  const [m, y] = [Number(match[1]), Number(match[2])]
  return m >= 1 && m <= 12 && y >= 1 ? { y, m } : null
}

export const toMonth = ({ y, m }: YM): string => `${pad(y, 4)}-${pad(m)}`
export const formatMonthDisplay = (value: string): string => {
  const p = parseMonth(value)
  return p ? `${pad(p.m)}/${pad(p.y, 4)}` : value
}
