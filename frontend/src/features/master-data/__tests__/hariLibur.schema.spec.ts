/**
 * G-08 Hari Libur (DBV-003/CR-010) — skema form: tanggal valid (sama dengan HariLiburRules backend), rentang, jenis
 * wajib, batas nama & keterangan; helper jumlah hari.
 */
import { describe, expect, it } from 'vitest'

import { hariLiburSchema, isValidHariLiburDate, jumlahHari } from '../schemas/hariLibur.schema'

const valid = { tgl_mulai: '2026-03-19', tgl_akhir: '2026-03-20', id_jenis_libur: '2', nama_libur: 'Cuti Bersama', keterangan: '', status: '1' }

function issues(input: Record<string, unknown>): Record<string, string> {
  const result = hariLiburSchema.safeParse(input)
  return result.success ? {} : Object.fromEntries(result.error.issues.map((i) => [i.path.join('.'), i.message]))
}

describe('isValidHariLiburDate', () => {
  it.each(['2026-01-01', '2024-02-29', '1900-01-01', '2100-12-31'])('%s valid', (date) => {
    expect(isValidHariLiburDate(date)).toBe(true)
  })

  it.each(['2026-02-30', '2025-02-29', '2026-1-1', '31-12-2026', '', '1899-12-31', '2101-01-01', '2026-13-01'])('%s tidak valid', (date) => {
    expect(isValidHariLiburDate(date)).toBe(false)
  })
})

describe('jumlahHari', () => {
  it('inklusif, lintas bulan/tahun; rentang terbalik = 0', () => {
    expect(jumlahHari('2026-01-01', '2026-01-01')).toBe(1)
    expect(jumlahHari('2026-03-19', '2026-03-20')).toBe(2)
    expect(jumlahHari('2026-12-31', '2027-01-02')).toBe(3)
    expect(jumlahHari('2026-01-02', '2026-01-01')).toBe(0)
    expect(jumlahHari('', '2026-01-01')).toBe(0)
  })
})

describe('hariLiburSchema', () => {
  it('menerima data valid dan merapikan spasi nama', () => {
    const result = hariLiburSchema.safeParse({ ...valid, nama_libur: '  Cuti   Bersama ' })
    expect(result.success).toBe(true)
    expect(result.success ? result.data.nama_libur : '').toBe('Cuti Bersama')
    expect(hariLiburSchema.safeParse({ ...valid, tgl_akhir: valid.tgl_mulai, status: '' }).success).toBe(true)
  })

  it('tanggal selesai sebelum tanggal mulai ditolak di tgl_akhir', () => {
    expect(issues({ ...valid, tgl_akhir: '2026-03-18' })).toEqual({ tgl_akhir: 'Tanggal selesai tidak boleh sebelum tanggal mulai.' })
  })

  it('tanggal tidak valid, jenis kosong, nama kosong/terlalu panjang, keterangan > 65.535 byte ditolak', () => {
    expect(issues({ ...valid, tgl_mulai: '2026-02-30' })).toHaveProperty('tgl_mulai')
    expect(issues({ ...valid, tgl_mulai: '' })).toEqual({ tgl_mulai: 'Tanggal mulai wajib diisi.' })
    expect(issues({ ...valid, id_jenis_libur: '' })).toEqual({ id_jenis_libur: 'Jenis libur wajib dipilih.' })
    expect(issues({ ...valid, nama_libur: '   ' })).toEqual({ nama_libur: 'Nama libur wajib diisi.' })
    expect(issues({ ...valid, nama_libur: 'a'.repeat(101) })).toEqual({ nama_libur: 'Nama libur maksimal 100 karakter.' })
    expect(issues({ ...valid, nama_libur: ` ${'a'.repeat(100)} ` })).toEqual({})
    expect(issues({ ...valid, keterangan: 'é'.repeat(32768) })).toEqual({ keterangan: 'Keterangan maksimal 65.535 byte.' })
    expect(issues({ ...valid, status: '10' })).toHaveProperty('status')
  })
})
