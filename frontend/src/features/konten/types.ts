/**
 * Konten admin Fase 7 (Jalur A) — tipe tampilan untuk komponen yang dipasang di beranda (PRD Fase 7 v2.0 §7).
 * Bentuk API (draf, dikunci saat BE F-04/F-05/F-07 masuk): id bisa INT atau string → dinormalkan ke string di service.
 */

export type KontenApiId = number | string

export interface PopupAktifApi {
  id: KontenApiId
  gambar_url: string
  url?: string | null
  keterangan?: string | null
}

export interface BannerAktifApi {
  id: KontenApiId
  gambar_url: string
  url?: string | null
  keterangan?: string | null
}

export interface BeritaRingkasApi {
  id: KontenApiId
  judul: string
  ringkasan?: string | null
  kategori?: string | null
  /** ISO 8601 (waktu terbit). */
  tanggal?: string | null
  foto_url?: string | null
}

export interface BeritaListApi {
  items?: BeritaRingkasApi[]
  total?: number
}

export interface PopupAktif {
  id: string
  gambar_url: string
  url: string | null
  keterangan: string | null
}

export type BannerAktif = PopupAktif

export interface BeritaRingkas {
  id: string
  judul: string
  ringkasan: string
  kategori: string
  tanggal: string | null
  foto_url: string | null
}

/** Spesifikasi target popup/banner/form digital (PRD Fase 7 v2.0): "0" = kementerian, "<id_unit>", "sat_<id_satker>"; NIP pegawai. */
export interface SpesifikasiTarget {
  unit_satker: string[]
  pegawai: string[]
  kecuali: string[]
}

/** Jenis konten bergambar yang dikelola role 1 (popup/banner berbagi bentuk dan penargetan). */
export type JenisKontenGambar = 'popup' | 'banner'

/** Baris daftar kelola (GET /konten/{popup|banner}); draf, dikunci saat BE F-04/F-05 masuk. */
export interface KontenGambarRowApi {
  id: KontenApiId
  keterangan?: string | null
  url?: string | null
  gambar_url?: string | null
  /** DATE (Y-m-d). */
  tgl_mulai: string
  tgl_akhir: string
  urutan?: number | null
  /** 1 aktif, 2 tidak aktif, 10 dihapus. */
  status?: number | string | null
  jumlah_penerima?: number | null
}

export interface KontenGambarRow {
  id: string
  keterangan: string
  url: string
  gambar_url: string | null
  tgl_mulai: string
  tgl_akhir: string
  jam_mulai: string
  jam_akhir: string
  urutan: number
  status: '1' | '2' | '10'
  jumlah_penerima: number
}

export interface KontenGambarForm {
  keterangan: string
  url: string
  tgl_mulai: string
  tgl_akhir: string
  /** HH:mm WIB; hanya popup (DATETIME), banner DATE. */
  jam_mulai: string
  jam_akhir: string
  target: SpesifikasiTarget
}

export interface KontenGambarListApi {
  items?: KontenGambarRowApi[]
  total?: number
}

/** Berita kelola (draf kontrak PRD §5.5): status 0 menunggu persetujuan, 1 disetujui, 2 ditolak/nonaktif, 10 dihapus. */
export type StatusBerita = '0' | '1' | '2' | '10'

export interface BeritaKelolaRowApi {
  id: number | string
  judul: string
  kategori?: string | null
  id_kategori?: number | string | null
  penulis?: string | null
  publish_at?: string | null
  ada_lampiran?: boolean
  status?: number | string | null
}

export interface BeritaKelolaRow {
  id: string
  judul: string
  kategori: string
  id_kategori: string
  penulis: string
  publish_at: string
  ada_lampiran: boolean
  status: StatusBerita
}

export interface BeritaForm {
  judul: string
  id_kategori: string
  /** HTML terbatas dari UiTeksSederhana. */
  isi: string
  /** Y-m-d (WIB). */
  publish_at: string
  /** HH:mm (WIB), jam terbit seperti `pub_time` legacy. */
  publish_jam: string
  /** Target tunggal: "0" seluruh kementerian, "<id_unit>", atau "sat_<id_satker>" (berita tidak memakai daftar pegawai). */
  target: string
}

/** Lampiran berita (satu berkas JPG/JPEG/PNG/GIF/PDF ≤ 5 MB, paritas legacy); `url` relatif terhadap base API. */
export interface LampiranBerita {
  nama: string
  tipe: string
  url: string
}

export interface DiboRowApi {
  id: number | string
  nomor: string
  tahun: number | string
  tentang: string
  jenis?: string | null
  id_jenis?: number | string | null
  keterangan?: string | null
  /** Nomor berkas yang ada (1..3). */
  berkas?: number[] | null
  status?: number | string | null
}

export interface DiboRow {
  id: string
  nomor: string
  tahun: string
  tentang: string
  jenis: string
  id_jenis: string
  keterangan: string
  berkas: number[]
  status: '1' | '2' | '10'
}

export interface DiboForm {
  id_jenis: string
  nomor: string
  tahun: string
  tentang: string
  keterangan: string
}

/** Berkas form DIBO: pilihan baru per nomor (null = tidak diganti) + penanda hapus berkas 2/3 (legacy `deleted_file[]`). */
export interface DiboBerkasInput {
  1: File | null
  2: File | null
  3: File | null
  hapus2: boolean
  hapus3: boolean
}

/** Master sederhana (kategori berita / jenis DIBO): nama + urutan + status. */
export interface MasterKontenRow {
  id: string
  nama: string
  urutan: number
  /** Kategori berita: boleh dipakai role 3 (`allow_ap`). */
  allow_ap: boolean
  status: '1' | '2' | '10'
}

export type JenisMasterKonten = 'berita-kategori' | 'dibo-jenis'

/** Portal berita (PRD §5.5): detail. Draf kontrak. */
export interface BeritaDetailApi {
  id: KontenApiId
  judul: string
  kategori?: string | null
  penulis?: string | null
  tanggal?: string | null
  foto_url?: string | null
  /** HTML terbatas; dibersihkan ulang di FE sebelum ditampilkan. */
  isi?: string | null
  lampiran?: LampiranBerita | null
}

export interface BeritaDetail {
  id: string
  judul: string
  kategori: string
  penulis: string
  tanggal: string | null
  foto_url: string | null
  isi: string
  lampiran: LampiranBerita | null
}

export interface BeritaKategoriOpsi {
  id: string
  nama: string
}

/** DIBO untuk dibaca semua role (PRD §5.6, Q7-12/13): hanya status 1; unduh berkas 1..3 terautentikasi. Draf kontrak. */
export interface DiboBacaRowApi {
  id: KontenApiId
  nomor: string
  tahun: number | string
  tentang: string
  keterangan?: string | null
  jenis?: string | null
  /** Nomor berkas yang tersedia (1..3). */
  berkas?: number[] | null
}

export interface DiboBacaRow {
  id: string
  nomor: string
  tahun: string
  tentang: string
  keterangan: string
  jenis: string
  berkas: number[]
}
