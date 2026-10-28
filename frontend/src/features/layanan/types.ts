/**
 * Tipe bersama Modul C (Layanan & Approval). Bentuk mengikuti respons backend (PRD Fase 4 §6.2.4, §6.11.4):
 * server menyediakan `label_status`, `nada_status`, `tahap[]`, dan `aksi {}` — FE tidak menghitung status/tahap/hak.
 */

/** Nada badge dari server (`nada_status`) — sama dengan `UiBadge`. */
export type NadaStatus = 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info' | 'secondary'

export type StateTahap = 'done' | 'current' | 'todo'

/** Satu langkah stepper dari `tahap[]`. */
export interface TahapLayanan {
  kode: string
  label: string
  state: StateTahap
  nama_petugas: string | null
}

/** `aksi {}` per baris: kunci → boleh/tidak (server; FE hanya menyembunyikan/menonaktifkan item ⋮). */
export type AksiLayanan = Record<string, boolean>

/** Tampilan status yang dihitung server untuk satu pengajuan. */
export interface TampilanStatus {
  label_status: string
  nada_status: NadaStatus
  tahap: TahapLayanan[]
  aksi: AksiLayanan
}

/** Aksi proses cuti (`POST cuti/{id}/process`). */
export type AksiProsesCuti = 'setujui' | 'tolak' | 'tangguhkan'
