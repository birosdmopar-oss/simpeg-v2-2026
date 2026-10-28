/**
 * Petunjuk TAMPILAN form per jenis cuti (PRD §6.2.3, §6.11.1). Hanya menentukan field mana yang diperlihatkan; seluruh
 * validasi, lama cuti, `tgl_akhir` jenis Besar, dan kuota tetap dari server (ADR-025/026, §6.11.3).
 */

/** Jenis 2 (Besar): `tgl_akhir` dihitung server dari `durasi` (1–3 bulan); kolom tanggal akhir tidak diisi pengguna. */
export const JENIS_PAKAI_DURASI = 2

/** Jenis 3 (Sakit): klien legacy mewajibkan lampiran bukti; server tetap yang menegakkan. */
export const JENIS_BUKTI_WAJIB = 3

/** Jenis 1 (Tahunan): panel kuota dari `POST cuti/hitung-lama`. */
export const JENIS_PANEL_KUOTA = 1

export const DURASI_OPSI = [
  { value: '1', label: '1 bulan' },
  { value: '2', label: '2 bulan' },
  { value: '3', label: '3 bulan' },
]

/** Lampiran bukti cuti: 5 MB, jpg/jpeg/png/gif/pdf (PRD §6.2.3 `file_bukti`). */
export const BERKAS_BUKTI = { allowed: ['jpg', 'jpeg', 'png', 'gif', 'pdf'], maxMb: 5 } as const
