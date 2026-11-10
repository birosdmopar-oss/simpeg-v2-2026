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
  status?: number | string | null
}

export interface BeritaKelolaRow {
  id: string
  judul: string
  kategori: string
  id_kategori: string
  penulis: string
  publish_at: string
  status: StatusBerita
}

export interface BeritaForm {
  judul: string
  id_kategori: string
  /** HTML terbatas dari UiTeksSederhana. */
  isi: string
  /** Y-m-d. */
  publish_at: string
  target: SpesifikasiTarget
}

export interface DiboRowApi {
  id: number | string
  judul: string
  jenis?: string | null
  id_jenis?: number | string | null
  keterangan?: string | null
  status?: number | string | null
}

export interface DiboRow {
  id: string
  judul: string
  jenis: string
  id_jenis: string
  keterangan: string
  status: '1' | '2' | '10'
}

export interface DiboForm {
  judul: string
  id_jenis: string
  keterangan: string
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
