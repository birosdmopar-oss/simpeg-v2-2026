/**
 * Item ⋮ per baris Petugas Layanan (AGENTS.md §1): Edit → Aktifkan/Nonaktifkan → Hapus (`danger`, paling bawah). Hak tampil
 * dari `aksi {}` server (role 1 saja); FE hanya menyembunyikan item.
 */
import type { RowAction } from '@/shared/components/rowActions'

import type { PetugasLayanan } from './services/petugasLayanan.service'

export function aksiBarisPetugas(row: PetugasLayanan, { busy = false }: { busy?: boolean } = {}): RowAction[] {
  return [
    { key: 'edit', label: 'Edit', hidden: !row.aksi.ubah, disabled: busy },
    { key: 'aktifkan', label: 'Aktifkan', hidden: !row.aksi.aktifkan, disabled: busy },
    { key: 'nonaktifkan', label: 'Nonaktifkan', hidden: !row.aksi.nonaktifkan, disabled: busy },
    { key: 'hapus', label: 'Hapus', danger: true, hidden: !row.aksi.hapus, disabled: busy },
  ]
}

/** Label baris untuk aria-label ⋮: "Aksi untuk PIC <nama>". */
export function labelAksiPetugas(row: PetugasLayanan): string {
  return `Aksi untuk PIC ${row.nama ?? row.nip}`
}
