/**
 * CR-023 (ISSUE-022) — stempel waktu API `YYYY-MM-DD HH:MM:SS` (UTC, tanpa zona) wajib dibaca sebagai UTC, bukan jam
 * lokal browser. Login 18.01 WIB (11.01 UTC) sempat tampil "29 Sep 2026, 11.01". Tanggal murni tidak disentuh.
 *
 * Zona dikunci agar stabil di mesin/CI mana pun: seluruh file berjalan di TZ non-UTC (America/New_York, UTC−4 pada
 * tanggal uji) lewat `vi.stubEnv('TZ', …)` — Node membaca ulang TZ saat process.env.TZ diubah — sehingga parse yang
 * keliru memakai jam lokal tetap tertangkap walau mesin/CI ber-TZ UTC. Assertion tampilan memakai `timeZone`
 * eksplisit, atau men-stub TZ sendiri untuk jalur "zona browser"; guard resolvedOptions memastikan stub berlaku.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { API_DATETIME_FORMAT, formatApiDateTime, parseApiDateTime } from '../dateTime'

const WIB: Intl.DateTimeFormatOptions = { ...API_DATETIME_FORMAT, timeZone: 'Asia/Jakarta' }

const iso = (value: string): string | null => parseApiDateTime(value)?.toISOString() ?? null

beforeEach(() => {
  vi.stubEnv('TZ', 'America/New_York')
  expect(Intl.DateTimeFormat().resolvedOptions().timeZone).toBe('America/New_York')
})

afterEach(() => {
  vi.unstubAllEnvs()
})

describe('parseApiDateTime', () => {
  it('string tanpa zona dari API dibaca sebagai UTC (spasi maupun T, detik opsional)', () => {
    expect(iso('2026-09-29 11:01:00')).toBe('2026-09-29T11:01:00.000Z')
    expect(iso('2026-09-29T11:01:00')).toBe('2026-09-29T11:01:00.000Z')
    expect(iso('2026-09-29 11:01')).toBe('2026-09-29T11:01:00.000Z')
    expect(iso(' 2026-09-29 11:01:00 ')).toBe('2026-09-29T11:01:00.000Z')
  })

  it('pecahan detik berapa pun digitnya dipotong ke milidetik', () => {
    expect(iso('2026-09-29 11:01:00.25')).toBe('2026-09-29T11:01:00.250Z')
    expect(iso('2026-09-29 11:01:00.123456')).toBe('2026-09-29T11:01:00.123Z')
    expect(iso('2026-09-29 11:01:59.9999')).toBe('2026-09-29T11:01:59.999Z')
  })

  it('zona eksplisit dipakai: Z, ±HH:MM, ±HHMM, ±HH, gaya PHP Y-m-d H:i:sP, boleh didahului spasi', () => {
    expect(iso('2026-09-29T11:01:00Z')).toBe('2026-09-29T11:01:00.000Z')
    expect(iso('2026-09-29 11:01:00Z')).toBe('2026-09-29T11:01:00.000Z')
    expect(iso('2026-09-29T18:01:00+07:00')).toBe('2026-09-29T11:01:00.000Z')
    expect(iso('2026-09-29 18:01:00+07:00')).toBe('2026-09-29T11:01:00.000Z')
    expect(iso('2026-09-29 18:01:00 +07:00')).toBe('2026-09-29T11:01:00.000Z')
    expect(iso('2026-09-29 18:01:00+0700')).toBe('2026-09-29T11:01:00.000Z')
    expect(iso('2026-09-29 18:01:00+07')).toBe('2026-09-29T11:01:00.000Z')
    expect(iso('2026-09-29 16:31:00.5+05:30')).toBe('2026-09-29T11:01:00.500Z')
    expect(iso('2026-09-29T04:01:00-07:00')).toBe('2026-09-29T11:01:00.000Z')
    expect(iso('2026-09-30 01:00:00+07:00')).toBe('2026-09-29T18:00:00.000Z')
  })

  it('tanggal/jam yang tidak ada di kalender → null (tidak dinormalkan diam-diam)', () => {
    expect(parseApiDateTime('2026-02-30 10:00:00')).toBeNull()
    expect(parseApiDateTime('2026-02-29 10:00:00')).toBeNull()
    expect(parseApiDateTime('2026-04-31 08:00:00')).toBeNull()
    expect(parseApiDateTime('2026-09-29 24:00:00')).toBeNull()
    expect(parseApiDateTime('2026-09-29 23:60:00')).toBeNull()
    expect(parseApiDateTime('2026-09-29 23:59:60')).toBeNull()
    expect(parseApiDateTime('2026-00-10 10:00:00')).toBeNull()
    expect(parseApiDateTime('2026-09-00 10:00:00')).toBeNull()
    expect(parseApiDateTime('2026-13-45 99:99:99')).toBeNull()
    expect(parseApiDateTime('0099-09-29 10:00:00')).toBeNull()
    expect(iso('2028-02-29 10:00:00')).toBe('2028-02-29T10:00:00.000Z')
  })

  it('offset zona di luar ±23:59 → null', () => {
    expect(parseApiDateTime('2026-09-29 11:01:00+24:00')).toBeNull()
    expect(parseApiDateTime('2026-09-29 11:01:00+07:60')).toBeNull()
  })

  it('kosong, tanggal murni, dan format yang tidak didukung → null (tanggal murni tidak digeser zona)', () => {
    expect(parseApiDateTime(null)).toBeNull()
    expect(parseApiDateTime(undefined)).toBeNull()
    expect(parseApiDateTime('')).toBeNull()
    expect(parseApiDateTime('2026-09-29')).toBeNull()
    expect(parseApiDateTime('kemarin')).toBeNull()
    expect(parseApiDateTime('2026-09-29 18:01:00 WIB')).toBeNull()
    expect(parseApiDateTime('2026-09-29 18:01:00 Asia/Jakarta')).toBeNull()
    expect(parseApiDateTime('2026-09-29 18:01:00+070')).toBeNull()
    expect(parseApiDateTime('2026-09-29  11:01:00')).toBeNull()
  })
})

describe('formatApiDateTime', () => {
  it('11.01 UTC ditampilkan 18.01 di Asia/Jakarta (bukan 11.01)', () => {
    expect(formatApiDateTime('2026-09-29 11:01:00', WIB)).toBe('29 Sep 2026, 18.01')
  })

  it('mengikuti zona waktu browser bila timeZone tidak diisi', () => {
    expect(formatApiDateTime('2026-09-29 11:01:00')).toBe('29 Sep 2026, 07.01')

    vi.stubEnv('TZ', 'Asia/Jakarta')
    expect(Intl.DateTimeFormat().resolvedOptions().timeZone).toBe('Asia/Jakarta')
    expect(formatApiDateTime('2026-09-29 11:01:00')).toBe('29 Sep 2026, 18.01')

    vi.stubEnv('TZ', 'UTC')
    expect(Intl.DateTimeFormat().resolvedOptions().timeZone).toBe('UTC')
    expect(formatApiDateTime('2026-09-29 11:01:00')).toBe('29 Sep 2026, 11.01')
  })

  it('pergantian hari mengikuti zona: 20.30 UTC tanggal 29 = 30 September WIB', () => {
    const dateOnly: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Asia/Jakarta' }
    expect(formatApiDateTime('2026-09-29 20:30:00', dateOnly)).toBe('30 September 2026')
  })

  it('format bawaan tidak bisa diubah pemanggil', () => {
    expect(Object.isFrozen(API_DATETIME_FORMAT)).toBe(true)
  })

  it('nilai yang tidak bisa dibaca → null (fallback ditentukan pemanggil)', () => {
    expect(formatApiDateTime(null)).toBeNull()
    expect(formatApiDateTime('2026-09-29')).toBeNull()
    expect(formatApiDateTime('2026-02-30 10:00:00')).toBeNull()
    expect(formatApiDateTime('bukan tanggal')).toBeNull()
  })
})
