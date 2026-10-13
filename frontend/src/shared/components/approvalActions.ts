import type { RowAction } from './rowActions'
import { STATUS_RIWAYAT, type StatusRiwayat } from './statusRiwayat'

export type ApprovalAksi = 'setujui' | 'tolak'

/** Payload `confirm` ApprovalDialog = body `POST …/{id}/process`. */
export interface ApprovalPayload {
  aksi: ApprovalAksi
  reason_note: string
}

export interface ApprovalActionOptions {
  /** Hak proses (descriptor `can_process`). Tanpa hak → item disembunyikan. */
  canProcess: boolean
  /** Status baris; Setujui/Tolak hanya berlaku untuk baris Menunggu (atau Diproses bila flag aktif). */
  status: StatusRiwayat | number
  diprosesEnabled?: boolean
  /** Sedang memproses baris ini → item tampil tetapi nonaktif. */
  busy?: boolean
}

/**
 * Item ⋮ "Setujui" dan "Tolak" (grup "aksi lain", AGENTS.md §1): sisipkan SESUDAH Edit/ubah status/urutan dan
 * SEBELUM "Hapus", mis. `[edit, ...approvalRowActions(opts), hapus]`. Pilihan item membuka ApprovalDialog dengan
 * `mode` = key item ('setujui' | 'tolak').
 */
export function approvalRowActions({ canProcess, status, diprosesEnabled = false, busy = false }: ApprovalActionOptions): RowAction[] {
  const pending = status === STATUS_RIWAYAT.MENUNGGU || (diprosesEnabled && status === STATUS_RIWAYAT.DIPROSES)
  const hidden = !canProcess || !pending
  return [
    { key: 'setujui', label: 'Setujui', hidden, disabled: busy },
    { key: 'tolak', label: 'Tolak', hidden, disabled: busy },
  ]
}
