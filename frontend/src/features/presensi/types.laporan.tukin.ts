/**
 * Tipe Laporan Tukin per pegawai & daftar (MAKE-048; README `Controllers/Api/Presensi` "Laporan Tukin MAKE-041").
 * Persen berupa string desimal server (tanpa aritmetika di FE). Tipe bersama laporan ada di `types.laporan.ts`.
 */
import type { HarianLaporan } from './types.laporan'

/** Satu pegawai di daftar Laporan Tukin (`GET presensi/tukin/pegawai[-il]`). */
export interface PegawaiTukin {
  nip: string
  nama: string
  gol_ruang: string
  pangkat: string
  jabatan: string
  eselon: string
  unit: string
  satker: string
  pendidikan: { strata: string; jurusan: string; institusi: string }
  jenis_pegawai: string
}

export interface BulanTersedia {
  bulan: string
  label: string
}

export interface DaftarPegawaiTukin {
  items: PegawaiTukin[]
  total: number
  page: number
  per_page: number
  bulan_tersedia: BulanTersedia[]
}

export interface IdentitasLaporan {
  nip: string
  nama: string
  /** Hanya PNS; PPPK/PTT null. */
  gol_ruang: string | null
  satker: string
  jabatan: string
  kelas_jabatan: number | null
  predikat_skp: string | null
}

export interface PeriodeLaporan {
  awal: string
  akhir: string
  /** Tanggal terakhir yang dihitung; sebelum `akhir` = periode berjalan. */
  dihitung_sampai: string
  sumber: 'bkn' | 'bawaan'
}

/** Laporan Tukin satu pegawai (`GET presensi/tukin/laporan/{nip}`). */
export interface LaporanTukinPegawai {
  identitas: IdentitasLaporan
  periode: PeriodeLaporan
  harian: HarianLaporan[]
  /** Hitungan kode kejadian (TL1..3, PSW1..3, TPM, TPP, TK, CS). */
  jumlah: Record<string, number>
  /** Potongan per kolom (TL, PSW, TA, TK, CUTI) dalam persen. */
  rincian: Record<string, string>
  total_presensi: string
  total_skp: string
  total_potongan: string
  alasan: string | null
  penyesuaian: { persen: string; alasan: string } | null
  keterangan: string
}
