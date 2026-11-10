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
