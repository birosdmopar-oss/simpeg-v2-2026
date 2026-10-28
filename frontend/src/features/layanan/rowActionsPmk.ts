/**
 * Item ⋮ per baris pengajuan PMK (AGENTS.md §1): Edit → Lihat detail → Proses/Setujui/Tolak (PIC) → Unduh berkas → Hapus
 * (`danger`, paling bawah). Hak dari server: `aksi.ubah`/`aksi.hapus` dan `aksi_tersedia[]` (aksi proses yang boleh pada
 * status sekarang); "Unduh …" hanya bila berkasnya ada. Label unduhan dari `opsi.berkas` (server).
 */
import type { RowAction } from '@/shared/components/rowActions'

import { KOLOM_BERKAS_PMK } from './services/pmk.service'
import type { PmkItem } from './types'

export interface OpsiAksiPmk {
  busy?: boolean
  detail?: boolean
  /** kolom berkas → label dari `GET pmk/opsi`. */
  labelBerkas?: Record<string, string>
}

const LABEL_BAWAAN: Record<string, string> = {
  file_kontrak: 'Surat Kontrak Kerja',
  file_paklaring: 'Surat Paklaring',
  file_surat_phk: 'Surat PHK',
  file_slip_gaji: 'Slip Gaji',
}

export function aksiBarisPmk(row: PmkItem, { busy = false, detail = true, labelBerkas = {} }: OpsiAksiPmk = {}): RowAction[] {
  const tersedia = (a: string) => row.aksi_tersedia.includes(a as never)
  return [
    { key: 'edit', label: 'Edit', hidden: !row.aksi.ubah, disabled: busy },
    { key: 'detail', label: 'Lihat detail', hidden: !detail, disabled: busy },
    { key: 'proses', label: 'Proses', hidden: !row.aksi.proses || !tersedia('proses'), disabled: busy },
    { key: 'setujui', label: 'Setujui', hidden: !row.aksi.proses || !tersedia('setujui'), disabled: busy },
    { key: 'tolak', label: 'Tolak', hidden: !row.aksi.proses || !tersedia('tolak'), disabled: busy },
    ...KOLOM_BERKAS_PMK.map((kolom) => ({
      key: `berkas:${kolom}`,
      label: `Unduh ${labelBerkas[kolom] ?? LABEL_BAWAAN[kolom] ?? kolom}`,
      hidden: !row.berkas[kolom],
      disabled: busy,
    })),
    { key: 'hapus', label: 'Hapus', danger: true, hidden: !row.aksi.hapus, disabled: busy },
  ]
}

/** Label baris untuk aria-label ⋮ (AGENTS §1 butir 7): "Aksi untuk PMK <nama> <periode>"; NIP hanya bila nama kosong. */
export function labelAksiPmk(row: PmkItem, periode: string): string {
  return `Aksi untuk PMK ${row.nama || row.nip} ${periode}`
}
