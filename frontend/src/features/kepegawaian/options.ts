/**
 * Opsi dropdown form Detail Pegawai + daftar tab riwayat.
 *
 * ASUMSI: opsi di bawah adalah contoh statis. Saat B-03 tersambung, agama/provinsi/kota/jenis pegawai/status
 * diambil dari Master Data (Fase 2, mis. /master/agama) — komponen form tidak perlu berubah, cukup sumber opsinya.
 */
import type { RiwayatMenu } from './types'

export interface Option {
  value: string
  label: string
}

const opts = (values: string[]): Option[] => values.map((v) => ({ value: v, label: v }))

export const PROVINSI = opts(['DKI Jakarta', 'Jawa Barat', 'Jawa Tengah', 'Jawa Timur', 'Bali'])

export const KOTA_BY_PROVINSI: Record<string, Option[]> = {
  'DKI Jakarta': opts(['Jakarta Pusat', 'Jakarta Selatan', 'Jakarta Timur']),
  'Jawa Barat': opts(['Kota Bandung', 'Kota Bekasi', 'Kota Bogor']),
  'Jawa Tengah': opts(['Kota Semarang', 'Kota Surakarta']),
  'Jawa Timur': opts(['Kota Surabaya', 'Kota Malang']),
  Bali: opts(['Kota Denpasar']),
}

export const JENIS_KELAMIN = opts(['Laki-Laki', 'Perempuan'])
export const STATUS_PERKAWINAN = opts(['Menikah', 'Tidak Menikah'])
export const AGAMA = opts(['Islam', 'Kristen', 'Katolik', 'Hindu', 'Buddha', 'Konghucu'])
export const JENIS_KERABAT = opts(['Ayah', 'Ibu', 'Suami/Istri', 'Anak', 'Kakak', 'Adik'])
export const JENIS_PEGAWAI = opts(['PNS', 'PPPK', 'PTT'])
export const STATUS_PEGAWAI = opts(['Aktif', 'Tugas Belajar', 'Pensiun'])
export const JENIS_STATUS = opts(['Biasa', 'Perbantuan', 'Dipekerjakan'])

/**
 * Tab riwayat Detail Pegawai (Gambar 22–24: tab tergulir + popup "Semua Menu"). Urutan mengikuti mockup.
 * Hanya `data-umum` yang punya isi pada Fase 3 tahap UI ini; sisanya dipenuhi task B-07…B-18 (kolom `task`).
 */
export const RIWAYAT_MENUS: RiwayatMenu[] = [
  { key: 'data-umum', label: 'Data Umum', task: 'B-03', ready: true },
  { key: 'data-alamat', label: 'Data Alamat', task: 'B-16', ready: false },
  { key: 'data-keluarga', label: 'Data Keluarga', task: 'B-16', ready: false },
  { key: 'riwayat-pendidikan', label: 'Riwayat Pendidikan', task: 'B-10', ready: false },
  { key: 'riwayat-pelatihan', label: 'Riwayat Pelatihan', task: 'B-11', ready: false },
  { key: 'kursus-seminar', label: 'Kursus/Seminar', task: 'B-11', ready: false },
  { key: 'riwayat-organisasi', label: 'Riwayat Organisasi', task: 'B-17', ready: false },
  { key: 'laporan-kerja-harian', label: 'Laporan Kerja Harian', task: 'B-12', ready: false },
  { key: 'skp-tahunan', label: 'SKP Tahunan', task: 'B-12', ready: false },
  { key: 'skp-periodik', label: 'SKP Periodik', task: 'B-12', ready: false },
  { key: 'riwayat-jabatan', label: 'Riwayat Jabatan', task: 'B-07', ready: false },
  { key: 'riwayat-pangkat', label: 'Riwayat Pangkat', task: 'B-08', ready: false },
  { key: 'riwayat-kgb', label: 'Riwayat KGB', task: 'B-09', ready: false },
  { key: 'riwayat-konket', label: 'Riwayat Konket', task: 'B-13', ready: false },
  { key: 'riwayat-hukdis', label: 'Riwayat Hukdis', task: 'B-14', ready: false },
  { key: 'angka-kredit', label: 'Angka Kredit', task: 'B-15', ready: false },
  { key: 'karpeg-karis-karsu', label: 'Karpeg / Karis / Karsu', task: 'B-17', ready: false },
  { key: 'tanda-jasa', label: 'Tanda Jasa', task: 'B-17', ready: false },
]
