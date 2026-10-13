/**
 * Kolom tabel Daftar Pegawai (§4.1.5) — sumber tunggal untuk header, isi sel, filter per kolom, dan popup
 * "Filter Kolom" (Gambar 20/21: daftar kolom dikelompokkan per huruf awal, bisa dicari).
 * Kolom `nama_nip` (avatar + nama + NIP) selalu tampil dan tidak muncul di popup. Nilai kolom = kolom respons
 * `GET pegawai` (MAKE-010): biodata `pegawai` + snapshot jabatan (`pegawai_mutasi_jabatan`) + pangkat (`pegawai_kp`).
 * Kolom legacy lain (90 kolom `L_employee::list`) belum dibawa (kartu lanjutan); ekspor memakai kolom yang sama (MAKE-014).
 */
import type { PegawaiListItem } from './types'

export interface PegawaiColumn {
  key: string
  label: string
  /** Kolom terkunci selalu tampil dan tidak ada di popup Filter Kolom. */
  locked?: boolean
  /** Teks yang dipakai untuk filter kolom & isi sel. */
  value: (row: PegawaiListItem) => string
}

export const fullName = (row: Pick<PegawaiListItem, 'glr_awal' | 'nama' | 'glr_akhir'>): string =>
  [row.glr_awal, row.nama].filter(Boolean).join(' ') + (row.glr_akhir ? `, ${row.glr_akhir}` : '')

const JENIS_KELAMIN: Record<number, string> = { 1: 'Laki-laki', 2: 'Perempuan' }

const text = (value: string | null | undefined): string => value ?? ''

export const COLUMNS: PegawaiColumn[] = [
  { key: 'nama_nip', label: 'Nama/NIP', locked: true, value: (r) => `${fullName(r)} ${r.nip}` },
  { key: 'nip_lama', label: 'NIP Lama', value: (r) => text(r.nip_lama) },
  { key: 'jabatan', label: 'Jabatan', value: (r) => text(r.jabatan) },
  { key: 'satker', label: 'Satuan Kerja', value: (r) => text(r.satker) },
  { key: 'unit', label: 'Unit Kerja', value: (r) => text(r.unit) },
  { key: 'gol_ruang', label: 'KP (Gol)', value: (r) => text(r.gol_ruang) },
  { key: 'pangkat', label: 'Pangkat', value: (r) => text(r.pangkat) },
  { key: 'jenis_pegawai', label: 'Jenis Pegawai', value: (r) => text(r.jenis_pegawai) },
  { key: 'jenis_status', label: 'Jenis Status', value: (r) => text(r.jenis_status) },
  { key: 'tmt_status', label: 'TMT Status', value: (r) => text(r.tmt_status) },
  { key: 'jenis_kelamin', label: 'Jenis Kelamin', value: (r) => JENIS_KELAMIN[r.jenis_kelamin] ?? '' },
  { key: 'agama', label: 'Agama', value: (r) => text(r.agama) },
]

/** Kolom bawaan. */
export const DEFAULT_COLUMN_KEYS: string[] = ['nama_nip', 'jabatan', 'satker', 'gol_ruang', 'jenis_pegawai']

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
