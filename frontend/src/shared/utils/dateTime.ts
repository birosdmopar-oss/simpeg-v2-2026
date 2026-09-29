/**
 * CR-023 (ISSUE-022) — stempel waktu dari API. Backend (CodeIgniter 4, `appTimezone = 'UTC'`) menulis dan mengirim
 * DATETIME dalam UTC berformat `YYYY-MM-DD HH:MM:SS` TANPA zona (mis. `last_login_at`, `created_at`, `updated_at`).
 * `new Date('2026-09-29 11:01:00')` / `new Date('2026-09-29T11:01:00')` membacanya sebagai jam LOKAL browser, sehingga
 * di WIB tampil 7 jam lebih awal. Helper ini membaca nilai tanpa zona sebagai UTC, lalu hasilnya ditampilkan di zona
 * waktu browser (pola yang sama dengan FAQ "Diperbarui").
 *
 * Batas asumsi UTC: hanya benar untuk nilai yang DITULIS APLIKASI. Nilai dari `DEFAULT CURRENT_TIMESTAMP` /
 * `ON UPDATE CURRENT_TIMESTAMP` server DB dan data impor legacy masih jam WIB sampai ada keputusan zona waktu
 * A-01 #7 / ISSUE-022 (DBV-011); nilai seperti itu akan tampil +7 jam bila lolos ke UI lewat helper ini.
 *
 * Format yang didukung (selain itu → `null`):
 * - `YYYY-MM-DD HH:MM[:SS[.pecahan]]`, pemisah tanggal–jam spasi atau `T`; pecahan detik berapa pun digitnya dipotong
 *   ke milidetik (3 digit).
 * - Tanpa zona → UTC. Dengan zona (boleh didahului satu spasi): `Z`, `±HH:MM`, `±HHMM`, atau `±HH` — mis. gaya PHP
 *   `Y-m-d H:i:sP` (`2026-09-29 18:01:00+07:00`) atau ISO 8601 (`2026-09-29T11:01:00Z`).
 * - Tanggal dan jam wajib valid di kalender: `2026-02-30`, `2026-02-29` (bukan kabisat), `2026-04-31`, jam `24:00`,
 *   menit/detik `60` ditolak — tidak dinormalkan diam-diam ke tanggal lain seperti `Date.parse` V8.
 *
 * Tidak didukung (→ `null`): tanggal murni `YYYY-MM-DD` (mis. tgl_mulai hari libur — SENGAJA ditolak; formatnya tetap
 * lewat formatter tanggal masing-masing dengan timeZone UTC agar tidak bergeser hari), nama zona (`WIB`,
 * `Asia/Jakarta`), `z` huruf kecil, tahun di luar 4 digit / sebelum 0100, dan format lain (RFC 2822, epoch, dsb.).
 */

const API_DATETIME = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)? ?(Z|[+-]\d{2}(?::?\d{2})?)?$/

const ZONE_OFFSET = /^([+-])(\d{2}):?(\d{2})?$/

/** Format bawaan: "29 Sep 2026, 18.01" (locale id-ID). */
export const API_DATETIME_FORMAT: Readonly<Intl.DateTimeFormatOptions> = Object.freeze({
  dateStyle: 'medium',
  timeStyle: 'short',
})

/** Selisih zona dalam menit (`Z`/tanpa zona = 0); `null` bila offset di luar ±23:59. */
function offsetMinutes(zone: string | undefined): number | null {
  if (!zone || zone === 'Z') return 0
  const match = ZONE_OFFSET.exec(zone)
  if (!match) return null
  const hours = Number(match[2])
  const minutes = Number(match[3] ?? '0')
  if (hours > 23 || minutes > 59) return null
  return (match[1] === '-' ? -1 : 1) * (hours * 60 + minutes)
}

/**
 * Parse stempel waktu API; tanpa zona dianggap UTC. `null` bila kosong, bukan stempel waktu (termasuk tanggal murni),
 * formatnya tidak didukung, atau tanggal/jamnya tidak ada di kalender.
 */
export function parseApiDateTime(value: string | null | undefined): Date | null {
  if (!value) return null
  const match = API_DATETIME.exec(value.trim())
  if (!match) return null

  const [year, month, day, hour, minute, second] = [match[1], match[2], match[3], match[4], match[5], match[6] ?? '0'].map(
    Number,
  )
  const fraction = match[7] ?? ''
  const millisecond = fraction === '' ? 0 : Number(fraction.slice(0, 3).padEnd(3, '0'))
  const offset = offsetMinutes(match[8])
  if (offset === null) return null

  // Cek bolak-balik (pola isValidHariLiburDate): komponen hasil Date.UTC harus sama persis dengan input.
  const wallClock = new Date(Date.UTC(year, month - 1, day, hour, minute, second, millisecond))
  if (
    wallClock.getUTCFullYear() !== year ||
    wallClock.getUTCMonth() !== month - 1 ||
    wallClock.getUTCDate() !== day ||
    wallClock.getUTCHours() !== hour ||
    wallClock.getUTCMinutes() !== minute ||
    wallClock.getUTCSeconds() !== second
  ) {
    return null
  }

  return new Date(wallClock.getTime() - offset * 60_000)
}

/**
 * Format stempel waktu API untuk ditampilkan (default zona waktu browser; `options.timeZone` boleh diisi eksplisit).
 * `null` bila nilainya tidak bisa dibaca — pemanggil menentukan fallback-nya sendiri ("—", teks mentah, atau kosong).
 */
export function formatApiDateTime(
  value: string | null | undefined,
  options: Readonly<Intl.DateTimeFormatOptions> = API_DATETIME_FORMAT,
  locale = 'id-ID',
): string | null {
  const date = parseApiDateTime(value)
  return date ? date.toLocaleString(locale, options) : null
}
