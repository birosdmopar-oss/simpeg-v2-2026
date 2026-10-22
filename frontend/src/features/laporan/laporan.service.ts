/**
 * Klien endpoint statistik F-03 (MAKE-036) lewat `api` (`@/lib/axios`, ADR-022):
 *   GET statistik/lingkup         lingkup yang boleh dipilih akun
 *   GET statistik/{jenis}         data statistik (`lingkup`, `id`)
 *   GET statistik/{jenis}/xlsx    unduh XLSX (blob; nama dari Content-Disposition)
 * Tidak ada data contoh di runtime — fixture hanya di `__tests__/`.
 */
import { api } from '@/lib/axios'

import { ambilBerkas, type BerkasUnduhan } from '@/features/kepegawaian/services/berkasUnduhan'

import { LAPORAN_TIPE, type LaporanTipe, type LingkupPilihan, type LingkupQuery, type LingkupTersedia, type StatistikData } from './types'

export const isLaporanTipe = (v: unknown): v is LaporanTipe => (LAPORAN_TIPE as readonly unknown[]).includes(v)

/** Nilai pemilih lingkup → query endpoint ('' = bawaan server: kementerian atau satker/unit akun). */
export function lingkupKeQuery(pilihan: LingkupPilihan): LingkupQuery {
  if (pilihan === '') return {}
  if (pilihan === 'kementerian') return { lingkup: 'kementerian' }
  const [tipe, id] = pilihan.split(':') as ['unit' | 'satker', string]
  return { lingkup: tipe, id }
}

const angka = (v: unknown): number => (v === null || v === undefined || v === '' ? 0 : Number(v))

/** MySQLi mengirim angka sebagai string; normalkan nilai/subtotal/total supaya aritmetika di halaman benar. */
function normalisasi(data: StatistikData): StatistikData {
  return {
    ...data,
    baris: data.baris.map((b) => ({ ...b, nilai: b.nilai.map(angka), total: angka(b.total) })),
    subtotal: data.subtotal.map(angka),
    total: angka(data.total),
  }
}

export const laporanService = {
  async lingkup(): Promise<LingkupTersedia> {
    // Interceptor `@/lib/axios` sudah membuka amplop {status, data}: `response.data` = payload (CR-059).
    const { data } = await api.get<LingkupTersedia>('/statistik/lingkup')
    return data
  },

  async statistik(tipe: LaporanTipe, pilihan: LingkupPilihan = ''): Promise<StatistikData> {
    const { data } = await api.get<StatistikData>(`/statistik/${tipe}`, { params: lingkupKeQuery(pilihan) })
    return normalisasi(data)
  },

  xlsx(tipe: LaporanTipe, pilihan: LingkupPilihan = ''): Promise<BerkasUnduhan> {
    const q = lingkupKeQuery(pilihan)
    const params: Record<string, string> = {}
    if (q.lingkup) params.lingkup = q.lingkup
    if (q.id) params.id = q.id
    return ambilBerkas(`/statistik/${tipe}/xlsx`, `statistik_${tipe}`, params)
  },
}
