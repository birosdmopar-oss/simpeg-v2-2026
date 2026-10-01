/**
 * columns — sumber tunggal kolom Daftar Pegawai: urutan bawaan, kolom terkunci, dan pengelompokan per huruf untuk
 * popup "Filter Kolom" (Gambar 20/21).
 */
import { describe, expect, it } from 'vitest'

import { COLUMN_BY_KEY, COLUMNS, DEFAULT_COLUMN_KEYS, fullName, groupPickableColumns } from '../columns'
import { PEGAWAI_ROWS } from '../services/pegawai.mock'

describe('columns', () => {
  it('kolom bawaan sesuai Gambar 18 dan semuanya terdaftar', () => {
    expect(DEFAULT_COLUMN_KEYS).toEqual(['nama_nip', 'golongan', 'jabatan', 'satuan_kerja', 'unit'])
    for (const key of DEFAULT_COLUMN_KEYS) expect(COLUMN_BY_KEY[key]).toBeDefined()
  })

  it('hanya Nama/NIP yang terkunci; NIP Lama menyusul tepat setelahnya (urutan mockup Gambar 19)', () => {
    expect(COLUMNS.filter((c) => c.locked).map((c) => c.key)).toEqual(['nama_nip'])
    expect(COLUMNS[1].key).toBe('nip_lama')
  })

  it('kunci kolom unik', () => {
    expect(new Set(COLUMNS.map((c) => c.key)).size).toBe(COLUMNS.length)
  })

  it('setiap kolom menghasilkan teks untuk setiap baris data contoh (tidak ada sel undefined)', () => {
    for (const column of COLUMNS) {
      for (const row of PEGAWAI_ROWS) expect(typeof column.value(row)).toBe('string')
    }
  })

  it('fullName: gelar awal di depan, gelar akhir setelah koma, tanpa spasi ganda', () => {
    const base = PEGAWAI_ROWS[0]
    expect(fullName({ ...base, nama: 'Budi Santoso', gelar_awal: 'Dr.', gelar_akhir: 'M.Si' })).toBe('Dr. Budi Santoso, M.Si')
    expect(fullName({ ...base, nama: 'Budi Santoso', gelar_awal: '', gelar_akhir: '' })).toBe('Budi Santoso')
  })
})

describe('groupPickableColumns', () => {
  it('mengecualikan kolom terkunci dan mengelompokkan per huruf awal urut abjad', () => {
    const groups = groupPickableColumns()
    const all = groups.flatMap((g) => g.columns.map((c) => c.key))

    expect(all).not.toContain('nama_nip')
    expect(all).toHaveLength(COLUMNS.length - 1)
    expect(groups.map((g) => g.letter)).toEqual([...groups.map((g) => g.letter)].sort())
    for (const g of groups) expect(g.columns.every((c) => c.label[0].toUpperCase() === g.letter)).toBe(true)
  })

  it('pencarian tidak peka huruf besar/kecil; tanpa hasil → kosong', () => {
    expect(groupPickableColumns('nip').flatMap((g) => g.columns.map((c) => c.key))).toEqual(['nip_lama'])
    expect(groupPickableColumns('  AGAMA ').flatMap((g) => g.columns.map((c) => c.key))).toEqual(['agama'])
    expect(groupPickableColumns('rumah')).toEqual([])
  })
})
