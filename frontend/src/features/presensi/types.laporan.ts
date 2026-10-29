/**
 * Tipe bersama halaman laporan Presensi 5C (PRD `docs/fase5/PRD_Fase5C_FE-Laporan-Regresi-SignOff.md` §6, §7). Bentuk data
 * mengikuti README `backend/app/Controllers/Api/Presensi` (Laporan Tukin MAKE-041, Rekap MAKE-042, Penyesuaian MAKE-045).
 * `api` sudah membuka amplop `{status, data}`; tipe di sini adalah isi `data`.
 */

/** Nilai `status` satu hari pada `harian[]` laporan per pegawai (`HasilHarian::$status`). */
export type StatusHarian = 'libur' | 'tugas_belajar' | 'cuti' | 'konket' | 'hadir' | 'TK' | 'TPM' | 'TPP' | 'di_luar_masa_kerja'

/**
 * Kode kejadian satu hari (`HasilHarian::$kategori`): TL1..3, PSW1..3 (terlambat / pulang sebelum waktunya), TPM / TPP
 * (tidak presensi masuk / pulang), TK (tanpa keterangan), CS (cuti sakit hari ke-15+).
 */
export type KodeKejadian = 'TL1' | 'TL2' | 'TL3' | 'PSW1' | 'PSW2' | 'PSW3' | 'TPM' | 'TPP' | 'TK' | 'CS'

/** Satu baris tabel harian laporan per pegawai (`GET presensi/tukin/laporan/{nip}` → `harian[]`). */
export interface HarianLaporan {
  tanggal: string
  status: StatusHarian
  /** Kode kejadian hari itu (CR-075, aditif); kosong/absen = tidak ada. Dipetakan oleh `statusPresensi.ts`. */
  kode?: string[]
  jam_masuk: string | null
  jam_pulang: string | null
  /** Persen potongan, string desimal server (mis. "0.40"). */
  potongan: string
  keterangan: string
  kategori_konket: number | null
}

/** Galat terkendali satu baris rekap (AC-D08.7): baris tetap tampil dengan penanda. */
export interface GalatBaris {
  code: string
  message: string
}

/** Lingkup laporan `unit_satker`: `0` Kementerian, `<id_unit>`, atau `sat_<id_satker>`. */
export type LingkupLaporan = string

/** Nilai filter bersama halaman laporan (PRD 5C §7.1). */
export interface FilterLaporan {
  /** `YYYY-MM`. */
  bulan: string
  /** Hanya dipakai role 1; role 3/4 dipaksa server. */
  idUnit: string
  idSatker: string
  /** Id master `jenis-pegawai` terpilih; kosong = bawaan server. */
  jenisPegawai: string[]
  search: string
  page: number
  perPage: number
}

/** Parameter query yang dikirim ke API laporan (kunci mengikuti README; nilai kosong tidak dikirim). */
export interface ParamLaporan {
  bulan: string
  unit_satker?: string
  jenis_pegawai?: string[]
  search?: string
  page: number
  per_page: number
}

export const PER_PAGE_LAPORAN = [10, 25, 50, 100] as const
