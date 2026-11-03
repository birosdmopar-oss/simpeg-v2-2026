/**
 * Verifikasi publik surat cuti (PRD Fase 6 v1.0f Q6-23, AC-02.8; MAKE-065) — tanpa jwt:
 *   GET  publik/surat-cuti/{token}   data minimal surat (tanpa NIP, tanpa unduh PDF)
 * PDF ber-TTE (`publik/surat-cuti/{token}/pdf`, Q6-22) dibuka langsung dari QR tanda tangan, bukan dari halaman ini.
 */
import { api } from '@/lib/axios'

export interface VerifikasiSuratCuti {
  status_keaslian: 'asli'
  jenis_surat: string
  jenis_cuti: string | null
  penanda_tangan: string | null
  tanggal_tanda_tangan: string | null
  status_tte: 'ditandatangani' | 'belum'
}

const teks = (v: unknown): string | null => (typeof v === 'string' && v.trim() !== '' ? v : null)

export const suratCutiPublikService = {
  async verifikasi(token: string): Promise<VerifikasiSuratCuti> {
    const { data } = await api.get<Record<string, unknown>>(`/publik/surat-cuti/${encodeURIComponent(token)}`)
    return {
      status_keaslian: 'asli',
      jenis_surat: teks(data.jenis_surat) ?? 'Surat Cuti',
      jenis_cuti: teks(data.jenis_cuti),
      penanda_tangan: teks(data.penanda_tangan),
      tanggal_tanda_tangan: teks(data.tanggal_tanda_tangan),
      status_tte: data.status_tte === 'ditandatangani' ? 'ditandatangani' : 'belum',
    }
  },
}
