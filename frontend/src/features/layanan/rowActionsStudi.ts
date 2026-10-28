/**
 * Item ⋮ per baris TB/IB (AGENTS.md §1): Edit → Lihat detail → Setujui → Tolak → Perpanjangan (TB) → Unduh surat → Hapus
 * (`danger`, paling bawah). Hak tampil dari `aksi {}` server; pagar jenis (`perpanjanganDiizinkan`) hanya tambahan.
 */
import type { RowAction } from '@/shared/components/rowActions'

import type { StudiConfig } from './studiConfig'
import type { StudiItem } from './services/studi.service'

export interface OpsiAksiStudi {
  /** Tampilkan "Lihat detail" (false di halaman detail sendiri). */
  detail?: boolean
  /** Sedang memproses baris ini → item tampil tetapi nonaktif. */
  busy?: boolean
}

export function aksiBarisStudi(row: StudiItem, cfg: StudiConfig, { detail = true, busy = false }: OpsiAksiStudi = {}): RowAction[] {
  const a = row.aksi
  return [
    { key: 'edit', label: 'Edit', hidden: !a.ubah, disabled: busy },
    { key: 'detail', label: 'Lihat detail', hidden: !detail, disabled: busy },
    { key: 'setujui', label: 'Setujui', hidden: !a.proses, disabled: busy },
    { key: 'tolak', label: 'Tolak', hidden: !a.proses, disabled: busy },
    { key: 'perpanjangan', label: 'Perpanjangan', hidden: !(a.perpanjangan === true && cfg.perpanjanganDiizinkan), disabled: busy },
    { key: 'surat', label: 'Unduh surat', hidden: !a.unduh_surat, disabled: busy },
    { key: 'hapus', label: 'Hapus', danger: true, hidden: !a.hapus, disabled: busy },
  ]
}

/** Label baris untuk aria-label ⋮: "Aksi untuk <judul> <nama>". */
export function labelAksiStudi(row: StudiItem, cfg: StudiConfig): string {
  return `Aksi untuk ${cfg.judul} ${row.nama ?? row.nip}`
}
