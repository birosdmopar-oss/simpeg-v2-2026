/**
 * Arti kode status cuti (`riwayat_cuti.status`) — BERBEDA dari `statusRiwayat.ts` Modul B (di sini 3 = disetujui atasan
 * langsung, 4 = ditangguhkan). Dipakai hanya sebagai cadangan tampilan bila item tidak membawa `label_status`; sumber
 * utama tetap server (PRD §6.11.4, FE dilarang menghitung tahap sendiri).
 */
import type { NadaStatus } from './types'

export const STATUS_CUTI = {
  PENGAJUAN: 0,
  DISETUJUI: 1,
  DITOLAK: 2,
  DISETUJUI_AL: 3,
  DITANGGUHKAN: 4,
} as const

export type StatusCutiKode = (typeof STATUS_CUTI)[keyof typeof STATUS_CUTI]

const TAMPIL: Record<StatusCutiKode, { label: string; nada: NadaStatus }> = {
  0: { label: 'Menunggu Atasan', nada: 'warning' },
  1: { label: 'Disetujui', nada: 'success' },
  2: { label: 'Ditolak', nada: 'danger' },
  3: { label: 'Menunggu PyB', nada: 'info' },
  4: { label: 'Ditangguhkan', nada: 'secondary' },
}

/** Cadangan label/nada untuk kode status cuti; kode tak dikenal → "Tidak diketahui" (abu-abu). */
export function tampilanStatusCuti(status: number): { label: string; nada: NadaStatus } {
  return TAMPIL[status as StatusCutiKode] ?? { label: 'Tidak diketahui', nada: 'neutral' }
}
