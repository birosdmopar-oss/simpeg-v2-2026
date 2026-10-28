/**
 * Opsi statis form cuti. Aturan per jenis (memakai durasi, lampiran wajib) datang dari server di `GET cuti/opsi`
 * (`jenis_cuti[].pakai_durasi`, `lampiran_wajib`; satu sumber dengan validasi server, ADR-025/026) — tidak ada
 * konstanta kode jenis di FE.
 */

export const DURASI_OPSI = [
  { value: '1', label: '1 bulan' },
  { value: '2', label: '2 bulan' },
  { value: '3', label: '3 bulan' },
]

/** Lampiran bukti cuti: 5 MB, jpg/jpeg/png/gif/pdf (PRD §6.2.3 `file_bukti`). */
export const BERKAS_BUKTI = { allowed: ['jpg', 'jpeg', 'png', 'gif', 'pdf'], maxMb: 5 } as const
