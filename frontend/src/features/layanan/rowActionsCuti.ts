/**
 * Item ⋮ per baris cuti (AGENTS.md §1): Edit → Lihat detail → Setujui/Tolak/Tangguhkan → Setujui/Tolak/Tangguhkan atas nama → Tunda → Unduh surat
 * → Hapus (`danger`, paling bawah). Semua hak dari `aksi {}` server — FE hanya menyembunyikan/menonaktifkan item.
 * Terbitkan TTE ditambahkan oleh paket e-sign (Paket 3).
 */
import type { RowAction } from '@/shared/components/rowActions'

import type { CutiItem } from './types'

export interface OpsiAksiCuti {
  /** Sedang memproses baris ini → item tampil tetapi nonaktif. */
  busy?: boolean
  /** Tampilkan "Lihat detail" (false di halaman detail sendiri). */
  detail?: boolean
}

export function aksiBarisCuti(row: CutiItem, { busy = false, detail = true }: OpsiAksiCuti = {}): RowAction[] {
  const a = row.aksi
  return [
    { key: 'edit', label: 'Edit', hidden: !a.ubah, disabled: busy },
    { key: 'detail', label: 'Lihat detail', hidden: !detail, disabled: busy },
    { key: 'setujui', label: 'Setujui', hidden: !a.proses, disabled: busy },
    { key: 'tolak', label: 'Tolak', hidden: !a.proses, disabled: busy },
    { key: 'tangguhkan', label: 'Tangguhkan', hidden: !a.tangguhkan, disabled: busy },
    { key: 'setujui-atas-nama', label: 'Setujui atas nama', hidden: !a.proses_atas_nama, disabled: busy },
    { key: 'tolak-atas-nama', label: 'Tolak atas nama', hidden: !a.proses_atas_nama, disabled: busy },
    { key: 'tangguhkan-atas-nama', label: 'Tangguhkan atas nama', hidden: !a.tangguhkan_atas_nama, disabled: busy },
    { key: 'tunda', label: 'Tunda', hidden: !a.tunda, disabled: busy },
    { key: 'surat', label: 'Unduh surat', hidden: !a.unduh_surat, disabled: busy },
    { key: 'hapus', label: 'Hapus', danger: true, hidden: !a.hapus, disabled: busy },
  ]
}

/** Label baris untuk aria-label ⋮: "Aksi untuk cuti <nama> <periode>". */
export function labelAksiCuti(row: CutiItem, periode: string, tampilkanNama: boolean): string {
  return `Aksi untuk cuti ${tampilkanNama ? row.nama + ' ' : ''}${periode}`
}
