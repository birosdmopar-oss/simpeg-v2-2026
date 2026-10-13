/**
 * Status verifikasi riwayat/usulan Modul B (kolom `status` tabel riwayat_*, ikut legacy): 0 Menunggu, 1 Disetujui,
 * 2 Ditolak, 10 Dihapus. 3 Diproses hanya dipakai bila flag status Diproses aktif (default nonaktif).
 * Dipakai StatusBadge (shared) dan tipe Modul B (`features/kepegawaian/types.ts`).
 */
export const STATUS_RIWAYAT = {
  MENUNGGU: 0,
  DISETUJUI: 1,
  DITOLAK: 2,
  DIPROSES: 3,
  DIHAPUS: 10,
} as const

export type StatusRiwayat = (typeof STATUS_RIWAYAT)[keyof typeof STATUS_RIWAYAT]

export const STATUS_RIWAYAT_LABELS: Record<StatusRiwayat, string> = {
  0: 'Menunggu',
  1: 'Disetujui',
  2: 'Ditolak',
  3: 'Diproses',
  10: 'Dihapus',
}
