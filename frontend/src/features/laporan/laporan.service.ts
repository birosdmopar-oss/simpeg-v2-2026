/**
 * Layanan laporan statistik. SEMENTARA memakai data contoh; kontrak fungsi = yang akan diisi `api` (`@/lib/axios`)
 * saat endpoint hr/chart/* tersedia (filter unit/satker dikerjakan backend; di sini hanya meniru).
 */
import { LAPORAN } from './laporan.mock'
import { LAPORAN_TIPE, type LaporanData, type LaporanTipe } from './types'

export const isLaporanTipe = (v: unknown): v is LaporanTipe => (LAPORAN_TIPE as readonly unknown[]).includes(v)

export const laporanService = {
  async get(tipe: LaporanTipe, unit?: string): Promise<LaporanData> {
    const data = LAPORAN[tipe]
    // Filter unit hanya berlaku untuk laporan yang barisnya = unit kerja; unit tak dikenal → seluruh data.
    if (!unit || tipe === 'struktural') return data
    const baris = data.baris.filter((b) => b.label === unit)
    return baris.length > 0 ? { ...data, baris } : data
  },

  /** Pilihan filter unit/satker. */
  async unitOptions(): Promise<Array<{ value: string; label: string }>> {
    return LAPORAN['unit-kerja'].baris.map((b) => ({ value: b.label, label: b.label }))
  },
}
