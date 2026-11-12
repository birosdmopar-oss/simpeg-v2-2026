/**
 * Tipe halaman Arsip (F-10, MAKE-091). Bentuk mengikuti kontrak PRD F-10 §8.1 (daftar kolom tertutup): `path`, `url`,
 * `basename`, dan `document_id` tidak pernah dikirim server, jadi tidak ada di tipe ini.
 */

/** Status berkas di disk: ada / hilang (cocok pola, tidak ada di disk) / tak dapat dibuka (path tidak cocok pola). */
export type StatusBerkasArsip = 'ada' | 'hilang' | 'tak_dapat_dibuka'

/** Jalur buka: `publik` = URL publik berkas legacy (Nginx); `privat` = endpoint `buka` (berkas unggahan v2); null = tak ada URL. */
export type AksesBerkasArsip = 'publik' | 'privat' | null

/** `pratinjau` (pdf/jpg/jpeg/png/gif) dibuka di popup; `unduh` selalu diunduh. */
export type JenisBukaArsip = 'pratinjau' | 'unduh'

/** Kunci folder semu "Semua berkas" di URL (`/arsip/:nip/semua`). */
export const FOLDER_SEMUA = 'semua'
/** Kunci folder baris tanpa jenis (`id_riwayat` NULL; F10-29). */
export const FOLDER_TANPA_JENIS = 'tanpa_jenis'
/** Kode `jenis_rwy` folder "Belum Terhubung" (F10-29). */
export const ID_BELUM_TERHUBUNG = 0

export interface ArsipPegawaiRingkas {
  nip: string
  nama: string
  glr_depan: string | null
  glr_belakang: string | null
}

export interface ArsipFolder {
  /** Kode `jenis_rwy`; null = folder "Tanpa Jenis". */
  id_jenis_rwy: number | null
  kode: string
  label: string
  urutan: number | null
  jumlah: number
  jumlah_hilang: number
  jumlah_tak_dapat_dibuka: number
}

/** `aksi` tingkat halaman. Tahap 1: `tulis` false dan `jenis_hubungkan` kosong. */
export interface ArsipAksiHalaman {
  tulis: boolean
  jenis_hubungkan: number[]
}

/** `GET pegawai/{nip}/arsip`. */
export interface ArsipFolderResponse {
  pegawai: ArsipPegawaiRingkas
  folder: ArsipFolder[]
  total: number
  total_hilang: number
  total_tak_dapat_dibuka: number
  penyajian_berkas_legacy: 'publik' | 'privat'
  aksi: ArsipAksiHalaman
}

/** `aksi` per baris. Tahap 1 selalu false; Tahap 2 dihitung server. */
export interface ArsipAksiBaris {
  edit: boolean
  hubungkan: boolean
  hapus: boolean
}

export interface ArsipBerkas {
  id_attachment: number
  nama_arsip: string
  tag: string | null
  display_name: string | null
  id_riwayat: number | null
  jenis_riwayat: string
  id_entri: string | null
  ringkasan_riwayat: string
  file_type: string | null
  created_at: string | null
  berkas_ada: boolean
  status_berkas: StatusBerkasArsip
  akses_berkas: AksesBerkasArsip
  /** Publik: URL absolut/relatif berkas legacy. Privat: path API relatif ke `buka`. Null: hilang / tak dapat dibuka. */
  url_berkas: string | null
  jenis_buka: JenisBukaArsip | null
  aksi: ArsipAksiBaris
}

/** `GET pegawai/{nip}/arsip/berkas`. */
export interface ArsipBerkasResponse {
  items: ArsipBerkas[]
  total: number
  page: number
  per_page: number
}

export interface ArsipBerkasQuery {
  /** Angka (`jenis_rwy`) atau `tanpa_jenis`; kosong = semua berkas. */
  id_jenis_rwy?: number | typeof FOLDER_TANPA_JENIS
  q?: string
  sort?: 'id' | 'nama' | 'jenis' | 'created_at'
  order?: 'asc' | 'desc'
  page?: number
  per_page?: number
}
