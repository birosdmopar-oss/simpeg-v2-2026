/** Portal Berita (Fase 7, F-07): semua role login melihat; CRUD (role 1, 3) di luar cakupan UI ini. */

export const BERITA_KATEGORI = ['Diklat', 'Seminar', 'Siaran Pers', 'Beasiswa', 'Pemberitahuan', 'Kebijakan'] as const
export type BeritaKategori = (typeof BERITA_KATEGORI)[number]

export interface BeritaItem {
  id: number
  judul: string
  /** Cuplikan isi (teks polos). */
  ringkasan: string
  penulis: string
  kategori: BeritaKategori
  /** ISO 8601 (UTC+7 diabaikan pada data contoh). */
  tanggal: string
  /** Indeks foto placeholder — HANYA data contoh; data asli membawa URL gambar dari backend. */
  foto_index: number
}

export interface BeritaListQuery {
  page: number
  per_page: number
  search?: string
  kategori?: string
}

export interface BeritaListResult {
  items: BeritaItem[]
  total: number
}

export interface BeritaKategoriCount {
  kategori: BeritaKategori
  jumlah: number
}
