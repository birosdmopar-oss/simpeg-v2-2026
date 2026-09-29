/**
 * CR-023 (ISSUE-022) — stempel waktu API `YYYY-MM-DD HH:MM:SS` (UTC, tanpa zona) wajib dibaca sebagai UTC, bukan jam
 * lokal browser. Login 18.01 WIB (11.01 UTC) sempat tampil "29 Sep 2026, 11.01". Tanggal murni tidak disentuh.
 *
 * Zona dikunci dua cara agar stabil di mesin/CI mana pun: `timeZone` eksplisit di opsi Intl, dan untuk jalur "zona
 * browser" lewat `vi.stubEnv('TZ', …)` (Node membaca ulang TZ saat process.env.TZ diubah) + guard resolvedOptions.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'

import { API_DATETIME_FORMAT, formatApiDateTime, parseApiDateTime } from '../dateTime'

const WIB: Intl.DateTimeFormatOptions = { ...API_DATETIME_FORMAT, timeZone: 'Asia/Jakarta' }

afterEach(() => {
  vi.unstubAllEnvs()
})

describe('parseApiDateTime', () => {
  it('string tanpa zona dari API dibaca sebagai UTC (spasi maupun T, detik & milidetik opsional)', () => {
    expect(parseApiDateTime('2026-09-29 11:01:00')?.toISOString()).toBe('2026-09-29T11:01:00.000Z')
    expect(parseApiDateTime('2026-09-29T11:01:00')?.toISOString()).toBe('2026-09-29T11:01:00.000Z')
    expect(parseApiDateTime('2026-09-29 11:01')?.toISOString()).toBe('2026-09-29T11:01:00.000Z')
    expect(parseApiDateTime('2026-09-29 11:01:00.250')?.toISOString()).toBe('2026-09-29T11:01:00.250Z')
    expect(parseApiDateTime(' 2026-09-29 11:01:00 ')?.toISOString()).toBe('2026-09-29T11:01:00.000Z')
  })

  it('ISO yang sudah membawa zona dipakai apa adanya', () => {
    expect(parseApiDateTime('2026-09-29T11:01:00Z')?.toISOString()).toBe('2026-09-29T11:01:00.000Z')
    expect(parseApiDateTime('2026-09-29T18:01:00+07:00')?.toISOString()).toBe('2026-09-29T11:01:00.000Z')
  })

  it('kosong, tanggal murni, dan teks tidak valid → null (tanggal murni tidak digeser zona)', () => {
    expect(parseApiDateTime(null)).toBeNull()
    expect(parseApiDateTime(undefined)).toBeNull()
    expect(parseApiDateTime('')).toBeNull()
    expect(parseApiDateTime('2026-09-29')).toBeNull()
    expect(parseApiDateTime('kemarin')).toBeNull()
    expect(parseApiDateTime('2026-13-45 99:99:99')).toBeNull()
  })
})

describe('formatApiDateTime', () => {
  it('11.01 UTC ditampilkan 18.01 di Asia/Jakarta (bukan 11.01)', () => {
    expect(formatApiDateTime('2026-09-29 11:01:00', WIB)).toBe('29 Sep 2026, 18.01')
  })

  it('mengikuti zona waktu browser bila timeZone tidak diisi', () => {
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

  it('nilai yang tidak bisa dibaca → null (fallback ditentukan pemanggil)', () => {
    expect(formatApiDateTime(null)).toBeNull()
    expect(formatApiDateTime('2026-09-29')).toBeNull()
    expect(formatApiDateTime('bukan tanggal')).toBeNull()
  })
})
