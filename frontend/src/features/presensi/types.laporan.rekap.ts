/**
 * Tipe Rekap Tukin (MAKE-049; README `Controllers/Api/Presensi` "Laporan Tukin MAKE-041": `GET presensi/tukin/rekap[-il]`).
 * Persen berupa string desimal server (tanpa aritmetika di FE). Baris yang gagal dihitung tetap ada dengan `galat`.
 */
import type { GalatBaris } from './types.laporan'

/** Potongan per kolom rekap (persen). */
export interface PenguranganRekap {
  TL: string
  PSW: string
  TK: string
  TA: string
  CUTI: string
  TB: string
}

export interface BarisRekapTukin {
  nip: string
  nama: string
  unit_es1: string
  unit_es2: string
  /** Null bila baris galat. */
  pengurangan: PenguranganRekap | null
  total_presensi: string | null
  total_skp: string | null
  total_potongan: string | null
  keterangan: string
  penyesuaian: { persen: string; alasan: string } | null
  galat: GalatBaris | null
}

export interface LingkupRekap {
  tipe: string
  id: number | null
  kode: string
  nama: string
}

export interface RekapTukin {
  items: BarisRekapTukin[]
  total: number
  page: number
  per_page: number
  bulan: string
  periode: { awal: string; akhir: string }
  lingkup: LingkupRekap | null
  jumlah_galat: number
}

export type FormatEksporRekap = 'xlsx' | 'pdf'
