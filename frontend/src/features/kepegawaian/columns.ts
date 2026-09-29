/**
 * Kolom tabel Daftar Pegawai (§4.1.5) — sumber tunggal untuk header, isi sel, filter per kolom, dan popup
 * "Filter Kolom" (Gambar 20/21: daftar kolom dikelompokkan per huruf awal, bisa dicari).
 * Kolom `nama_nip` (avatar + nama + NIP) selalu tampil dan tidak muncul di popup.
 */
import type { PegawaiRow } from './types'

export interface PegawaiColumn {
  key: string
  label: string
  /** Kolom terkunci selalu tampil dan tidak ada di popup Filter Kolom. */
  locked?: boolean
  /** Teks yang dipakai untuk filter kolom & isi sel. */
  value: (row: PegawaiRow) => string
}

export const fullName = (row: PegawaiRow): string =>
  [row.gelar_awal, row.nama].filter(Boolean).join(' ') + (row.gelar_akhir ? `, ${row.gelar_akhir}` : '')

export const COLUMNS: PegawaiColumn[] = [
  { key: 'nama_nip', label: 'Nama/NIP', locked: true, value: (r) => `${fullName(r)} ${r.nip}` },
  { key: 'nip_lama', label: 'NIP Lama', value: (r) => r.nip_lama },
  { key: 'golongan', label: 'KP (Gol)', value: (r) => r.golongan },
  { key: 'tmt_golongan', label: 'KP (TMT)', value: (r) => r.tmt_golongan },
  { key: 'jabatan', label: 'Jabatan', value: (r) => r.jabatan },
  { key: 'satuan_kerja', label: 'Satuan Kerja', value: (r) => r.satuan_kerja },
  { key: 'unit', label: 'Unit', value: (r) => r.unit },
  { key: 'eselon', label: 'Eselon', value: (r) => r.eselon },
  { key: 'jenis_pegawai', label: 'Jenis Pegawai', value: (r) => r.jenis_pegawai },
  { key: 'status_pegawai', label: 'Status Pegawai', value: (r) => r.status_pegawai },
  { key: 'jenis_kelamin', label: 'Jenis Kelamin', value: (r) => r.jenis_kelamin },
  { key: 'agama', label: 'Agama', value: (r) => r.agama },
  { key: 'alamat', label: 'Alamat', value: (r) => r.alamat },
  { key: 'email', label: 'Email', value: (r) => r.email },
]

/** Kolom bawaan sesuai Gambar 18. */
export const DEFAULT_COLUMN_KEYS: string[] = ['nama_nip', 'golongan', 'jabatan', 'satuan_kerja', 'unit']

export const COLUMN_BY_KEY: Record<string, PegawaiColumn> = Object.fromEntries(COLUMNS.map((c) => [c.key, c]))

/** Kolom yang boleh dipilih di popup, dikelompokkan per huruf awal label (A, E, J, K, N, S, U…). */
export function groupPickableColumns(query = ''): Array<{ letter: string; columns: PegawaiColumn[] }> {
  const needle = query.trim().toLowerCase()
  const groups = new Map<string, PegawaiColumn[]>()

  COLUMNS.filter((c) => !c.locked && c.label.toLowerCase().includes(needle))
    .sort((a, b) => a.label.localeCompare(b.label, 'id'))
    .forEach((column) => {
      const letter = column.label[0].toUpperCase()
      groups.set(letter, [...(groups.get(letter) ?? []), column])
    })

  return [...groups.entries()].map(([letter, columns]) => ({ letter, columns }))
}
