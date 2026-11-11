/** F-11 Survei Kepuasan Layanan / SKL (MAKE-084) — kontrak API `survei-layanan*`. */

/** Label rating [K `views/template/modal/survey_kepuasan.php:28-37`]. */
export const LABEL_RATING: Record<number, string> = {
  5: 'Sangat Memuaskan',
  4: 'Memuaskan',
  3: 'Cukup Memuaskan',
  2: 'Kurang Memuaskan',
  1: 'Tidak Memuaskan',
}

/** `GET survei-layanan/saya`. */
export interface TagihanSkl {
  jenis_rwy: number
  id_rwy: number
  jenis_layanan: string
  info: string
}

export interface KirimSkl {
  jenis_rwy: number
  id_rwy: number
  rate: number
  kritik_saran: string | null
}

export interface BarisSkl {
  id: number
  jenis_rwy: number
  jenis_layanan: string
  nip: string
  nama: string
  unit: string
  satker: string
  kritik_saran: string | null
  dinilai_at: string
  rate: number
}

export interface QuerySkl {
  jenis_rwy?: number
  rate?: number
  dari?: string
  sampai?: string
  q?: string
  page?: number
  per_page?: number
}

export interface RingkasanSkl {
  periode: { dari: string; sampai: string }
  per_jenis: { jenis_rwy: number; jenis_layanan: string; jumlah: number; rata_rata: number | null; sebaran: Record<string, number> }[]
  total: { jumlah: number; rata_rata: number | null }
}
