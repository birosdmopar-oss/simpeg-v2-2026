/**
 * CR-023 (ISSUE-022) — stempel waktu dari API. Backend (CodeIgniter 4, `appTimezone = 'UTC'`) menyimpan dan mengirim
 * DATETIME dalam UTC berformat `YYYY-MM-DD HH:MM:SS` TANPA zona (mis. `last_login_at`, `created_at`, `updated_at`).
 * `new Date('2026-09-29 11:01:00')` / `new Date('2026-09-29T11:01:00')` membacanya sebagai jam LOKAL browser, sehingga
 * di WIB tampil 7 jam lebih awal. Helper ini menambahkan `Z` (UTC) sebelum parse, lalu hasilnya ditampilkan di zona
 * waktu browser (pola yang sama dengan FAQ "Diperbarui").
 *
 * Hanya untuk stempel waktu (ada jam). Tanggal murni `YYYY-MM-DD` (mis. tgl_mulai hari libur) SENGAJA ditolak
 * (hasil `null`): formatnya tetap lewat formatter tanggal masing-masing (timeZone UTC) agar tidak bergeser hari.
 */

/** `YYYY-MM-DD HH:MM[:SS[.mmm]]` atau dengan `T`, tanpa penanda zona → dianggap UTC. */
const NAIVE_DATETIME = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}(?::\d{2}(?:\.\d{1,3})?)?)$/

/** ISO 8601 yang sudah membawa zona (`Z` atau `±HH:MM`) → dipakai apa adanya. */
const ZONED_DATETIME = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,3})?)?(?:Z|[+-]\d{2}:\d{2})$/

/** Format bawaan: "29 Sep 2026, 18.01" (locale id-ID). */
export const API_DATETIME_FORMAT: Intl.DateTimeFormatOptions = { dateStyle: 'medium', timeStyle: 'short' }

/** Parse stempel waktu API sebagai UTC; `null` bila kosong, bukan stempel waktu (termasuk tanggal murni), atau tidak valid. */
export function parseApiDateTime(value: string | null | undefined): Date | null {
  if (!value) return null
  const text = value.trim()
  const naive = NAIVE_DATETIME.exec(text)
  const iso = naive ? `${naive[1]}T${naive[2]}Z` : ZONED_DATETIME.test(text) ? text : null
  if (iso === null) return null
  const time = Date.parse(iso)
  return Number.isNaN(time) ? null : new Date(time)
}

/**
 * Format stempel waktu API untuk ditampilkan (default zona waktu browser; `options.timeZone` boleh diisi eksplisit).
 * `null` bila nilainya tidak bisa dibaca — pemanggil menentukan fallback-nya sendiri ("—", teks mentah, atau kosong).
 */
export function formatApiDateTime(
  value: string | null | undefined,
  options: Intl.DateTimeFormatOptions = API_DATETIME_FORMAT,
  locale = 'id-ID',
): string | null {
  const date = parseApiDateTime(value)
  return date ? date.toLocaleString(locale, options) : null
}
