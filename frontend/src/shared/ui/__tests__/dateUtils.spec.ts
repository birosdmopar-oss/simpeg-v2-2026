/**
 * dateUtils (CR-060) — parsing/format murni untuk picker tanggal/waktu/bulan. Semua memakai komponen kalender lokal;
 * tidak ada toISOString (zona waktu).
 */
import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  addDays,
  addMonths,
  compareYmd,
  daysInMonth,
  formatDisplay,
  formatLong,
  formatMonthDisplay,
  monthMatrix,
  parseIso,
  parseMonth,
  parseMonthTyped,
  parseTime,
  parseTimeTyped,
  parseTyped,
  today,
  toIso,
  toMonth,
  toTime,
  weekdayMon0,
} from '../dateUtils'

afterEach(() => {
  vi.useRealTimers()
})

describe('tanggal', () => {
  it('parseIso ketat: hanya YYYY-MM-DD yang ada di kalender', () => {
    expect(parseIso('2026-10-20')).toEqual({ y: 2026, m: 10, d: 20 })
    expect(parseIso('2024-02-29')).toEqual({ y: 2024, m: 2, d: 29 })
    for (const bad of ['2026-02-29', '2026-13-01', '2026-00-10', '2026-10-32', '26-10-20', '2026-1-1', '', ' ', 'abc', null, undefined, 20261020]) {
      expect(parseIso(bad), String(bad)).toBeNull()
    }
    // Nilai native lama (partial/tahun kecil) tetap terbaca sebagai tanggal sah.
    expect(parseIso('0002-08-17')).toEqual({ y: 2, m: 8, d: 17 })
  })

  it('parseTyped menerima dd/mm/yyyy, dd-mm-yyyy, dd.mm.yyyy, ddmmyyyy, d/m/yyyy, dan yyyy-mm-dd', () => {
    const expected = { y: 2026, m: 8, d: 17 }
    for (const text of ['17/08/2026', '17-08-2026', '17.08.2026', '17082026', '17/8/2026', ' 17/08/2026 ', '2026-08-17']) {
      expect(parseTyped(text), text).toEqual(expected)
    }
    for (const bad of ['31/04/2026', '17/08/26', '17/13/2026', '2026/08/17', '17082', '17/08/20266', 'tujuh belas', '']) {
      expect(parseTyped(bad), bad).toBeNull()
    }
  })

  it('format tampilan dan label panjang', () => {
    expect(formatDisplay('2026-08-17')).toBe('17/08/2026')
    expect(formatDisplay('bukan tanggal')).toBe('bukan tanggal')
    expect(formatLong({ y: 2026, m: 10, d: 20 })).toBe('20 Oktober 2026')
    expect(toIso({ y: 2026, m: 3, d: 5 })).toBe('2026-03-05')
    expect(toIso({ y: 7, m: 1, d: 2 })).toBe('0007-01-02')
  })

  it('addDays/addMonths melewati batas bulan/tahun; bulan menjepit hari ke akhir bulan tujuan', () => {
    expect(addDays({ y: 2026, m: 12, d: 31 }, 1)).toEqual({ y: 2027, m: 1, d: 1 })
    expect(addDays({ y: 2026, m: 3, d: 1 }, -1)).toEqual({ y: 2026, m: 2, d: 28 })
    expect(addDays({ y: 2024, m: 3, d: 1 }, -1)).toEqual({ y: 2024, m: 2, d: 29 })
    expect(addMonths({ y: 2026, m: 1, d: 31 }, 1)).toEqual({ y: 2026, m: 2, d: 28 })
    expect(addMonths({ y: 2026, m: 11, d: 30 }, 3)).toEqual({ y: 2027, m: 2, d: 28 })
    expect(addMonths({ y: 2026, m: 1, d: 15 }, -2)).toEqual({ y: 2025, m: 11, d: 15 })
    expect(addMonths({ y: 2026, m: 5, d: 10 }, 12)).toEqual({ y: 2027, m: 5, d: 10 })
  })

  it('compareYmd, daysInMonth, weekdayMon0 (Senin = 0)', () => {
    expect(compareYmd({ y: 2026, m: 1, d: 1 }, { y: 2026, m: 1, d: 2 })).toBeLessThan(0)
    expect(compareYmd({ y: 2027, m: 1, d: 1 }, { y: 2026, m: 12, d: 31 })).toBeGreaterThan(0)
    expect(compareYmd({ y: 2026, m: 5, d: 5 }, { y: 2026, m: 5, d: 5 })).toBe(0)
    expect(daysInMonth(2026, 2)).toBe(28)
    expect(daysInMonth(2024, 2)).toBe(29)
    expect(daysInMonth(2026, 10)).toBe(31)
    expect(weekdayMon0({ y: 2026, m: 10, d: 19 })).toBe(0) // Senin
    expect(weekdayMon0({ y: 2026, m: 10, d: 25 })).toBe(6) // Minggu
  })

  it('monthMatrix: 6 baris × 7 kolom, Senin dulu, hari bulan ini bertanda inMonth', () => {
    const m = monthMatrix(2026, 10) // 1 Okt 2026 = Kamis
    expect(m).toHaveLength(6)
    expect(m.every((row) => row.length === 7)).toBe(true)
    expect(m[0]?.[0]).toMatchObject({ y: 2026, m: 9, d: 28, inMonth: false })
    expect(m[0]?.[3]).toMatchObject({ y: 2026, m: 10, d: 1, inMonth: true })
    expect(m.flat().filter((c) => c.inMonth)).toHaveLength(31)
  })

  it('today() memakai komponen LOKAL: 20 Okt 00:30 waktu lokal tetap 20 Okt (toISOString bisa menggeser ke 19 di UTC+)', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(2026, 9, 20, 0, 30, 0))
    expect(today()).toEqual({ y: 2026, m: 10, d: 20 })
    expect(toIso(today())).toBe('2026-10-20')
    vi.setSystemTime(new Date(2026, 9, 20, 23, 59, 59))
    expect(toIso(today())).toBe('2026-10-20')
  })
})

describe('waktu', () => {
  it('parseTime ketat HH:mm (detik diabaikan); parseTimeTyped menerima H:mm, HH.mm, HHmm', () => {
    expect(parseTime('08:05')).toEqual({ h: 8, min: 5 })
    expect(parseTime('23:59:30')).toEqual({ h: 23, min: 59 })
    for (const bad of ['24:00', '12:60', '8:05', '', 'abc', null, 1230]) expect(parseTime(bad), String(bad)).toBeNull()
    for (const text of ['08:05', '8:05', '08.05', '0805']) expect(parseTimeTyped(text), text).toEqual({ h: 8, min: 5 })
    for (const bad of ['24:00', '8:5', '12:60', '805', 'pagi']) expect(parseTimeTyped(bad), bad).toBeNull()
    expect(toTime({ h: 7, min: 0 })).toBe('07:00')
  })
})

describe('bulan', () => {
  it('parseMonth ketat YYYY-MM; parseMonthTyped menerima mm/yyyy, m/yyyy, mm-yyyy, yyyy-mm', () => {
    expect(parseMonth('2026-10')).toEqual({ y: 2026, m: 10 })
    for (const bad of ['2026-13', '2026-00', '2026-1', '', null]) expect(parseMonth(bad), String(bad)).toBeNull()
    for (const text of ['10/2026', '10-2026', '2026-10', '10.2026']) expect(parseMonthTyped(text), text).toEqual({ y: 2026, m: 10 })
    expect(parseMonthTyped('1/2026')).toEqual({ y: 2026, m: 1 })
    for (const bad of ['13/2026', '10/26', 'Oktober']) expect(parseMonthTyped(bad), bad).toBeNull()
    expect(toMonth({ y: 2026, m: 3 })).toBe('2026-03')
    expect(formatMonthDisplay('2026-03')).toBe('03/2026')
  })
})
